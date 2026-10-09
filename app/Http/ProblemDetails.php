<?php

namespace App\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

/**
 * API 錯誤回應的格式：RFC 9457 problem details（`application/problem+json`）。
 *
 * 形狀：
 *
 *     {
 *       "type": "about:blank",
 *       "title": "Unprocessable Content",   // HTTP 狀態碼的標準名稱
 *       "status": 422,
 *       "detail": "名稱為必填。",            // 這一次錯誤的說明，跟 message 相同
 *       "instance": "/api/scopes",          // 出錯的請求路徑（不含查詢字串）
 *       "message": "名稱為必填。",           // Laravel 原本的欄位，保留
 *       "errors": { "name": ["名稱為必填。"] } // 只有 422 才有，Laravel 原本的欄位，保留
 *     }
 *
 * 向下相容：`message`、`errors` 跟原本的值完全一樣（前端與 Triple 讀的就是這兩個欄位
 * 跟狀態碼），problem details 的欄位是**加上去**的；其他自訂欄位（例如
 * RelationLockedException 的 `locked_fields`）也原樣保留，算 RFC 9457 的 extension member。
 *
 * `type` 一律用 `about:blank`：RFC 9457 §4.2.1 定義它代表「除了 HTTP 狀態碼本身
 * 以外沒有額外語意」，這時 `title` 應該就是狀態碼的標準名稱。目前沒有哪一種錯誤
 * 需要比狀態碼更細的機器可讀分類；之後真的需要時再為那一種錯誤定義自己的 type URI。
 *
 * 套用的地方在 `bootstrap/app.php` 的 `withExceptions()`：例外變成回應之後統一經過
 * `fromJsonResponse()`。
 */
class ProblemDetails
{
    public const CONTENT_TYPE = 'application/problem+json';

    /**
     * 這個請求的錯誤要不要用 JSON（problem details）回：`/api` 底下的一律要，
     * 其他路徑（`routes/web.php` 的 Triple 登入等）看用戶端有沒有要 JSON。
     *
     * `/api` 不看 Accept 的理由：前端的 GET／DELETE 沒有帶 `Accept: application/json`，
     * 只看 Accept 的話，同一支 API 查無資料時會回 Laravel 的 HTML 錯誤頁，
     * 未登入時會試著導去不存在的 `login` 路由。API 的錯誤應該不管怎麼呼叫都是同一種格式。
     */
    public static function wanted(Request $request): bool
    {
        return $request->is('api', 'api/*') || $request->expectsJson();
    }

    /**
     * 把 Laravel 產生的 JSON 錯誤回應改成 problem details。沿用同一個回應物件，
     * 狀態碼、header（例如 429 的 `Retry-After`）跟 cookie 都不會掉。
     */
    public static function fromJsonResponse(JsonResponse $response, Request $request): JsonResponse
    {
        $data = $response->getData(true);

        if (! is_array($data)) {
            $data = ['message' => (string) $data];
        }

        $response->setData(self::body($response->getStatusCode(), $request, $data));
        $response->headers->set('Content-Type', self::CONTENT_TYPE);

        return $response;
    }

    /**
     * @param  array<string, mixed>  $data  原本回應的內容（`message`、`errors` 等）
     * @return array<string, mixed>
     */
    public static function body(int $status, Request $request, array $data = []): array
    {
        $title = Response::$statusTexts[$status] ?? 'Unknown Error';
        $message = $data['message'] ?? null;
        $detail = is_string($message) && $message !== '' ? $message : $title;

        $problem = [
            'type' => 'about:blank',
            'title' => $title,
            'status' => $status,
            'detail' => $detail,
            'instance' => $request->getPathInfo(),
            // 一定要有 message：前端登入表單直接顯示它，原本有些錯誤（例如 FormRequest
            // 自訂的 422）沒有這個欄位。
            'message' => $message ?? $detail,
        ];

        return array_merge($problem, Arr::except($data, array_keys($problem)));
    }
}
