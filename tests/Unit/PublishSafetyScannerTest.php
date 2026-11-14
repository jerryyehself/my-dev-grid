<?php

namespace Tests\Unit;

use App\Services\PublishSafetyScanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * 匯入文章時的公開安全警告。測試裡的憑證都是照格式組出來的假值。
 */
class PublishSafetyScannerTest extends TestCase
{
    /** @return list<string> */
    private function codes(string $text): array
    {
        return array_column((new PublishSafetyScanner)->scan($text), 'code');
    }

    /** @return iterable<string, array{string, string}> */
    public static function flagged(): iterable
    {
        yield 'classic GitHub token' => ['token: ghp_'.str_repeat('Ab1', 12), 'github_token'];
        yield 'GitHub Actions token' => ['ghs_'.str_repeat('x9', 18), 'github_token'];
        yield 'fine-grained GitHub token' => ['github_pat_'.str_repeat('A1_', 10), 'github_token'];
        yield '網址裡的 GitHub token' => ['https://ghp_'.str_repeat('Z', 36).'@github.com/o/r', 'github_token'];
        yield 'Google client secret' => ['GOCSPX-'.str_repeat('aB3-', 7), 'google_client_secret'];
        yield 'AWS access key' => ['key=AKIA'.str_repeat('Q', 16), 'aws_access_key'];
        yield 'AWS 暫時性 key' => ['ASIA'.str_repeat('7', 16), 'aws_access_key'];
        yield 'RSA 私鑰' => ["-----BEGIN RSA PRIVATE KEY-----\nMIIE", 'private_key'];
        yield 'PKCS8 私鑰' => ['-----BEGIN PRIVATE KEY-----', 'private_key'];
        yield 'OpenSSH 私鑰' => ['-----BEGIN OPENSSH PRIVATE KEY-----', 'private_key'];
        yield 'service account' => ['deployer@some-project.iam.gserviceaccount.com', 'service_account'];
        yield 'service account 大寫' => ['X@P.IAM.GSERVICEACCOUNT.COM', 'service_account'];
        yield '家目錄路徑' => ['見 /home/user/project/notes.md', 'local_path'];
        yield '暫存路徑' => ['/tmp/claude-0/scratch', 'local_path'];
        yield 'email' => ['寄到 someone@mail.co.uk', 'email'];
        yield '長得像 example.com 的其他網域' => ['a@example.com.attacker.io', 'email'];
    }

    #[DataProvider('flagged')]
    #[TestDox('看起來不該公開的內容會列成警告')]
    public function test_suspicious_content_is_flagged(string $text, string $code): void
    {
        $this->assertContains($code, $this->codes($text));
    }

    /** @return iterable<string, array{string}> */
    public static function notFlagged(): iterable
    {
        yield '只提到 token 前綴' => ['classic token 是 `ghp_` 開頭，fine-grained 是 `github_pat_` 開頭'];
        yield '太短的 ghp_' => ['ghp_abc123'];
        yield '前綴前面還有字' => ['xghp_'.str_repeat('a', 36)];
        yield 'AKIA 後面不足 16 字' => ['AKIA1234'];
        yield 'AKIA 後面太長' => ['AKIA'.str_repeat('Q', 20)];
        yield '公鑰' => ['-----BEGIN PUBLIC KEY-----'];
        yield '文件範例 email' => ['寄到 someone@example.com 或 a@example.org'];
        yield '一般路徑' => ['/home/runner/work 與 /tmp/cache'];
        yield '沒有 TLD 的 @' => ['@mention 與 user@localhost'];
    }

    #[DataProvider('notFlagged')]
    #[TestDox('只是提到格式、或是範例值時不列警告')]
    public function test_harmless_text_is_not_flagged(string $text): void
    {
        $this->assertSame([], $this->codes($text));
    }

    #[TestDox('警告帶行號，不帶比對到的文字')]
    public function test_warning_has_line_number_but_not_the_matched_text(): void
    {
        $token = 'ghp_'.str_repeat('Ab1', 12);
        $warnings = (new PublishSafetyScanner)->scan("第一行\n第二行\n{$token}\n", 10);

        $this->assertSame([['code' => 'github_token', 'message' => '疑似 GitHub token', 'line' => 12]], $warnings);
        $this->assertStringNotContainsString($token, json_encode($warnings));
    }

    #[TestDox('同一行同一種只報一次，總數有上限')]
    public function test_duplicates_on_a_line_are_merged_and_total_is_capped(): void
    {
        $scanner = new PublishSafetyScanner;

        $this->assertCount(1, $scanner->scan('a@b.io c@d.io'));
        $this->assertCount(PublishSafetyScanner::MAX_WARNINGS, $scanner->scan(str_repeat("x@y.io\n", 80)));
    }

    #[TestDox('沒有行號的文字（標題）回報 line 為 null')]
    public function test_text_without_line_numbers_reports_null(): void
    {
        $this->assertSame([['code' => 'email', 'message' => 'email 地址', 'line' => null]], (new PublishSafetyScanner)->scan('a@b.io', null));
    }
}
