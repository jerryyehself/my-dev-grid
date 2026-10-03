<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Auth\Concerns\IssuesFrontendTokens;
use App\Http\Controllers\Controller;
use App\Http\RefreshTokenCookie;
use D076\SanctumRefreshTokens\Models\PersonalRefreshToken;
use D076\SanctumRefreshTokens\Services\IAuthService;
use D076\SanctumRefreshTokens\Services\ITokenService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * my-dev-grid-front 的 refresh token 端點（「重新整理後維持登入」）。
 *
 * - refresh：用 cookie 裡的 refresh token 換一組新的 access＋refresh token。
 * - session：OAuth 登入完成後，把回呼帶回來的短效 token 換成一組正式的
 *   access＋refresh token，並在這次回應設定 refresh cookie。
 *
 * 為什麼 OAuth 回呼不直接設 cookie、要多一支 session（CHIPS 的陷阱）：
 * refresh cookie 是 Partitioned，存進哪個分區由「設定當下的頂層網站」決定。
 * OAuth 回呼（/auth/token/{provider}/callback）是使用者被 Google／LINE 導回
 * run.app 的「頂層導覽」，那時頂層網站是 run.app，cookie 會存進 run.app 自己
 * 的分區；之後在 jerrylib.com 頁面裡發的 fetch 讀的是 jerrylib.com 分區，
 * 永遠拿不到它。所以回呼照舊用 `#token=` 把短效 token 交給前端，由前端在
 * jerrylib.com 頁面裡 fetch 這支 session，cookie 才會落在正確的分區。
 */
class TokenRefreshController extends Controller
{
    use IssuesFrontendTokens;

    /**
     * 不帶 Authorization header，只看 refresh cookie。
     * 套件的 refresh() 是單次使用：舊的 refresh token（連同綁定的 access token）
     * 刪掉後才發新的一組，重放舊值會被拒。
     */
    public function refresh(Request $request, IAuthService $auth): JsonResponse
    {
        $value = RefreshTokenCookie::read($request);

        if ($value === null) {
            return $this->rejectRefresh();
        }

        try {
            $tokens = DB::transaction(function () use ($auth, $value) {
                // 先鎖住這筆 refresh token 的資料列。套件的 refresh() 是「先查、
                // 再刪、再發新的」，沒有鎖的話兩個同時送來的請求（例如兩個分頁同時
                // 重新整理）可能都查得到同一支、各換出一組，單次使用就破功了。
                // 鎖住之後，第二個請求要等第一個 commit，那時這筆已經被刪，查不到 → 401。
                // （pgsql 是真的列鎖；sqlite 會忽略 FOR UPDATE，但它本來就整個資料庫序列化寫入。）
                PersonalRefreshToken::query()
                    ->whereKey(RefreshTokenCookie::idOf($value))
                    ->lockForUpdate()
                    ->first();

                return $auth->refresh($value);
            });
        } catch (AuthenticationException) {
            return $this->rejectRefresh();
        }

        return $this->tokenPairResponse($tokens);
    }

    /**
     * 只接受 OAuth 回呼發的短效 token（名稱是 TokenSocialAuthController::CALLBACK_TOKEN_NAME），
     * 一般 access token 不能拿來換 refresh token——不然偷到一支 15 分鐘的 access
     * token，就能用 curl 自己填 Origin 打這支，換成 30 天的 refresh token。
     * 換發時把回呼那支 token 撤銷，回呼 token 只能用一次。
     */
    public function session(Request $request): JsonResponse
    {
        $user = $request->user();
        $current = $user->currentAccessToken();

        if (! $current instanceof PersonalAccessToken
            || $current->name !== TokenSocialAuthController::CALLBACK_TOKEN_NAME) {
            return response()->json(['message' => '這個 token 不能用來建立登入狀態。'], 403);
        }

        $tokens = DB::transaction(function () use ($user) {
            app(ITokenService::class, ['user' => $user])->deleteCurrentTokens();

            return $this->issueTokenPair($user);
        });

        return $this->tokenPairResponse($tokens);
    }

    private function rejectRefresh(): JsonResponse
    {
        return response()
            ->json(['message' => '登入已過期，請重新登入。'], 401)
            ->withCookie(RefreshTokenCookie::forget());
    }
}
