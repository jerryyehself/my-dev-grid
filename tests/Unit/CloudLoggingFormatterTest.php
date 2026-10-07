<?php

namespace Tests\Unit;

use App\Logging\CloudLoggingFormatter;
use DateTimeImmutable;
use DateTimeZone;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * 釘住 CloudLoggingFormatter 輸出的「形狀」：Cloud Logging 靠這些欄位名稱把 JSON
 * 抽進 LogEntry（severity、httpRequest、logging.googleapis.com/trace），
 * Error Reporting 靠 message 裡的 PHP 堆疊格式收例外。欄位名稱改了，
 * 日誌還是印得出來，但篩選、trace 關聯、錯誤收集會無聲失效，所以要測。
 */
class CloudLoggingFormatterTest extends TestCase
{
    private function record(
        string $message = 'hello',
        Level $level = Level::Info,
        array $context = [],
        array $extra = [],
    ): LogRecord {
        return new LogRecord(
            datetime: new DateTimeImmutable('2026-10-07 12:34:56.789', new DateTimeZone('Asia/Taipei')),
            channel: 'testing',
            level: $level,
            message: $message,
            context: $context,
            extra: $extra,
        );
    }

    /** @return array<string, mixed> */
    private function decode(string $line): array
    {
        $this->assertStringEndsWith("\n", $line);
        $this->assertSame(1, substr_count($line, "\n"), '一筆日誌必須剛好一行');

        $decoded = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    public function test_basic_shape_has_severity_message_and_utc_time(): void
    {
        $out = $this->decode((new CloudLoggingFormatter)->format($this->record('hello')));

        $this->assertSame('INFO', $out['severity']);
        $this->assertSame('hello', $out['message']);
        // 輸入是 Asia/Taipei 12:34:56.789，輸出要換成 UTC。
        $this->assertSame('2026-10-07T04:34:56.789Z', $out['time']);
        $this->assertArrayNotHasKey('context', $out);
        $this->assertArrayNotHasKey('extra', $out);
        $this->assertArrayNotHasKey('httpRequest', $out);
        $this->assertArrayNotHasKey('logging.googleapis.com/trace', $out);
    }

    #[DataProvider('severityProvider')]
    public function test_severity_uses_cloud_logging_names(Level $level, string $expected): void
    {
        $out = $this->decode((new CloudLoggingFormatter)->format($this->record(level: $level)));

        $this->assertSame($expected, $out['severity']);
    }

    public static function severityProvider(): array
    {
        return [
            'debug' => [Level::Debug, 'DEBUG'],
            'info' => [Level::Info, 'INFO'],
            'notice' => [Level::Notice, 'NOTICE'],
            'warning' => [Level::Warning, 'WARNING'],
            'error' => [Level::Error, 'ERROR'],
            'critical' => [Level::Critical, 'CRITICAL'],
            'alert' => [Level::Alert, 'ALERT'],
            'emergency' => [Level::Emergency, 'EMERGENCY'],
        ];
    }

    public function test_request_fields_are_hoisted_and_not_duplicated_in_extra(): void
    {
        $formatter = new CloudLoggingFormatter(projectId: 'my-project');
        $traceId = str_repeat('ab', 16);

        $out = $this->decode($formatter->format($this->record(
            context: ['foo' => 'bar'],
            extra: [
                'request_id' => 'req-1',
                'trace_id' => $traceId,
                'trace_sampled' => true,
                'other' => 'x',
            ],
        )));

        $this->assertSame('req-1', $out['request_id']);
        $this->assertSame("projects/my-project/traces/{$traceId}", $out['logging.googleapis.com/trace']);
        $this->assertTrue($out['logging.googleapis.com/trace_sampled']);
        $this->assertSame(['foo' => 'bar'], $out['context']);
        $this->assertSame(['other' => 'x'], $out['extra']);
    }

    public function test_trace_fields_are_omitted_without_project_id_but_request_id_stays(): void
    {
        $out = $this->decode((new CloudLoggingFormatter)->format($this->record(
            extra: ['request_id' => 'req-1', 'trace_id' => str_repeat('ab', 16), 'trace_sampled' => true],
        )));

        $this->assertSame('req-1', $out['request_id']);
        $this->assertArrayNotHasKey('logging.googleapis.com/trace', $out);
        $this->assertArrayNotHasKey('logging.googleapis.com/trace_sampled', $out);
    }

    public function test_http_request_is_passed_through_as_log_entry_http_request(): void
    {
        $httpRequest = ['requestMethod' => 'GET', 'requestUrl' => 'https://example.test/api/x', 'remoteIp' => '203.0.113.9'];

        $out = $this->decode((new CloudLoggingFormatter)->format($this->record(
            level: Level::Error,
            extra: ['request_id' => 'req-1', 'http_request' => $httpRequest],
        )));

        $this->assertSame($httpRequest, $out['httpRequest']);
        $this->assertArrayNotHasKey('extra', $out);
    }

    public function test_exception_becomes_php_stack_trace_message_for_error_reporting(): void
    {
        $exception = new RuntimeException('boom');

        $out = $this->decode((new CloudLoggingFormatter)->format($this->record(
            message: 'boom',
            level: Level::Error,
            context: ['exception' => $exception, 'userId' => 7],
        )));

        // Error Reporting 對 PHP 的要求：以 "PHP Fatal error: " 開頭，並含 (string) $exception。
        $this->assertStringStartsWith('PHP Fatal error:  Uncaught ', $out['message']);
        $this->assertStringContainsString((string) $exception, $out['message']);
        $this->assertStringContainsString('Stack trace:', $out['message']);
        $this->assertStringContainsString('RuntimeException: boom', $out['message']);
        $this->assertSame('ERROR', $out['severity']);
        // 例外已經在 message 裡，不要在 context 重複一份；其他 context 保留。
        $this->assertSame(['userId' => 7], $out['context']);
        // 呼叫端的文字跟例外訊息相同時不用另外留。
        $this->assertArrayNotHasKey('log_message', $out);
    }

    public function test_caller_message_is_kept_when_it_differs_from_exception_message(): void
    {
        $out = $this->decode((new CloudLoggingFormatter)->format($this->record(
            message: 'GitService::get_repos failed',
            level: Level::Error,
            context: ['exception' => new RuntimeException('timeout')],
        )));

        $this->assertSame('GitService::get_repos failed', $out['log_message']);
        $this->assertStringContainsString('timeout', $out['message']);
    }

    public function test_multiline_message_stays_on_one_line_and_chinese_is_not_escaped(): void
    {
        $line = (new CloudLoggingFormatter)->format($this->record("第一行\n第二行"));

        $this->assertSame(1, substr_count($line, "\n"));
        $this->assertStringContainsString('第一行\n第二行', $line);
        $this->assertSame("第一行\n第二行", $this->decode($line)['message']);
    }

    public function test_long_message_is_truncated_and_line_stays_valid_json(): void
    {
        $formatter = new CloudLoggingFormatter(maxMessageBytes: 100);

        // 中文每字 3 位元組；100 不是 3 的倍數，確認不會把字元切一半而讓 JSON 失效。
        $out = $this->decode($formatter->format($this->record(str_repeat('字', 200))));

        $this->assertStringEndsWith('[truncated]', $out['message']);
        $this->assertLessThanOrEqual(100 + strlen("\n... [truncated]"), strlen($out['message']));
        $this->assertTrue(mb_check_encoding($out['message'], 'UTF-8'));
    }
}
