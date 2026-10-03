<?php

namespace App\Service;

use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;

/**
 * 兩支 OAuth controller（SocialAuthController／TokenSocialAuthController）共用的
 * Socialite driver 取得點，集中處理「各 provider 要申請哪些 scope」。
 *
 * LINE：socialiteproviders/line 預設申請 openid＋profile＋email，但 email scope
 * 要先在 LINE Developers Console 另外申請權限，沒申請就帶上去，LINE 授權頁會直接
 * 回 INVALID_SCOPE。登入綁定只靠 provider_user_id（LINE 的 userId，由 profile scope
 * 的 /v2/profile 取得），用不到 email，所以只申請 openid＋profile，也少拿一份個資。
 */
final class SocialiteDriver
{
    /** 要覆寫套件預設 scope 的 provider；沒列在這裡的照套件預設 */
    private const SCOPES = [
        'line' => ['openid', 'profile'],
    ];

    /**
     * @return Provider
     */
    public static function for(string $provider)
    {
        $driver = Socialite::driver($provider);

        if (isset(self::SCOPES[$provider])) {
            $driver->setScopes(self::SCOPES[$provider]);
        }

        return $driver;
    }
}
