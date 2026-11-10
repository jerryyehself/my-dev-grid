<?php

namespace Tests\Feature;

use App\Models\Documentation;
use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Scope;
use App\Models\Technique;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\Rules\Exists;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 外鍵指向已軟刪除的資料時，要回 422，不能寫進去。
 *
 * `exists:scopes,id` 這類規則只看資料列在不在，不管 `deleted_at`，所以已刪除的分類、述詞、
 * 文件都會被當成存在：寫進去之後，這筆資料連到一個畫面上看不到的東西（分類的父層甚至會在
 * controller 裡 `findOrFail()` 找不到而回 404）。外鍵規則統一由 `ForeignId::rules()` 產生，
 * 由它排除已刪除的資料。
 *
 * 每個資料集各送兩次：一次指向已刪除的資料（要 422，錯誤掛在該欄位上），一次是同一份請求
 * 指向現存的資料（要成功），確認 422 是因為那筆資料已刪除，不是請求本身有別的問題。
 */
class SoftDeletedForeignKeyTest extends TestCase
{
    use RefreshDatabase;

    private const ENTITY_MODELS = [
        'documentation' => Documentation::class,
        'technique' => Technique::class,
        'implementation' => Implementation::class,
    ];

    /** 三種實體各自可以連到哪兩種實體。 */
    private const LINK_FIELDS = [
        'documentation' => ['techniques', 'implementations'],
        'technique' => ['documentations', 'implementations'],
        'implementation' => ['documentations', 'techniques'],
    ];

    /**
     * 每種請求（三種實體、述詞、分類）× 新增／更新 × 外鍵欄位。
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function foreignKeyFields(): iterable
    {
        foreach (['store', 'update'] as $mode) {
            foreach (self::LINK_FIELDS as $own => $fields) {
                yield "{$mode} {$own} type" => [$mode, $own, 'type'];

                foreach ($fields as $field) {
                    yield "{$mode} {$own} {$field}.0.id" => [$mode, $own, "{$field}.0.id"];
                    yield "{$mode} {$own} {$field}.0.relation_id" => [$mode, $own, "{$field}.0.relation_id"];
                }
            }

            foreach (['subject_id', 'object_id', 'reverse_id'] as $path) {
                yield "{$mode} relation {$path}" => [$mode, 'relation', $path];
            }

            yield "{$mode} scope parent_class" => [$mode, 'scope', 'parent_class'];
        }
    }

    #[DataProvider('foreignKeyFields')]
    #[TestDox('外鍵指向已軟刪除的資料時回 422，指向現存的資料時寫入成功')]
    public function test_foreign_key_pointing_at_a_soft_deleted_row_returns_422(string $mode, string $own, string $path): void
    {
        $this->actingAsOwner();
        $scope = Scope::factory()->create(['parent_class' => null, 'call_number' => '00']);
        [$url, $payload] = $this->validRequest($mode, $own, $scope);

        $trashed = $this->createTarget($own, $path, $scope);
        $trashed->delete();
        $this->assertSoftDeleted($trashed);

        $withTrashedTarget = $payload;
        Arr::set($withTrashedTarget, $path, $trashed->getKey());

        $this->send($mode, $url, $withTrashedTarget)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$path]);

        $this->send($mode, $url, $payload)->assertSuccessful();
    }

    /**
     * 新的外鍵欄位如果直接寫 `exists:`，或忘了排除已刪除的資料，這裡會失敗；
     * 找到的外鍵欄位也都要列在上面的資料集裡，已刪除的情況才有測到。
     */
    #[TestDox('每個 FormRequest 的外鍵欄位都排除已刪除的資料，且都有測試')]
    public function test_every_foreign_key_rule_uses_the_shared_rule(): void
    {
        $found = [];

        foreach (File::files(app_path('Http/Requests')) as $file) {
            $class = 'App\\Http\\Requests\\'.$file->getFilenameWithoutExtension();

            foreach ((new $class)->rules() as $attribute => $rules) {
                $rules = is_string($rules) ? explode('|', $rules) : Arr::wrap($rules);
                $existsRules = array_filter(
                    $rules,
                    fn ($rule) => $rule instanceof Exists || (is_string($rule) && str_starts_with($rule, 'exists')),
                );

                if ($existsRules === []) {
                    continue;
                }

                $field = class_basename($class)." {$attribute}";
                $found[] = $field;

                foreach ($existsRules as $rule) {
                    $this->assertInstanceOf(Exists::class, $rule, "{$field}：外鍵改用 ForeignId::rules()，不要直接寫 exists 字串。");
                    $this->assertStringContainsString('deleted_at,"NULL"', (string) $rule, "{$field}：exists 規則沒有排除已刪除的資料。");
                }

                $this->assertContains('bail', $rules, "{$field}：外鍵規則少了 bail。");
                $this->assertContains('integer', $rules, "{$field}：外鍵規則少了 integer。");
            }
        }

        $tested = [];
        foreach (self::foreignKeyFields() as [$mode, $own, $path]) {
            $tested[] = Str::studly($mode).Str::studly($own).'Request '.str_replace('.0.', '.*.', $path);
        }

        sort($found);
        sort($tested);
        $this->assertNotEmpty($found);
        $this->assertSame($tested, $found, '外鍵欄位跟 foreignKeyFields() 的資料集對不上：新增的外鍵欄位要補進資料集。');
    }

    /**
     * 一份會成功的請求：所有外鍵都指向現存、而且彼此相容的資料。
     *
     * 實體與連結的述詞都用同一個分類，`RelationFitsLinkedScopes` 才會通過。
     *
     * @return array{string, array<string, mixed>}
     */
    private function validRequest(string $mode, string $own, Scope $scope): array
    {
        if ($own === 'scope') {
            $payload = [
                'parent_class' => $scope->id,
                'call_number' => '10',
                'name' => 'newScope',
                'comment' => 'comment',
            ];
            $url = $mode === 'store' ? '/api/scopes' : '/api/scopes/'.Scope::factory()->create()->id;

            return [$url, $payload];
        }

        if ($own === 'relation') {
            $payload = [
                'subject_id' => $scope->id,
                'object_id' => $scope->id,
                'class_number' => '01',
                'call_number' => '00',
                'name' => 'newName',
                'reverse_id' => $this->relationBetween($scope)->id,
            ];
            $url = $mode === 'store' ? '/api/relations' : '/api/relations/'.$this->relationBetween($scope)->id;

            return [$url, $payload];
        }

        $payload = ['type' => $scope->id, 'title' => 'Own title'];
        foreach (self::LINK_FIELDS[$own] as $field) {
            $class = self::ENTITY_MODELS[Str::singular($field)];
            $payload[$field] = [[
                'id' => $class::factory()->create(['type' => $scope->id])->id,
                'relation_id' => $this->relationBetween($scope)->id,
            ]];
        }

        $plural = Str::plural($own);
        $url = $mode === 'store'
            ? "/api/{$plural}"
            : "/api/{$plural}/".self::ENTITY_MODELS[$own]::factory()->create(['type' => $scope->id])->id;

        return [$url, $payload];
    }

    /** 一筆跟 `validRequest()` 裡同一欄位的資料條件相同的新資料，給測試刪掉後當作外鍵的目標。 */
    private function createTarget(string $own, string $path, Scope $scope): Model
    {
        if ($own === 'scope') {
            return Scope::factory()->create(['parent_class' => null, 'call_number' => '00']);
        }

        if (in_array($path, ['type', 'subject_id', 'object_id'], true)) {
            return Scope::factory()->create(['parent_class' => $scope->id]);
        }

        if ($path === 'reverse_id' || str_ends_with($path, '.relation_id')) {
            return $this->relationBetween($scope);
        }

        $class = self::ENTITY_MODELS[Str::singular(Str::before($path, '.'))];

        return $class::factory()->create(['type' => $scope->id]);
    }

    /** 主詞與受詞都是 `$scope`、還沒有配對反向的述詞。 */
    private function relationBetween(Scope $scope): Relation
    {
        return Relation::factory()->create(['subject_id' => $scope->id, 'object_id' => $scope->id]);
    }

    /** @param  array<string, mixed>  $payload */
    private function send(string $mode, string $url, array $payload): TestResponse
    {
        return $mode === 'store' ? $this->postJson($url, $payload) : $this->putJson($url, $payload);
    }
}
