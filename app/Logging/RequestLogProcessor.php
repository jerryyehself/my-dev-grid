<?php

namespace App\Logging;

use App\Providers\AppServiceProvider;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * 把目前請求的資訊補進日誌的 `extra`，給 CloudLoggingFormatter 抬到頂層用。
 *
 * 只有請求經過 AssignRequestId（`request_id` 在 request attributes 裡）才會補；
 * 指令列、queue worker、排程沒有請求，這裡什麼都不做。
 *
 * 補的東西：
 * - `request_id`、`trace_id`、`trace_sampled`：每一行日誌都補。
 * - `http_request`（LogEntry.httpRequest 格式）：只在 ERROR 以上補。Cloud Run 本來就
 *   會替每個請求自動寫一筆帶 httpRequest 的 request log，每一行應用程式日誌都再帶
 *   一份只會讓列表變得很吵；出錯時才需要知道「是哪個請求出的錯」。
 *   網址不含 query string（OAuth 的 `code`、`state` 之類會放在那裡），也不記請求內容。
 */
class RequestLogProcessor implements ProcessorInterface
{
    public function __construct(private readonly Container $app) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        $request = $this->currentRequest();
        if ($request === null) {
            return $record;
        }

        $extra = $record->extra;
        $extra['request_id'] = $request->attributes->get('request_id');
        $extra['trace_id'] = $request->attributes->get('trace_id');
        $extra['trace_sampled'] = $request->attributes->get('trace_sampled');

        if ($record->level->isHigherThan(Level::Warning)) {
            $extra['http_request'] = array_filter([
                'requestMethod' => $request->getMethod(),
                'requestUrl' => $request->url(),
                'userAgent' => $request->userAgent(),
                'remoteIp' => AppServiceProvider::clientIp($request),
                'referer' => $request->headers->get('Referer'),
                'protocol' => $request->server('SERVER_PROTOCOL'),
            ], fn ($value) => $value !== null && $value !== '');
        }

        return $record->with(extra: $extra);
    }

    private function currentRequest(): ?Request
    {
        if (! $this->app->bound('request')) {
            return null;
        }

        $request = $this->app->make('request');

        return $request instanceof Request && $request->attributes->has('request_id') ? $request : null;
    }
}
