<?php

namespace App\Logging;

use DateTimeZone;
use Monolog\Formatter\JsonFormatter;
use Monolog\LogRecord;
use Throwable;

/**
 * 把一筆日誌格式化成 Cloud Run 的 Cloud Logging 看得懂的單行 JSON。
 *
 * Cloud Run 會收容器 stdout/stderr；一行是序列化 JSON 就會被解析進 `jsonPayload`，
 * 其中幾個特殊欄位會被抽出來填進 LogEntry 對應的欄位：
 *
 * - `severity`                        → LogEntry.severity（DEBUG … EMERGENCY）
 * - `message`                         → 日誌列表上顯示的那一行文字
 * - `time`                            → 時間戳
 * - `httpRequest`                     → LogEntry.httpRequest
 * - `logging.googleapis.com/trace`    → `projects/PROJECT_ID/traces/TRACE_ID`，
 *                                       讓這行日誌歸到該請求的 request log 底下
 * - `logging.googleapis.com/trace_sampled`
 *
 * 其餘欄位（`request_id`、`context`、`extra`）留在 `jsonPayload`，可以用
 * `jsonPayload.request_id="..."` 篩選。
 *
 * 例外：Error Reporting 會自動收「severity 為 ERROR 以上、而且 message 內含
 * 支援語言格式堆疊」的日誌。PHP 的格式是以 `PHP Fatal error: ` 開頭、
 * 後面接 `(string) $exception`，所以只要 context 裡帶 `exception`（Laravel 的
 * 例外處理器就是這樣記錄未處理例外的），message 就整個換成這個格式。
 *
 * 文件出處與完整引述見 docs/observability.md。
 *
 * 一定要維持「一筆日誌一行」：換行都在 JSON 字串裡被跳脫成 `\n`。
 * message 超過 $maxMessageBytes（位元組）會被截斷：JSON 跳脫最壞把每個位元組變兩倍，
 * 這樣整行仍在 php-fpm 的 `log_limit`（65536，見 Dockerfile）之內，否則 php-fpm 會把
 * 一行拆成多行、JSON 就壞了。
 */
class CloudLoggingFormatter extends JsonFormatter
{
    /** RequestLogProcessor／AssignRequestId 放進 extra、要被抬到頂層的 key */
    private const REQUEST_KEYS = ['request_id', 'trace_id', 'trace_sampled', 'http_request'];

    public function __construct(
        private readonly ?string $projectId = null,
        private readonly int $maxMessageBytes = 30000,
    ) {
        parent::__construct(self::BATCH_MODE_JSON, true);
    }

    public function format(LogRecord $record): string
    {
        $context = $record->context;
        $extra = $record->extra;

        $exception = $context['exception'] ?? null;
        if ($exception instanceof Throwable) {
            unset($context['exception']);
            $message = self::phpErrorMessage($exception);
        } else {
            $message = $record->message;
        }

        $entry = [
            'severity' => strtoupper($record->level->name),
            'time' => $record->datetime->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
            'message' => $this->truncate($message),
        ];

        // 例外的 message 是堆疊；原本呼叫端給的文字（例如「GitService::get_repos failed」）
        // 跟例外本身的訊息不同時另外留著，不要被蓋掉。
        if ($exception instanceof Throwable && $record->message !== '' && $record->message !== $exception->getMessage()) {
            $entry['log_message'] = $record->message;
        }

        $requestId = $extra['request_id'] ?? $context['request_id'] ?? null;
        if ($requestId !== null) {
            $entry['request_id'] = $requestId;
        }

        $traceId = $extra['trace_id'] ?? null;
        if ($traceId !== null && $this->projectId !== null && $this->projectId !== '') {
            $entry['logging.googleapis.com/trace'] = 'projects/'.$this->projectId.'/traces/'.$traceId;
            if (($extra['trace_sampled'] ?? null) !== null) {
                $entry['logging.googleapis.com/trace_sampled'] = (bool) $extra['trace_sampled'];
            }
        }

        if (! empty($extra['http_request'])) {
            $entry['httpRequest'] = $extra['http_request'];
        }

        unset($context['request_id']);
        foreach (self::REQUEST_KEYS as $key) {
            unset($extra[$key]);
        }

        if ($context !== []) {
            $entry['context'] = $context;
        }
        if ($extra !== []) {
            $entry['extra'] = $extra;
        }

        return $this->toJson($this->normalize($entry), true)."\n";
    }

    /**
     * Error Reporting 認得的 PHP 堆疊格式（`PHP Fatal error:  Uncaught ` 開頭，
     * 接 `(string) $exception`，跟 PHP 自己在 uncaught exception 時印出來的一樣）。
     */
    private static function phpErrorMessage(Throwable $e): string
    {
        return sprintf(
            "PHP Fatal error:  Uncaught %s\n  thrown in %s on line %d",
            $e,
            $e->getFile(),
            $e->getLine(),
        );
    }

    private function truncate(string $message): string
    {
        if (strlen($message) <= $this->maxMessageBytes) {
            return $message;
        }

        // mb_strcut 以位元組計算、但不會把一個多位元組字元切成兩半。
        return mb_strcut($message, 0, $this->maxMessageBytes, 'UTF-8')."\n... [truncated]";
    }
}
