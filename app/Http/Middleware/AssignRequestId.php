<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * 給每個請求一個 request id，讓同一個請求的所有日誌可以用同一個值篩出來。
 *
 * id 的來源，依序：
 * 1. 請求帶的 `X-Request-Id`（前端或呼叫端自己給的）。這個值是用戶端可以亂填的，
 *    所以只接受 1～128 個 `A-Za-z0-9._-`；不合格的丟掉，避免有人塞換行、
 *    超長字串或 JSON 片段進日誌。
 * 2. Cloud Run 前端加的 `X-Cloud-Trace-Context: TRACE_ID/SPAN_ID;o=TRACE_TRUE`
 *    裡的 TRACE_ID（32 位十六進位）。這樣 request id 剛好等於 Cloud Logging
 *    自己的 trace id，兩邊可以互查。
 * 3. 都沒有就產生一個 UUID（本機、測試、直連）。
 *
 * 做三件事：
 * - 存到 `$request->attributes`（`request_id`、`trace_id`、`trace_sampled`），
 *   `App\Logging\RequestLogProcessor` 靠它把 trace 與 httpRequest 補進結構化日誌。
 * - 寫進 `Context`，Laravel 會自動把它放進這個請求的每一行日誌（任何 channel），
 *   也會帶進這個請求派出去的 queue job。
 * - 回應帶 `X-Request-Id`，使用者回報問題時可以直接拿這個值去查。
 *
 * 在 bootstrap/app.php 用 prepend 掛成最外層的全域 middleware，連被 throttle
 * 擋掉（429）或 404 的請求也有 id。
 */
class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    public const TRACE_HEADER = 'X-Cloud-Trace-Context';

    public function handle(Request $request, Closure $next): Response
    {
        [$traceId, $traceSampled] = self::parseTraceHeader((string) $request->headers->get(self::TRACE_HEADER, ''));

        $requestId = self::validRequestId((string) $request->headers->get(self::HEADER, ''))
            ?? $traceId
            ?? (string) Str::uuid();

        $request->attributes->set('request_id', $requestId);
        $request->attributes->set('trace_id', $traceId);
        $request->attributes->set('trace_sampled', $traceSampled);

        Context::add('request_id', $requestId);

        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }

    /**
     * @return array{0: ?string, 1: ?bool} [trace id, 是否取樣]；header 缺或格式不對時都是 null
     */
    public static function parseTraceHeader(string $header): array
    {
        if (preg_match('#^([0-9a-f]{32})(?:/\d+)?(?:;o=([01]))?$#i', trim($header), $m) !== 1) {
            return [null, null];
        }

        return [strtolower($m[1]), isset($m[2]) ? $m[2] === '1' : null];
    }

    private static function validRequestId(string $value): ?string
    {
        return preg_match('/^[A-Za-z0-9._-]{1,128}$/', $value) === 1 ? $value : null;
    }
}
