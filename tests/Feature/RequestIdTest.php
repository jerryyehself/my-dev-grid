<?php

namespace Tests\Feature;

use App\Http\Middleware\AssignRequestId;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;
use Tests\TestCase;

/**
 * request id middleware 與 `cloud_run` 日誌 channel 的端到端行為：
 * 回應帶 X-Request-Id，同一個請求的每一行日誌（包含未處理例外）都帶同一個 id。
 *
 * 日誌寫到暫存檔再讀回來，驗的是 Laravel 真的走完 channel → processor → formatter 的結果，
 * 而不是各元件各自的單元行為。
 */
class RequestIdTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logFile = tempnam(sys_get_temp_dir(), 'cloud-run-log-');

        config([
            'logging.default' => 'cloud_run',
            'logging.channels.cloud_run.handler_with.stream' => $this->logFile,
            'logging.channels.cloud_run.formatter_with.projectId' => 'test-project',
            'logging.channels.cloud_run.level' => 'debug',
        ]);

        Route::get('/_test/log', function () {
            Log::info('first line');
            Log::warning('second line', ['foo' => 'bar']);

            return response()->json(['ok' => true]);
        });
        Route::get('/_test/boom', function () {
            throw new RuntimeException('kaboom');
        });
    }

    protected function tearDown(): void
    {
        @unlink($this->logFile);

        parent::tearDown();
    }

    /** @return list<array<string, mixed>> */
    private function loggedEntries(): array
    {
        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($this->logFile))));

        return array_map(fn (string $line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), $lines);
    }

    #[TestDox('回應會帶產生的 X-Request-Id')]
    public function test_response_carries_a_generated_request_id(): void
    {
        $response = $this->getJson('/up');

        $id = $response->headers->get('X-Request-Id');
        $this->assertNotNull($id);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $id);
    }

    #[TestDox('前端網域的 CORS 回應會公開 X-Request-Id')]
    public function test_cors_response_from_the_frontend_origin_exposes_the_request_id_header(): void
    {
        // 跨 origin 的 fetch 只讀得到 CORS-safelisted 標頭；X-Request-Id 要列在
        // Access-Control-Expose-Headers，前端 JS 才拿得到（D-128）。
        // allowed_origins 讀 env('FRONTEND_URL')；沒有 .env 的環境（例如乾淨 checkout）是空的，
        // 這裡明確指定，測試才不依賴 .env。
        $origin = 'http://localhost:5173';
        config(['cors.allowed_origins' => [$origin]]);
        Route::get('/api/_test/ping', fn () => response()->json(['ok' => true]));

        $response = $this->getJson('/api/_test/ping', ['Origin' => $origin]);

        $response->assertHeader('Access-Control-Allow-Origin', $origin);
        $response->assertHeader('Access-Control-Expose-Headers', 'X-Request-Id');
        $response->assertHeader('X-Request-Id');
    }

    #[TestDox('每個請求產生不同的 request id')]
    public function test_each_request_gets_a_different_generated_id(): void
    {
        $first = $this->getJson('/up')->headers->get('X-Request-Id');
        $second = $this->getJson('/up')->headers->get('X-Request-Id');

        $this->assertNotSame($first, $second);
    }

    #[TestDox('合法的傳入 request id 會原樣回傳')]
    public function test_valid_incoming_request_id_is_echoed_back(): void
    {
        $this->getJson('/up', ['X-Request-Id' => 'frontend-abc_123.4'])
            ->assertHeader('X-Request-Id', 'frontend-abc_123.4');
    }

    #[DataProvider('invalidRequestIdProvider')]
    #[TestDox('不合法的傳入 request id 會被取代')]
    public function test_invalid_incoming_request_id_is_replaced(string $incoming): void
    {
        $id = $this->getJson('/up', ['X-Request-Id' => $incoming])->headers->get('X-Request-Id');

        $this->assertNotSame($incoming, $id);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $id);
    }

    public static function invalidRequestIdProvider(): array
    {
        return [
            'spaces' => ['has space'],
            'json fragment' => ['{"a":1}'],
            'too long' => [str_repeat('a', 129)],
            'non ascii' => ['請求編號'],
        ];
    }

    #[TestDox('沒有 request id 標頭時改用 Cloud Trace 標頭')]
    public function test_cloud_trace_header_is_used_when_there_is_no_request_id_header(): void
    {
        $traceId = '105445aa7843bc8bf206b12000100000';

        $this->getJson('/up', ['X-Cloud-Trace-Context' => "{$traceId}/1;o=1"])
            ->assertHeader('X-Request-Id', $traceId);
    }

    #[TestDox('request id 標頭優先於 trace 標頭')]
    public function test_request_id_header_wins_over_trace_header(): void
    {
        $this->getJson('/up', [
            'X-Request-Id' => 'mine',
            'X-Cloud-Trace-Context' => '105445aa7843bc8bf206b12000100000/1;o=1',
        ])->assertHeader('X-Request-Id', 'mine');
    }

    #[TestDox('格式錯誤的 trace 標頭會被忽略')]
    public function test_malformed_trace_header_is_ignored(): void
    {
        $id = $this->getJson('/up', ['X-Cloud-Trace-Context' => 'not-a-trace'])->headers->get('X-Request-Id');

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $id);
    }

    #[TestDox('路由層的錯誤回應也帶 request id')]
    public function test_router_level_errors_also_carry_the_header(): void
    {
        // 沒有任何 POST 路由吃得到這個路徑：路由器在全域 middleware 管線內丟 405，
        // 確認最外層的 middleware 連這種請求都有包到。
        $this->postJson('/_test/log')
            ->assertStatus(405)
            ->assertHeader('X-Request-Id');
    }

    #[TestDox('同一請求的每行日誌帶相同 request id 與 trace')]
    public function test_every_log_line_of_a_request_has_the_same_request_id_and_trace(): void
    {
        $traceId = '105445aa7843bc8bf206b12000100000';

        $response = $this->getJson('/_test/log', ['X-Cloud-Trace-Context' => "{$traceId}/1;o=1"]);
        $response->assertOk();

        $entries = array_values(array_filter(
            $this->loggedEntries(),
            fn (array $e) => in_array($e['message'], ['first line', 'second line'], true),
        ));
        $this->assertCount(2, $entries);

        foreach ($entries as $entry) {
            $this->assertSame($traceId, $entry['request_id']);
            $this->assertSame("projects/test-project/traces/{$traceId}", $entry['logging.googleapis.com/trace']);
            $this->assertTrue($entry['logging.googleapis.com/trace_sampled']);
            // 非錯誤等級不重複帶 httpRequest（Cloud Run 本來就會寫 request log）。
            $this->assertArrayNotHasKey('httpRequest', $entry);
        }
        $this->assertSame('INFO', $entries[0]['severity']);
        $this->assertSame('WARNING', $entries[1]['severity']);
        $this->assertSame(['foo' => 'bar'], $entries[1]['context']);
    }

    #[TestDox('不同請求的日誌各帶自己的 request id')]
    public function test_log_lines_of_different_requests_carry_their_own_ids(): void
    {
        $this->getJson('/_test/log', ['X-Request-Id' => 'req-one'])->assertOk();
        $this->getJson('/_test/log', ['X-Request-Id' => 'req-two'])->assertOk();

        $ids = array_column($this->loggedEntries(), 'request_id');

        $this->assertSame(['req-one', 'req-one', 'req-two', 'req-two'], $ids);
    }

    #[TestDox('未處理例外記成帶 request id 的 ERROR 與堆疊')]
    public function test_unhandled_exception_is_logged_as_error_reporting_stack_trace_with_request_id(): void
    {
        $response = $this->getJson('/_test/boom', ['X-Request-Id' => 'req-boom', 'User-Agent' => 'phpunit-ua']);

        $response->assertStatus(500);
        $response->assertHeader('X-Request-Id', 'req-boom');

        $errors = array_values(array_filter($this->loggedEntries(), fn (array $e) => $e['severity'] === 'ERROR'));
        $this->assertCount(1, $errors);
        $entry = $errors[0];

        $this->assertSame('req-boom', $entry['request_id']);
        $this->assertStringStartsWith('PHP Fatal error:  Uncaught RuntimeException: kaboom', $entry['message']);
        $this->assertStringContainsString('Stack trace:', $entry['message']);
        $this->assertStringContainsString('RequestIdTest.php', $entry['message']);

        $this->assertSame('GET', $entry['httpRequest']['requestMethod']);
        $this->assertStringEndsWith('/_test/boom', $entry['httpRequest']['requestUrl']);
        $this->assertSame('phpunit-ua', $entry['httpRequest']['userAgent']);
    }

    #[TestDox('日誌中的請求網址不含查詢字串')]
    public function test_http_request_url_never_includes_the_query_string(): void
    {
        $this->getJson('/_test/boom?code=secret-oauth-code&state=abc');

        $entry = collect($this->loggedEntries())->firstWhere('severity', 'ERROR');

        $this->assertStringNotContainsString('secret-oauth-code', json_encode($entry));
        $this->assertStringNotContainsString('?', $entry['httpRequest']['requestUrl']);
    }

    #[TestDox('請求以外的日誌不帶請求欄位')]
    public function test_log_lines_outside_a_request_have_no_request_fields(): void
    {
        Log::info('from a command');

        $entry = collect($this->loggedEntries())->firstWhere('message', 'from a command');

        $this->assertArrayNotHasKey('request_id', $entry);
        $this->assertArrayNotHasKey('httpRequest', $entry);
    }

    #[TestDox('可正確解析 Cloud Trace 標頭')]
    public function test_parse_trace_header(): void
    {
        $id = '105445aa7843bc8bf206b12000100000';

        $this->assertSame([$id, true], AssignRequestId::parseTraceHeader("{$id}/123;o=1"));
        $this->assertSame([$id, false], AssignRequestId::parseTraceHeader("{$id}/123;o=0"));
        $this->assertSame([$id, null], AssignRequestId::parseTraceHeader("{$id}/123"));
        $this->assertSame([$id, null], AssignRequestId::parseTraceHeader($id));
        $this->assertSame([null, null], AssignRequestId::parseTraceHeader(''));
        $this->assertSame([null, null], AssignRequestId::parseTraceHeader('zzz/1;o=1'));
    }

    #[TestDox('本機預設日誌不是 JSON 通道')]
    public function test_local_default_logging_is_not_the_json_channel(): void
    {
        // setUp 為了其他測試把 default 換成 cloud_run；這裡確認的是「出廠設定」：
        // .env.example 的 LOG_CHANNEL 不是 cloud_run，本機日誌維持人看得懂的格式。
        $env = (string) file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression('/^LOG_CHANNEL=stack$/m', $env);
        $this->assertDoesNotMatchRegularExpression('/^LOG_CHANNEL=cloud_run$/m', $env);
    }
}
