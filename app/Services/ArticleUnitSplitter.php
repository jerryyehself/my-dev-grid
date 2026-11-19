<?php

namespace App\Services;

/**
 * 把一篇文章的 Markdown 內文切成段落單位（純函式，不碰資料庫）。
 *
 * - 單位是每個 H2、H3 標題底下的內容，到下一個同級或更高一級的標題為止。H3 的 `parent_id`
 *   指向它所屬 H2 的 `unit_id`；H2、導言的 `parent_id` 是 null。H2 之前出現的 H3 沒有所屬 H2，
 *   `parent_id` 也是 null。H4 以下與非開頭的 H1 不切，算在所屬單位的內容裡。
 * - 第一個 H2／H3 之前的內容是導言，固定 `unit_id` 為 `lead`、`role` 為 `intro`；
 *   導言一定會出現在清單的第一筆，沒有內容時範圍是空的。
 * - 標題（去掉 `{#id}` 之後）是「來源」或「參考文獻」的 H2，`role` 為 `references`，
 *   底下的 H3 沿用；其餘是 `body`。
 * - 標題行尾的 `{#id}` 是明確 ID：解析成 `unit_id` 並從 `heading` 剝掉。沒寫的 `unit_id` 是 null，
 *   由之後的補寫腳本補上。
 * - 程式碼區塊（``` 或 ~~~ 圍起來）裡的 `#` 不是標題；縮排四個空白以上的行也不是標題。
 *
 * `parent_index` 是父單位在回傳清單裡的位置（0 起算）：H2 的 `unit_id` 可能還是 null（尚未補寫），
 * 這時 `parent_id` 也是 null，靠 `parent_index` 才找得到父單位。
 *
 * ID 重複時不丟例外：照常切出所有單位，重複的 ID 列在回傳的 `duplicate_ids`，由呼叫端決定要不要擋。
 * 導言的 `lead` 也算在內，所以標題寫了 `{#lead}` 也會被列為重複。
 *
 * 行號都是 1 起算、相對於傳進來的內文。內容範圍是標題行的下一行到單位最後一行；
 * 單位沒有內容時 `end_line` 是 `start_line - 1`。
 */
final class ArticleUnitSplitter
{
    public const LEAD_ID = 'lead';

    private const REFERENCES_HEADINGS = ['來源', '參考文獻'];

    /** ATX 標題：最多三個空白縮排，井號後要有空白，可有收尾的井號。 */
    private const HEADING_PATTERN = '/^ {0,3}(#{1,6})[ \t]+(.*?)(?:[ \t]+#+)?[ \t]*$/u';

    /** 整行版本：`{#id}` 後面只能接收尾的井號與空白。 */
    private const LINE_ID_PATTERN = '/[ \t]*\{#[A-Za-z0-9][A-Za-z0-9_-]*\}(?=(?:[ \t]+#+)?[ \t]*$)/';

    private const ID_PATTERN = '/[ \t]*\{#([A-Za-z0-9][A-Za-z0-9_-]*)\}$/';

    /**
     * @return array{units: list<array{unit_id: string|null, parent_id: string|null, parent_index: int|null, heading: string|null, role: string, level: int|null, start_line: int, end_line: int, content: string}>, duplicate_ids: list<string>}
     */
    public function split(string $body): array
    {
        $lines = explode("\n", str_replace("\r\n", "\n", $body));
        $total = count($lines);

        $units = [];
        $current = $this->newUnit(self::LEAD_ID, null, null, null, 'intro', null, 1);
        $currentH2 = null;
        $h2Index = null;
        $h2Role = 'body';

        foreach ($this->scan($lines) as $number => $heading) {
            if ($heading === null || ! in_array($heading['level'], [2, 3], true)) {
                continue;
            }

            $current['end_line'] = $number - 1;
            $units[] = $current;

            if ($heading['level'] === 2) {
                $role = in_array($heading['text'], self::REFERENCES_HEADINGS, true) ? 'references' : 'body';
                $h2Role = $role;
                $currentH2 = $heading['id'];
                $h2Index = count($units);
                $parent = null;
                $parentIndex = null;
            } else {
                $role = $h2Role;
                $parent = $currentH2;
                $parentIndex = $h2Index;
            }

            $current = $this->newUnit($heading['id'], $parent, $parentIndex, $heading['text'], $role, $heading['level'], $number + 1);
        }

        $current['end_line'] = $total;
        $units[] = $current;

        foreach ($units as &$unit) {
            $unit['content'] = implode("\n", array_slice($lines, $unit['start_line'] - 1, max(0, $unit['end_line'] - $unit['start_line'] + 1)));
        }
        unset($unit);

        return ['units' => $units, 'duplicate_ids' => $this->duplicateIds($units)];
    }

    /**
     * 拿掉標題行尾的 `{#id}`，其他地方（內文、程式碼區塊裡的標題樣子）一律不動。
     * 給顯示用：原稿與資料庫保留 `{#id}`，轉成 HTML 之前先過這個函式。
     */
    public static function stripHeadingIds(string $body): string
    {
        $lines = explode("\n", $body);

        foreach ((new self)->scan(array_map(fn (string $line) => rtrim($line, "\r"), $lines)) as $number => $heading) {
            if ($heading === null || $heading['id'] === null) {
                continue;
            }

            $index = $number - 1;
            $cr = str_ends_with($lines[$index], "\r") ? "\r" : '';
            $lines[$index] = preg_replace(self::LINE_ID_PATTERN, '', rtrim($lines[$index], "\r"), 1).$cr;
        }

        return implode("\n", $lines);
    }

    /**
     * 逐行判斷是不是標題（跳過程式碼區塊），回傳「行號 => 標題資訊或 null」。
     *
     * @param  list<string>  $lines
     * @return array<int, array{level: int, text: string, id: string|null}|null>
     */
    private function scan(array $lines): array
    {
        $result = [];
        $fence = null; // [字元, 開頭長度]

        foreach ($lines as $index => $line) {
            $number = $index + 1;

            if (preg_match('/^ {0,3}(`{3,}|~{3,})(.*)$/', $line, $match) === 1) {
                $marker = $match[1];
                if ($fence === null) {
                    // 反引號開頭的區塊，資訊字串裡不能再有反引號，否則只是行內程式碼
                    if ($marker[0] !== '`' || ! str_contains($match[2], '`')) {
                        $fence = [$marker[0], strlen($marker)];
                    }
                } elseif ($marker[0] === $fence[0] && strlen($marker) >= $fence[1] && trim($match[2]) === '') {
                    $fence = null;
                }

                continue;
            }

            if ($fence !== null || preg_match(self::HEADING_PATTERN, $line, $match) !== 1) {
                continue;
            }

            $text = trim($match[2]);
            $id = null;
            if (preg_match(self::ID_PATTERN, $text, $idMatch) === 1) {
                $id = $idMatch[1];
                $text = trim(substr($text, 0, -strlen($idMatch[0])));
            }

            $result[$number] = ['level' => strlen($match[1]), 'text' => $text, 'id' => $id];
        }

        return $result;
    }

    /**
     * @return array{unit_id: string|null, parent_id: string|null, parent_index: int|null, heading: string|null, role: string, level: int|null, start_line: int, end_line: int, content: string}
     */
    private function newUnit(?string $id, ?string $parent, ?int $parentIndex, ?string $heading, string $role, ?int $level, int $start): array
    {
        return ['unit_id' => $id, 'parent_id' => $parent, 'parent_index' => $parentIndex, 'heading' => $heading, 'role' => $role, 'level' => $level, 'start_line' => $start, 'end_line' => $start - 1, 'content' => ''];
    }

    /**
     * @param  list<array{unit_id: string|null}>  $units
     * @return list<string>
     */
    private function duplicateIds(array $units): array
    {
        $counts = [];
        foreach ($units as $unit) {
            if ($unit['unit_id'] !== null) {
                $counts[$unit['unit_id']] = ($counts[$unit['unit_id']] ?? 0) + 1;
            }
        }

        return array_map('strval', array_keys(array_filter($counts, fn (int $count) => $count > 1)));
    }
}
