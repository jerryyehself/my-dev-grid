<?php

namespace App\Http\Controllers\Auth\Concerns;

use App\Http\RefreshTokenCookie;
use App\Models\User;
use D076\SanctumRefreshTokens\DTOs\TokensDTO;
use D076\SanctumRefreshTokens\Services\ITokenService;
use Illuminate\Http\JsonResponse;

/**
 * my-dev-grid-front 的「發一組 access＋refresh token、回 JSON＋設 cookie」，
 * 帳密登入（TokenLoginController）跟 OAuth 換發（TokenRefreshController::session）
 * 共用同一份，回應形狀只定義在這裡。
 */
trait IssuesFrontendTokens
{
    protected function issueTokenPair(User $user): TokensDTO
    {
        // 壽命明確從 config 帶進去，不吃套件 createTokens() 的預設值——
        // 套件預設的 refresh 壽命走 `_no_remember`（1 天），見 config/sanctum.php。
        return app(ITokenService::class, ['user' => $user])->createTokens(
            accessTokenExpiresAt: now()->addMinutes((int) config('sanctum.expiration')),
            refreshTokenExpiresAt: now()->addMinutes((int) config('sanctum.refresh_token_expiration')),
        );
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
