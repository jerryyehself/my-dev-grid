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
use Laravel\Socialite\Two\User as SocialiteUser;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/**
 * 「重新整理後維持登入」：短效 access token（JSON）＋長效 refresh token
 * （Partitioned httpOnly cookie）。端點見 routes/api.php 的 /auth/refresh、
 * /auth/session、/auth/logout。
 *
 * 請求一律走 call() 直接帶 cookie（不經過測試工具的 cookie 加密），因為這顆
 * cookie 本來就不加密（bootstrap/app.php 的 encryptCookies except）；每次請求前
 * Auth::forgetGuards()，理由同 TokenLoginControllerTest::test_logout_revokes_the_token。
 */
class RefreshTokenCookieTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-password';

    /**
     * @param  array<string, string>  $cookies
     * @param  array<string, string|null>  $headers  值給 null 代表不送這個 header
     */
    private function send(string $method, string $uri, array $cookies = [], array $headers = [], array $body = []): TestResponse
    {
        Auth::forgetGuards();

        $headers = array_filter(array_merge([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Origin' => config('app.frontend_url'),
        ], $headers), fn ($value) => $value !== null);

        return $this->call(
            $method,
            $uri,
            [],
            $cookies,
            [],
            $this->transformHeadersToServerVars($headers),
            $body ? json_encode($body) : null,
        );
    }

    private function createUser(): User
    {
        return User::factory()->create([
            'email' => 'owner@example.com',
            'password' => self::PASSWORD,
        ]);
    }

    private function login(): TestResponse
    {
        return $this->send('POST', '/api/auth/login', body: [
            'email' => 'owner@example.com',
            'password' => self::PASSWORD,
        ]);
    }

    private function refreshCookie(TestResponse $response): ?Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === RefreshTokenCookie::NAME) {
                return $cookie;
            }
        }

        return null;
    }

    /** @return array<string, string> */
    private function cookieJar(string $value): array
    {
        return [RefreshTokenCookie::NAME => $value];
    }

    private function rawSetCookieHeader(TestResponse $response): string
    {
        foreach ($response->headers->all('set-cookie') as $line) {
            if (str_starts_with($line, RefreshTokenCookie::NAME.'=')) {
                return $line;
            }
        }

        $this->fail('回應裡沒有 refresh cookie 的 Set-Cookie');
    }

    private function assertCookieCleared(TestResponse $response): void
    {
        $cookie = $this->refreshCookie($response);
        $this->assertNotNull($cookie, '應該要送出清除 refresh cookie 的 Set-Cookie');
        $this->assertTrue($cookie->isCleared());
        // 清除時屬性要跟設定時一致，尤其是 Partitioned，不然瀏覽器會當成另一顆 cookie
        $this->assertTrue($cookie->isPartitioned());
        $this->assertTrue($cookie->isSecure());
        $this->assertSame('/', $cookie->getPath());
        $this->assertNull($cookie->getDomain());
    }

    public function test_login_sets_the_refresh_cookie_with_all_required_attributes()
    {
        $user = $this->createUser();

        $response = $this->login();

        // JSON 形狀維持原本的 data＋token，只多 expires_in（秒）
        $response->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonStructure(['data', 'token', 'expires_in'])
            ->assertJsonPath('expires_in', 15 * 60);
        $this->assertArrayNotHasKey('refresh_token', $response->json());

        $cookie = $this->refreshCookie($response);
        $this->assertNotNull($cookie);
        $this->assertSame('__Host-mdg_refresh', $cookie->getName());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertTrue($cookie->isSecure());
        $this->assertSame(Cookie::SAMESITE_NONE, $cookie->getSameSite());
        $this->assertTrue($cookie->isPartitioned());
        $this->assertSame('/', $cookie->getPath());
        $this->assertNull($cookie->getDomain());
        $this->assertEqualsWithDelta(30 * 24 * 60 * 60, $cookie->getMaxAge(), 5);
        // refresh token 跟 access token 是不同的值
        $this->assertNotSame($response->json('token'), $cookie->getValue());

        // 實際送出的 header 字串也確認一次（瀏覽器看的是這個）
        $raw = strtolower($this->rawSetCookieHeader($response));
        foreach (['; path=/', '; secure', '; httponly', '; samesite=none', '; partitioned', '; max-age='] as $attribute) {
            $this->assertStringContainsString($attribute, $raw);
        }
        $this->assertStringNotContainsString('domain=', $raw);

        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseCount('personal_refresh_tokens', 1);
    }

    public function test_failed_login_does_not_set_the_cookie()
    {
        $this->createUser();

        $response = $this->send('POST', '/api/auth/login', body: [
            'email' => 'owner@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertUnprocessable();
        $this->assertNull($this->refreshCookie($response));
        $this->assertDatabaseCount('personal_refresh_tokens', 0);
    }

    public function test_refresh_returns_a_new_access_token_and_rotates_the_refresh_token()
    {
        $user = $this->createUser();
        $login = $this->login();
        $oldAccessToken = $login->json('token');
        $oldRefresh = $this->refreshCookie($login)->getValue();

        $response = $this->send('POST', '/api/auth/refresh', $this->cookieJar($oldRefresh));

        $response->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('expires_in', 15 * 60)
            ->assertJsonStructure(['token', 'expires_in', 'data']);
        $newAccessToken = $response->json('token');
        $newRefresh = $this->refreshCookie($response);
        $this->assertNotSame($oldAccessToken, $newAccessToken);
        $this->assertNotNull($newRefresh);
        $this->assertNotSame($oldRefresh, $newRefresh->getValue());
        $this->assertTrue($newRefresh->isPartitioned());

        // 新的 access token 能用
        $this->send('GET', '/api/user', headers: ['Authorization' => "Bearer {$newAccessToken}"])
            ->assertOk()
            ->assertJsonPath('id', $user->id);

        // 舊的 refresh token 已經用掉：重放回 401、清 cookie
        $replay = $this->send('POST', '/api/auth/refresh', $this->cookieJar($oldRefresh));
        $replay->assertUnauthorized();
        $this->assertCookieCleared($replay);

        // 換發時舊的 access token 一起撤銷
        $this->send('GET', '/api/user', headers: ['Authorization' => "Bearer {$oldAccessToken}"])
            ->assertUnauthorized();

        // 新的 refresh token 還能再換一次
        $this->send('POST', '/api/auth/refresh', $this->cookieJar($newRefresh->getValue()))->assertOk();

        $this->assertDatabaseCount('personal_refresh_tokens', 1);
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_refresh_without_cookie_returns_401_and_clears_the_cookie()
    {
        $response = $this->send('POST', '/api/auth/refresh');

        $response->assertUnauthorized();
        $this->assertCookieCleared($response);
    }

    public function test_refresh_with_garbage_cookie_returns_401_and_clears_the_cookie()
    {
        $this->createUser();
        $valid = $this->refreshCookie($this->login())->getValue();
        [$id] = explode('|', $valid, 2);

        // 非數字的 id（'abc|def'、'|x'）在 pgsql 曾經讓套件的 findToken() 丟 500，
        // 現在由 RefreshTokenCookie::read() 先擋掉
        $garbage = ['garbage', '999|nope', "{$id}|wrong-secret", '|', '|x', 'abc|def', '0|x', '99999999999999999999|x', "{$id}|"];
        foreach ($garbage as $value) {
            $response = $this->send('POST', '/api/auth/refresh', $this->cookieJar($value));

            $response->assertUnauthorized();
            $this->assertCookieCleared($response);
        }

        // 亂打不會影響真的那支
        $this->send('POST', '/api/auth/refresh', $this->cookieJar($valid))->assertOk();
    }

    public function test_logout_with_garbage_cookie_still_logs_out()
    {
        $this->createUser();
        $token = $this->login()->json('token');

        $response = $this->send('POST', '/api/auth/logout', $this->cookieJar('abc|def'), ['Authorization' => "Bearer {$token}"]);

        $response->assertOk();
        $this->assertCookieCleared($response);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_refresh_token_expires_after_the_configured_lifetime()
    {
        $this->createUser();
        $value = $this->refreshCookie($this->login())->getValue();

        $this->travel(30 * 24 * 60 + 1)->minutes();

        $response = $this->send('POST', '/api/auth/refresh', $this->cookieJar($value));
        $response->assertUnauthorized();
        $this->assertCookieCleared($response);
    }

    public function test_access_token_expires_after_the_configured_lifetime()
    {
        $this->createUser();
        $token = $this->login()->json('token');
        $bearer = ['Authorization' => "Bearer {$token}"];

        $this->travel(14)->minutes();
        $this->send('GET', '/api/user', headers: $bearer)->assertOk();

        $this->travel(2)->minutes();
        $this->send('GET', '/api/user', headers: $bearer)->assertUnauthorized();
    }

    public function test_access_token_lifetime_is_configurable()
    {
        config(['sanctum.expiration' => 5]);
        $this->createUser();

        $login = $this->login();
        $login->assertJsonPath('expires_in', 5 * 60);
        $bearer = ['Authorization' => 'Bearer '.$login->json('token')];

        $this->travel(6)->minutes();
        $this->send('GET', '/api/user', headers: $bearer)->assertUnauthorized();
    }

    public function test_refresh_session_and_logout_reject_a_wrong_or_missing_origin()
    {
        $user = $this->createUser();
        $login = $this->login();
        $cookies = $this->cookieJar($this->refreshCookie($login)->getValue());
        $bearer = 'Bearer '.$login->json('token');

        foreach (['https://evil.example', null] as $origin) {
            $this->send('POST', '/api/auth/refresh', $cookies, ['Origin' => $origin])->assertForbidden();
            $this->send('POST', '/api/auth/session', $cookies, ['Origin' => $origin, 'Authorization' => $bearer])->assertForbidden();
            $this->send('POST', '/api/auth/logout', $cookies, ['Origin' => $origin, 'Authorization' => $bearer])->assertForbidden();
        }

        // 都被擋下來，什麼都沒有被撤銷或換發
        $this->assertDatabaseCount('personal_refresh_tokens', 1);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->send('POST', '/api/auth/refresh', $cookies)->assertOk()->assertJsonPath('data.id', $user->id);
    }

    public function test_oauth_callback_issues_a_short_lived_callback_token_and_no_cookie()
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

        $this->assertStringStartsWith(config('app.frontend_url').'/auth/callback#token=', $response->headers->get('Location'));
        // CHIPS：回呼是 run.app 的頂層導覽，在這裡設 cookie 會落在錯的分區
        $this->assertNull($this->refreshCookie($response));
        $this->assertDatabaseHas('personal_access_tokens', ['name' => TokenSocialAuthController::CALLBACK_TOKEN_NAME]);
        $this->assertDatabaseCount('personal_refresh_tokens', 0);
    }

    public function test_session_exchanges_the_oauth_callback_token_for_a_token_pair_and_cookie()
    {
        $user = $this->createUser();
        $callbackToken = $user->createToken(
            TokenSocialAuthController::CALLBACK_TOKEN_NAME,
            ['*'],
            now()->addMinutes(15),
        )->plainTextToken;

        $response = $this->send('POST', '/api/auth/session', headers: ['Authorization' => "Bearer {$callbackToken}"]);

        $response->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('expires_in', 15 * 60)
            ->assertJsonStructure(['data', 'token', 'expires_in']);
        $cookie = $this->refreshCookie($response);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isPartitioned());
        $this->assertFalse($cookie->isCleared());

        // 回呼 token 只能用一次：換發後已撤銷
        $this->assertDatabaseMissing('personal_access_tokens', ['name' => TokenSocialAuthController::CALLBACK_TOKEN_NAME]);
        $this->send('GET', '/api/user', headers: ['Authorization' => "Bearer {$callbackToken}"])->assertUnauthorized();
        $this->send('POST', '/api/auth/session', headers: ['Authorization' => "Bearer {$callbackToken}"])->assertUnauthorized();

        // 新的一組都能用
        $this->send('GET', '/api/user', headers: ['Authorization' => 'Bearer '.$response->json('token')])->assertOk();
        $this->send('POST', '/api/auth/refresh', $this->cookieJar($cookie->getValue()))->assertOk();
    }

    public function test_session_does_not_issue_a_pair_when_the_callback_token_was_used_concurrently()
    {
        // 模擬兩個同時送來的 session 請求：這個請求通過 auth:sanctum 之後、換發之前，
        // 另一個請求已經把同一支回呼 token 用掉（刪掉）了。這個請求不能再換出一組。
        $user = $this->createUser();
        $callbackToken = $user->createToken(
            TokenSocialAuthController::CALLBACK_TOKEN_NAME,
            ['*'],
            now()->addMinutes(15),
        )->plainTextToken;

        Event::listen(TokenAuthenticated::class, function (TokenAuthenticated $event) {
            PersonalAccessToken::query()->whereKey($event->token->getKey())->delete();
        });

        $response = $this->send('POST', '/api/auth/session', headers: ['Authorization' => "Bearer {$callbackToken}"]);

        $response->assertUnauthorized();
        $this->assertNull($this->refreshCookie($response));
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('personal_refresh_tokens', 0);
    }

    public function test_session_rejects_a_regular_access_token()
    {
        // 一般 access token 不能拿來換 refresh token（偷到 15 分鐘的 token 不能升級成 30 天）
        $this->createUser();
        $token = $this->login()->json('token');

        $response = $this->send('POST', '/api/auth/session', headers: ['Authorization' => "Bearer {$token}"]);

        $response->assertForbidden();
        $this->assertNull($this->refreshCookie($response));
        $this->assertDatabaseCount('personal_refresh_tokens', 1);
    }

    public function test_session_requires_a_token()
    {
        $this->send('POST', '/api/auth/session')->assertUnauthorized();
    }

    public function test_logout_revokes_the_access_token_and_the_cookie_refresh_token_and_clears_the_cookie()
    {
        $this->createUser();
        $login = $this->login();
        $token = $login->json('token');
        $refresh = $this->refreshCookie($login)->getValue();

        $response = $this->send('POST', '/api/auth/logout', $this->cookieJar($refresh), ['Authorization' => "Bearer {$token}"]);

        $response->assertOk()->assertJsonPath('message', '已登出。');
        $this->assertCookieCleared($response);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('personal_refresh_tokens', 0);

        $this->send('GET', '/api/user', headers: ['Authorization' => "Bearer {$token}"])->assertUnauthorized();
        $this->send('POST', '/api/auth/refresh', $this->cookieJar($refresh))->assertUnauthorized();
    }

    public function test_logout_also_revokes_a_cookie_refresh_token_from_a_different_pair()
    {
        // 前端拿著的 access token 跟 cookie 裡的 refresh token 不一定是同一組
        // （例如另一個分頁換發過），兩支都要撤。
        $user = $this->createUser();
        $first = $this->login();
        $second = $this->login();
        $this->assertDatabaseCount('personal_refresh_tokens', 2);

        $response = $this->send(
            'POST',
            '/api/auth/logout',
            $this->cookieJar($this->refreshCookie($second)->getValue()),
            ['Authorization' => 'Bearer '.$first->json('token')],
        );

        $response->assertOk();
        $this->assertDatabaseCount('personal_refresh_tokens', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_logout_does_not_revoke_another_users_refresh_token()
    {
        $this->createUser();
        $other = User::factory()->create(['email' => 'other@example.com', 'password' => self::PASSWORD]);
        $otherRefresh = $this->refreshCookie($this->send('POST', '/api/auth/login', body: [
            'email' => 'other@example.com',
            'password' => self::PASSWORD,
        ]))->getValue();
        $token = $this->login()->json('token');

        $response = $this->send('POST', '/api/auth/logout', $this->cookieJar($otherRefresh), ['Authorization' => "Bearer {$token}"]);

        $response->assertOk();
        $this->assertCookieCleared($response);
        $this->send('POST', '/api/auth/refresh', $this->cookieJar($otherRefresh))
            ->assertOk()
            ->assertJsonPath('data.id', $other->id);
    }

    public function test_logout_without_cookie_still_works_for_the_old_frontend()
    {
        // 舊版前端（還沒接 refresh）登出時只帶 Bearer，不帶 cookie
        $user = $this->createUser();
        $token = $user->createToken('my-dev-grid-front')->plainTextToken;

        $response = $this->send('POST', '/api/auth/logout', headers: ['Authorization' => "Bearer {$token}"]);

        $response->assertOk();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_cors_allows_credentials_only_for_the_frontend_origin()
    {
        $preflight = fn (string $origin) => $this->send('OPTIONS', '/api/auth/refresh', headers: [
            'Origin' => $origin,
            'Access-Control-Request-Method' => 'POST',
        ]);

        $allowed = $preflight(config('app.frontend_url'));
        $allowed->assertHeader('Access-Control-Allow-Origin', config('app.frontend_url'));
        $allowed->assertHeader('Access-Control-Allow-Credentials', 'true');

        // 只放行一個 origin 時，CORS middleware（fruitcake/php-cors）一律回那個固定值，
        // 不會把對方的 Origin 照抄回去——瀏覽器比對不符就擋，效果等同拒絕。
        $denied = $preflight('https://evil.example');
        $this->assertNotSame('https://evil.example', $denied->headers->get('Access-Control-Allow-Origin'));
        $this->assertNotSame('*', $denied->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_refresh_has_its_own_rate_limit()
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->send('POST', '/api/auth/refresh')->assertUnauthorized();
        }

        $this->send('POST', '/api/auth/refresh')
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }
}
