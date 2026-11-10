<?php

namespace Tests\Feature;

use App\Models\Relation;
use App\Models\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 述詞與分類的文字欄位，長度與型別要在驗證時擋下，不能送到資料庫才出錯。
 *
 * `name` 是 varchar(50)，述詞的 `class_number`、`call_number` 是 varchar(2)，分類的 `call_number`
 * 是 char(2)。PostgreSQL 收到太長的值會丟 `value too long`（500），SQLite 不檢查長度、照樣存進去，
 * 所以超過長度的情況只有 CI 的 PostgreSQL 那一輪看得到。`name`、`note` 收到陣列時，兩種資料庫都是 500。
 */
class RelationScopeInputLimitsTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINTS = ['store relation', 'update relation', 'store scope', 'update scope'];

    /**
     * 四支寫入端點 × 欄位 × 值。最後一欄是這個值該不該通過。
     *
     * @return iterable<string, array{string, string, mixed, bool}>
     */
    public static function fieldValues(): iterable
    {
        $common = [
            // 中文字確認算的是字數不是位元組：PostgreSQL 的 varchar(50) 也是算字數。
            'name 剛好 50 字' => ['name', str_repeat('名', 50), true],
            'name 51 字' => ['name', str_repeat('名', 51), false],
            'name 是陣列' => ['name', ['a'], false],
            'note 是陣列' => ['note', ['a'], false],
            'note 是 null' => ['note', null, true],
            'call_number 兩位數' => ['call_number', '12', true],
            'call_number 是 JSON 整數' => ['call_number', 7, true],
            'call_number 三位數' => ['call_number', '123', false],
            'call_number 帶負號' => ['call_number', '-1', false],
            'call_number 是小數' => ['call_number', '1.5', false],
            'call_number 是陣列' => ['call_number', ['1'], false],
        ];
        $relationOnly = [
            'class_number 兩位數' => ['class_number', '12', true],
            'class_number 三位數' => ['class_number', '123', false],
            'class_number 是陣列' => ['class_number', ['1'], false],
        ];

        foreach (self::ENDPOINTS as $endpoint) {
            $cases = str_ends_with($endpoint, 'relation') ? [...$common, ...$relationOnly] : $common;

            foreach ($cases as $label => [$field, $value, $valid]) {
                yield "{$endpoint} {$label}" => [$endpoint, $field, $value, $valid];
            }
        }
    }

    #[DataProvider('fieldValues')]
    #[TestDox('述詞與分類的名稱、類號超過欄位長度或型別不對時回 422')]
    public function test_field_length_and_type_are_validated(string $endpoint, string $field, mixed $value, bool $valid): void
    {
        $this->actingAsOwner();
        [$mode, $resource] = explode(' ', $endpoint);
        [$url, $payload] = $resource === 'relation' ? $this->relationRequest($mode) : $this->scopeRequest($mode);

        Arr::set($payload, $field, $value);
        $response = $this->send($mode, $url, $payload);

        if ($valid) {
            $response->assertSuccessful();
        } else {
            $response->assertUnprocessable()->assertJsonValidationErrors([$field]);
        }
    }

    /** @return array{string, array<string, mixed>} */
    private function relationRequest(string $mode): array
    {
        $scope = Scope::factory()->create();
        $payload = [
            'subject_id' => $scope->id,
            'object_id' => $scope->id,
            'class_number' => '01',
            'call_number' => '00',
            'name' => 'newName',
            'note' => 'note',
        ];
        $url = $mode === 'store' ? '/api/relations' : '/api/relations/'.Relation::factory()->create()->id;

        return [$url, $payload];
    }

    /** @return array{string, array<string, mixed>} */
    private function scopeRequest(string $mode): array
    {
        $parent = Scope::factory()->create(['parent_class' => null, 'call_number' => '00']);
        $payload = [
            'parent_class' => $parent->id,
            'call_number' => '10',
            'name' => 'newScope',
            'comment' => 'comment',
            'note' => 'note',
        ];
        $url = $mode === 'store' ? '/api/scopes' : '/api/scopes/'.Scope::factory()->create()->id;

        return [$url, $payload];
    }

    /** @param  array<string, mixed>  $payload */
    private function send(string $mode, string $url, array $payload): TestResponse
    {
        return $mode === 'store' ? $this->postJson($url, $payload) : $this->putJson($url, $payload);
    }
}
