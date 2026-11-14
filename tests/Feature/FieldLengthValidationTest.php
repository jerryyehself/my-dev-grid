<?php

namespace Tests\Feature;

use App\Models\Relation;
use App\Models\Scope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * 分類與述詞的文字欄位，長度超過資料庫欄位、或型別不對（陣列）時要回 422。
 *
 * `scopes`／`relations` 的 `name` 是 varchar(50)，`class_number`、`call_number` 是兩個字元。
 * 驗證沒擋的話，PostgreSQL 寫入時會丟 `value too long`，整個請求變成 500；SQLite 不檢查長度，
 * 照樣寫進去，所以長度的案例要在 PostgreSQL 上跑才守得住（CI 的 pgsql 那一輪）。
 * 陣列則是兩種資料庫都會出錯：沒有 `string` 規則時，陣列會一路送到查詢或寫入才失敗。
 * 類號與子類號維持原本「數字、可以只有一位」的收法，只多擋超過兩位、或帶小數點與正負號的值。
 */
class FieldLengthValidationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 新增／更新 × 述詞／分類 × 欄位 × 不合格的值。
     *
     * @return iterable<string, array{string, string, string, mixed}>
     */
    public static function rejectedValues(): iterable
    {
        $tooLongOrNotText = [
            'relation' => [
                'name' => ['51 個字' => str_repeat('a', 51), '陣列' => ['a']],
                'class_number' => ['三位數字串' => '123', '三位數' => 123, '帶小數點' => '1.5', '負號' => '-1', '陣列' => ['12']],
                'call_number' => ['三位數字串' => '100', '三位數' => 100, '帶小數點' => '1.5', '負號' => '-1', '陣列' => ['00']],
                'note' => ['256 個字' => str_repeat('a', 256), '256 個全形字' => str_repeat('註', 256), '陣列' => ['a']],
            ],
            'scope' => [
                'name' => ['51 個字' => str_repeat('a', 51), '陣列' => ['a']],
                'call_number' => ['三位數字串' => '100', '三位數' => 100, '帶小數點' => '1.5', '負號' => '-1', '陣列' => ['10']],
                // `scopes.comment` 不允許 NULL，留空要在驗證就擋下（空字串會被框架轉成 null）。
                'comment' => ['陣列' => ['a'], 'null' => null, '空字串' => ''],
                'note' => ['256 個字' => str_repeat('a', 256), '256 個全形字' => str_repeat('註', 256), '陣列' => ['a']],
            ],
        ];

        foreach (['store', 'update'] as $mode) {
            foreach ($tooLongOrNotText as $own => $fields) {
                foreach ($fields as $field => $values) {
                    foreach ($values as $label => $value) {
                        yield "{$mode} {$own} {$field} = {$label}" => [$mode, $own, $field, $value];
                    }
                }
            }
        }
    }

    #[DataProvider('rejectedValues')]
    #[TestDox('文字欄位太長或不是文字時回 422，只報那一個欄位')]
    public function test_too_long_or_non_text_value_returns_422(string $mode, string $own, string $field, mixed $value): void
    {
        $this->actingAsOwner();
        [$url, $payload] = $this->validRequest($mode, $own);

        $response = $this->send($mode, $url, [...$payload, $field => $value]);

        $response->assertUnprocessable()->assertJsonValidationErrors([$field]);
        $this->assertSame([$field], array_keys($response->json('errors')));
    }

    /**
     * 剛好在上限的值、以及留空，都要照常寫入。
     *
     * 「50 個全形字」守的是長度算字元不算位元組：PostgreSQL 的 varchar(50) 也是算字元。
     * 留空分成 null 與空字串兩種送法（空字串會被框架轉成 null）。
     *
     * @return iterable<string, array{string, string, string, mixed}>
     */
    public static function acceptedValues(): iterable
    {
        $withinLimit = [
            'relation' => [
                'name' => ['50 個字' => str_repeat('a', 50), '50 個全形字' => str_repeat('類', 50)],
                'class_number' => ['兩位數字串' => '99', '兩位數' => 99, '一位數字串' => '7'],
                'call_number' => ['兩位數字串' => '99', '兩位數' => 99, '一位數字串' => '7', 'null' => null, '空字串' => ''],
                'note' => ['255 個字' => str_repeat('a', 255), '255 個全形字' => str_repeat('註', 255), 'null' => null, '空字串' => ''],
            ],
            'scope' => [
                'name' => ['50 個字' => str_repeat('a', 50), '50 個全形字' => str_repeat('類', 50)],
                'call_number' => ['兩位數字串' => '99', '兩位數' => 99, '一位數字串' => '7', 'null' => null, '空字串' => ''],
                'note' => ['255 個字' => str_repeat('a', 255), '255 個全形字' => str_repeat('註', 255), 'null' => null, '空字串' => ''],
            ],
        ];

        foreach (['store', 'update'] as $mode) {
            foreach ($withinLimit as $own => $fields) {
                foreach ($fields as $field => $values) {
                    foreach ($values as $label => $value) {
                        yield "{$mode} {$own} {$field} = {$label}" => [$mode, $own, $field, $value];
                    }
                }
            }
        }
    }

    #[DataProvider('acceptedValues')]
    #[TestDox('文字欄位剛好在長度上限、或留空時照常寫入')]
    public function test_value_within_the_limit_is_accepted(string $mode, string $own, string $field, mixed $value): void
    {
        $this->actingAsOwner();
        [$url, $payload] = $this->validRequest($mode, $own);

        $response = $this->send($mode, $url, [...$payload, $field => $value]);

        $response->assertSuccessful();

        if ($field === 'name') {
            $this->assertDatabaseHas($own === 'relation' ? 'relations' : 'scopes', ['name' => $value]);
        }
    }

    /**
     * 一份會成功的請求；測試再把其中一個欄位換掉。
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function validRequest(string $mode, string $own): array
    {
        // 子類號固定成 00，不用工廠隨機挑的：新增與更新的子類號都不能跟同一個類號底下的別筆重複，
        // 隨機挑到 10 或 99 時會跟請求的子類號撞到。
        $top = Scope::factory()->create(['parent_class' => null, 'call_number' => '00']);

        if ($own === 'scope') {
            $existing = Scope::factory()->create(['parent_class' => null, 'call_number' => '01']);

            return [
                $mode === 'store' ? '/api/scopes' : "/api/scopes/{$existing->id}",
                ['name' => 'newScope', 'comment' => 'comment', 'note' => 'note', 'call_number' => '10', 'parent_class' => $top->id],
            ];
        }

        $existing = Relation::factory()->create();

        return [
            $mode === 'store' ? '/api/relations' : "/api/relations/{$existing->id}",
            ['name' => 'newName', 'note' => 'note', 'class_number' => '01', 'call_number' => '00', 'subject_id' => $top->id, 'object_id' => $top->id],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function send(string $mode, string $url, array $payload)
    {
        return $mode === 'store' ? $this->postJson($url, $payload) : $this->putJson($url, $payload);
    }
}
