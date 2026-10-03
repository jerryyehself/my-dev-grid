<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Cloud Run 轉給容器的是 http 請求；APP_URL 是 https 時，產生的網址（特別是
 * OAuth 的 redirect_uri）要是 https，否則跟 Google／LINE 後台登記的網址對不上。
 */
class ForceHttpsUrlTest extends TestCase
{
    protected function tearDown(): void
    {
        URL::forceScheme(null);

        parent::tearDown();
    }

    public function test_oauth_redirect_uri_uses_https_when_app_url_is_https()
    {
        config(['app.url' => 'https://api.example.test', 'services.google.client_id' => 'test-client']);
        (new AppServiceProvider($this->app))->boot();

        // 模擬 Cloud Run：請求本身是 http
        $response = $this->get('http://api.example.test/auth/token/google/redirect');

        $response->assertRedirect();
        $this->assertStringContainsString(
            'redirect_uri='.urlencode('https://api.example.test/auth/token/google/callback'),
            $response->headers->get('Location'),
        );
    }

    public function test_urls_stay_http_when_app_url_is_http()
    {
        config(['app.url' => 'http://localhost']);
        (new AppServiceProvider($this->app))->boot();

        $this->assertStringStartsWith('http://', route('auth.token.social.callback', 'google'));
    }
}
