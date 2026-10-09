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
 *
 * PKCE（issue #103）：兩個 provider 都開 enablePKCE()。導向授權頁時 Socialite 會把
 * code_verifier 存進 session、在網址帶 code_challenge＋code_challenge_method=S256；
 * callback 換 token 時從 session 取出 code_verifier 一起送。授權碼就算在中途被攔截
 * （例如瀏覽器紀錄、Referer、log），沒有同一個 session 裡的 code_verifier 也換不到
 * token。LINE 只支援 S256，Socialite 固定用 S256，兩邊一致。
 *
 * 這依賴 callback 跑在有 session 的路由上（routes/web.php 的 web middleware group，
 * state 驗證本來就需要），不能改成 stateless()——stateless 沒有 session 可以存 verifier。
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
        $driver = Socialite::driver($provider)->enablePKCE();

        if (isset(self::SCOPES[$provider])) {
            $driver->setScopes(self::SCOPES[$provider]);
        }

        return $driver;
    }
}
