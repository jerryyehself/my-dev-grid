<?php

namespace Tests\Unit;

use App\Services\ArticleUnitSplitter;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * 文章切段：單位的界線、父子關係、角色、明確 ID，以及顯示用的 `{#id}` 剝除。
 */
class ArticleUnitSplitterTest extends TestCase
{
    private function split(string $body): array
    {
        return (new ArticleUnitSplitter)->split($body);
    }

    /** @return list<string|null> */
    private function ids(array $result): array
    {
        return array_column($result['units'], 'unit_id');
    }

    #[TestDox('導言是第一個標題前的內容，固定 lead 與 intro')]
    public function test_lead_is_content_before_first_heading(): void
    {
        $result = $this->split("前言一\n前言二\n\n## 第一節\n內容\n");

        $lead = $result['units'][0];
        $this->assertSame('lead', $lead['unit_id']);
        $this->assertSame('intro', $lead['role']);
        $this->assertNull($lead['parent_id']);
        $this->assertNull($lead['heading']);
        $this->assertSame("前言一\n前言二\n", $lead['content']);
        $this->assertSame([1, 3], [$lead['start_line'], $lead['end_line']]);
    }

    #[TestDox('H2、H3 各自成為單位，H3 指向所屬 H2，到同級或更高一級標題為止')]
    public function test_h2_h3_boundaries_and_parent(): void
    {
        $result = $this->split("## A {#a}\n甲\n### A1 {#a1}\n甲一\n### A2 {#a2}\n甲二\n## B {#b}\n乙\n### B1 {#b1}\n乙一\n");

        $this->assertSame(['lead', 'a', 'a1', 'a2', 'b', 'b1'], $this->ids($result));
        $this->assertSame([null, null, 'a', 'a', null, 'b'], array_column($result['units'], 'parent_id'));
        $this->assertSame([null, null, 1, 1, null, 4], array_column($result['units'], 'parent_index'));
        $this->assertSame([null, 2, 3, 3, 2, 3], array_column($result['units'], 'level'));
        $this->assertSame(['甲', '甲一', '甲二', '乙', '乙一'], array_map(fn ($u) => trim($u['content']), array_slice($result['units'], 1)));
    }

    #[TestDox('H2 還沒有 ID 時，H3 靠 parent_index 找到父單位')]
    public function test_parent_index_when_parent_has_no_id(): void
    {
        $result = $this->split("## A\n### A1\n");

        $this->assertSame([null, null], [$result['units'][2]['parent_id'], $result['units'][1]['unit_id']]);
        $this->assertSame(1, $result['units'][2]['parent_index']);
    }

    #[TestDox('H2 之前的 H3 沒有父單位')]
    public function test_h3_before_any_h2_has_no_parent(): void
    {
        $result = $this->split("### 孤兒 {#orphan}\n內容\n");

        $this->assertNull($result['units'][1]['parent_id']);
        $this->assertNull($result['units'][1]['parent_index']);
        $this->assertSame('body', $result['units'][1]['role']);
    }

    #[TestDox('來源與參考文獻節的角色是 references，底下的 H3 沿用，其他是 body')]
    public function test_references_role(): void
    {
        $result = $this->split("## 說明\n## 參考文獻 {#refs}\n- a\n### 補充\n## 來源\n- b\n## 來源說明\n");

        $this->assertSame(['intro', 'body', 'references', 'references', 'references', 'body'], array_column($result['units'], 'role'));
    }

    #[TestDox('標題行尾的 {#id} 成為 unit_id 並從 heading 剝掉，沒寫的是 null')]
    public function test_explicit_id_is_parsed_and_stripped(): void
    {
        $result = $this->split("## 有 ID {#intro-2}\n內文\n## 沒有 ID\n內文 {#not-a-heading}\n## 收尾井號 {#c3} ##\n");

        $this->assertSame(['lead', 'intro-2', null, 'c3'], $this->ids($result));
        $this->assertSame([null, '有 ID', '沒有 ID', '收尾井號'], array_column($result['units'], 'heading'));
        // 內文裡的 {#...} 不是標題的 ID
        $this->assertStringContainsString('{#not-a-heading}', $result['units'][2]['content']);
    }

    #[TestDox('格式不合的 {#...} 不當 ID，留在標題文字裡')]
    public function test_malformed_id_is_left_in_heading(): void
    {
        $result = $this->split("## 空的 {#}\n## 中間 {#x} 後面\n## 有空白 {#a b}\n");

        $this->assertSame(['lead', null, null, null], $this->ids($result));
        $this->assertSame('空的 {#}', $result['units'][1]['heading']);
    }

    #[TestDox('程式碼區塊裡的 # 不當標題，波浪線與較長的圍欄也一樣')]
    public function test_headings_inside_code_fences_are_ignored(): void
    {
        $body = "## 真的 {#real}\n```md\n## 假的 {#fake}\n### 也是假的\n```\n~~~\n## 波浪線裡 {#fake2}\n~~~\n````\n```\n## 內層 {#fake3}\n````\n## 後面 {#after}\n";
        $result = $this->split($body);

        $this->assertSame(['lead', 'real', 'after'], $this->ids($result));
        $this->assertStringContainsString('## 假的 {#fake}', $result['units'][1]['content']);
    }

    #[TestDox('關閉圍欄後面接垂直定位字元（\\v）不算關閉，後面的 {#...} 仍在程式碼區塊內')]
    public function test_fence_close_followed_by_vertical_tab_is_not_a_close(): void
    {
        $body = "```\n```\v\n## x {#a}\n```\n## y {#b}\n";

        $this->assertSame(['lead', 'b'], $this->ids($this->split($body)));
        $this->assertSame("```\n```\v\n## x {#a}\n```\n## y\n", ArticleUnitSplitter::stripHeadingIds($body));
    }

    #[TestDox('H4 以下、縮排四格的井號行、井號後沒空白的行都不切')]
    public function test_non_unit_headings_do_not_split(): void
    {
        $result = $this->split("## A {#a}\n#### 小標\n    ## 縮排\n##沒空白\n# 內文 H1\n");

        $this->assertSame(['lead', 'a'], $this->ids($result));
        $this->assertStringContainsString('#### 小標', $result['units'][1]['content']);
    }

    #[TestDox('沒有標題的文章只有導言，內容是整篇')]
    public function test_article_without_headings(): void
    {
        $result = $this->split("只有一段\n兩行\n");

        $this->assertSame(['lead'], $this->ids($result));
        $this->assertSame("只有一段\n兩行\n", $result['units'][0]['content']);
        $this->assertSame([], $result['duplicate_ids']);
    }

    #[TestDox('空內文與只有標題的文章：導言範圍是空的')]
    public function test_empty_body_and_headings_only(): void
    {
        $empty = $this->split('');
        $this->assertSame(['lead'], $this->ids($empty));
        $this->assertSame('', $empty['units'][0]['content']);

        $headingOnly = $this->split('## 只有標題 {#t}');
        $this->assertSame(['lead', 't'], $this->ids($headingOnly));
        $this->assertSame([1, 0], [$headingOnly['units'][0]['start_line'], $headingOnly['units'][0]['end_line']]);
        $this->assertSame('', $headingOnly['units'][1]['content']);
    }

    #[TestDox('CRLF 換行跟 LF 切出一樣的結果')]
    public function test_crlf_is_normalized(): void
    {
        $this->assertSame(
            $this->split("導言\n## A {#a}\n內容\n"),
            $this->split("導言\r\n## A {#a}\r\n內容\r\n"),
        );
    }

    #[TestDox('ID 重複時照常切出所有單位，重複的 ID 列在 duplicate_ids')]
    public function test_duplicate_ids_are_reported_not_thrown(): void
    {
        $result = $this->split("## A {#x}\n## B {#x}\n### C {#lead}\n## D {#y}\n");

        $this->assertSame(['lead', 'x', 'x', 'lead', 'y'], $this->ids($result));
        $this->assertSame(['lead', 'x'], $result['duplicate_ids']);
    }

    #[TestDox('沒有重複時 duplicate_ids 是空的，多個 null 不算重複')]
    public function test_nulls_are_not_duplicates(): void
    {
        $this->assertSame([], $this->split("## A\n## B\n## C {#c}\n")['duplicate_ids']);
    }

    #[TestDox('顯示用剝除：只拿掉標題行尾的 {#id}，內文與程式碼區塊不動')]
    public function test_strip_heading_ids(): void
    {
        $body = "# 大標 {#t}\n內文 {#keep}\n## 小節 {#a}\n### 再小 {#b} ##\n```\n## 區塊內 {#c}\n```\n## 沒有 ID\n";

        $this->assertSame(
            "# 大標\n內文 {#keep}\n## 小節\n### 再小 ##\n```\n## 區塊內 {#c}\n```\n## 沒有 ID\n",
            ArticleUnitSplitter::stripHeadingIds($body),
        );
    }

    #[TestDox('剝除保留 CRLF 換行')]
    public function test_strip_keeps_crlf(): void
    {
        $this->assertSame("## A\r\n內容\r\n", ArticleUnitSplitter::stripHeadingIds("## A {#a}\r\n內容\r\n"));
    }
}
