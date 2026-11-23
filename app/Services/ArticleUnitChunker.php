<?php

namespace App\Services;

/**
 * 把太長的單位內容切成取用用的子片段（純函式，給 `ArticleUnitSplitter` 用）。
 *
 * - 先在段落邊界（空行）切成區塊，再依序把區塊裝進片段，裝得下（去掉空白後不超過上限）就併進同一片，
 *   裝不下就開新的一片。區塊之間在片段裡用一個空行接起來。
 * - 不能切斷的區塊：程式碼區塊（圍欄裡的空行不算段落邊界）、表格、清單（項目之間有空行、
 *   或下一段縮排接著上一項，都算同一個清單）、引言區塊、HTML。這些區塊自己超過上限也整塊放一片。
 * - 只有一般段落自己超過上限時，才在句子邊界切：全形的「。」「！」「？」（後面可以接收尾的引號或括號），
 *   或半形的 `.`、`!`、`?` 後面接空白。一句話自己超過上限就整句放一片。
 * - 依序裝，不回頭平均分配：最後一片可能很短。
 */
final class ArticleUnitChunker
{
    /** 句子邊界：全形句末標點（後面沒有再接句末標點或收尾符號），或收尾符號之後；半形句末標點要接空白。 */
    private const SENTENCE_BOUNDARY = '/(?<=[。！？])(?![。！？」』）”’])|(?<=[。！？][」』）”’])|(?<=[.!?])(?=\s)|(?<=[.!?]["\')\]])(?=\s)/u';

    /** 區塊開頭是這些就不是一般段落：清單、引言、表格、標題、HTML。 */
    private const NON_PARAGRAPH_START = '/^(?: {0,3}(?:[-+*][ \t]|\d{1,9}[.)](?:[ \t]|$)|>|\||#{1,6}(?:[ \t]|$)|<[A-Za-z\/!?])| {4}|\t)/';

    /** 表格的分隔列（`|---|:--:|`），沒有開頭的 `|` 也算。 */
    private const TABLE_DELIMITER = '/^ {0,3}\|?[ \t]*:?-+:?[ \t]*(?:\|[ \t]*:?-+:?[ \t]*)*\|?[ \t]*$/';

    /** 清單項目的開頭；下一個區塊是清單項目或縮排行時，跟上一個清單併成同一塊。 */
    private const LIST_ITEM = '/^ {0,3}(?:[-+*]|\d{1,9}[.)])(?:[ \t]|$)/';

    /**
     * @param  int  $limit  每片的軟上限（去掉空白後的字元數）
     * @return list<string> 切出來的片段；不用切（不超過上限）或切不開時只有一片
     */
    public static function chunk(string $content, int $limit): array
    {
        if (self::length($content) <= $limit) {
            return [$content];
        }

        $chunks = [];
        $current = null;
        foreach (self::blocks($content) as $block) {
            $pieces = self::length($block) > $limit && self::isParagraph($block) ? self::sentencePieces($block, $limit) : [$block];
            foreach ($pieces as $i => $piece) {
                // 同一段落切出來的句子片段直接相接，不同區塊之間補一個空行
                $joined = $current === null ? $piece : $current.($i === 0 ? "\n\n" : '').$piece;
                if ($current !== null && self::length($joined) > $limit) {
                    $chunks[] = $current;
                    $joined = $piece;
                }
                $current = $joined;
            }
        }
        if ($current !== null) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /** 去掉所有空白後的字元數（中文一個字算一個）。 */
    public static function length(string $text): int
    {
        return mb_strlen((string) preg_replace('/\s+/u', '', $text));
    }

    /**
     * 在空行切成區塊；程式碼區塊裡的空行不切，清單與它縮排的後續段落併成一塊。
     *
     * @return list<string>
     */
    private static function blocks(string $content): array
    {
        $lines = explode("\n", $content);
        $codeLines = ArticleUnitSplitter::codeLines($lines);

        $blocks = [];
        $current = [];
        foreach ($lines as $index => $line) {
            if (! isset($codeLines[$index + 1]) && preg_match('/^[ \t]*$/', $line) === 1) {
                if ($current !== []) {
                    $blocks[] = $current;
                    $current = [];
                }

                continue;
            }
            $current[] = $line;
        }
        if ($current !== []) {
            $blocks[] = $current;
        }

        $merged = [];
        foreach ($blocks as $block) {
            $previous = $merged === [] ? null : $merged[count($merged) - 1];
            $continuesList = $previous !== null
                && preg_match(self::LIST_ITEM, $previous[0]) === 1
                && (preg_match(self::LIST_ITEM, $block[0]) === 1 || preg_match('/^(?: {2,}|\t)/', $block[0]) === 1);
            if ($continuesList) {
                $merged[count($merged) - 1] = [...$previous, '', ...$block];
            } else {
                $merged[] = $block;
            }
        }

        return array_map(fn (array $block) => implode("\n", $block), $merged);
    }

    /** 一般段落：不含程式碼區塊、表格，開頭不是清單、引言、標題、HTML 或縮排程式碼。 */
    private static function isParagraph(string $block): bool
    {
        $lines = explode("\n", $block);
        if (ArticleUnitSplitter::codeLines($lines) !== [] || preg_match(self::NON_PARAGRAPH_START, $lines[0]) === 1) {
            return false;
        }
        foreach ($lines as $line) {
            if (str_contains($line, '|') && preg_match(self::TABLE_DELIMITER, $line) === 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * 把一個段落在句子邊界切開，依序裝成不超過上限的幾段；接起來等於原段落。
     *
     * @return list<string>
     */
    private static function sentencePieces(string $paragraph, int $limit): array
    {
        $sentences = preg_split(self::SENTENCE_BOUNDARY, $paragraph, -1, PREG_SPLIT_NO_EMPTY);
        if ($sentences === false || count($sentences) < 2) {
            return [$paragraph];
        }

        $pieces = [];
        $current = '';
        foreach ($sentences as $sentence) {
            if ($current !== '' && self::length($current.$sentence) > $limit) {
                $pieces[] = $current;
                $current = '';
            }
            $current .= $sentence;
        }
        $pieces[] = $current;

        return $pieces;
    }
}
