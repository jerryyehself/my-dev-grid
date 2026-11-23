<?php

namespace App\Services;

/**
 * 把一篇文章的 Markdown 內文切成段落單位（純函式，不碰資料庫）。
 *
 * - 單位是每個 H2、H3 標題底下的內容，到下一個同級或更高一級的標題為止。H3 的 `parent_id`
 *   指向它所屬 H2 的 `unit_id`；H2、導言的 `parent_id` 是 null。H2 之前出現的 H3 沒有所屬 H2，
 *   `parent_id` 也是 null。H4 以下與任何位置的 H1 不切，算在所屬單位的內容裡（開頭的 H1 算進導言）。
 * - 第一個 H2／H3 之前的內容是導言，固定 `unit_id` 為 `lead`、`role` 為 `intro`；
 *   導言一定會出現在清單的第一筆，沒有內容時範圍是空的。
 * - 標題（去掉 `{#id}` 之後）是「來源」或「參考文獻」的 H2，`role` 為 `references`，
 *   底下的 H3 沿用；其餘是 `body`。
 * - 標題行尾的 `{#id}` 是明確 ID：解析成 `unit_id` 並從 `heading` 剝掉。沒寫的 `unit_id` 是 null，
 *   由之後的補寫腳本補上。
 * - 程式碼區塊（``` 或 ~~~ 圍起來）裡的 `#` 不是標題；縮排四個空白以上的行也不是標題。
 * - 只認 ATX 標題（`## 標題`）；setext 標題（底線式）與 HTML 區塊不處理。
 * - 單獨一行 `##`（沒有標題文字的空 H2）不是標題，不切。
 * - 換行與前端（`headings.ts`）一致：只去掉每行最後一個 `\r`，標題與圍欄判斷只認空格與 tab，
 *   不用 PHP 預設的 `trim()`（會連 `\v`、`\0` 一起吃掉）。
 *
 * 腳註（判斷方式見 `ArticleFootnotes`）：
 * - 有被引用的定義從所在單位的 `content` 拿掉，放進引用它的單位的 `footnotes`（取用時接在單位後面），
 *   同時記下原本的位置：`defined_in` 是原本所在單位在清單裡的位置，`start_line`／`end_line` 是行號。
 *   定義放在哪裡沒有語意（Pandoc 手冊："need not be placed at the end of the document"），
 *   放在文末時跟引用句隔得很遠，切段後就跟引用它的那一節分開了。
 * - 同一個標籤被好幾個單位引用時，每個單位都附一份；同一個單位裡引用好幾次只附一份。
 * - 定義裡又引用別的腳註時，那個腳註也附給同一個單位。
 * - 沒有被引用的定義、同一個標籤後面重複的定義（第一個才算數）留在原位，不附給任何單位。
 * - 引用寫在標題裡也算是那個單位引用的。
 * - `source` 是單位在原稿裡的原文（標題行以下，腳註定義還沒拿掉），`content` 是拿掉之後的內容。
 *
 * 容器：H2 底下有 H3、自己的內容（腳註定義拿掉之後、去掉空白）不超過 `CONTAINER_MAX_CHARS` 字時，
 * `is_container` 是 true。容器只用來組樹，不當取用單位；仍然留在清單裡，H3 的父指標照常指向它。
 *
 * 子片段：導言與 `body` 單位（不含容器）的內容去掉空白後超過 `CHUNK_SOFT_LIMIT` 字時，
 * 用 `ArticleUnitChunker` 在段落、再不行在句子邊界切成 `chunks`；每片的 `id` 是 `<unit_id>~N`（N 從 1 起算，
 * 單位還沒有 ID 時是 null），`footnotes` 是這一片引用到的腳註標籤。子片段只給檢索內部用，
 * 引用與心智圖一律指回原單位。沒超過、或切不開（例如整節是一個大表格）時是 null。
 * `references` 單位不切：它不是正文的取用單位。
 *
 * `parent_index` 是父單位在回傳清單裡的位置（0 起算）：H2 的 `unit_id` 可能還是 null（尚未補寫），
 * 這時 `parent_id` 也是 null，靠 `parent_index` 才找得到父單位。
 *
 * ID 重複時不丟例外：照常切出所有單位，重複的 ID 列在回傳的 `duplicate_ids`，由呼叫端決定要不要擋。
 * 導言的 `lead` 也算在內，所以標題寫了 `{#lead}` 也會被列為重複。
 *
 * 行號都是 1 起算、相對於傳進來的內文。內容範圍是標題行的下一行到單位最後一行；
 * 單位沒有內容時 `end_line` 是 `start_line - 1`。
 *
 * @phpstan-type Section array{unit_id: string|null, parent_id: string|null, parent_index: int|null, heading: string|null, role: string, level: int|null, start_line: int, end_line: int}
 * @phpstan-type Definition array{label: string, key: string, start_line: int, end_line: int, content: string, defined_in: int}
 * @phpstan-type Footnote array{label: string, content: string, defined_in: int, start_line: int, end_line: int}
 * @phpstan-type Chunk array{id: string|null, content: string, footnotes: list<string>}
 * @phpstan-type Unit array{unit_id: string|null, parent_id: string|null, parent_index: int|null, heading: string|null, role: string, level: int|null, start_line: int, end_line: int, source: string, content: string, footnotes: list<Footnote>, is_container: bool, chunks: list<Chunk>|null}
 */
final class ArticleUnitSplitter
{
    public const LEAD_ID = 'lead';

    /**
     * 容器 H2 自己的內容上限（去掉空白後的字元數）。
     *
     * 依據：已發布的文章裡，有 H3 的 H2 自己的內容只有兩種：只用來分組的（例如「其他取捨」底下直接是 H3，
     * 0～2 字），以及 362 字以上的正文，中間沒有落在模糊地帶的。20 字留給「以下分三點說明。」這種一句話的引子。
     */
    public const CONTAINER_MAX_CHARS = 20;

    /**
     * 子片段的軟上限（去掉空白後的字元數）。
     *
     * 1500 參考 MDKeyChunker 的預設（1500 字元），只有這一個來源，還沒有用這裡的文章校準過。
     */
    public const CHUNK_SOFT_LIMIT = 1500;

    /** 子片段 ID 的分隔字元：`{#id}` 只能有英數、`_`、`-`，不會跟單位 ID 撞在一起。 */
    public const CHUNK_SEPARATOR = '~';

    private const REFERENCES_HEADINGS = ['來源', '參考文獻'];

    /** ATX 標題：最多三個空白縮排，井號後要有空白，可有收尾的井號。 */
    private const HEADING_PATTERN = '/^ {0,3}(#{1,6})[ \t]+(.*?)(?:[ \t]+#+)?[ \t]*$/u';

    /** 整行版本：`{#id}` 後面只能接收尾的井號與空白。 */
    private const LINE_ID_PATTERN = '/[ \t]*\{#[A-Za-z0-9][A-Za-z0-9_-]*\}(?=(?:[ \t]+#+)?[ \t]*$)/';

    private const ID_PATTERN = '/[ \t]*\{#([A-Za-z0-9][A-Za-z0-9_-]*)\}$/';

    /**
     * @return array{units: list<Unit>, duplicate_ids: list<string>}
     */
    public function split(string $body): array
    {
        $lines = array_map(self::stripCr(...), explode("\n", $body));
        $sections = $this->sections($lines);
        $footnotes = $this->assignFootnotes($sections, $lines);
        $hasChildren = array_flip(array_filter(array_column($sections, 'parent_index'), fn (?int $index) => $index !== null));

        $units = [];
        foreach ($sections as $index => $section) {
            $range = $section['end_line'] < $section['start_line'] ? [] : range($section['start_line'], $section['end_line']);
            $kept = array_values(array_filter($range, fn (int $line) => ! isset($footnotes['moved'][$line])));
            $content = implode("\n", array_map(fn (int $line) => $lines[$line - 1], $kept));
            $keys = $footnotes['keys'][$index];
            $isContainer = $section['level'] === 2
                && isset($hasChildren[$index])
                && ArticleUnitChunker::length($content) <= self::CONTAINER_MAX_CHARS;

            $units[] = $section + [
                'source' => implode("\n", array_map(fn (int $line) => $lines[$line - 1], $range)),
                'content' => $content,
                'footnotes' => array_map(fn (string $key) => $this->footnote($footnotes['definitions'][$key]), $keys),
                'is_container' => $isContainer,
                'chunks' => $section['role'] === 'references' || $isContainer
                    ? null
                    : $this->chunks($section['unit_id'], $content, $keys, $footnotes['definitions'], $footnotes['nested']),
            ];
        }

        return ['units' => $units, 'duplicate_ids' => $this->duplicateIds($units)];
    }

    /**
     * 依 H2、H3 切出各單位的範圍、父子關係與角色，還沒有內容。
     *
     * @param  list<string>  $lines
     * @return non-empty-list<Section>
     */
    private function sections(array $lines): array
    {
        $sections = [];
        $current = $this->newSection(self::LEAD_ID, null, null, null, 'intro', null, 1);
        $currentH2 = null;
        $h2Index = null;
        $h2Role = 'body';

        foreach ($this->scan($lines)['headings'] as $number => $heading) {
            if (! in_array($heading['level'], [2, 3], true)) {
                continue;
            }

            $current['end_line'] = $number - 1;
            $sections[] = $current;

            if ($heading['level'] === 2) {
                $role = in_array($heading['text'], self::REFERENCES_HEADINGS, true) ? 'references' : 'body';
                $h2Role = $role;
                $currentH2 = $heading['id'];
                $h2Index = count($sections);
                $parent = null;
                $parentIndex = null;
            } else {
                $role = $h2Role;
                $parent = $currentH2;
                $parentIndex = $h2Index;
            }

            $current = $this->newSection($heading['id'], $parent, $parentIndex, $heading['text'], $role, $heading['level'], $number + 1);
        }

        $current['end_line'] = count($lines);
        $sections[] = $current;

        return $sections;
    }

    /**
     * 拿掉標題行尾的 `{#id}`，其他地方（內文、程式碼區塊裡的標題樣子）一律不動。
     * 給顯示用：原稿與資料庫保留 `{#id}`，轉成 HTML 之前先過這個函式。
     */
    public static function stripHeadingIds(string $body): string
    {
        $lines = explode("\n", $body);

        foreach ((new self)->scan(array_map(self::stripCr(...), $lines))['headings'] as $number => $heading) {
            if ($heading['id'] === null) {
                continue;
            }

            $index = $number - 1;
            $cr = str_ends_with($lines[$index], "\r") ? "\r" : '';
            $lines[$index] = preg_replace(self::LINE_ID_PATTERN, '', self::stripCr($lines[$index]), 1).$cr;
        }

        return implode("\n", $lines);
    }

    /**
     * 程式碼區塊（含圍欄行）的行號，1 起算。沒關上的圍欄一直延續到最後一行（CommonMark）。
     *
     * @param  list<string>  $lines  已去掉行尾 `\r` 的行
     * @return array<int, true>
     */
    public static function codeLines(array $lines): array
    {
        return (new self)->scan($lines)['code'];
    }

    /**
     * 逐行判斷是不是標題（跳過程式碼區塊）。`headings` 是「行號 => 標題資訊」，不是標題的行不在裡面；
     * `code` 是程式碼區塊的行號（含開頭與結尾的圍欄行）。
     *
     * @param  list<string>  $lines
     * @return array{headings: array<int, array{level: int, text: string, id: string|null}>, code: array<int, true>}
     */
    private function scan(array $lines): array
    {
        $result = [];
        $code = [];
        $fence = null; // [字元, 開頭長度]

        foreach ($lines as $index => $line) {
            $number = $index + 1;

            // 資訊字串不吃 \r、U+2028、U+2029：PHP 的 `.` 會吃，JS 的 `.` 不吃，要與前端 headings.ts 判斷一致
            if (preg_match('/^ {0,3}(`{3,}|~{3,})((?:(?!\r|\xE2\x80[\xA8\xA9]).)*)$/', $line, $match) === 1) {
                $marker = $match[1];
                if ($fence === null) {
                    // 反引號開頭的區塊，資訊字串裡不能再有反引號，否則只是行內程式碼
                    if ($marker[0] !== '`' || ! str_contains($match[2], '`')) {
                        $fence = [$marker[0], strlen($marker)];
                        $code[$number] = true;
                    }
                } else {
                    $code[$number] = true;
                    if ($marker[0] === $fence[0] && strlen($marker) >= $fence[1] && preg_match('/^[ \t]*$/', $match[2]) === 1) {
                        // 關閉圍欄後面只能接空格或 tab（CommonMark）；PHP 的 trim() 還會吃掉 \v、\0，會誤判成關閉
                        $fence = null;
                    }
                }

                continue;
            }

            if ($fence !== null) {
                $code[$number] = true;

                continue;
            }

            if (preg_match(self::HEADING_PATTERN, $line, $match) !== 1) {
                continue;
            }

            $text = trim($match[2], " \t");
            $id = null;
            if (preg_match(self::ID_PATTERN, $text, $idMatch) === 1) {
                $id = $idMatch[1];
                $text = trim(substr($text, 0, -strlen($idMatch[0])), " \t");
            }

            $result[$number] = ['level' => strlen($match[1]), 'text' => $text, 'id' => $id];
        }

        return ['headings' => $result, 'code' => $code];
    }

    /**
     * 決定每個單位附哪些腳註、哪些定義的行要從原單位拿掉。
     *
     * 回傳的 `keys` 是每個單位附的腳註（正規化後的標籤，照定義在原稿裡的順序），`moved` 是要拿掉的行號，
     * `nested` 是每個定義裡又引用到的腳註。
     *
     * @param  list<Section>  $sections
     * @param  list<string>  $lines
     * @return array{keys: list<list<string>>, moved: array<int, true>, definitions: array<string, Definition>, nested: array<string, list<string>>}
     */
    private function assignFootnotes(array $sections, array $lines): array
    {
        // 同一個標籤只認第一個定義；後面重複的留在原位。所有定義的行（含重複的）都不拿來找引用：
        // 開頭的 `[^x]:` 長得像引用，定義裡的引用由 $nested 處理
        $definitions = [];
        $definitionLines = [];
        foreach (ArticleFootnotes::definitions($lines, self::codeLines($lines)) as $definition) {
            for ($line = $definition['start_line']; $line <= $definition['end_line']; $line++) {
                $definitionLines[$line] = true;
            }
            $definitions[$definition['key']] ??= $definition + ['defined_in' => $this->sectionAt($sections, $definition['start_line'])];
        }

        $nested = array_map(fn (array $definition) => ArticleFootnotes::referencesInDefinition($definition['content']), $definitions);

        $keys = [];
        $moved = [];
        foreach ($sections as $section) {
            // 標題行也算（導言沒有標題行）
            $from = $section['level'] === null ? $section['start_line'] : $section['start_line'] - 1;
            $own = [];
            for ($line = $from; $line <= $section['end_line']; $line++) {
                $own[] = isset($definitionLines[$line]) ? '' : $lines[$line - 1];
            }

            $attached = $this->closure(ArticleFootnotes::references($own), $definitions, $nested);
            $keys[] = $attached;
            // 被引用的定義才搬走
            foreach ($attached as $key) {
                for ($line = $definitions[$key]['start_line']; $line <= $definitions[$key]['end_line']; $line++) {
                    $moved[$line] = true;
                }
            }
        }

        return ['keys' => $keys, 'moved' => $moved, 'definitions' => $definitions, 'nested' => $nested];
    }

    /**
     * 引用到的腳註，加上它們的定義裡再引用到的（遞迴），只留有定義的，照定義在原稿裡的順序。
     *
     * @param  list<string>  $keys
     * @param  array<string, Definition>  $definitions
     * @param  array<string, list<string>>  $nested
     * @return list<string>
     */
    private function closure(array $keys, array $definitions, array $nested): array
    {
        $found = [];
        $queue = $keys;
        while ($queue !== []) {
            $key = array_shift($queue);
            if (isset($found[$key]) || ! isset($definitions[$key])) {
                continue;
            }
            $found[$key] = true;
            array_push($queue, ...$nested[$key]);
        }

        // 標籤是純數字時陣列 key 會變成整數，轉回字串
        $result = array_map('strval', array_keys($found));
        usort($result, fn (string $a, string $b) => $definitions[$a]['start_line'] <=> $definitions[$b]['start_line']);

        return $result;
    }

    /**
     * 某一行屬於第幾個單位（標題行算進它自己的單位）。
     *
     * @param  list<Section>  $sections
     */
    private function sectionAt(array $sections, int $line): int
    {
        $found = 0;
        foreach ($sections as $index => $section) {
            if ($line >= $section['start_line'] - 1) {
                $found = $index;
            }
        }

        return $found;
    }

    /**
     * @param  Definition  $definition
     * @return Footnote
     */
    private function footnote(array $definition): array
    {
        return [
            'label' => $definition['label'],
            'content' => $definition['content'],
            'defined_in' => $definition['defined_in'],
            'start_line' => $definition['start_line'],
            'end_line' => $definition['end_line'],
        ];
    }

    /**
     * 太長的單位切成子片段；不用切或切不開時是 null。
     *
     * @param  list<string>  $attached  這個單位附的腳註
     * @param  array<string, Definition>  $definitions
     * @param  array<string, list<string>>  $nested
     * @return list<Chunk>|null
     */
    private function chunks(?string $unitId, string $content, array $attached, array $definitions, array $nested): ?array
    {
        $pieces = ArticleUnitChunker::chunk($content, self::CHUNK_SOFT_LIMIT);
        if (count($pieces) < 2) {
            return null;
        }

        $chunks = [];
        foreach ($pieces as $n => $piece) {
            // 每片只附它自己引用到的腳註（定義裡再引用的也算）。留在原位的定義（沒被引用、重複的）
            // 開頭的 `[^x]:` 也長得像引用，所以只取這個單位本來就附的那些
            $keys = array_values(array_intersect(
                $this->closure(ArticleFootnotes::references(explode("\n", $piece)), $definitions, $nested),
                $attached,
            ));
            $chunks[] = [
                'id' => $unitId === null ? null : $unitId.self::CHUNK_SEPARATOR.($n + 1),
                'content' => $piece,
                'footnotes' => array_map(fn (string $key) => $definitions[$key]['label'], $keys),
            ];
        }

        return $chunks;
    }

    /** 只去掉一個行尾 `\r`（CRLF 的 CR），跟前端一樣；不用 rtrim，免得連續的 `\r` 全被吃掉。 */
    private static function stripCr(string $line): string
    {
        return str_ends_with($line, "\r") ? substr($line, 0, -1) : $line;
    }

    /**
     * @return Section
     */
    private function newSection(?string $id, ?string $parent, ?int $parentIndex, ?string $heading, string $role, ?int $level, int $start): array
    {
        return ['unit_id' => $id, 'parent_id' => $parent, 'parent_index' => $parentIndex, 'heading' => $heading, 'role' => $role, 'level' => $level, 'start_line' => $start, 'end_line' => $start - 1];
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
