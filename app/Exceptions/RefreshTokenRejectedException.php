<?php

namespace App\Exceptions;

use App\Http\RefreshTokenCookie;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * refresh token 無效、過期、已被撤銷或超過 90 天上限（TokenRefreshController::refresh）：
 * 回 401，並叫瀏覽器刪掉 refresh cookie。
 *
 * 為什麼是例外而不是 controller 直接 return 一個 401：錯誤回應要統一經過例外處理
 * （bootstrap/app.php 的 withExceptions），才會跟其他錯誤一樣是 problem details 格式。
 * 不能用 HttpResponseException——Laravel 在路由層就直接把它的回應交出去，
 * 不會經過例外處理。
 */
class RefreshTokenRejectedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('登入已過期，請重新登入。');
    }

    /**
     * 用戶端的 cookie 失效是常態（過期、登出後重整），不是伺服器故障，不寫進 error log。
     */
    public function report(): bool
    {
        return false;
    }

    public function render(Request $request): JsonResponse
    {
        return response()
            ->json(['message' => $this->getMessage()], 401)
            ->withCookie(RefreshTokenCookie::forget());
    }
}
