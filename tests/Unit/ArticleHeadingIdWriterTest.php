<?php

namespace Tests\Unit;

use App\Services\ArticleHeadingIdWriter;
use App\Services\ArticleSourceParser;
use App\Services\ArticleUnitSplitter;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * 補寫標題 ID：只替沒有 `{#id}` 的 H2、H3 補上，其餘位元組一個都不動，重跑不再變。
 * 標題怎麼認、ID 怎麼讀，全部沿用 `ArticleUnitSplitter`，這裡只驗補寫的結果。
 */
class ArticleHeadingIdWriterTest extends TestCase
{
    private const FRONT_MATTER = "---\nslug: sample\ntitle: 範例\nimport: true\n---\n";

    /** @param  list<string>  $reserved */
    private function write(string $raw, array $reserved = []): array
    {
        return (new ArticleHeadingIdWriter)->write($raw, $reserved);
    }

    private function doc(string $body): string
    {
        return self::FRONT_MATTER.$body;
    }

    /** @return list<string> 補上的 ID，照文件順序 */
    private function addedIds(array $result): array
    {
        return array_column($result['added'], 'id');
    }

    /** 單位的所有欄位，去掉會因補 ID 而改變的 ID 欄位（單位 ID、父單位 ID、子片段 ID）。 */
    private function unitsWithoutIds(string $body): array
    {
        return array_map(function (array $unit) {
            unset($unit['unit_id'], $unit['parent_id']);
            $unit['chunks'] = $unit['chunks'] === null ? null : array_map(fn (array $chunk) => array_diff_key($chunk, ['id' => 1]), $unit['chunks']);

            return $unit;
        }, (new ArticleUnitSplitter)->split($body)['units']);
    }

    #[TestDox('沒有 ID 的 H2、H3 依文件順序補上 s1、s2…，H1 與 H4 以下不動')]
    public function test_adds_short_ascii_ids_to_h2_and_h3_in_order(): void
    {
        $result = $this->write($this->doc("# 大標\n\n導言\n\n## 前提\n\n內文\n\n### 細節\n\n#### 更細\n\n## 結論\n"));

        $this->assertSame(
            self::FRONT_MATTER."# 大標\n\n導言\n\n## 前提 {#s1}\n\n內文\n\n### 細節 {#s2}\n\n#### 更細\n\n## 結論 {#s3}\n",
            $result['content'],
        );
        $this->assertSame(['s1', 's2', 's3'], $this->addedIds($result));
        $this->assertNull($result['skipped']);
    }

    #[TestDox('回報補在哪一行（原檔行號，含 front matter）、哪一級、什麼標題')]
    public function test_reports_file_line_level_and_heading(): void
    {
        $result = $this->write($this->doc("導言\n\n## 前提\n\n### 細節\n"));

        $this->assertSame([
            ['line' => 8, 'level' => 2, 'heading' => '前提', 'id' => 's1'],
            ['line' => 10, 'level' => 3, 'heading' => '細節', 'id' => 's2'],
        ], $result['added']);
    }

    #[TestDox('已有 ID 的標題不動，只補沒有的')]
    public function test_leaves_headings_that_already_have_an_id(): void
    {
        $raw = $this->doc("## 前提 {#premise}\n\n## 結論\n\n### 細節 {#detail}\n");

        $result = $this->write($raw);

        $this->assertSame(self::FRONT_MATTER."## 前提 {#premise}\n\n## 結論 {#s1}\n\n### 細節 {#detail}\n", $result['content']);
        $this->assertSame(['s1'], $this->addedIds($result));
    }

    #[TestDox('所有標題都有 ID 時不改任何位元組')]
    public function test_changes_nothing_when_every_heading_has_an_id(): void
    {
        $raw = $this->doc("## 前提 {#a}\n\n### 細節 {#b}\n");

        $result = $this->write($raw);

        $this->assertSame($raw, $result['content']);
        $this->assertSame([], $result['added']);
    }

    /** @return array<string, array{string, list<string>}> */
    public static function collisions(): array
    {
        return [
            '已有 s1，新的從 s2 起' => ["## 甲 {#s1}\n\n## 乙\n", ['s2']],
            '已有 s2，s1 還空著就用 s1，s2 跳過' => ["## 甲\n\n## 乙 {#s2}\n\n## 丙\n", ['s1', 's3']],
            '大小寫不同也算撞（S1）' => ["## 甲 {#S1}\n\n## 乙\n", ['s2']],
            '寫在 H4 標題上的 ID 也算' => ["#### 細項 {#s1}\n\n## 乙\n", ['s2']],
            '寫在 H1 標題上的 ID 也算' => ["# 大標 {#s1}\n\n## 乙\n", ['s2']],
        ];
    }

    /** @param  list<string>  $expectedIds */
    #[DataProvider('collisions')]
    #[TestDox('新 ID 不跟同一篇既有的 ID 撞')]
    public function test_new_ids_never_collide_with_existing_ones(string $body, array $expectedIds): void
    {
        $result = $this->write($this->doc($body));

        $this->assertSame($expectedIds, $this->addedIds($result));
        $this->assertSame([], (new ArticleUnitSplitter)->split($this->bodyOf($result['content']))['duplicate_ids']);
    }

    /** @return array<string, array{string, list<string>, list<string>}> */
    public static function reservedIds(): array
    {
        return [
            '刪掉的 s2 不再發出去' => ["## 甲 {#s1}\n\n## 丙\n", ['lead', 's1', 's2'], ['s3']],
            '原稿已經沒有任何 ID，保留的照樣跳過' => ["## 甲\n\n## 乙\n", ['s1', 's3'], ['s2', 's4']],
            '保留的大小寫不同也算（S1）' => ["## 甲\n", ['S1'], ['s2']],
            '不是 sN 的保留 ID 不影響編號' => ["## 甲\n", ['intro', 's10'], ['s1']],
            '沒有保留的 ID 時照舊從 s1 起' => ["## 甲\n", [], ['s1']],
        ];
    }

    /**
     * @param  list<string>  $reserved
     * @param  list<string>  $expectedIds
     */
    #[DataProvider('reservedIds')]
    #[TestDox('新 ID 也跳過呼叫端保留的 ID（資料庫裡這篇文章用過、原稿已經刪掉的）')]
    public function test_new_ids_skip_reserved_ones(string $body, array $reserved, array $expectedIds): void
    {
        $this->assertSame($expectedIds, $this->addedIds($this->write($this->doc($body), $reserved)));
    }

    private function bodyOf(string $content): string
    {
        return substr($content, strlen(self::FRONT_MATTER));
    }

    /** @return array<string, array{string}> */
    public static function nonHeadings(): array
    {
        return [
            '反引號圍欄裡' => ["```\n## 範例\n### 範例\n```\n"],
            '波浪號圍欄裡' => ["~~~md\n## 範例\n~~~\n"],
            '圍欄沒關上，一直到文末' => ["```\n## 範例\n\n## 還是範例\n"],
            '四個空白縮排' => ["    ## 縮排的程式碼\n"],
            '一個 tab 縮排' => ["\t## 縮排的程式碼\n"],
            '#號後面沒有空白' => ["##沒有空白\n"],
            '單獨一行 ##' => ["##\n"],
            '引言區塊裡' => ["> ## 引言裡的標題\n"],
            '行內的井號' => ["內文 ## 不是標題\n"],
            'H4 以下與 H1' => ["# 大標\n\n#### 四\n\n##### 五\n\n###### 六\n"],
        ];
    }

    #[DataProvider('nonHeadings')]
    #[TestDox('程式碼區塊、縮排行與不是 H2／H3 的行不補 ID')]
    public function test_does_not_touch_lines_that_are_not_h2_or_h3_headings(string $body): void
    {
        $raw = $this->doc($body);

        $result = $this->write($raw);

        $this->assertSame($raw, $result['content']);
        $this->assertSame([], $result['added']);
    }

    #[TestDox('程式碼區塊前後的標題照補，區塊裡面不補，區塊關上之後恢復')]
    public function test_headings_around_a_code_block_are_still_handled(): void
    {
        $result = $this->write($this->doc("## 前\n\n```\n## 範例\n```\n\n## 後\n"));

        $this->assertSame(self::FRONT_MATTER."## 前 {#s1}\n\n```\n## 範例\n```\n\n## 後 {#s2}\n", $result['content']);
    }

    #[TestDox('front matter 裡長得像標題的行不動')]
    public function test_does_not_touch_the_front_matter(): void
    {
        $raw = "---\nslug: sample\ntitle: 範例\nimport: true\nnote: |\n  ## 不是標題\n---\n## 真標題\n";

        $result = $this->write($raw);

        $this->assertSame("---\nslug: sample\ntitle: 範例\nimport: true\nnote: |\n  ## 不是標題\n---\n## 真標題 {#s1}\n", $result['content']);
    }

    /** @return array<string, array{string, string}> 原稿、補上之後 */
    public static function byteCases(): array
    {
        $fm = self::FRONT_MATTER;

        return [
            'CRLF 換行' => [str_replace("\n", "\r\n", $fm)."## 甲\r\n\r\n內文\r\n### 乙\r\n", str_replace("\n", "\r\n", $fm)."## 甲 {#s1}\r\n\r\n內文\r\n### 乙 {#s2}\r\n"],
            '開頭有 BOM' => ["\u{FEFF}".$fm."## 甲\n", "\u{FEFF}".$fm."## 甲 {#s1}\n"],
            'BOM 加 CRLF' => ["\u{FEFF}".str_replace("\n", "\r\n", $fm)."## 甲\r\n", "\u{FEFF}".str_replace("\n", "\r\n", $fm)."## 甲 {#s1}\r\n"],
            '結尾沒有換行' => [$fm.'## 甲', $fm.'## 甲 {#s1}'],
            '結尾沒有換行（CRLF，最後一行是標題）' => [$fm."## 甲\r\n## 乙", $fm."## 甲 {#s1}\r\n## 乙 {#s2}"],
            '結尾多個空行' => [$fm."## 甲\n\n\n\n", $fm."## 甲 {#s1}\n\n\n\n"],
            '標題尾端有空白' => [$fm."## 甲  \t\n", $fm."## 甲 {#s1}  \t\n"],
            '標題有收尾的井號' => [$fm."## 甲 ##\n### 乙 ###  \n", $fm."## 甲 {#s1} ##\n### 乙 {#s2} ###  \n"],
            '標題結尾是井號但不是收尾（C#）' => [$fm."## C#\n", $fm."## C# {#s1}\n"],
            '最多三個空白縮排的標題' => [$fm."   ## 甲\n", $fm."   ## 甲 {#s1}\n"],
            '標題之間混有空行與其他 Unicode 空白' => [$fm."## 甲\u{3000}\n\n\u{3000}\n## 乙\n", $fm."## 甲\u{3000} {#s1}\n\n\u{3000}\n## 乙 {#s2}\n"],
            '行尾有孤立的 CR（只去掉最後一個）' => [$fm."## 甲\r\r\n", $fm."## 甲\r {#s1}\r\n"],
        ];
    }

    #[DataProvider('byteCases')]
    #[TestDox('除了補上的 {#id}，其餘位元組（換行、BOM、結尾、空白）逐位元組不變')]
    public function test_everything_except_the_inserted_ids_is_byte_identical(string $raw, string $expected): void
    {
        $result = $this->write($raw);

        $this->assertSame($expected, $result['content']);
        // 把補上的 ID 拿掉要剛好還原：沒有多吃、多加任何東西
        $restored = $result['content'];
        foreach ($result['added'] as $added) {
            $restored = str_replace(' {#'.$added['id'].'}', '', $restored);
        }
        $this->assertSame($raw, $restored);
    }

    #[DataProvider('byteCases')]
    #[TestDox('補完再跑一次，內容不變、補 0 個')]
    public function test_second_run_changes_nothing(string $raw): void
    {
        $first = $this->write($raw);

        $second = $this->write($first['content']);

        $this->assertSame($first['content'], $second['content']);
        $this->assertSame([], $second['added']);
        $this->assertNull($second['skipped']);
    }

    #[TestDox('補完之後切出的單位（數量、標題、內容、腳註）跟補之前一致，只多了 ID')]
    public function test_split_result_is_identical_apart_from_ids(): void
    {
        $body = "導言 [^1]\n\n## 前提\n\n內文\n\n### 細節\n\n```\n## 範例\n```\n\n## 來源\n\n- 連結\n\n[^1]: 腳註\n";

        $result = $this->write($this->doc($body));
        $after = $this->bodyOf($result['content']);

        $this->assertSame($this->unitsWithoutIds($body), $this->unitsWithoutIds($after));
        $this->assertSame(['lead', 's1', 's2', 's3'], array_column((new ArticleUnitSplitter)->split($after)['units'], 'unit_id'));
    }

    #[TestDox('補完的原稿匯入時不會被判成不合格')]
    public function test_result_passes_the_import_parser(): void
    {
        $result = $this->write($this->doc("## 前提\n\n內文\n\n### 細節\n"));

        $parsed = (new ArticleSourceParser)->parse($result['content']);

        $this->assertTrue($parsed['import']);
        $this->assertNull($parsed['invalid']);
    }

    /** @return array<string, array{string, string}> 原稿、錯誤訊息要含的字 */
    public static function rejected(): array
    {
        $fm = self::FRONT_MATTER;

        return [
            '既有 ID 重複' => [$fm."## 甲 {#a}\n\n## 乙 {#a}\n\n## 丙\n", '重複'],
            '既有 ID 跟導言的 lead 重複' => [$fm."## 甲 {#lead}\n\n## 乙\n", '重複'],
            '不是 UTF-8' => [$fm."## 甲\xB1\xB2\n", 'UTF-8'],
            '含 NUL 字元' => [$fm."## 甲\n\0\n", 'NUL'],
        ];
    }

    #[DataProvider('rejected')]
    #[TestDox('無法安全補寫的原稿丟例外，不回傳半成品')]
    public function test_rejects_sources_it_cannot_safely_rewrite(string $raw, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->write($raw);
    }

    /** @return array<string, array{string}> */
    public static function withoutFrontMatter(): array
    {
        return [
            '完全沒有 front matter' => ["## 甲\n\n## 乙\n"],
            'front matter 沒有結束行' => ["---\nslug: sample\n\n## 甲\n"],
            '--- 不在第一行' => ["\n---\nslug: sample\n---\n## 甲\n"],
            '空檔案' => [''],
        ];
    }

    #[DataProvider('withoutFrontMatter')]
    #[TestDox('沒有 front matter 的檔案（匯入時不認）原樣回傳並標記略過')]
    public function test_skips_files_without_front_matter(string $raw): void
    {
        $result = $this->write($raw);

        $this->assertSame($raw, $result['content']);
        $this->assertSame([], $result['added']);
        $this->assertNotNull($result['skipped']);
    }

    #[TestDox('front matter 的結束行可以是 ... ，也容許行尾有空白')]
    public function test_front_matter_may_end_with_dots_and_trailing_spaces(): void
    {
        $result = $this->write("---  \nslug: sample\ntitle: 範例\nimport: true\n...  \n## 甲\n");

        $this->assertSame("---  \nslug: sample\ntitle: 範例\nimport: true\n...  \n## 甲 {#s1}\n", $result['content']);
    }
}
