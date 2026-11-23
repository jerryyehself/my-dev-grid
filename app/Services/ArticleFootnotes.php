<?php

namespace App\Services;

/**
 * 找出 Markdown 裡的 GFM 腳註定義（`[^label]: …`）與引用（`[^label]`）。純函式，給 `ArticleUnitSplitter` 用。
 *
 * 判斷方式照前端用的 micromark（`micromark-extension-gfm-footnote`），兩邊才會認出同一批腳註：
 * - 標籤不能有空格、tab、換行與 `[`；`\` 後面接 `[`、`]`、`\` 算跳脫；最長 999 字元。
 *   比對時忽略大小寫（`normalizeKey()`）。
 * - 定義從行首開始（最多三個空白縮排），後面接的行屬於同一個定義的情況：
 *   - 縮排剛好四欄以上（空白或 tab）的行；中間夾的空行也算，定義最後面的空行不算。
 *   - 惰性續行：上一行是定義裡的非空行，這一行不是空行、也不是會打斷段落的區塊開頭
 *     （標題、圍欄、引言、分隔線、清單項目、HTML、另一個腳註定義）。
 * - 程式碼區塊（``` 或 ~~~）裡的 `[^x]:` 與 `[^x]` 都不算；行內程式碼（反引號）裡的引用也不算。
 * - 同一個標籤定義兩次時，第一個才是定義（CommonMark 的規則），後面的由呼叫端決定怎麼處理。
 *
 * 不處理：寫在引言或清單裡面的定義（`> [^1]: …`）、縮排程式碼區塊（四格縮排）裡的引用。
 * 這兩種在目前的文章裡都沒有；前者不會被搬走，後者會被誤算成引用。
 */
final class ArticleFootnotes
{
    /** 標籤本體：一般字元，或 `\` 加上（可有可無的）`[`、`]`、`\`。佔有量詞避免回溯成另一種切法。 */
    private const LABEL = '((?>\\\\[\[\]\\\\]|\\\\|[^ \t\n\[\]\\\\])++)';

    private const MAX_LABEL_LENGTH = 999;

    private const DEFINITION_PATTERN = '/^ {0,3}\[\^'.self::LABEL.'\]:/u';

    /** 前面是 `\` 的 `[` 是跳脫字元，不是引用的開頭。 */
    private const REFERENCE_PATTERN = '/(?<!\\\\)\[\^'.self::LABEL.'\]/u';

    /** 續行要縮排四欄：四個空白，或三個以內的空白接 tab。 */
    private const INDENT_PATTERN = '/^(?: {4}| {0,3}\t)/';

    /** 會打斷段落、所以不能當惰性續行的區塊開頭（最多三個空白縮排）。 */
    private const INTERRUPT_PATTERN = '/^ {0,3}(?:#{1,6}(?:[ \t]|$)|`{3,}|~{3,}|>|(?:\*[ \t]*){3,}$|(?:-[ \t]*){3,}$|(?:_[ \t]*){3,}$|[-+*][ \t]+\S|1[.)][ \t]+\S|<[A-Za-z\/!?])/';

    /** 行內程式碼：開頭與結尾是一樣長的一串反引號，不跨空行。 */
    private const CODE_SPAN_PATTERN = '/(?<!`)(`+)(?!`)(?:(?!\n[ \t]*\n).)+?(?<!`)\1(?!`)/su';

    /**
     * 逐行找出腳註定義。
     *
     * @param  list<string>  $lines  已去掉行尾 `\r` 的行
     * @param  array<int, true>  $codeLines  程式碼區塊的行號（1 起算，含圍欄行）
     * @return list<array{label: string, key: string, start_line: int, end_line: int, content: string}>
     */
    public static function definitions(array $lines, array $codeLines): array
    {
        $definitions = [];
        $total = count($lines);

        for ($index = 0; $index < $total; $index++) {
            $number = $index + 1;
            $label = isset($codeLines[$number]) ? null : self::definitionLabel($lines[$index]);
            if ($label === null) {
                continue;
            }

            $end = $index;
            for ($next = $index + 1; $next < $total; $next++) {
                $line = $lines[$next];
                if (isset($codeLines[$next + 1])) {
                    break;
                }
                if (self::isBlank($line)) {
                    // 空行先不算進來，要後面還有縮排的續行才算
                    continue;
                }
                if (preg_match(self::INDENT_PATTERN, $line) === 1) {
                    $end = $next;

                    continue;
                }
                // 惰性續行只能緊接在非空行後面
                if ($end === $next - 1 && preg_match(self::INTERRUPT_PATTERN, $line) !== 1 && self::definitionLabel($line) === null) {
                    $end = $next;

                    continue;
                }
                break;
            }

            $definitions[] = [
                'label' => $label,
                'key' => self::normalizeKey($label),
                'start_line' => $number,
                'end_line' => $end + 1,
                'content' => implode("\n", array_slice($lines, $index, $end - $index + 1)),
            ];
            $index = $end;
        }

        return $definitions;
    }

    /**
     * 一段 Markdown 裡引用到的腳註標籤（正規化後、不重複，照第一次出現的順序）。
     * 程式碼區塊與行內程式碼裡的不算；有沒有對應的定義由呼叫端判斷。
     *
     * @param  list<string>  $lines  已去掉行尾 `\r` 的行
     * @return list<string>
     */
    public static function references(array $lines): array
    {
        $codeLines = ArticleUnitSplitter::codeLines($lines);
        foreach (array_keys($codeLines) as $number) {
            $lines[$number - 1] = '';
        }

        $text = (string) preg_replace(self::CODE_SPAN_PATTERN, '', implode("\n", $lines));
        if (preg_match_all(self::REFERENCE_PATTERN, $text, $matches) === false) {
            return [];
        }

        $keys = [];
        foreach ($matches[1] as $label) {
            if (mb_strlen($label) <= self::MAX_LABEL_LENGTH) {
                $keys[self::normalizeKey($label)] = true;
            }
        }

        return array_map('strval', array_keys($keys));
    }

    /**
     * 定義本身的內容（去掉開頭的 `[^label]:`）裡引用到的其他腳註。
     *
     * @return list<string>
     */
    public static function referencesInDefinition(string $content): array
    {
        $lines = explode("\n", $content);
        $lines[0] = (string) preg_replace(self::DEFINITION_PATTERN, '', $lines[0], 1);

        return self::references($lines);
    }

    /** 比對用的標籤：跟 micromark 的 `normalizeIdentifier` 一樣，空白併成一個、去頭尾，轉小寫再轉大寫。 */
    public static function normalizeKey(string $label): string
    {
        return mb_strtoupper(mb_strtolower(trim((string) preg_replace('/[\t\n\r ]+/', ' ', $label), ' ')));
    }

    private static function definitionLabel(string $line): ?string
    {
        if (preg_match(self::DEFINITION_PATTERN, $line, $match) !== 1 || mb_strlen($match[1]) > self::MAX_LABEL_LENGTH) {
            return null;
        }

        return $match[1];
    }

    private static function isBlank(string $line): bool
    {
        return preg_match('/^[ \t]*$/', $line) === 1;
    }
}
