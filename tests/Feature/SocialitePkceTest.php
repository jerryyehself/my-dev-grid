<?php

namespace Tests\Feature;

use App\Models\OauthIdentity;
use App\Models\User;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Google／LINE 登入的 PKCE（issue #103），兩種登入模式都測：
 * Triple 的 session 模式（/auth/*，SocialAuthController）跟
 * my-dev-grid-front 的 token 模式（/auth/token/*，TokenSocialAuthController）。
 *
 * 不用 Socialite::fake()：fake 的 redirect() 回固定網址、user() 直接回假使用者，
 * 完全不經過 PKCE 那段。這裡跑真的 driver，只把對外的 HTTP（換 token、拿使用者資料）
 * 透過 services.{provider}.guzzle 換成 Guzzle MockHandler，再檢查實際送出的請求。
 */
class SocialitePkceTest extends TestCase
{
    use RefreshDatabase;

    /** 各 provider 的 token 端點與換到 token 後拿使用者資料的回應 */
    private const PROVIDERS = [
        'google' => [
            'token_url' => 'https://www.googleapis.com/oauth2/v4/token',
            'profile' => ['sub' => 'google-pkce-123', 'name' => 'PKCE 測試', 'email' => 'pkce@example.com'],
            'provider_user_id' => 'google-pkce-123',
        ],
        'line' => [
            'token_url' => 'https://api.line.me/oauth2/v2.1/token',
            'profile' => ['userId' => 'line-pkce-123', 'displayName' => 'PKCE 測試'],
            'provider_user_id' => 'line-pkce-123',
        ],
    ];

    public static function loginModeAndProviderProvider(): array
    {
        return [
            'session 模式 google' => ['/auth', 'google'],
            'session 模式 line' => ['/auth', 'line'],
            'token 模式 google' => ['/auth/token', 'google'],
            'token 模式 line' => ['/auth/token', 'line'],
        ];
    }

    #[DataProvider('loginModeAndProviderProvider')]
    public function test_redirect_sends_s256_code_challenge_derived_from_session_verifier(string $prefix, string $provider)
    {
        $response = $this->get("{$prefix}/{$provider}/redirect");

        $response->assertRedirect();
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->assertSame('S256', $query['code_challenge_method'] ?? null);

        // code_verifier 只存在 session（伺服器端），網址上只有它的 S256 雜湊。
        $verifier = session('code_verifier');
        $this->assertIsString($verifier);
        // RFC 7636 §4.1：code_verifier 長度 43～128。
        $this->assertGreaterThanOrEqual(43, strlen($verifier));
        $this->assertLessThanOrEqual(128, strlen($verifier));
        $this->assertSame(
            rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            $query['code_challenge'] ?? null,
        );
        $this->assertStringNotContainsString($verifier, $response->headers->get('Location'));
    }

    #[DataProvider('loginModeAndProviderProvider')]
    public function test_callback_sends_session_code_verifier_to_token_endpoint(string $prefix, string $provider)
    {
        $user = User::factory()->create();
        OauthIdentity::factory()->create([
            'user_id' => $user->id,
            'provider' => $provider,
            'provider_user_id' => self::PROVIDERS[$provider]['provider_user_id'],
        ]);
        $history = $this->fakeProviderHttp($provider);

        $response = $this->withSession(['state' => 'pkce-state', 'code_verifier' => 'pkce-verifier'])
            ->get("{$prefix}/{$provider}/callback?state=pkce-state&code=pkce-code");

        $tokenRequest = $history[0]['request'];
        $this->assertSame('POST', $tokenRequest->getMethod());
        $this->assertSame(self::PROVIDERS[$provider]['token_url'], (string) $tokenRequest->getUri());
        parse_str((string) $tokenRequest->getBody(), $fields);
        $this->assertSame('pkce-verifier', $fields['code_verifier'] ?? null);
        $this->assertSame('pkce-code', $fields['code'] ?? null);

        // 用過就從 session 拿掉，同一個 verifier 不能再換第二次。
        $response->assertSessionMissing('code_verifier');

        // 整段流程照常走完、登入成功（不是因為 PKCE 卡在錯誤頁）。
        if ($prefix === '/auth') {
            $response->assertRedirect('/');
            $this->assertAuthenticatedAs($user);
        } else {
            $this->assertStringStartsWith(
                config('app.frontend_url').'/auth/callback#token=',
                $response->headers->get('Location'),
            );
        }
    }

    /**
     * 部署切換的情境：舊版（還沒開 PKCE）導去授權頁的使用者，callback 落到新版。
     * session 裡沒有 code_verifier，換 token 時就不帶這個欄位（不會送出空字串），
     * 授權頁當時也沒收到 code_challenge，provider 那邊不會要求 verifier。
     */
    #[DataProvider('loginModeAndProviderProvider')]
    public function test_callback_omits_code_verifier_when_login_started_before_pkce(string $prefix, string $provider)
    {
        $history = $this->fakeProviderHttp($provider);

        $this->withSession(['state' => 'pkce-state'])
            ->get("{$prefix}/{$provider}/callback?state=pkce-state&code=pkce-code");

        parse_str((string) $history[0]['request']->getBody(), $fields);
        $this->assertSame('pkce-code', $fields['code'] ?? null);
        $this->assertArrayNotHasKey('code_verifier', $fields);
    }

    /**
     * 把這個 provider 對外的 HTTP 換成假回應（先換 token，再拿使用者資料），
     * 回傳的陣列會在請求送出後填入實際請求，供斷言用。
     */
    private function fakeProviderHttp(string $provider): \ArrayObject
    {
        $history = new \ArrayObject;
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'access_token' => 'fake-access-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ])),
            new Response(200, ['Content-Type' => 'application/json'], json_encode(self::PROVIDERS[$provider]['profile'])),
        ]));
        $stack->push(Middleware::history($history));

        config(["services.{$provider}.guzzle" => ['handler' => $stack]]);

        return $history;
    }
}
