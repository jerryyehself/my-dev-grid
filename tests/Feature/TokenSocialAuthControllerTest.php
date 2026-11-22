<?php

namespace Tests\Feature;

use App\Models\OauthIdentity;
use App\Models\User;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\Concerns\CapturesSecurityEvents;
use Tests\TestCase;

class TokenSocialAuthControllerTest extends TestCase
{
    use CapturesSecurityEvents;
    use RefreshDatabase;

    #[TestDox('token 模式的導向遇到未知 provider 回 404')]
    public function test_redirect_rejects_unknown_provider()
    {
        $response = $this->get('/auth/token/facebook/redirect');

        $response->assertNotFound();
    }

    #[TestDox('token 模式的 Google 導向交給 Socialite')]
    public function test_redirect_delegates_to_socialite_for_google()
    {
        Socialite::fake('google');

        $response = $this->get('/auth/token/google/redirect');

        $response->assertRedirect();
        $this->assertStringContainsString('google', $response->headers->get('Location'));
    }

    #[TestDox('LINE 導向只要求 openid 與 profile 範圍')]
    public function test_line_redirect_requests_only_openid_and_profile_scopes()
    {
        // 不用 Socialite::fake()：要看真的 LINE driver 組出來的授權網址。
        // 帶 email scope 而頻道沒申請 email 權限時，LINE 會回 INVALID_SCOPE。
        $response = $this->get('/auth/token/line/redirect');

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith('https://access.line.me/oauth2/v2.1/authorize?', $location);

        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('openid profile', $query['scope']);
    }

    #[TestDox('token 模式的回呼遇到未知 provider 回 404')]
    public function test_callback_rejects_unknown_provider()
    {
        $response = $this->get('/auth/token/facebook/callback');

        $response->assertNotFound();
    }

    #[TestDox('OAuth 身分已綁定時發 token，不建立 session')]
    public function test_callback_issues_a_token_when_oauth_identity_already_exists()
    {
        $user = User::factory()->create();
        $identity = OauthIdentity::factory()->create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'google-123',
        ]);

        Socialite::fake('google', SocialiteUser::fake([
            'id' => $identity->provider_user_id,
            'email' => $identity->provider_email,
            'name' => $user->name,
        ]));

        $response = $this->get('/auth/token/google/callback');

        // Token 模式不建立 session——這是跟 SocialAuthController::callback
        // 最根本的差異，斷言 guest 而不是 assertAuthenticatedAs()。
        $this->assertGuest();
        $this->assertDatabaseCount('personal_access_tokens', 1);

        $location = $response->headers->get('Location');
        $this->assertStringStartsWith(config('app.frontend_url').'/auth/callback#token=', $location);
    }

    #[TestDox('未綁定的 OAuth 身分被拒絕，且不自動註冊')]
    public function test_callback_rejects_unknown_oauth_identity_without_auto_registration()
    {
        $this->captureLogs();

        Socialite::fake('google', SocialiteUser::fake([
            'id' => 'google-unknown-999',
            'email' => 'stranger@example.com',
            'name' => 'Stranger',
        ]));

        $response = $this->get('/auth/token/google/callback');

        $response->assertRedirect(config('app.frontend_url').'/?auth_error=not_authorized');
        $this->assertGuest();
        $this->assertDatabaseCount('oauth_identities', 0);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        // provider id/email 只能出現在 log，不能出現在 HTTP 回應裡。
        $this->assertStringNotContainsString('google-unknown-999', $response->getContent() ?: '');
        $this->assertStringNotContainsString('stranger@example.com', $response->getContent() ?: '');
        // 綁定只需要 provider_user_id；陌生人的 email、姓名不寫進日誌（issue #103）
        $this->assertSecurityEvent('authn_login_fail', [
            'auth_method' => 'google',
            'auth_mode' => 'token',
            'reason' => 'identity_not_bound',
            'provider_user_id' => 'google-unknown-999',
        ], 'warning');
        $this->assertNotLogged('stranger@example.com');
        $this->assertNotLogged('Stranger');
    }

    #[TestDox('LINE 未綁定的身分同樣被拒絕')]
    public function test_callback_rejects_unknown_identity_for_line_provider_too()
    {
        Socialite::fake('line', SocialiteUser::fake([
            'id' => 'line-unknown-999',
            'email' => null,
            'name' => 'Stranger',
        ]));

        $response = $this->get('/auth/token/line/callback');

        $response->assertRedirect(config('app.frontend_url').'/?auth_error=not_authorized');
        $this->assertGuest();
    }

    /**
     * @return array<string, array{string, array<string, string>}>
     */
    public static function invalidStateCases(): array
    {
        return [
            // session 過期或換了瀏覽器：session 裡已經沒有出發時存的 state
            'google，session 裡沒有 state' => ['google', []],
            'line，session 裡沒有 state' => ['line', []],
            // 舊分頁或重複點登入：session 裡的 state 已經換成後來那一次的
            'google，state 被後來的登入蓋掉' => ['google', ['state' => 'state-of-a-later-attempt']],
            'line，state 被後來的登入蓋掉' => ['line', ['state' => 'state-of-a-later-attempt']],
        ];
    }

    /**
     * state 不符是使用者重試就會好的情況，不該回 500。不用 Socialite::fake()：
     * 走真的 driver，由 Socialite 自己的 state 檢查丟出 InvalidStateException，
     * 跟正式環境是同一條路。
     *
     * @param  array<string, string>  $session
     */
    #[TestDox('state 不符時導回前端並帶登入逾時，仍記錄登入失敗')]
    #[DataProvider('invalidStateCases')]
    public function test_callback_redirects_to_frontend_with_state_expired_when_state_is_invalid(string $provider, array $session)
    {
        $this->captureLogs();

        $response = $this->withSession($session)
            ->get("/auth/token/{$provider}/callback?code=oauth-code-must-not-be-logged&state=state-from-an-old-tab");

        $response->assertRedirect(config('app.frontend_url').'/?auth_error=state_expired');
        $this->assertGuest();
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertSecurityEvent('authn_login_fail', [
            'auth_method' => $provider,
            'auth_mode' => 'token',
            'reason' => 'invalid_state',
            'error_class' => 'InvalidStateException',
        ], 'warning');
        $this->assertNotLogged('oauth-code-must-not-be-logged');
        $this->assertNotLogged('state-from-an-old-tab');
    }

    /**
     * 只有 state 不符改成導回；其他向 provider 取資料失敗的例外照舊丟出（500）。
     */
    #[TestDox('其他例外照舊回 500，並記錄登入失敗')]
    public function test_callback_still_errors_for_exceptions_other_than_invalid_state()
    {
        $driver = Mockery::mock(AbstractProvider::class);
        $driver->shouldReceive('enablePKCE')->andReturnSelf();
        $driver->shouldReceive('redirectUrl')->andReturnSelf();
        $driver->shouldReceive('user')->andThrow(new Exception('state could not be exchanged'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
        $this->captureLogs();

        $response = $this->get('/auth/token/google/callback?code=c&state=s');

        $response->assertServerError();
        $this->assertSecurityEvent('authn_login_fail', [
            'auth_method' => 'google',
            'auth_mode' => 'token',
            'reason' => 'provider_error',
            'error_class' => 'Exception',
        ], 'warning');
    }
}
