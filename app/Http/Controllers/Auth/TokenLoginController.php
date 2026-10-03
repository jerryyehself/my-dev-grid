<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Auth\Concerns\IssuesFrontendTokens;
use App\Http\Controllers\Controller;
use App\Http\RefreshTokenCookie;
use App\Service\RefreshTokenFamilies;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * my-dev-grid-front（跨 origin SPA）的 email+password 登入/登出，發 Sanctum
 * API token，不是 session（decision-register.md D-56）。跟
 * SessionAuthController 是同一組 v1 需求（D-34）的兩個獨立實作：
 * SessionAuthController 服務同源的 Triple 後台（session cookie），這支服務
 * 跨 origin 的 my-dev-grid-front（bearer token）。刻意不共用同一支
 * controller——Triple 還沒被移除（D-48 卡在本體論 CRUD 搬到前端之前），
 * 改動它現有的登入方式沒有任何好處，純粹增加讓它跟著壞掉的風險。
 *
 * 2026-10-03 起登入改發「短效 access token＋長效 refresh token」一組
 * （d076/sanctum-refresh-tokens）：access token 照舊放在 JSON 的 `token`，
 * refresh token 放在 Partitioned httpOnly cookie（見 RefreshTokenCookie），
 * 前端整頁重新整理後用它換回登入狀態（TokenRefreshController::refresh）。
 * JSON 形狀只多一個 `expires_in`，舊版前端照樣讀得懂。
 */
class TokenLoginController extends Controller
{
    use IssuesFrontendTokens;

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Auth::once() 只驗證這次 request、完全不碰 session——token 模式
        // 不該像 SessionAuthController 那樣呼叫 session()->regenerate()，
        // 這裡本來就不该有 session 產生。
        if (! Auth::once($credentials)) {
            throw ValidationException::withMessages([
                'email' => ['帳號或密碼錯誤。'],
            ]);
        }

        return $this->tokenPairResponse($this->issueTokenPair(Auth::user()));
    }

    /**
     * 登出：不要求有效的 access token（不掛 auth:sanctum），bearer 跟 refresh cookie
     * 任一個能證明身分就撤銷它所屬的整個 refresh token 家族，最後一律叫瀏覽器刪 cookie。
     *
     * 為什麼不要求 bearer：access token 只活 15 分鐘，閒置之後按登出，
     * 以前會先被 401 擋掉，前端得先換發再登出，中間任何一步失敗 cookie 就留著，
     * 重新整理又自動登入回來。refresh cookie 本身就是這次登入的憑證，拿得到它的人
     * 本來就能換出 token，讓它也能撤銷自己的家族不會多給任何權限。
     * Origin 檢查（frontend.origin）照舊，跨站請求打不到這裡。
     *
     * 兩個都沒有或都無效時照樣回 200（登出是冪等的）、清 cookie。
     * Triple 走 session 的請求拿到的是 TransientToken，沒有資料庫裡的 token 可撤。
     */
    public function logout(Request $request, RefreshTokenFamilies $families): JsonResponse
    {
        $current = $request->user('sanctum')?->currentAccessToken();

        $families->revokeForLogout(
            RefreshTokenCookie::read($request),
            $current instanceof PersonalAccessToken ? $current : null,
        );

        return response()
            ->json(['message' => '已登出。'])
            ->withCookie(RefreshTokenCookie::forget());
    }
}
