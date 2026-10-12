<?php

namespace App\Logging;

use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * 寫安全與稽核事件（issue #103）。走一般的 Log facade，所以正式環境（LOG_CHANNEL=cloud_run）
 * 就是 CloudLoggingFormatter 的單行 JSON，自動帶 request_id／trace；本機是一般文字日誌。
 *
 * 一筆事件長這樣：
 * - `message`：`<事件名稱> <中文說明>`，Logs Explorer 列表上一眼看得出是什麼
 * - `context.event`：事件名稱本身（SecurityEvent，OWASP Logging Vocabulary），篩選用這個
 * - `context` 其餘欄位：vocabulary 把參數寫成 `authn_login_fail:joebob1` 這種位置參數字串，
 *   這裡改成具名欄位（`user_id`、`reason`…），Cloud Logging 才能逐欄篩選
 *
 * 不記：密碼、access／refresh token 的值、OAuth `code`／`state`、session id、金鑰、請求內容。
 * 使用者識別只記內部的 `user_id`（OAuth 帳號沒綁定時另記建綁定需要的 `provider_user_id`）；
 * `source_ip` 只出現在「失敗／被擋」類事件。理由與完整的
 * 事件表見 docs/observability.md「安全與稽核事件」。
 */
final class SecurityLog
{
    /**
     * @param  array<string, mixed>  $fields  值是 null 或空陣列的欄位不輸出
     */
    public static function write(SecurityEvent $event, string $description, array $fields = []): void
    {
        $fields = array_filter($fields, fn ($value) => $value !== null && $value !== []);

        Log::log($event->level(), $event->value.' '.$description, ['event' => $event->value] + $fields);
    }

    /**
     * @param  string  $method  google、line、password
     * @param  string  $mode  session（Triple 後台）或 token（my-dev-grid-front）
     */
    public static function loginSucceeded(int|string $userId, string $method, string $mode): void
    {
        self::write(SecurityEvent::LoginSuccess, "使用者 {$userId} 以 {$method} 登入（{$mode}）", [
            'user_id' => $userId,
            'auth_method' => $method,
            'auth_mode' => $mode,
        ]);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    public static function loginFailed(string $method, string $mode, string $reason, array $fields = []): void
    {
        self::write(SecurityEvent::LoginFail, "{$method} 登入失敗（{$mode}）：{$reason}", [
            'auth_method' => $method,
            'auth_mode' => $mode,
            'reason' => $reason,
            'source_ip' => self::sourceIp(),
        ] + $fields);
    }

    /**
     * 帳密登入失敗。只記帳號存在時的 `user_id`；打錯或不存在的 email 本身不記——
     * 那可能是別人的 email，也可能是使用者把密碼打進了帳號欄。
     */
    public static function passwordLoginFailed(string $email, string $mode): void
    {
        self::loginFailed('password', $mode, 'invalid_credentials', [
            'user_id' => User::query()->where('email', $email)->value('id'),
        ]);
    }

    /**
     * OAuth callback 向 provider 取使用者資料失敗（Socialite 的 user() 丟例外）。
     * 原因只看例外的類別與 provider 導回來時帶的 `error` 參數（RFC 6749 §4.1.2.1 的
     * 錯誤代碼，例如 access_denied），不記例外訊息——裡面可能有 provider 回應的片段。
     */
    public static function oauthLoginFailed(string $provider, string $mode, Throwable $e, Request $request): void
    {
        $providerError = (string) $request->query('error', '');
        $providerError = preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $providerError) === 1 ? $providerError : null;

        $reason = match (true) {
            $e instanceof InvalidStateException => 'invalid_state',
            $providerError !== null => 'provider_denied',
            default => 'provider_error',
        };

        self::loginFailed($provider, $mode, $reason, [
            'provider_error' => $providerError,
            'error_class' => class_basename($e),
        ]);
    }

    /**
     * 例外變成 403／429 回應時記一筆（bootstrap/app.php 的 respond()）。
     * 這個 app 的 403 跟 429 都是例外產生的：Policy 的 AuthorizationException、
     * abort(403)（EnsureFrontendOrigin、TokenRefreshController::session）、
     * throttle middleware 的 ThrottleRequestsException。
     */
    public static function httpError(Response $response, Throwable $e, Request $request): void
    {
        $status = $response->getStatusCode();
        if ($status !== 403 && $status !== 429) {
            return;
        }

        $route = $request->route();
        $common = [
            'user_id' => self::authenticatedUserId(),
            'http_method' => $request->getMethod(),
            'route' => $route instanceof Route ? $route->uri() : null,
            'source_ip' => AppServiceProvider::clientIp($request),
        ];

        if ($status === 403) {
            self::write(SecurityEvent::AuthzFail, '權限不足被拒（403）', $common + [
                // 路徑不含 query string（OAuth 的 code、state 在那裡）
                'resource' => '/'.ltrim($request->path(), '/'),
                // 例外訊息是程式裡自己寫的固定字串（abort() 的第二個參數、Policy 的 deny 訊息）
                'reason' => Str::limit($e->getMessage(), 200),
            ]);

            return;
        }

        $max = $response->headers->get('X-RateLimit-Limit');
        $retryAfter = $response->headers->get('Retry-After');

        self::write(SecurityEvent::RateLimitExceeded, '超過頻率限制（429）', $common + [
            // 哪個 limiter 看上限就知道：api 60、social-login 20、token-refresh 30、login 5
            // （AppServiceProvider::configureRateLimiting）
            'max' => is_numeric($max) ? (int) $max : null,
            'retry_after' => is_numeric($retryAfter) ? (int) $retryAfter : null,
        ]);
    }

    /**
     * 這個請求「已經」驗證出來的使用者 id；還沒驗證過就是 null，不會為了記日誌
     * 另外去查 token 或 session。
     *
     * auth:sanctum 通過後會把預設 guard 切成 sanctum（Authenticate middleware 的
     * shouldUse()），所以通過驗證的請求看預設 guard 就夠；hasUser() 不會觸發查詢。
     */
    public static function authenticatedUserId(): int|string|null
    {
        $guard = Auth::guard();

        return $guard->hasUser() ? $guard->id() : null;
    }

    /**
     * 用戶端 IP，跟 rate limit 用的是同一個值（AppServiceProvider::clientIp）。
     * 沒有 HTTP 請求（指令列、queue）時是 null。
     */
    public static function sourceIp(): ?string
    {
        $app = app();
        if (! $app->bound('request')) {
            return null;
        }

        $request = $app->make('request');

        return $request instanceof Request && $request->attributes->has('request_id')
            ? AppServiceProvider::clientIp($request)
            : null;
    }
}
