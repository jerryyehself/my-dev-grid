<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Logging\SecurityLog;
use App\Models\OauthIdentity;
use App\Service\SocialiteDriver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Throwable;

class SocialAuthController extends Controller
{
    /**
     * v1 範圍（D-34）只開放這兩個 OAuth provider，
     * 其他值一律視為無效路由（404），不要交給 Socialite 憑空 driver。
     */
    private const ALLOWED_PROVIDERS = ['google', 'line'];

    /**
     * 導向指定 provider 的 OAuth 授權頁面。
     */
    public function redirect(string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, self::ALLOWED_PROVIDERS, true), 404);

        return SocialiteDriver::for($provider)->redirect();
    }

    /**
     * Provider 授權完導回來的 callback。
     *
     * 這是封閉系統（v1 範圍只做登入，不開放註冊，見
     * D-34）：找得到既有的 oauth_identities 綁定就登入，找不到就直接拒絕，
     * 不會自動幫使用者建立帳號或綁定。
     */
    public function callback(Request $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, self::ALLOWED_PROVIDERS, true), 404);

        try {
            $socialiteUser = SocialiteDriver::for($provider)->user();
        } catch (Throwable $e) {
            // state 不符、使用者在 provider 那邊按取消、換 token 失敗：記一筆
            // authn_login_fail 再照原樣丟出去，回應跟改動前一樣。
            SecurityLog::oauthLoginFailed($provider, 'session', $e, $request);

            throw $e;
        }

        $identity = OauthIdentity::where('provider', $provider)
            ->where('provider_user_id', $socialiteUser->getId())
            ->first();

        if (! $identity) {
            // 首次登入 bootstrap 問題：這個系統關閉註冊，所以「第一次」有人
            // 用這個 provider 帳號登入時（包括專案owner 自己的第一次登入），
            // oauth_identities 裡必然找不到任何一筆綁定——這不是 bug，是
            // 預期中的雞生蛋問題。解法是把 provider 給的使用者 id 寫進
            // application log，讓專案owner 事後自己用
            // `php artisan tinker` 手動建一筆 oauth_identities row 把自己
            // 的 provider 帳號綁到既有的 users row 上。日誌事件 identity_not_bound
            // 帶哪些欄位，見 docs/observability.md。
            //
            // 絕對不能把這些值放進 HTTP 回應本身——這是任何人都能打到的
            // 公開 callback 端點，provider id 屬於半敏感資訊，外洩等於告訴
            // 陌生人「這組 provider 帳號存在、剛剛嘗試登入這個系統」，所以
            // 只寫進只有專案owner 看得到的 log，回應本身維持一個不透露任何
            // 細節的通用「未授權」導向。
            //
            // 2026-10-12（issue #103）起只記 provider_user_id，不再記 provider
            // 給的 email 跟名稱：建綁定只需要 id，打到這裡的多半是跟這個系統
            // 無關的陌生人，他們的 email／姓名沒有理由留在日誌裡。要認出哪一筆是
            // 自己，看自己登入那一刻的時間或回應的 X-Request-Id。
            SecurityLog::loginFailed($provider, 'session', 'identity_not_bound', [
                'provider_user_id' => (string) $socialiteUser->getId(),
            ]);

            return redirect('/?auth_error=not_authorized');
        }

        Auth::login($identity->user);
        $request->session()->regenerate();

        SecurityLog::loginSucceeded($identity->user_id, $provider, 'session');

        return redirect('/');
    }
}
