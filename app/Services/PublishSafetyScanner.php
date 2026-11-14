<?php

namespace App\Services;

/**
 * 匯入文章前的公開安全檢查：找出看起來不該公開的內容，列成警告。只警告、不擋匯入——
 * 規則一定會誤判（例如文章本來就在講 token 格式），由管理者看過再決定要不要發布。
 *
 * 這裡只放通用的格式規則（各家憑證的前綴、私鑰標頭、雲端服務帳號網域、本機路徑、email）。
 * 特定的私人識別字（帳號名稱、子網域、私有 repo 或資料夾名稱）不能寫在這個公開的 repo 裡，
 * 不然規則本身就把它們公開了。
 *
 * 警告只帶規則代號、說明與行號，不帶比對到的文字：比對到的可能就是真的 token，
 * 不能因為警告而出現在 API 回應或快取裡。
 */
final class PublishSafetyScanner
{
    /** 一份文章最多回報幾筆，避免整份貼了 log 的文章塞出巨大的回應。 */
    public const MAX_WARNINGS = 50;

    /**
     * 代號 => [正規表示式, 說明]。token 類的規則要求前綴後面接夠長的字元，
     * 只提到前綴的說明文字（例如「`ghp_` 開頭」）不會被當成 token。
     */
    private const RULES = [
        'github_token' => ['/(?<![A-Za-z0-9_])(?:gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,})/', '疑似 GitHub token'],
        'google_client_secret' => ['/GOCSPX-[A-Za-z0-9_-]{10,}/', '疑似 Google OAuth client secret'],
        'aws_access_key' => ['/(?<![A-Z0-9])(?:AKIA|ASIA)[A-Z0-9]{16}(?![A-Z0-9])/', '疑似 AWS access key'],
        'private_key' => ['/-----BEGIN (?:[A-Z0-9]+ )*PRIVATE KEY-----/', '私鑰'],
        'service_account' => ['/iam\.gserviceaccount\.com/i', 'GCP service account 帳號'],
        'local_path' => ['~/home/user/|/tmp/claude~', '本機或執行環境的檔案路徑'],
        'email' => ['/[A-Za-z0-9._%+-]+@([A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,})/', 'email 地址'],
    ];

    /** RFC 2606 保留給文件範例用的網域，email 規則不回報。 */
    private const EXAMPLE_EMAIL_DOMAINS = ['example.com', 'example.org', 'example.net'];

    /**
     * @param  int|null  $firstLine  `$text` 第一行在原檔的行號；null 表示這段文字沒有行號（例如 front matter 的標題）
     * @return list<array{code: string, message: string, line: int|null}>
     */
    public function scan(string $text, ?int $firstLine = 1): array
    {
        $warnings = [];

        foreach (self::RULES as $code => [$pattern, $message]) {
            if (preg_match_all($pattern, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
                continue;
            }

            $lines = [];
            foreach ($matches as $match) {
                if ($code === 'email' && in_array(strtolower($match[1][0]), self::EXAMPLE_EMAIL_DOMAINS, true)) {
                    continue;
                }

                $line = $firstLine === null ? null : $firstLine + substr_count($text, "\n", 0, $match[0][1]);
                // 同一行同一種只報一次
                $lines[$line ?? 0] = $line;
            }

            foreach ($lines as $line) {
                $warnings[] = ['code' => $code, 'message' => $message, 'line' => $line];
            }
        }

        usort($warnings, fn (array $a, array $b) => [$a['line'] ?? 0, $a['code']] <=> [$b['line'] ?? 0, $b['code']]);

        return array_slice($warnings, 0, self::MAX_WARNINGS);
    }
}
