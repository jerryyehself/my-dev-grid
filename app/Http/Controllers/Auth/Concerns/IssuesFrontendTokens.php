<?php

namespace App\Http\Controllers\Auth\Concerns;

use App\Http\RefreshTokenCookie;
use App\Models\User;
use App\Service\RefreshTokenFamilies;
use D076\SanctumRefreshTokens\DTOs\TokensDTO;
use Illuminate\Http\JsonResponse;

/**
 * my-dev-grid-front 的「發一組 access＋refresh token、回 JSON＋設 cookie」，
 * 帳密登入（TokenLoginController）跟 OAuth 換發（TokenRefreshController::session）
 * 共用同一份，回應形狀只定義在這裡。
 */
trait IssuesFrontendTokens
{
    /**
     * 新的一次登入：開一個新的 refresh token 家族（見 App\Service\RefreshTokenFamilies）。
     */
    protected function issueTokenPair(User $user): TokensDTO
    {
        return app(RefreshTokenFamilies::class)->issue($user);
    }

    /**
     * JSON 維持原本帳密登入的形狀（`data`＝使用者、`token`＝access token），
     * 只多一個 `expires_in`（access token 還剩幾秒）。refresh token 不進 JSON，
     * 只放在 httpOnly cookie 裡，前端 JS 永遠碰不到它。
     */
    protected function tokenPairResponse(TokensDTO $tokens): JsonResponse
    {
        $expiresIn = $tokens->access_token_expires_at
            ? max(0, (int) round(now()->diffInSeconds($tokens->access_token_expires_at, true)))
            : null;

        return response()
            ->json([
                'data' => $tokens->user,
                'token' => $tokens->access_token,
                'expires_in' => $expiresIn,
            ])
            ->withCookie(RefreshTokenCookie::make(
                $tokens->refresh_token,
                $tokens->refresh_token_expires_at ?? now()->addMinutes((int) config('sanctum.refresh_token_expiration')),
            ));
    }
}
