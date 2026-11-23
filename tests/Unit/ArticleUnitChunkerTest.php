<?php

namespace Tests\Unit;

use App\Services\ArticleUnitChunker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * 太長單位的取用子片段：段落邊界優先、再來句子邊界，表格、程式碼、清單、引言不切斷。
 * 用很小的上限（10 字）讓短文字就能觸發。
 */
class ArticleUnitChunkerTest extends TestCase
{
    private const LIMIT = 10;

    /** @return list<string> */
    private function chunk(string $content): array
    {
        return ArticleUnitChunker::chunk($content, self::LIMIT);
    }

    #[TestDox('長度是去掉空白後的字元數，中文一個字算一個')]
    public function test_length_ignores_whitespace(): void
    {
        $this->assertSame(4, ArticleUnitChunker::length(" 中 文\n\tab "));
    }

    #[TestDox('沒超過上限時原樣一片')]
    public function test_short_content_is_one_chunk(): void
    {
        $this->assertSame(["一二三四五\n\n六七八九十"], $this->chunk("一二三四五\n\n六七八九十"));
    }

    #[TestDox('在段落邊界切，裝得下的段落併進同一片')]
    public function test_split_at_paragraph_boundaries(): void
    {
        $this->assertSame(["甲甲甲\n\n乙乙乙", '丙丙丙丙丙', '丁丁丁丁丁丁'], $this->chunk("甲甲甲\n\n乙乙乙\n\n\n丙丙丙丙丙\n\n丁丁丁丁丁丁"));
    }

    #[TestDox('太長的段落在句子邊界切：全形句末標點（可接收尾引號），半形標點要接空白')]
    public function test_long_paragraph_splits_at_sentences(): void
    {
        $this->assertSame(['甲甲甲甲。乙乙乙乙！', '丙丙「丙丙。」', '丁丁丁丁？'], $this->chunk('甲甲甲甲。乙乙乙乙！丙丙「丙丙。」丁丁丁丁？'));
        $this->assertSame(['Aaaa. Bbbb.', ' Cccc dd.'], $this->chunk('Aaaa. Bbbb. Cccc dd.'));
    }

    #[TestDox('網址裡的 ? 與 . 不是句子邊界；一句話自己超過上限就整句一片')]
    public function test_no_split_inside_url_or_long_sentence(): void
    {
        $this->assertSame(['看https://a.b/c.php?x=1&y=2這裡'], $this->chunk('看https://a.b/c.php?x=1&y=2這裡'));
        $this->assertSame(['一二三四五六七八九十十一。', '短句。'], $this->chunk('一二三四五六七八九十十一。短句。'));
    }

    /** @return iterable<string, array{string}> */
    public static function atomicBlocks(): iterable
    {
        yield '程式碼區塊（中間有空行）' => ["```\n一二三四五\n\n六七八九十。十一\n```"];
        yield '表格' => ["| 一二三 | 四五六 |\n|---|---|\n| 七八九。 | 十十一 |"];
        yield '沒有外框的表格' => ["一二三 | 四五六\n--- | ---\n七八九。 | 十十一"];
        yield '清單（項目之間有空行）' => ["- 一二三四。\n\n- 五六七八。\n\n  九十十一。"];
        yield '有序清單' => ["1. 一二三四。\n2. 五六七八。九十十一。"];
        yield '引言' => ['> 一二三四五。六七八九十。十一。'];
        yield '縮排程式碼' => ['    一二三四五。六七八九十。十一。'];
    }

    #[DataProvider('atomicBlocks')]
    #[TestDox('不能切斷的區塊超過上限也整塊放一片')]
    public function test_atomic_blocks_are_not_split(string $block): void
    {
        $this->assertSame(['前面', $block, '後面'], $this->chunk("前面\n\n{$block}\n\n後面"));
    }

    /** @return iterable<string, array{string}> */
    public static function blocksAfterParagraphWithoutBlankLine(): iterable
    {
        yield '段落後面直接接清單：同一個清單項目不能切成兩半' => ["以下幾點：\n- 第一項很長的內容。第一項的第二句話。\n- 第二項的內容。"];
        yield '段落後面直接接引言：引言不能被切開' => ["引言前的說明：\n> 引言第一句。引言第二句。引言第三句。"];
        yield '段落後面直接接清單、項目之間有空行：清單不能在項目之間分開' => ["說明：\n- 甲項。\n\n- 乙項內容很長很長很長。\n\n- 丙項內容很長很長很長。"];
    }

    #[DataProvider('blocksAfterParagraphWithoutBlankLine')]
    #[TestDox('段落後面不空行直接接清單或引言：整塊放一片，不切斷清單或引言')]
    public function test_list_or_quote_right_after_a_paragraph_is_not_split(string $content): void
    {
        $this->assertGreaterThan(12, ArticleUnitChunker::length($content));
        $this->assertSame([$content], ArticleUnitChunker::chunk($content, 12));
        // 前後有別的段落時，這一塊仍然完整地落在同一片
        $this->assertContains($content, ArticleUnitChunker::chunk("前面的段落。\n\n{$content}\n\n後面的段落。", 12));
    }

    #[TestDox('切出來的片段接起來（段落之間一個空行）等於原內容')]
    public function test_chunks_rejoin_to_the_content(): void
    {
        $content = "甲甲甲。乙乙乙乙乙。丙丙丙丙。\n\n```\nx\n\ny\n```\n\n丁丁丁丁丁丁丁丁丁丁丁";
        $chunks = $this->chunk($content);

        $this->assertGreaterThan(2, count($chunks));
        $this->assertSame(
            str_replace("\n\n", '', $content),
            str_replace("\n\n", '', implode('', $chunks)),
        );
    }
}
