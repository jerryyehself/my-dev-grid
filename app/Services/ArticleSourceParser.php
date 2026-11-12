<?php

namespace App\Services;

use DateTimeInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * 把文章來源 repo 裡的一份 Markdown 原稿，轉成要寫進 `documentations` 的欄位。
 *
 * 只認明確選擇匯入的檔案：開頭要有 YAML front matter，而且 `import: true`（只認布林值 true，
 * 字串 "true" 不算）。沒有 front matter、或沒有選擇匯入的檔案回傳 `import: false`，
 * 不會出現在匯入清單裡；選擇匯入但格式不對的回傳 `invalid`，清單上顯示原因。
 *
 * - `slug` 必填，是重新匯入時對上同一篇文章的識別碼，只收小寫英數字與連字號。
 * - `title` 選填；沒寫就取內文第一行的 H1，並把那行 H1 從內文拿掉。front matter 的 title
 *   跟開頭 H1 相同時也拿掉，不同時保留那行 H1，不替作者刪內容。
 * - `creation_date` 選填，日期格式 `YYYY-MM-DD`。
 * - `body` 存去掉 front matter（與上面那行 H1）之後的 Markdown 原文，換行統一成 `\n`。
 *
 * 其他 front matter 欄位（例如技術、實作的對應）目前不處理。
 */
final class ArticleSourceParser
{
    /** `documentations.title` 與 `documentations.source_key` 都是 varchar(255)。 */
    public const MAX_TITLE_LENGTH = 255;

    public const MAX_KEY_LENGTH = 255;

    private const SLUG_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    /** 不能匯入的原因（清單上 `reason` 的值）與給管理者看的說明。 */
    public const INVALID_REASONS = [
        'too_large' => '檔案超過 1 MB，GitHub 的 Contents API 不提供內容，無法匯入。',
        'not_utf8' => '檔案不是 UTF-8 編碼。',
        'binary' => '檔案含有 NUL 字元，看起來不是文字檔。',
        'yaml_error' => 'front matter 不是合法的 YAML。',
        'slug_missing' => 'front matter 缺少 slug。',
        'slug_invalid' => 'slug 只能用小寫英文字母、數字與連字號，最長 255 字元。',
        'duplicate_slug' => '同一個 repo 裡有其他檔案用了相同的 slug。',
        'title_missing' => '沒有標題：front matter 沒有 title，內文開頭也沒有 H1。',
        'title_invalid' => 'title 必須是文字，最長 255 字元。',
        'creation_date_invalid' => 'creation_date 必須是 YYYY-MM-DD 格式的日期。',
    ];

    /**
     * `body_line` 是 `body` 第一行在原檔的行號（從 1 起算），掃描警告時用來回報原檔的行號。
     *
     * @return array{import: bool, key: string|null, title: string|null, body: string|null, creation_date: string|null, invalid: string|null, body_line: int}
     */
    public function parse(string $raw): array
    {
        $result = ['import' => false, 'key' => null, 'title' => null, 'body' => null, 'creation_date' => null, 'invalid' => null, 'body_line' => 1];

        $text = str_replace("\r\n", "\n", $raw);
        if (str_starts_with($text, "\u{FEFF}")) {
            $text = substr($text, 3);
        }

        $frontMatter = $this->splitFrontMatter($text);
        if ($frontMatter === null) {
            return $result;
        }
        [$yaml, $body, $bodyLine] = $frontMatter;

        try {
            // PARSE_DATETIME：不加的話，沒加引號的日期會被轉成 Unix timestamp 整數。
            // 不開 PARSE_OBJECT／PARSE_CUSTOM_TAGS／PARSE_CONSTANT，原稿裡的 !php/object 這類標籤不會建立物件。
            $meta = Yaml::parse($yaml, Yaml::PARSE_DATETIME);
        } catch (ParseException) {
            // 解析失敗就看不出有沒有選擇匯入；列成 invalid 讓管理者看到，不默默略過
            return ['import' => true, 'invalid' => 'yaml_error'] + $result;
        }

        if (! is_array($meta) || ($meta['import'] ?? null) !== true) {
            return $result;
        }
        $result['import'] = true;

        // PostgreSQL 的 text 欄位不收 NUL 與非 UTF-8 的位元組，寫入時會丟錯，先在這裡擋下
        if (str_contains($text, "\0")) {
            return ['invalid' => 'binary'] + $result;
        }
        if (! mb_check_encoding($text, 'UTF-8')) {
            return ['invalid' => 'not_utf8'] + $result;
        }

        $slug = $meta['slug'] ?? null;
        if ($slug === null || $slug === '') {
            return ['invalid' => 'slug_missing'] + $result;
        }
        if (! is_string($slug) || strlen($slug) > self::MAX_KEY_LENGTH || preg_match(self::SLUG_PATTERN, $slug) !== 1) {
            return ['invalid' => 'slug_invalid'] + $result;
        }
        $result['key'] = $slug;

        [$body, $bodyLine] = $this->dropLeadingBlankLines($body, $bodyLine);

        $title = $meta['title'] ?? null;
        if ($title !== null && ! is_string($title)) {
            return ['invalid' => 'title_invalid'] + $result;
        }
        $title = $title === null ? null : trim($title);
        $heading = $this->leadingHeading($body);

        if ($heading !== null && ($title === null || $title === '' || $title === $heading['text'])) {
            $title = $heading['text'];
            [$body, $bodyLine] = $this->dropLeadingBlankLines(substr($body, $heading['length']), $bodyLine + 1);
        }

        if ($title === null || $title === '') {
            return ['invalid' => 'title_missing'] + $result;
        }
        if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            return ['invalid' => 'title_invalid'] + $result;
        }

        $creationDate = $this->creationDate($meta['creation_date'] ?? null);
        if ($creationDate === false) {
            return ['invalid' => 'creation_date_invalid'] + $result;
        }

        return [
            'title' => $title,
            'body' => $body,
            'creation_date' => $creationDate,
            'body_line' => $bodyLine,
        ] + $result;
    }

    /**
     * 第一行是 `---`，之後第一個 `---`（或 YAML 的文件結尾 `...`）那一行結束。
     * 沒有結束行就當作沒有 front matter：開頭的 `---` 在 Markdown 裡也可能只是一條分隔線。
     *
     * @return array{0: string, 1: string, 2: int}|null YAML 文字、其後的內文、內文第一行的行號
     */
    private function splitFrontMatter(string $text): ?array
    {
        $lines = explode("\n", $text);
        if (rtrim($lines[0]) !== '---') {
            return null;
        }

        $count = count($lines);
        for ($i = 1; $i < $count; $i++) {
            $line = rtrim($lines[$i]);
            if ($line === '---' || $line === '...') {
                return [
                    implode("\n", array_slice($lines, 1, $i - 1)),
                    implode("\n", array_slice($lines, $i + 1)),
                    $i + 2,
                ];
            }
        }

        return null;
    }

    /**
     * 只拿掉整行空白的行；有內容的行連開頭的縮排都保留（四個空白縮排在 Markdown 是程式碼區塊）。
     *
     * @return array{0: string, 1: int}
     */
    private function dropLeadingBlankLines(string $body, int $line): array
    {
        while (preg_match('/\A[ \t]*\n/', $body, $match) === 1) {
            $body = substr($body, strlen($match[0]));
            $line++;
        }

        return [$body, $line];
    }

    /**
     * 內文第一行是 ATX 的 H1（`# 標題`，結尾可以有收尾的 `#`）時，回傳標題文字與那一行連換行的長度。
     *
     * @return array{text: string, length: int}|null
     */
    private function leadingHeading(string $body): ?array
    {
        if (preg_match('/\A {0,3}#[ \t]+(.+?)(?:[ \t]+#+)?[ \t]*(?:\n|\z)/u', $body, $match) !== 1) {
            return null;
        }

        $text = trim($match[1]);

        return $text === '' ? null : ['text' => $text, 'length' => strlen($match[0])];
    }

    /**
     * @return string|false|null 沒寫是 null，格式不對是 false
     */
    private function creationDate(mixed $value): string|false|null
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $match) === 1
            && checkdate((int) $match[2], (int) $match[3], (int) $match[1])) {
            return $value;
        }

        return false;
    }
}
