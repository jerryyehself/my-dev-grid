<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * 替一份文章原稿的 H2、H3 標題補上行尾的 `{#id}`（一次性補寫，之後 ID 凍結，作法同 Docusaurus 的 `write-heading-ids`）。
 *
 * - 標題怎麼認（程式碼區塊裡的、縮排四格以上的、井號後沒有空白的都不算）與 `{#id}` 怎麼讀，完全沿用
 *   `ArticleUnitSplitter::headings()`，不另外寫一套；所以補完之後 `ArticleUnitSplitter` 切出的單位只多了 ID。
 * - 只補 H2、H3 裡還沒有 `{#id}` 的，已經有的不動；H1、H4 以下不補。
 * - 新 ID 是 `s1`、`s2`…，照文件順序，短 ASCII、不從標題文字推：ID 是身分，不是名稱，標題改寫了也不能跟著變。
 *   跳過這篇已經用掉的 ID（任何層級標題上的 `{#id}` 都算，不分大小寫）、呼叫端傳進來的保留 ID
 *   （這份原稿以前的版本用過的，見 `write()`）與導言固定的 `lead`。
 * - 只在標題文字結束處插入 ` {#id}`，其餘位元組（換行、BOM、結尾有沒有換行、標題尾端的空白與收尾井號）一個都不動，
 *   所以補完再補一次是 0 變更。
 * - 內文從 front matter 之後算起，界線的判斷與 `ArticleSourceParser` 一致（第一行是 `---`、之後第一個 `---` 或 `...`）。
 *   沒有 front matter 的檔案匯入時不認，這裡也不動，回傳時標記略過。
 *
 * 補不了的情況（丟 `InvalidArgumentException`，不回傳半成品）：不是 UTF-8、含 NUL、既有的 `{#id}` 有重複。
 * 重複的 ID 要由作者決定留哪一個，這裡不替他改。
 */
final class ArticleHeadingIdWriter
{
    /** 新 ID 的前綴：`s` 加流水號。 */
    public const ID_PREFIX = 's';

    public function __construct(private readonly ArticleUnitSplitter $splitter = new ArticleUnitSplitter) {}

    /**
     * `added` 的 `line` 是補在原檔的第幾行（1 起算，含 front matter）；`skipped` 是略過的原因，沒略過是 null。
     *
     * `$reserved` 是新 ID 也要跳過的 ID（不分大小寫），呼叫端傳這份原稿以前的版本用過的（`headingIds()`）。
     * 要緊的是已經刪掉的：段落刪掉之後，資料庫裡那一列標成移除、ID 留著，引用它的地方顯示「段落已移除」；
     * 同一個 ID 再發給新標題的話，下次匯入會用 ID 對上那一列，舊的引用就指到不相干的新段落。
     *
     * @param  list<string>  $reserved
     * @return array{content: string, added: list<array{line: int, level: int, heading: string, id: string}>, skipped: string|null}
     *
     * @throws InvalidArgumentException
     */
    public function write(string $raw, array $reserved = []): array
    {
        $lines = explode("\n", $raw);
        $bodyStart = $this->bodyStart($lines);
        if ($bodyStart === null) {
            return ['content' => $raw, 'added' => [], 'skipped' => '沒有 front matter，匯入時不認這個檔案'];
        }

        if (! mb_check_encoding($raw, 'UTF-8')) {
            throw new InvalidArgumentException('檔案不是 UTF-8 編碼，不補寫');
        }
        if (str_contains($raw, "\0")) {
            throw new InvalidArgumentException('檔案含有 NUL 字元，看起來不是文字檔，不補寫');
        }

        $bodyLines = array_slice($lines, $bodyStart);
        $duplicates = $this->splitter->split(implode("\n", $bodyLines))['duplicate_ids'];
        if ($duplicates !== []) {
            throw new InvalidArgumentException('標題的 {#id} 有重複（'.implode('、', $duplicates).'），請先改掉再補寫');
        }

        $headings = ArticleUnitSplitter::headings($bodyLines);
        $taken = [ArticleUnitSplitter::LEAD_ID => true];
        foreach ($reserved as $id) {
            $taken[strtolower($id)] = true;
        }
        foreach ($headings as $heading) {
            if ($heading['id'] !== null) {
                $taken[strtolower($heading['id'])] = true;
            }
        }

        $added = [];
        $next = 1;
        foreach ($headings as $number => $heading) {
            if ($heading['id'] !== null || ! in_array($heading['level'], [2, 3], true)) {
                continue;
            }

            while (isset($taken[self::ID_PREFIX.$next])) {
                $next++;
            }
            $id = self::ID_PREFIX.$next;
            $taken[$id] = true;

            $index = $bodyStart + $number - 1;
            $lines[$index] = substr($lines[$index], 0, $heading['end']).' {#'.$id.'}'.substr($lines[$index], $heading['end']);
            $added[] = ['line' => $index + 1, 'level' => $heading['level'], 'heading' => $heading['text'], 'id' => $id];
        }

        return ['content' => implode("\n", $lines), 'added' => $added, 'skipped' => null];
    }

    /**
     * 一份原稿標題上寫的所有 `{#id}`（任何層級都算），照文件順序；標題怎麼認跟 `write()` 相同。
     * 給呼叫端從原稿的舊版本收集保留 ID 用。沒有 front matter 時整份當內文：舊版本可能還沒加 front matter，
     * 上面的 ID 一樣可能已經發出去過，多算只是跳號。
     *
     * @return list<string>
     */
    public function headingIds(string $raw): array
    {
        $lines = explode("\n", $raw);
        $ids = [];
        foreach (ArticleUnitSplitter::headings(array_slice($lines, $this->bodyStart($lines) ?? 0)) as $heading) {
            if ($heading['id'] !== null) {
                $ids[] = $heading['id'];
            }
        }

        return $ids;
    }

    /**
     * 內文第一行在 `$lines` 裡的位置（0 起算）；沒有 front matter 是 null。
     * 判斷與 `ArticleSourceParser::splitFrontMatter()` 相同：開頭的 BOM 先去掉，行尾空白（含 `\r`）不算。
     *
     * @param  list<string>  $lines
     */
    private function bodyStart(array $lines): ?int
    {
        $first = str_starts_with($lines[0], "\u{FEFF}") ? substr($lines[0], 3) : $lines[0];
        if (rtrim($first) !== '---') {
            return null;
        }

        for ($i = 1, $count = count($lines); $i < $count; $i++) {
            $line = rtrim($lines[$i]);
            if ($line === '---' || $line === '...') {
                return $i + 1;
            }
        }

        return null;
    }
}
