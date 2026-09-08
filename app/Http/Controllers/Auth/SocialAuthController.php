<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\OauthIdentity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    /**
     * v1 範圍（decision-register.md D-34）只開放這兩個 OAuth provider，
     * 其他值一律視為無效路由（404），不要交給 Socialite 憑空 driver。
     */
    private const ALLOWED_PROVIDERS = ['google', 'line'];

    /**
     * 導向指定 provider 的 OAuth 授權頁面。
     */
    public function redirect(string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, self::ALLOWED_PROVIDERS, true), 404);

        return Socialite::driver($provider)->redirect();
    }

    /**
     * Provider 授權完導回來的 callback。
     *
     * 這是封閉系統（v1 範圍只做登入，不開放註冊，見 decision-register.md
     * D-34）：找得到既有的 oauth_identities 綁定就登入，找不到就直接拒絕，
     * 不會自動幫使用者建立帳號或綁定。
     */
    public function callback(Request $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, self::ALLOWED_PROVIDERS, true), 404);

        $socialiteUser = Socialite::driver($provider)->user();

        $identity = OauthIdentity::where('provider', $provider)
            ->where('provider_user_id', $socialiteUser->getId())
            ->first();

        if (! $identity) {
            // 首次登入 bootstrap 問題：這個系統關閉註冊，所以「第一次」有人
            // 用這個 provider 帳號登入時（包括專案owner 自己的第一次登入），
            // oauth_identities 裡必然找不到任何一筆綁定——這不是 bug，是
            // 預期中的雞生蛋問題。解法是把 provider 給的識別資訊寫進
            // application log，讓專案owner 事後自己用
            // `php artisan tinker` 手動建一筆 oauth_identities row 把自己
            // 的 provider 帳號綁到既有的 users row 上。詳細步驟見
            // my-dev-grid-skills 的 roles/backend/2026-09-08.md 交接文件。
            //
            // 絕對不能把這些值放進 HTTP 回應本身——這是任何人都能打到的
            // 公開 callback 端點，provider id/email 屬於半敏感資訊，外洩
            // 等於告訴陌生人「這組 provider 帳號存在、剛剛嘗試登入這個
            // 系統」，所以只寫進只有專案owner 看得到的 log，回應本身維持
            // 一個不透露任何細節的通用「未授權」導向。
            Log::info('OAuth 登入遭拒：找不到對應的 oauth_identities 綁定', [
                'provider' => $provider,
                'provider_user_id' => $socialiteUser->getId(),
                'provider_email' => $socialiteUser->getEmail(),
                'provider_name' => $socialiteUser->getName(),
            ]);

            return redirect('/?auth_error=not_authorized');
        }

        Auth::login($identity->user);
        $request->session()->regenerate();

        return redirect('/');
    }
}
