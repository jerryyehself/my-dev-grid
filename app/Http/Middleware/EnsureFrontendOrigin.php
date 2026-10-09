<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 只放行 `Origin` header 等於 FRONTEND_URL 的請求，其他一律 403。
 *
 * 掛在會讀寫 refresh token cookie 的端點（/api/auth/refresh、/api/auth/session、
 * /api/auth/logout）上，是縱深防禦，不是唯一一道防線：refresh cookie 是
 * Partitioned（CHIPS），只有頂層網站是 jerrylib.com 時瀏覽器才會送它，
 * 別的網站發出的跨站請求本來就帶不到。這層再把「不是從前端網站發出的瀏覽器
 * 請求」擋在 controller 之外。
 *
 * 跨 origin 的 fetch（前端一定是跨 origin）瀏覽器一定會帶 Origin，而且頁面
 * 的 JS 改不了它；沒帶 Origin 的請求（curl 之類）同樣擋掉——這幾支本來就只給
 * 瀏覽器裡的前端用。注意 Origin 是非瀏覽器用戶端可以自己填的，這層擋不了
 * 偷到 token 的人直接用 curl 打，那部分靠 token 本身的設計（見各 controller）。
 */
class EnsureFrontendOrigin
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = rtrim((string) config('app.frontend_url'), '/');
        $origin = (string) $request->headers->get('Origin', '');

        if ($expected === '' || $origin !== $expected) {
            abort(403, '不允許的來源。');
        }

        return $next($request);
    }
}
