<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\OauthIdentity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

/**
 * my-dev-grid-front 版的 OAuth 登入回呼——跟 SocialAuthController 平行存在，
 * 差別是登入成功後發 Sanctum API token（透過 URL fragment 帶回前端），
 * 不是建立 session。理由同 TokenLoginController 的類別註解，不重複寫。
 *
 * 用 `redirectUrl()` 明確指定這支自己的 callback 網址，不吃
 * config/services.php 的 `redirect`（那個是 SocialAuthController／Triple
 * 在用的預設值）——這代表 issue #49 申請正式 OAuth 憑證時，Google／LINE
 * 兩邊都要把「兩個」callback 網址都登記成 authorized redirect URI，
 * 不是只登記 SocialAuthController 那一個。
 */
class TokenSocialAuthController extends Controller
{
    private const ALLOWED_PROVIDERS = ['google', 'line'];

    public function redirect(string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, self::ALLOWED_PROVIDERS, true), 404);

        return Socialite::driver($provider)
            ->redirectUrl(route('auth.token.social.callback', $provider))
            ->redirect();
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        abort_unless(in_array($provider, self::ALLOWED_PROVIDERS, true), 404);

        $socialiteUser = Socialite::driver($provider)
            ->redirectUrl(route('auth.token.social.callback', $provider))
            ->user();

        $identity = OauthIdentity::where('provider', $provider)
            ->where('provider_user_id', $socialiteUser->getId())
            ->first();

        if (! $identity) {
            // 同 SocialAuthController::callback 的雞生蛋問題與隱私考量，
            // 不再重複寫一次，見那支的註解。
            Log::info('OAuth 登入遭拒（token 模式）：找不到對應的 oauth_identities 綁定', [
                'provider' => $provider,
                'provider_user_id' => $socialiteUser->getId(),
                'provider_email' => $socialiteUser->getEmail(),
                'provider_name' => $socialiteUser->getName(),
            ]);

            return redirect(config('app.frontend_url').'/?auth_error=not_authorized');
        }

        $token = $identity->user->createToken('my-dev-grid-front')->plainTextToken;

        // Token 放在 URL fragment（#token=...），不是 query string：fragment
        // 不會被送到任何伺服器（包含前端自己的 hosting），瀏覽器也不會把它
        // 記進 Referer 或存取紀錄——這是 2026-09-22 討論 token 儲存風險時
        // 查證過的傳遞路徑慣例（前端拿到後存哪裡是另一件事，見
        // daily-claude-summary/reports/frontend-build-tooling-qa.md）。
        return redirect(config('app.frontend_url')."/auth/callback#token={$token}");
    }
}
