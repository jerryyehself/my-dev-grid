<?php

namespace Tests\Feature;

use App\Models\Documentation;
use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Scope;
use App\Models\Technique;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Exists;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 外鍵欄位指到已軟刪除的資料時要回 422，不能照樣寫進去。
 *
 * Laravel 的 `exists` 規則直接查資料表，不知道 model 有軟刪除；不另外排除的話，已刪除的分類、
 * 述詞、實體都還能被新的資料連上。排除的條件寫在 `App\Rules\ForeignId::rules()`，每個外鍵欄位
 * 都透過它產生規則。
 *
 * 這裡守兩件事：每個外鍵欄位的行為（指到已刪除的資料回 422，指到存在的資料照常寫入），以及
 * 每支 FormRequest 的外鍵規則都長成 `ForeignId::rules()` 的樣子，新欄位漏掉時測試會失敗。
 */
class SoftDeletedForeignKeyTest extends TestCase
{
    use RefreshDatabase;

    private const MODELS = [
        'documentation' => Documentation::class,
        'technique' => Technique::class,
        'implementation' => Implementation::class,
    ];

    /** 每種請求有哪些外鍵欄位（清單欄位以第 0 筆代表）。 */
    private const FOREIGN_KEYS = [
        'documentation' => ['type', 'techniques.0.id', 'techniques.0.relation_id', 'implementations.0.id', 'implementations.0.relation_id'],
        'technique' => ['type', 'documentations.0.id', 'documentations.0.relation_id', 'implementations.0.id', 'implementations.0.relation_id'],
        'implementation' => ['type', 'documentations.0.id', 'documentations.0.relation_id', 'techniques.0.id', 'techniques.0.relation_id'],
        'relation' => ['subject_id', 'object_id', 'reverse_id'],
        'scope' => ['parent_class'],
    ];

    /** @return iterable<string, array{string, string}> */
    public static function requests(): iterable
    {
        foreach (['store', 'update'] as $mode) {
            foreach (array_keys(self::FOREIGN_KEYS) as $own) {
                yield "{$mode} {$own}" => [$mode, $own];
            }
        }
    }

    /**
     * 每種請求 × 新增／更新 × 外鍵欄位。
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function foreignKeys(): iterable
    {
        foreach (self::requests() as $name => [$mode, $own]) {
            foreach (self::FOREIGN_KEYS[$own] as $path) {
                yield "{$name} {$path}" => [$mode, $own, $path];
            }
        }
    }

    #[DataProvider('foreignKeys')]
    #[TestDox('外鍵欄位指到已軟刪除的資料時回 422，只報那一個欄位')]
    public function test_soft_deleted_target_returns_422(string $mode, string $own, string $path): void
    {
        $this->actingAsOwner();
        [$url, $payload, $targets] = $this->validRequest($mode, $own, only: $path);

        $targets[$path]->delete();
        $this->assertSoftDeleted($targets[$path]);

        $response = $mode === 'store' ? $this->postJson($url, $payload) : $this->putJson($url, $payload);

        $response->assertUnprocessable()->assertJsonValidationErrors([$path]);
        // 其他欄位都指到存在的資料，所以錯誤只能來自被刪除的那一筆。
        $this->assertSame([$path], array_keys($response->json('errors')));
    }

    /**
     * 對照組：同一份請求、每個外鍵都指到存在的資料時要成功，上面的 422 才確定是軟刪除造成的。
     */
    #[DataProvider('requests')]
    #[TestDox('外鍵欄位都指到存在的資料時照常寫入')]
    public function test_live_targets_are_accepted(string $mode, string $own): void
    {
        $this->actingAsOwner();
        [$url, $payload, $targets] = $this->validRequest($mode, $own);

        $this->assertSame(self::FOREIGN_KEYS[$own], array_keys($targets));

        $response = $mode === 'store' ? $this->postJson($url, $payload) : $this->putJson($url, $payload);

        $response->assertSuccessful();
    }

    /**
     * 外鍵規則一律由 `ForeignId::rules()` 產生：`bail`、`integer`，加上排除軟刪除的 `exists`。
     *
     * 直接寫 `exists:table,id` 字串、或自己組 `Rule::exists()` 而漏掉其中一項，這裡都會失敗。
     * 同時對照上面的 `FOREIGN_KEYS`：FormRequest 多了一個外鍵欄位而這份測試沒列到，也會失敗。
     */
    #[TestDox('每支 FormRequest 的外鍵規則都排除軟刪除，而且都列在這份測試裡')]
    public function test_every_form_request_foreign_key_uses_the_shared_rules(): void
    {
        $violations = [];
        $found = [];

        foreach (glob(app_path('Http/Requests/*.php')) as $file) {
            $name = basename($file, '.php');
            /** @var FormRequest $request */
            $request = new ('App\\Http\\Requests\\'.$name);

            foreach ($request->rules() as $field => $rules) {
                $rules = is_string($rules) ? explode('|', $rules) : Arr::wrap($rules);
                $strings = array_filter($rules, is_string(...));

                foreach ($rules as $rule) {
                    if (is_string($rule) && Str::startsWith($rule, 'exists')) {
                        $violations[] = "{$name} 的 {$field} 直接寫了 `{$rule}`，請改用 ForeignId::rules()。";
                    }

                    if (! $rule instanceof Exists) {
                        continue;
                    }

                    $found[] = Str::of($name)->replaceMatches('/^(Store|Update|Index)(\w+)Request$/', '$1 $2')->lower()
                        .' '.str_replace('.*.', '.0.', $field);

                    if (! in_array('bail', $strings, true) || ! in_array('integer', $strings, true)) {
                        $violations[] = "{$name} 的 {$field} 在 exists 前面少了 bail 或 integer，請改用 ForeignId::rules()。";
                    }

                    if (! Str::contains((string) $rule, 'deleted_at,"NULL"')) {
                        $violations[] = "{$name} 的 {$field} 沒有排除已軟刪除的資料，請改用 ForeignId::rules()。";
                    }
                }
            }
        }

        $this->assertSame([], $violations);

        $tested = array_keys(iterator_to_array(self::foreignKeys()));
        sort($found);
        sort($tested);
        $this->assertSame($tested, $found, 'FormRequest 的外鍵欄位跟這份測試列的對不上，新欄位要補進 FOREIGN_KEYS。');
    }

    /**
     * 組出一份會成功的請求，並回傳每個外鍵欄位指到的那一筆資料。
     *
     * 刪除分類會連帶軟刪除以它為主詞、受詞的述詞（`Scope::booted()`），所以各欄位指到的資料
     * 要彼此獨立，刪掉其中一筆才不會讓別的欄位跟著失效：
     * - 實體的 `type` 用頂層分類底下的子分類，述詞的兩端規定的是頂層分類（子分類算符合）。
     * - 述詞的 `reverse_id` 指到的那條述詞，兩端就是請求的 `subject_id`／`object_id`；
     *   要刪除其中一個分類時（$only 是 `subject_id`／`object_id`），請求就不帶 `reverse_id`。
     *
     * @param  string|null  $only  只打算刪除這個欄位指到的資料；null 表示全部都留著
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, Scope|Relation|Documentation|Technique|Implementation>}
     */
    private function validRequest(string $mode, string $own, ?string $only = null): array
    {
        $top = Scope::factory()->create(['parent_class' => null]);

        if ($own === 'scope') {
            $existing = Scope::factory()->create(['parent_class' => null]);

            return [
                $mode === 'store' ? '/api/scopes' : "/api/scopes/{$existing->id}",
                ['name' => 'newScope', 'comment' => 'comment', 'call_number' => '10', 'parent_class' => $top->id],
                ['parent_class' => $top],
            ];
        }

        if ($own === 'relation') {
            $object = Scope::factory()->create(['parent_class' => null]);
            $reverse = Relation::factory()->create(['subject_id' => $object->id, 'object_id' => $top->id]);
            $existing = Relation::factory()->create();
            $withReverse = $only === null || $only === 'reverse_id';

            return [
                $mode === 'store' ? '/api/relations' : "/api/relations/{$existing->id}",
                [
                    'name' => 'newName', 'class_number' => '01', 'call_number' => '00',
                    'subject_id' => $top->id, 'object_id' => $object->id,
                    'reverse_id' => $withReverse ? $reverse->id : null,
                ],
                ['subject_id' => $top, 'object_id' => $object, 'reverse_id' => $reverse],
            ];
        }

        $type = Scope::factory()->create(['parent_class' => $top->id]);
        $payload = ['type' => $type->id, 'title' => 'Own title'];
        $targets = ['type' => $type];

        foreach (self::FOREIGN_KEYS[$own] as $path) {
            if (! str_ends_with($path, '.0.id')) {
                continue;
            }

            $field = Str::before($path, '.');
            $linked = self::MODELS[Str::singular($field)]::factory()->create(['type' => $top->id]);
            $relation = Relation::factory()->create(['subject_id' => $top->id, 'object_id' => $top->id]);

            $payload[$field] = [['id' => $linked->id, 'relation_id' => $relation->id]];
            $targets["{$field}.0.id"] = $linked;
            $targets["{$field}.0.relation_id"] = $relation;
        }

        $plural = Str::plural($own);
        $existing = self::MODELS[$own]::factory()->create(['type' => $type->id]);

        return [$mode === 'store' ? "/api/{$plural}" : "/api/{$plural}/{$existing->id}", $payload, $targets];
    }
}
