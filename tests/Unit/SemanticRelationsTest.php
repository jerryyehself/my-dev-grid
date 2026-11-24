<?php

namespace Tests\Unit;

use App\Services\SemanticRelations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * 上下位與相關的規則檢查（`SemanticRelations::problems()`），以及合併的模擬（`merged()`），不碰資料庫。
 *
 * 用這樣的形狀：65 Cloudflare Workers、66 Cloudflare Pages 掛在 64 Cloudflare 底下，
 * 66 與 46 github-pages 掛在 67 靜態託管底下；56 vue-router related 68 SPA。
 */
class SemanticRelationsTest extends TestCase
{
    /** @return array<int, list<int>> */
    private static function e2(): array
    {
        return [65 => [64], 66 => [64, 67], 46 => [67]];
    }

    #[TestDox('多重上位、技術掛到概念底下的資料沒有任何問題（多重上位、兄弟之間沒標相關）')]
    public function test_the_design_examples_are_clean(): void
    {
        $relations = new SemanticRelations(self::e2(), [56 => [68], 65 => [68], 68 => [56, 65]]);

        $this->assertSame([], $relations->problems());
    }

    /** @return iterable<string, array{array<int, list<int>>, array<int, list<int>>, string, list<int>}> */
    public static function violations(): iterable
    {
        yield '上位是自己' => [[64 => [64]], [], 'self', [64]];
        yield '相關是自己' => [[], [68 => [68]], 'self', [68]];
        yield '兩個互為上位' => [[64 => [65], 65 => [64]], [], 'cycle', [64, 65]];
        yield '三個繞一圈' => [[1 => [2], 2 => [3], 3 => [1]], [], 'cycle', [1, 2, 3]];
        yield '下位跟上位又標相關（S27）' => [self::e2(), [65 => [64], 64 => [65]], 'related_in_hierarchy', [64, 65]];
        yield '隔兩層也算上下位路徑（broaderTransitive）' => [[1 => [2], 2 => [3]], [1 => [3], 3 => [1]], 'related_in_hierarchy', [1, 3]];
        yield '已經能經 Cloudflare Pages 走到靜態託管，又直接標一次' => [[66 => [67], 99 => [66, 67]], [], 'redundant_broader', [99, 67]];
    }

    /**
     * @param  array<int, list<int>>  $broader
     * @param  array<int, list<int>>  $related
     * @param  list<int>  $ids
     */
    #[DataProvider('violations')]
    #[TestDox('違反詞表規則的資料都找得出來')]
    public function test_violations_are_reported(array $broader, array $related, string $rule, array $ids): void
    {
        $problems = array_values((new SemanticRelations($broader, $related))->problems());

        $this->assertContains([$rule, $ids], $problems);
    }

    /** @return iterable<string, array{array<int, list<int>>, array<int, list<int>>}> */
    public static function nearMisses(): iterable
    {
        yield '兩個上位各走各的（多重上位，不是重複）' => [[66 => [64, 67]], []];
        yield '兄弟之間標相關（S27 只管上下位路徑）' => [[65 => [64], 66 => [64]], [65 => [66], 66 => [65]]];
        yield '同一個上位被兩個下位指到（不是環）' => [[1 => [3], 2 => [3]], []];
        yield '一路往上的鏈（1→2→3），沒有另外直接標 1→3' => [[1 => [2], 2 => [3]], []];
    }

    /**
     * @param  array<int, list<int>>  $broader
     * @param  array<int, list<int>>  $related
     */
    #[DataProvider('nearMisses')]
    #[TestDox('長得像、但沒有違反規則的資料不會被報')]
    public function test_near_misses_are_not_reported(array $broader, array $related): void
    {
        $this->assertSame([], (new SemanticRelations($broader, $related))->problems());
    }

    #[TestDox('只有指進環的技術不算在環上，環只報環上的那幾個')]
    public function test_a_node_pointing_into_a_cycle_is_not_part_of_it(): void
    {
        $problems = (new SemanticRelations([1 => [2], 2 => [3], 3 => [2]], []))->problems();

        $this->assertSame(['cycle:2,3' => ['cycle', [2, 3]]], $problems);
    }

    #[TestDox('合併的模擬：邊改接到留下來的那一筆，改接後自己連自己的拿掉，重複的只留一條')]
    public function test_merged_rewires_edges(): void
    {
        // 10 Vue 3 isVersionOf 29 Vue（算上下位），10 related 68 SPA；合併 10 → 29
        $merged = (new SemanticRelations([10 => [29], 56 => [29]], [10 => [68], 68 => [10]]))->merged(10, 29);

        $this->assertSame([56 => [29]], $merged->broader());
        $this->assertSame([[29, 68]], $merged->relatedPairs());
        $this->assertSame([], $merged->problems());
    }

    #[TestDox('鄰居包含上位、下位與相關，不含自己')]
    public function test_neighbours(): void
    {
        $relations = new SemanticRelations(self::e2(), [66 => [68], 68 => [66]]);

        $this->assertEqualsCanonicalizing([64, 67, 68], $relations->neighbours(66));
        $this->assertEqualsCanonicalizing([65, 66], $relations->neighbours(64));
    }
}
