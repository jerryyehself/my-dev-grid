<?php

namespace Tests\Unit;

use App\Services\ArticleSourceParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * 文章原稿的解析：哪些檔案算選擇匯入、front matter 與 H1 怎麼拿掉、哪些情況不能匯入。
 */
class ArticleSourceParserTest extends TestCase
{
    private function parse(string $raw): array
    {
        return (new ArticleSourceParser)->parse($raw);
    }

    #[TestDox('拿掉 front matter 與開頭的 H1，標題取自 H1')]
    public function test_front_matter_and_leading_heading_are_removed(): void
    {
        $result = $this->parse("---\nslug: my-post\nimport: true\n---\n\n# 我的文章\n\n## 小節\n\n內文\n");

        $this->assertTrue($result['import']);
        $this->assertNull($result['invalid']);
        $this->assertSame('my-post', $result['key']);
        $this->assertSame('我的文章', $result['title']);
        $this->assertSame("## 小節\n\n內文\n", $result['body']);
        $this->assertSame(8, $result['body_line']);
    }

    #[TestDox('front matter 的標題跟 H1 不同時保留 H1')]
    public function test_heading_is_kept_when_front_matter_title_differs(): void
    {
        $result = $this->parse("---\nslug: s\ntitle: 站上的標題\nimport: true\n---\n# 原稿的 H1\n內文\n");

        $this->assertSame('站上的標題', $result['title']);
        $this->assertSame("# 原稿的 H1\n內文\n", $result['body']);
    }

    #[TestDox('front matter 的標題跟 H1 相同時拿掉 H1')]
    public function test_heading_is_removed_when_it_matches_the_front_matter_title(): void
    {
        $result = $this->parse("---\nslug: s\ntitle: 同一個標題\nimport: true\n---\n# 同一個標題 #\n\n內文\n");

        $this->assertSame('同一個標題', $result['title']);
        $this->assertSame("內文\n", $result['body']);
    }

    #[TestDox('H2 不會被當成標題')]
    public function test_second_level_heading_is_not_a_title(): void
    {
        $result = $this->parse("---\nslug: s\nimport: true\n---\n## 小節\n");

        $this->assertSame('title_missing', $result['invalid']);
    }

    #[TestDox('CRLF 換行與 BOM 都能解析，內文換行統一成 LF')]
    public function test_crlf_and_bom_are_normalised(): void
    {
        $result = $this->parse("\u{FEFF}---\r\nslug: s\r\nimport: true\r\n---\r\n# 標題\r\n\r\n內文\r\n");

        $this->assertNull($result['invalid']);
        $this->assertSame('標題', $result['title']);
        $this->assertSame("內文\n", $result['body']);
    }

    #[TestDox('縮排的程式碼區塊不會被修剪')]
    public function test_indented_code_block_is_preserved(): void
    {
        $result = $this->parse("---\nslug: s\ntitle: T\nimport: true\n---\n\n    code\n");

        $this->assertSame("    code\n", $result['body']);
    }

    #[TestDox('日期會轉成 YYYY-MM-DD')]
    public function test_creation_date_is_parsed(): void
    {
        $this->assertSame('2026-09-22', $this->parse("---\nslug: s\nimport: true\ncreation_date: 2026-09-22\n---\n# T\n")['creation_date']);
        $this->assertSame('2026-09-22', $this->parse("---\nslug: s\nimport: true\ncreation_date: '2026-09-22'\n---\n# T\n")['creation_date']);
        $this->assertNull($this->parse("---\nslug: s\nimport: true\n---\n# T\n")['creation_date']);
    }

    /** @return iterable<string, array{string}> */
    public static function notOptedIn(): iterable
    {
        yield '沒有 front matter' => ["# 標題\n內文\n"];
        yield 'front matter 沒有結束行' => ["---\nslug: s\nimport: true\n# 標題\n"];
        yield 'import: false' => ["---\nslug: s\nimport: false\n---\n# 標題\n"];
        yield '沒有 import' => ["---\nslug: s\n---\n# 標題\n"];
        yield 'import 是字串 "true"' => ["---\nslug: s\nimport: \"true\"\n---\n# 標題\n"];
        yield 'import 是 1' => ["---\nslug: s\nimport: 1\n---\n# 標題\n"];
        yield 'import 是 yes' => ["---\nslug: s\nimport: yes\n---\n# 標題\n"];
        yield 'front matter 是清單' => ["---\n- import\n- true\n---\n# 標題\n"];
        yield 'front matter 是空的' => ["---\n---\n# 標題\n"];
        yield 'front matter 前面有空行' => ["\n---\nslug: s\nimport: true\n---\n# 標題\n"];
        yield '空檔案' => [''];
    }

    #[DataProvider('notOptedIn')]
    #[TestDox('沒有明確 import: true 的檔案不算選擇匯入')]
    public function test_files_without_explicit_opt_in_are_not_imported(string $raw): void
    {
        $result = $this->parse($raw);

        $this->assertFalse($result['import']);
        $this->assertNull($result['invalid']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidFiles(): iterable
    {
        yield 'YAML 錯誤' => ["---\nslug: [x\nimport: true\n---\n# T\n", 'yaml_error'];
        // 沒開 PARSE_OBJECT，物件標籤解析成 null，不會建立物件
        yield 'slug 是 php 物件標籤' => ["---\nslug: !php/object 'O:8:\"stdClass\":0:{}'\nimport: true\n---\n# T\n", 'slug_missing'];
        yield '沒有 slug' => ["---\nimport: true\n---\n# T\n", 'slug_missing'];
        yield 'slug 是空字串' => ["---\nslug: ''\nimport: true\n---\n# T\n", 'slug_missing'];
        yield 'slug 有大寫' => ["---\nslug: My-Post\nimport: true\n---\n# T\n", 'slug_invalid'];
        yield 'slug 有斜線' => ["---\nslug: a/b\nimport: true\n---\n# T\n", 'slug_invalid'];
        yield 'slug 有空白' => ["---\nslug: 'a b'\nimport: true\n---\n# T\n", 'slug_invalid'];
        yield 'slug 結尾是連字號' => ["---\nslug: a-\nimport: true\n---\n# T\n", 'slug_invalid'];
        yield 'slug 是數字' => ["---\nslug: 2024\nimport: true\n---\n# T\n", 'slug_invalid'];
        yield 'slug 是清單' => ["---\nslug: [a]\nimport: true\n---\n# T\n", 'slug_invalid'];
        yield 'slug 超過 255 字元' => ["---\nslug: ".str_repeat('a', 256)."\nimport: true\n---\n# T\n", 'slug_invalid'];
        yield '沒有標題' => ["---\nslug: s\nimport: true\n---\n內文\n", 'title_missing'];
        yield 'title 是數字' => ["---\nslug: s\ntitle: 42\nimport: true\n---\n內文\n", 'title_invalid'];
        yield 'title 超過 255 字' => ["---\nslug: s\ntitle: ".str_repeat('字', 256)."\nimport: true\n---\n內文\n", 'title_invalid'];
        yield '日期格式不對' => ["---\nslug: s\nimport: true\ncreation_date: 22/09/2026\n---\n# T\n", 'creation_date_invalid'];
        yield '不存在的日期' => ["---\nslug: s\nimport: true\ncreation_date: '2026-02-30'\n---\n# T\n", 'creation_date_invalid'];
        yield 'about_techniques 是單一字串' => ["---\nslug: s\nimport: true\nabout_techniques: vue\n---\n# T\n", 'about_techniques_invalid'];
        yield 'about_techniques 是數字' => ["---\nslug: s\nimport: true\nabout_techniques: 3\n---\n# T\n", 'about_techniques_invalid'];
        yield 'about_techniques 是 map' => ["---\nslug: s\nimport: true\nabout_techniques: {a: b}\n---\n# T\n", 'about_techniques_invalid'];
        yield 'about_techniques 有數字項目' => ["---\nslug: s\nimport: true\nabout_techniques: [vue, 8]\n---\n# T\n", 'about_techniques_invalid'];
        yield 'about_techniques 有布林項目' => ["---\nslug: s\nimport: true\nabout_techniques: [true]\n---\n# T\n", 'about_techniques_invalid'];
        yield 'about_techniques 有 null 項目' => ["---\nslug: s\nimport: true\nabout_techniques: [vue, ~]\n---\n# T\n", 'about_techniques_invalid'];
        yield 'about_techniques 有巢狀清單' => ["---\nslug: s\nimport: true\nabout_techniques: [[vue]]\n---\n# T\n", 'about_techniques_invalid'];
        yield 'about_techniques 有空字串項目' => ["---\nslug: s\nimport: true\nabout_techniques: [vue, '']\n---\n# T\n", 'about_techniques_invalid'];
        yield 'about_techniques 有只含空白的項目' => ["---\nslug: s\nimport: true\nabout_techniques: ['  ']\n---\n# T\n", 'about_techniques_invalid'];
        yield 'about_techniques 的名稱超過 100 字' => ["---\nslug: s\nimport: true\nabout_techniques: [".str_repeat('a', 101)."]\n---\n# T\n", 'about_techniques_invalid'];
        yield 'about_techniques 超過 50 筆' => ["---\nslug: s\nimport: true\nabout_techniques: [".implode(',', array_map(fn (int $i) => "t{$i}", range(1, 51)))."]\n---\n# T\n", 'about_techniques_invalid'];
        yield 'about_projects 是單一字串' => ["---\nslug: s\nimport: true\nabout_projects: my-app\n---\n# T\n", 'about_projects_invalid'];
        yield 'about_projects 有數字項目' => ["---\nslug: s\nimport: true\nabout_projects: [8]\n---\n# T\n", 'about_projects_invalid'];
        yield 'about_projects 有空字串項目' => ["---\nslug: s\nimport: true\nabout_projects: ['']\n---\n# T\n", 'about_projects_invalid'];
        yield 'about_projects 的名稱超過 100 字' => ["---\nslug: s\nimport: true\nabout_projects: [".str_repeat('a', 101)."]\n---\n# T\n", 'about_projects_invalid'];
        yield '含 NUL' => ["---\nslug: s\nimport: true\n---\n# T\n\0\n", 'binary'];
        yield '不是 UTF-8' => ["---\nslug: s\nimport: true\n---\n# T\n\xC3\x28\n", 'not_utf8'];
    }

    #[DataProvider('invalidFiles')]
    #[TestDox('選擇匯入但格式不對的檔案標成 invalid 並附原因')]
    public function test_opted_in_files_with_problems_are_invalid(string $raw, string $reason): void
    {
        $result = $this->parse($raw);

        $this->assertTrue($result['import']);
        $this->assertSame($reason, $result['invalid']);
        $this->assertArrayHasKey($reason, ArticleSourceParser::INVALID_REASONS);
    }

    #[TestDox('剛好 255 字的標題與 slug 可以匯入')]
    public function test_title_and_slug_at_the_length_limit_are_accepted(): void
    {
        $result = $this->parse("---\nslug: ".str_repeat('a', 255)."\ntitle: ".str_repeat('字', 255)."\nimport: true\n---\n內文\n");

        $this->assertNull($result['invalid']);
    }

    #[TestDox('about_techniques 與 about_projects 讀成名稱清單')]
    public function test_about_lists_are_parsed_into_names(): void
    {
        $result = $this->parse("---\nslug: s\nimport: true\nabout_techniques: [vue, Laravel]\nabout_projects:\n  - my-dev-grid\n  - \"Side Project\"\n---\n# T\n");

        $this->assertNull($result['invalid']);
        $this->assertSame(['techniques' => ['vue', 'Laravel'], 'projects' => ['my-dev-grid', 'Side Project']], $result['about']);
    }

    /** @return iterable<string, array{string}> */
    public static function emptyAbout(): iterable
    {
        yield '兩個欄位都沒寫' => ["---\nslug: s\nimport: true\n---\n# T\n"];
        yield '空清單' => ["---\nslug: s\nimport: true\nabout_techniques: []\nabout_projects: []\n---\n# T\n"];
        yield '欄位留空（null）' => ["---\nslug: s\nimport: true\nabout_techniques:\nabout_projects: ~\n---\n# T\n"];
    }

    #[DataProvider('emptyAbout')]
    #[TestDox('沒寫、空清單、null 都是沒有名稱，不算格式錯誤')]
    public function test_missing_or_empty_about_is_no_names(string $raw): void
    {
        $result = $this->parse($raw);

        $this->assertNull($result['invalid']);
        $this->assertSame(['techniques' => [], 'projects' => []], $result['about']);
    }

    #[TestDox('名稱去掉頭尾空白，不分大小寫去重，保留第一次出現的寫法與順序')]
    public function test_about_names_are_trimmed_and_deduplicated_ignoring_case(): void
    {
        $result = $this->parse("---\nslug: s\nimport: true\nabout_techniques: ['  Vue ', laravel, vue, LARAVEL, 'Vue 3']\n---\n# T\n");

        $this->assertSame(['Vue', 'laravel', 'Vue 3'], $result['about']['techniques']);
    }

    #[TestDox('剛好 50 筆、名稱剛好 100 字可以匯入')]
    public function test_about_lists_at_the_limits_are_accepted(): void
    {
        $names = array_map(fn (int $i) => "t{$i}", range(1, 50));
        $long = str_repeat('字', 100);
        $result = $this->parse("---\nslug: s\nimport: true\nabout_techniques: [".implode(',', $names)."]\nabout_projects: [{$long}]\n---\n# T\n");

        $this->assertNull($result['invalid']);
        $this->assertCount(50, $result['about']['techniques']);
        $this->assertSame([$long], $result['about']['projects']);
    }

    #[TestDox('about 欄位格式不對時，沒選擇匯入的檔案仍然不出現在清單，也不丟例外')]
    public function test_malformed_about_does_not_matter_for_files_that_did_not_opt_in(): void
    {
        $result = $this->parse("---\nslug: s\nimport: false\nabout_techniques: 3\n---\n# T\n");

        $this->assertFalse($result['import']);
        $this->assertNull($result['invalid']);
        $this->assertSame(['techniques' => [], 'projects' => []], $result['about']);
    }
}
