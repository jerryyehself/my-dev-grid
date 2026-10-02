<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rate limiting（2026-10-02 站長決定）：
 * 登入每分鐘 5 次/IP、一般 API 每分鐘 60 次/IP，超過回 429。
 * limiter 定義在 AppServiceProvider，key 取法見 AppServiceProvider::clientIp()。
 *
 * 測試用的是正式環境的上限值（5 / 60），沒有另外調低。
 * 每個測試都是全新的 app 實例與 array cache，計數不會跨測試殘留。
 */
class RateLimitTest extends TestCase
{
    use RefreshDatabase;

    private function attemptApiLogin(?string $forwardedFor = null)
    {
        $headers = $forwardedFor ? ['X-Forwarded-For' => $forwardedFor] : [];

        return $this->postJson('/api/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong-password',
        ], $headers);
    }

    public function test_api_login_returns_429_on_the_sixth_attempt_within_a_minute()
    {
        for ($i = 1; $i <= 5; $i++) {
            // 帳號不存在 → 422，代表前 5 次都有進到 controller，沒被擋。
            $this->attemptApiLogin()->assertUnprocessable();
        }

        $this->attemptApiLogin()
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_session_login_returns_429_on_the_sixth_attempt_within_a_minute()
    {
        $attempt = fn () => $this->postJson('/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'wrong-password',
        ]);

        for ($i = 1; $i <= 5; $i++) {
            $attempt()->assertUnprocessable();
        }

        $attempt()->assertStatus(429);
    }

    public function test_api_returns_429_on_the_61st_request_within_a_minute()
    {
        for ($i = 1; $i <= 60; $i++) {
            $this->getJson('/api/scopes')->assertOk();
        }

        $this->getJson('/api/scopes')
            ->assertStatus(429)
            ->assertHeader('Retry-After');
    }

    public function test_login_attempts_do_not_use_up_the_general_api_bucket()
    {
        // 登入桶子（5）用完之後，一般 API 仍然可用——兩個 limiter 的桶子要分開，
        // 否則被鎖的登入會連帶讓整個 API 變 429。
        for ($i = 1; $i <= 6; $i++) {
            $this->attemptApiLogin();
        }

        $this->getJson('/api/scopes')->assertOk();
    }

    public function test_a_different_forwarded_ip_gets_its_own_bucket()
    {
        // 模擬 Cloud Run：Google 前端把真實 IP 附加在 X-Forwarded-For。
        for ($i = 1; $i <= 5; $i++) {
            $this->attemptApiLogin('203.0.113.1')->assertUnprocessable();
        }
        $this->attemptApiLogin('203.0.113.1')->assertStatus(429);

        // 另一個 IP 有自己的 5 次額度，不受影響。
        $this->attemptApiLogin('203.0.113.2')->assertUnprocessable();

        // 原本那個 IP 還是被擋。
        $this->attemptApiLogin('203.0.113.1')->assertStatus(429);
    }

    public function test_a_different_forwarded_ip_gets_its_own_api_bucket()
    {
        for ($i = 1; $i <= 60; $i++) {
            $this->getJson('/api/scopes', ['X-Forwarded-For' => '203.0.113.1'])->assertOk();
        }
        $this->getJson('/api/scopes', ['X-Forwarded-For' => '203.0.113.1'])->assertStatus(429);

        $this->getJson('/api/scopes', ['X-Forwarded-For' => '203.0.113.2'])->assertOk();
    }

    public function test_spoofed_left_side_of_forwarded_for_cannot_bypass_the_limit()
    {
        // 用戶端自己偽造 X-Forwarded-For 的左邊，Google 前端仍會把真實 IP 附加在
        // 最右邊。每次換不同的偽造值也不該拿到新的桶子。
        for ($i = 1; $i <= 5; $i++) {
            $this->attemptApiLogin("10.9.8.$i, 203.0.113.9")->assertUnprocessable();
        }

        $this->attemptApiLogin('10.9.8.99, 203.0.113.9')->assertStatus(429);
    }

    public function test_social_login_routes_are_limited_but_more_generously_than_password_login()
    {
        // 不認識的 provider 由 controller 回 404（見 routes/web.php 的註解），
        // 不用 mock Socialite 就能數請求次數。
        for ($i = 1; $i <= 20; $i++) {
            $this->get('/auth/unknown-provider/redirect')->assertNotFound();
        }

        $this->get('/auth/unknown-provider/redirect')->assertStatus(429);
        $this->get('/auth/token/unknown-provider/callback')->assertStatus(429);
    }
}
