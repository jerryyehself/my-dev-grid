<?php

namespace Tests\Feature;

use App\Models\Documentation;
use App\Models\Implementation;
use App\Models\Relation;
use App\Models\Scope;
use App\Models\Technique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 外鍵欄位收到不是整數的值，要回 422，不能讓值原封不動送進資料庫。
 *
 * `exists:scopes,id` 之類的規則會拿值去查 bigint 欄位。SQLite 對 `'abc'`、`1.5` 都不報錯，
 * PostgreSQL 卻會丟 `invalid input syntax for type bigint`，整個請求變成 500。
 * 所以每個外鍵欄位在 `exists` 前面都要先過 `integer`（並加 `bail`，失敗就不再往下跑）；
 * 自訂規則 `RelationFitsLinkedScopes` 也不能拿非整數的值去 `find()`。
 *
 * 這個測試在 SQLite 上本來就會過，要在 PostgreSQL 上跑才守得住這件事（CI 的 pgsql 那一輪）。
 */
class NonIntegerIdValidationTest extends TestCase
{
    use RefreshDatabase;

    private const MODELS = [
        'documentation' => Documentation::class,
        'technique' => Technique::class,
        'implementation' => Implementation::class,
    ];

    /**
     * 每種請求（三種實體、述詞、分類）× 新增／更新 × 外鍵欄位，再 × 不合格的值。
     *
     * @return iterable<string, array{string, string, string, mixed}>
     */
    public static function nonIntegerForeignKeys(): iterable
    {
        $linkFields = [
            'documentation' => ['techniques', 'implementations'],
            'technique' => ['documentations', 'implementations'],
            'implementation' => ['documentations', 'techniques'],
        ];
        // 字串、小數、陣列、超出 bigint 範圍（JSON 解成浮點數）。
        $values = ['abc' => 'abc', '1.5' => 1.5, '[1]' => [1], '1e20' => 99999999999999999999];

        $cases = [];
        foreach (['store', 'update'] as $mode) {
            foreach ($linkFields as $own => $fields) {
                $paths = ['type'];
                foreach ($fields as $field) {
                    $paths[] = "{$field}.0.id";
                    $paths[] = "{$field}.0.relation_id";
                }
                $cases[] = [$mode, $own, $paths];
            }
            $cases[] = [$mode, 'relation', ['subject_id', 'object_id', 'reverse_id']];
            // 更新分類時 `call_number` 的唯一性規則也會拿 `parent_class` 去查父分類，所以同時帶與不帶都要測。
            $cases[] = [$mode, 'scope', ['parent_class']];
            $cases[] = [$mode, 'scope without call_number', ['parent_class']];
        }

        foreach ($cases as [$mode, $own, $paths]) {
            foreach ($paths as $path) {
                foreach ($values as $label => $value) {
                    yield "{$mode} {$own} {$path} = {$label}" => [$mode, $own, $path, $value];
                }
            }
        }
    }

    #[DataProvider('nonIntegerForeignKeys')]
    #[TestDox('外鍵欄位收到非整數的值時回 422，不會讓資料庫丟錯')]
    public function test_non_integer_foreign_key_returns_422_not_a_database_error(string $mode, string $own, string $path, mixed $value): void
    {
        $this->actingAsOwner();
        $scope = Scope::factory()->create();

        if (str_starts_with($own, 'scope')) {
            $payload = ['name' => 'newScope', 'comment' => 'comment', 'parent_class' => Scope::factory()->create(['parent_class' => null])->id];
            if ($own === 'scope') {
                $payload['call_number'] = '10';
            }
            $url = $mode === 'store' ? '/api/scopes' : '/api/scopes/'.Scope::factory()->create()->id;
        } elseif ($own === 'relation') {
            $payload = ['name' => 'newName', 'subject_id' => $scope->id, 'object_id' => $scope->id, 'class_number' => '01', 'call_number' => '00'];
            $existing = Relation::factory()->create();
            $url = $mode === 'store' ? '/api/relations' : "/api/relations/{$existing->id}";
        } else {
            $relation = Relation::factory()->create();
            $payload = ['type' => $scope->id, 'title' => 'Own title'];
            foreach (['documentations', 'techniques', 'implementations'] as $field) {
                $class = self::MODELS[Str::singular($field)];

                if ($class !== self::MODELS[$own]) {
                    $payload[$field] = [['id' => $class::factory()->create()->id, 'relation_id' => $relation->id]];
                }
            }
            $plural = Str::plural($own);
            $url = $mode === 'store' ? "/api/{$plural}" : "/api/{$plural}/".self::MODELS[$own]::factory()->create()->id;
        }

        Arr::set($payload, $path, $value);

        $response = $mode === 'store' ? $this->postJson($url, $payload) : $this->putJson($url, $payload);

        $response->assertUnprocessable()->assertJsonValidationErrors([$path]);
    }
}
