<?php

namespace Tests\Concerns;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;

/**
 * 收集測試期間寫出的日誌（MessageLogged 事件），用來檢查安全事件（App\Logging\SecurityLog）。
 * 不用 Log::spy()：spy 會把日誌整個吃掉，也很難同時檢查「某個值沒有出現在任何一行」。
 */
trait CapturesSecurityEvents
{
    /** @var list<MessageLogged> */
    private array $capturedLogs = [];

    private bool $listeningToLogs = false;

    /**
     * 從這裡開始收集（之前收到的丟掉）。準備測試資料之後再呼叫，只看要測的那個請求。
     */
    protected function captureLogs(): void
    {
        $this->capturedLogs = [];

        if (! $this->listeningToLogs) {
            $this->listeningToLogs = true;
            Log::listen(function (MessageLogged $message) {
                $this->capturedLogs[] = $message;
            });
        }
    }

    /**
     * @return list<MessageLogged>
     */
    protected function securityEvents(string $event): array
    {
        return array_values(array_filter(
            $this->capturedLogs,
            fn (MessageLogged $m) => ($m->context['event'] ?? null) === $event,
        ));
    }

    /**
     * 剛好一筆這個事件，context 包含 $expected 的每一個鍵值；回傳那一筆的 context。
     *
     * @param  array<string, mixed>  $expected
     * @return array<string, mixed>
     */
    protected function assertSecurityEvent(string $event, array $expected = [], ?string $level = null): array
    {
        $found = $this->securityEvents($event);
        $this->assertCount(1, $found, "預期剛好一筆 {$event}，實際 ".count($found).' 筆。全部日誌：'.$this->dumpLogs());

        $context = $found[0]->context;
        foreach ($expected as $key => $value) {
            $this->assertArrayHasKey($key, $context, "{$event} 少了 {$key}");
            $this->assertSame($value, $context[$key], "{$event} 的 {$key}");
        }
        if ($level !== null) {
            $this->assertSame($level, $found[0]->level, "{$event} 的等級");
        }

        return $context;
    }

    protected function assertNoSecurityEvent(string $event): void
    {
        $this->assertCount(0, $this->securityEvents($event), "不該有 {$event}。全部日誌：".$this->dumpLogs());
    }

    /**
     * 這個字串沒有出現在任何一行日誌（訊息或 context）裡。
     */
    protected function assertNotLogged(string $needle): void
    {
        $this->assertNotSame('', $needle);
        $this->assertStringNotContainsString($needle, $this->dumpLogs());
    }

    private function dumpLogs(): string
    {
        return json_encode(array_map(
            fn (MessageLogged $m) => [$m->level, $m->message, $m->context],
            $this->capturedLogs,
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '';
    }
}
