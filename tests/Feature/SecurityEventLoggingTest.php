<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\TokenSocialAuthController;
use App\Http\RefreshTokenCookie;
use App\Models\OauthIdentity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Events\TokenAuthenticated;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;
use Tests\Concerns\CapturesSecurityEvents;
use Tests\TestCase;
use Throwable;

/**
 * 安全事件日誌（issue #103，App\Logging\SecurityLog）：每種事件至少一個測試，
 * 並確認 token、密碼、OAuth code 不會出現在任何一行日誌裡。
 * 事件表見 docs/observability.md「安全與稽核事件」。
 */
class SecurityEventLoggingTest extends TestCase
{
    use CapturesSecurityEvents;
    use RefreshDatabase;

    private const PASSWORD = 'correct-password';

    /**
     * 同 RefreshTokenCookieTest::send：直接帶不加密的 refresh cookie，每次請求前清掉 guard。
     *
     * @param  array<string, string>  $cookies
     * @param  array<string, string|null>  $headers
     */
    private function send(string $method, string $uri, array $cookies = [], array $headers = [], array $body = []): TestResponse
    {
        Auth::forgetGuards();

        $headers = array_filter(array_merge([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Origin' => config('app.frontend_url'),
        ], $headers), fn ($value) => $value !== null);

        return $this->call($method, $uri, [], $cookies, [], $this->transformHeadersToServerVars($headers), $body ? json_encode($body) : null);
    }

    private function createUser(): User
    {
        return User::factory()->create(['email' => 'owner@example.com', 'password' => self::PASSWORD]);
    }

    private function apiLogin(string $password = self::PASSWORD, string $email = 'owner@example.com'): TestResponse
    {
        return $this->send('POST', '/api/auth/login', body: ['email' => $email, 'password' => $password]);
    }

    private function refreshCookieValue(TestResponse $response): string
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === RefreshTokenCookie::NAME) {
                return $cookie->getValue();
            }
        }

        $this->fail('回應裡沒有 refresh cookie');
    }

    /** `{id}|{secret}` 格式（Sanctum token、refresh cookie）的秘密部分 */
    private function secretOf(string $token): string
    {
        return explode('|', $token, 2)[1];
    }

    private function bindIdentity(string $provider, string $providerUserId): User
    {
        $user = User::factory()->create();
        OauthIdentity::factory()->create([
            'user_id' => $user->id,
            'provider' => $provider,
            'provider_user_id' => $providerUserId,
        ]);
        Socialite::fake($provider, SocialiteUser::fake(['id' => $providerUserId, 'email' => null, 'name' => $user->name]));

        return $user;
    }

    /** Socialite 的 user() 丟例外（state 不符、provider 拒絕、換 token 失敗） */
    private function socialiteThrows(string $provider, Throwable $e): void
    {
        $driver = Mockery::mock(AbstractProvider::class);
        $driver->shouldReceive('enablePKCE')->andReturnSelf();
        $driver->shouldReceive('setScopes')->andReturnSelf();
        $driver->shouldReceive('redirectUrl')->andReturnSelf();
        $driver->shouldReceive('user')->andThrow($e);
        Socialite::shouldReceive('driver')->with($provider)->andReturn($driver);
    }

    // ── authn_login_success ──────────────────────────────────────────

    #[TestDox('Google session 回呼成功時記錄登入成功事件')]
    public function test_google_session_callback_logs_login_success()
    {
        $user = $this->bindIdentity('google', 'google-123');
        $this->captureLogs();

        $this->get('/auth/google/callback?code=oauth-code-must-not-be-logged&state=s')->assertRedirect('/');

        $this->assertSecurityEvent('authn_login_success', [
            'user_id' => $user->id,
            'auth_method' => 'google',
            'auth_mode' => 'session',
        ], 'info');
        $this->assertNotLogged('oauth-code-must-not-be-logged');
        $this->assertNotLogged('google-123');
    }

    #[TestDox('LINE token 回呼記錄登入與發 token，不含 token 值')]
    public function test_line_token_callback_logs_login_success_and_token_created_without_the_token()
    {
        $user = $this->bindIdentity('line', 'line-123');
        $this->captureLogs();

        $response = $this->get('/auth/token/line/callback');
        $token = explode('#token=', $response->headers->get('Location'), 2)[1];

        $this->assertSecurityEvent('authn_login_success', [
            'user_id' => $user->id,
            'auth_method' => 'line',
            'auth_mode' => 'token',
        ]);
        $created = $this->assertSecurityEvent('authn_token_created', [
            'user_id' => $user->id,
            'reason' => 'oauth_callback',
            'abilities' => ['*'],
        ], 'info');
        $this->assertSame(PersonalAccessToken::findToken($token)->getKey(), $created['access_token_id']);
        $this->assertNotLogged($this->secretOf($token));
    }

    #[TestDox('密碼登入的兩種模式都記錄登入成功')]
    public function test_password_logins_log_success_for_both_modes()
    {
        $user = $this->createUser();

        $this->captureLogs();
        $token = $this->apiLogin()->assertOk()->json('token');
        $this->assertSecurityEvent('authn_login_success', ['user_id' => $user->id, 'auth_method' => 'password', 'auth_mode' => 'token']);
        $this->assertNotLogged(self::PASSWORD);
        $this->assertNotLogged($this->secretOf($token));

        $this->captureLogs();
        $this->postJson('/auth/login', ['email' => 'owner@example.com', 'password' => self::PASSWORD])->assertOk();
        $this->assertSecurityEvent('authn_login_success', ['user_id' => $user->id, 'auth_method' => 'password', 'auth_mode' => 'session']);
        $this->assertNotLogged(self::PASSWORD);
    }

    // ── authn_login_fail ─────────────────────────────────────────────

    #[TestDox('密碼登入失敗只在帳號存在時記 user_id')]
    public function test_password_login_failure_logs_the_user_id_only_when_the_account_exists()
    {
        $user = $this->createUser();

        $this->captureLogs();
        $this->apiLogin('wrong-password')->assertUnprocessable();
        $context = $this->assertSecurityEvent('authn_login_fail', [
            'auth_method' => 'password',
            'auth_mode' => 'token',
            'reason' => 'invalid_credentials',
            'user_id' => $user->id,
        ], 'warning');
        $this->assertSame('127.0.0.1', $context['source_ip']);
        $this->assertNotLogged('wrong-password');

        // 不存在的帳號：email 本身不記（可能是別人的 email）
        $this->captureLogs();
        $this->postJson('/auth/login', ['email' => 'stranger@example.com', 'password' => 'guess'])->assertUnprocessable();
        $context = $this->assertSecurityEvent('authn_login_fail', ['auth_mode' => 'session', 'reason' => 'invalid_credentials']);
        $this->assertArrayNotHasKey('user_id', $context);
        $this->assertNotLogged('stranger@example.com');
        $this->assertNotLogged('guess');
    }

    #[TestDox('OAuth state 不符時記錄登入失敗並維持原本的錯誤')]
    public function test_oauth_invalid_state_logs_login_fail_and_still_errors_like_before()
    {
        $this->socialiteThrows('google', new InvalidStateException);
        $this->captureLogs();

        $this->get('/auth/google/callback?code=oauth-code-must-not-be-logged&state=tampered')->assertServerError();

        $this->assertSecurityEvent('authn_login_fail', [
            'auth_method' => 'google',
            'auth_mode' => 'session',
            'reason' => 'invalid_state',
            'error_class' => 'InvalidStateException',
        ], 'warning');
        $this->assertNotLogged('oauth-code-must-not-be-logged');
    }

    #[TestDox('OAuth 提供者拒絕時記錄其錯誤代碼')]
    public function test_oauth_provider_denial_logs_the_provider_error_code()
    {
        $this->socialiteThrows('line', new RuntimeException('provider said no'));
        $this->captureLogs();

        $this->get('/auth/token/line/callback?error=access_denied&state=s')->assertServerError();

        $context = $this->assertSecurityEvent('authn_login_fail', [
            'auth_method' => 'line',
            'auth_mode' => 'token',
            'reason' => 'provider_denied',
            'provider_error' => 'access_denied',
        ]);
        // 例外訊息可能含 provider 回應的片段，不放進安全事件
        $this->assertArrayNotHasKey('message', $context);
        $this->assertStringNotContainsString('provider said no', json_encode($context));
    }

    #[TestDox('refresh cookie 無效記登入失敗，缺少則不記')]
    public function test_an_invalid_refresh_cookie_logs_login_fail_but_a_missing_one_does_not()
    {
        $this->captureLogs();
        $this->send('POST', '/api/auth/refresh', [RefreshTokenCookie::NAME => '999|not-a-real-secret-value-0000000000000000'])->assertUnauthorized();
        $this->assertSecurityEvent('authn_login_fail', ['auth_method' => 'refresh_token', 'reason' => 'invalid_refresh_token']);
        $this->assertNotLogged('not-a-real-secret-value');

        // 沒登入過的訪客，前端開機時也會打 refresh：不是登入嘗試
        $this->captureLogs();
        $this->send('POST', '/api/auth/refresh')->assertUnauthorized();
        $this->assertNoSecurityEvent('authn_login_fail');
    }

    #[TestDox('重用已換發的回呼 token 時記錄登入失敗')]
    public function test_reusing_an_already_exchanged_callback_token_logs_login_fail()
    {
        $user = $this->createUser();
        $callbackToken = $user->createToken(TokenSocialAuthController::CALLBACK_TOKEN_NAME, ['*'], now()->addMinutes(15))->plainTextToken;
        // 同 RefreshTokenCookieTest：驗證通過之後、換發之前，另一個請求已經把它用掉
        Event::listen(TokenAuthenticated::class, fn (TokenAuthenticated $event) => PersonalAccessToken::query()->whereKey($event->token->getKey())->delete());
        $this->captureLogs();

        $this->send('POST', '/api/auth/session', headers: ['Authorization' => "Bearer {$callbackToken}"])->assertUnauthorized();

        $this->assertSecurityEvent('authn_login_fail', [
            'auth_method' => 'oauth_callback_token',
            'reason' => 'callback_token_already_used',
            'user_id' => $user->id,
        ]);
        $this->assertNotLogged($this->secretOf($callbackToken));
    }

    // ── authn_token_created／authn_token_revoked／authn_token_reuse ──

    #[TestDox('登入與輪替記錄發 token 事件，不含 token 值')]
    public function test_login_and_rotation_log_token_created_without_token_values()
    {
        $user = $this->createUser();

        $this->captureLogs();
        $login = $this->apiLogin();
        $first = $this->assertSecurityEvent('authn_token_created', ['user_id' => $user->id, 'reason' => 'login', 'abilities' => ['*']], 'info');
        $cookie = $this->refreshCookieValue($login);
        $this->assertSame(RefreshTokenCookie::idOf($cookie), $first['refresh_token_id']);

        $this->captureLogs();
        $rotated = $this->send('POST', '/api/auth/refresh', [RefreshTokenCookie::NAME => $cookie])->assertOk();
        $second = $this->assertSecurityEvent('authn_token_created', ['user_id' => $user->id, 'reason' => 'rotation']);
        $this->assertSame($first['family_id'], $second['family_id']);

        foreach ([$login->json('token'), $cookie, $rotated->json('token'), $this->refreshCookieValue($rotated)] as $token) {
            $this->assertNotLogged($this->secretOf($token));
        }
    }

    #[TestDox('token 登出記錄登出與 token 撤銷事件')]
    public function test_token_logout_logs_session_logout_and_token_revoked()
    {
        $user = $this->createUser();
        $login = $this->apiLogin();
        $this->captureLogs();

        $this->send('POST', '/api/auth/logout', [RefreshTokenCookie::NAME => $this->refreshCookieValue($login)], [
            'Authorization' => 'Bearer '.$login->json('token'),
        ])->assertOk();

        $this->assertSecurityEvent('session_logout', ['user_id' => $user->id, 'auth_mode' => 'token'], 'info');
        $this->assertSecurityEvent('authn_token_revoked', [
            'user_id' => $user->id,
            'reason' => 'logout',
            'refresh_tokens_revoked' => 1,
        ], 'info');
    }

    #[TestDox('無憑證的 token 登出只記登出，不帶使用者')]
    public function test_token_logout_without_credentials_logs_session_logout_without_a_user()
    {
        $this->captureLogs();

        $this->send('POST', '/api/auth/logout')->assertOk();

        $context = $this->assertSecurityEvent('session_logout', ['auth_mode' => 'token']);
        $this->assertArrayNotHasKey('user_id', $context);
        $this->assertNoSecurityEvent('authn_token_revoked');
    }

    #[TestDox('session 登出記錄登出事件')]
    public function test_session_logout_logs_session_logout()
    {
        $user = $this->createUser();
        $this->actingAs($user);
        $this->captureLogs();

        $this->postJson('/auth/logout')->assertOk();

        $this->assertSecurityEvent('session_logout', ['user_id' => $user->id, 'auth_mode' => 'session']);
    }

    #[TestDox('重放 refresh token 記 critical 重用與撤銷事件')]
    public function test_refresh_token_replay_logs_token_reuse_as_critical_and_the_revocation()
    {
        $user = $this->createUser();
        $first = $this->refreshCookieValue($this->apiLogin());
        $this->send('POST', '/api/auth/refresh', [RefreshTokenCookie::NAME => $first])->assertOk();
        $this->travel(config('sanctum.refresh_token_reuse_grace_seconds') + 1)->seconds();
        $this->captureLogs();

        $this->send('POST', '/api/auth/refresh', [RefreshTokenCookie::NAME => $first])->assertUnauthorized();

        $reuse = $this->assertSecurityEvent('authn_token_reuse', [
            'user_id' => $user->id,
            'refresh_token_id' => RefreshTokenCookie::idOf($first),
            'source_ip' => '127.0.0.1',
        ], 'critical');
        $this->assertSecurityEvent('authn_token_revoked', [
            'user_id' => $user->id,
            'reason' => 'reuse',
            'family_id' => $reuse['family_id'],
            'refresh_tokens_revoked' => 2,
        ]);
        $this->assertNotLogged($this->secretOf($first));
    }

    // ── session_expired ──────────────────────────────────────────────

    #[TestDox('碰到登入絕對上限時記錄 session 過期')]
    public function test_hitting_the_absolute_cap_logs_session_expired()
    {
        config(['sanctum.refresh_token_max_lifetime' => 60]);
        $user = $this->createUser();
        $value = $this->refreshCookieValue($this->apiLogin());
        $this->travel(61)->minutes();
        $this->captureLogs();

        $this->send('POST', '/api/auth/refresh', [RefreshTokenCookie::NAME => $value])->assertUnauthorized();

        $this->assertSecurityEvent('session_expired', ['user_id' => $user->id, 'reason' => 'max_lifetime'], 'info');
        $this->assertSecurityEvent('authn_token_revoked', ['user_id' => $user->id, 'reason' => 'max_lifetime']);
    }

    // ── authz_fail（403）─────────────────────────────────────────────

    #[TestDox('Origin 錯誤記錄授權失敗，不帶使用者')]
    public function test_a_wrong_origin_logs_authz_fail_without_a_user()
    {
        $this->captureLogs();

        $this->send('POST', '/api/auth/refresh', headers: ['Origin' => 'https://evil.example'])->assertForbidden();

        $context = $this->assertSecurityEvent('authz_fail', [
            'http_method' => 'POST',
            'route' => 'api/auth/refresh',
            'resource' => '/api/auth/refresh',
            'reason' => '不允許的來源。',
            'source_ip' => '127.0.0.1',
        ], 'warning');
        $this->assertArrayNotHasKey('user_id', $context);
    }

    #[TestDox('已登入使用者被禁止時，授權失敗事件帶使用者')]
    public function test_a_forbidden_action_by_an_authenticated_user_logs_the_user()
    {
        $user = $this->createUser();
        $token = $this->apiLogin()->json('token');
        $this->captureLogs();

        // 一般 access token 不能拿來建立登入狀態（TokenRefreshController::session）
        $this->send('POST', '/api/auth/session', headers: ['Authorization' => "Bearer {$token}"])->assertForbidden();

        $this->assertSecurityEvent('authz_fail', ['user_id' => $user->id, 'route' => 'api/auth/session']);
        $this->assertNotLogged($this->secretOf($token));
    }

    // ── excess_rate_limit_exceeded（429）─────────────────────────────

    #[TestDox('被頻率限制的請求會記錄上限與路由')]
    public function test_rate_limited_requests_log_the_limit_and_route()
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'nobody@example.com', 'password' => 'x'])->assertUnprocessable();
        }
        $this->captureLogs();

        $this->postJson('/api/auth/login', ['email' => 'nobody@example.com', 'password' => 'x'])->assertStatus(429);

        // 上限 5 代表是 'login' limiter（不是整組 api 的 60）
        $context = $this->assertSecurityEvent('excess_rate_limit_exceeded', [
            'http_method' => 'POST',
            'route' => 'api/auth/login',
            'max' => 5,
            'source_ip' => '127.0.0.1',
        ], 'warning');
        $this->assertGreaterThan(0, $context['retry_after']);
        $this->assertArrayNotHasKey('user_id', $context);
        // 被擋下的那次不算登入嘗試（throttle 在 controller 之前）
        $this->assertNoSecurityEvent('authn_login_fail');
    }

    // ── 正式環境的格式 ───────────────────────────────────────────────

    #[TestDox('事件以 JSON 帶 request id 寫入 Cloud Run 通道')]
    public function test_events_reach_the_cloud_run_channel_as_json_with_the_request_id()
    {
        $logFile = tempnam(sys_get_temp_dir(), 'security-log-');
        config([
            'logging.default' => 'cloud_run',
            'logging.channels.cloud_run.handler_with.stream' => $logFile,
        ]);

        try {
            $response = $this->send('POST', '/api/auth/refresh', headers: ['Origin' => 'https://evil.example', 'X-Request-Id' => 'sec-test-1']);
            $lines = array_values(array_filter(explode("\n", (string) file_get_contents($logFile))));
        } finally {
            @unlink($logFile);
        }

        $response->assertForbidden()->assertHeader('X-Request-Id', 'sec-test-1');
        $this->assertCount(1, $lines);
        $entry = json_decode($lines[0], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('WARNING', $entry['severity']);
        $this->assertSame('sec-test-1', $entry['request_id']);
        $this->assertSame('authz_fail', $entry['context']['event']);
        $this->assertStringStartsWith('authz_fail ', $entry['message']);
    }

    #[TestDox('其他狀態碼不會被記成 403 或 429 事件')]
    public function test_403_and_429_are_not_logged_for_other_statuses()
    {
        $this->captureLogs();

        $this->getJson('/api/scopes/999999')->assertNotFound();
        $this->postJson('/api/scopes', [])->assertUnauthorized();

        $this->assertNoSecurityEvent('authz_fail');
        $this->assertNoSecurityEvent('excess_rate_limit_exceeded');
    }
}
