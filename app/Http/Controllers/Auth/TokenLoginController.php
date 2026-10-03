<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Auth\Concerns\IssuesFrontendTokens;
use App\Http\Controllers\Controller;
use App\Http\RefreshTokenCookie;
use D076\SanctumRefreshTokens\Models\PersonalRefreshToken;
use D076\SanctumRefreshTokens\Services\ITokenService;
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
     * 撤銷這次請求用的 access token（連同跟它同一組的 refresh token），再撤銷
     * cookie 裡那支 refresh token，最後叫瀏覽器刪掉 cookie。兩支都要撤：cookie
     * 裡的 refresh token 不一定跟目前這支 access token 同一組（例如中間換發過、
     * 或前端還拿著舊的 access token），只撤 access token 的話，cookie 留著還能換新的。
     */
    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        // Triple 走 session 的請求拿到的是 TransientToken，沒有資料庫裡的 token 可撤，
        // 這裡只處理真正的 API token。
        if ($user->currentAccessToken() instanceof PersonalAccessToken) {
            app(ITokenService::class, ['user' => $user])->deleteCurrentTokens();
        }

        if ($value = RefreshTokenCookie::read($request)) {
            $refreshToken = PersonalRefreshToken::findToken($value);

            // 只撤銷屬於這個登入者的 refresh token；不是他的就不動（只清 cookie）。
            if ($refreshToken
                && $refreshToken->tokenable_type === $user->getMorphClass()
                && (int) $refreshToken->tokenable_id === (int) $user->getKey()) {
                $refreshToken->delete();
            }
        }

        return response()
            ->json(['message' => '已登出。'])
            ->withCookie(RefreshTokenCookie::forget());
    }
}
