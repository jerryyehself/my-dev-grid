<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use SocialiteProviders\Line\Provider;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // socialiteproviders/line 官方安裝說明（Laravel 11+）：
        // 用 Event::listen 掛上 SocialiteWasCalled，讓 Socialite 認得 'line' 這個 driver。
        // Google 是 Socialite 內建 driver，不需要這道手續。
        Event::listen(function (SocialiteWasCalled $event) {
            $event->extendSocialite('line', Provider::class);
        });

        $this->configureRateLimiting();
    }

    /**
     * 定義具名 rate limiter（Laravel 官方文件：Routing → Rate Limiting）。
     * 超過上限時 throttle middleware 回 HTTP 429（含 Retry-After header）。
     *
     * 三個 limiter 在快取裡各用自己的 key 前綴，桶子互不影響。
     * 正式環境 CACHE_STORE=database，所有 Cloud Run instance 共用同一份計數。
     */
    protected function configureRateLimiting(): void
    {
        // 一般 API（routes/api.php 整組）：每分鐘 60 次，依 IP。
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(60)->by(self::clientIp($request)));

        // 登入（POST /auth/login 與 POST /api/auth/login）：每分鐘 5 次，依 IP。
        //
        // 刻意「只依 IP」，不加 email 當 key：key 若含 email，攻擊者從同一個 IP
        // 換不同 email 就能無限嘗試（密碼噴灑攻擊），等於這條限制形同虛設。
        // 代價是同一個 NAT 後面的人共用 5 次額度；這個站的登入使用者極少，可以接受。
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(self::clientIp($request)));

        // 社群登入（/auth/{provider}/*、/auth/token/{provider}/*）：每分鐘 20 次，依 IP。
        //
        // 不共用 5 次的 login limiter：一次正常的 OAuth 登入就要打 redirect＋callback
        // 兩個請求，而且 callback 是 Google/LINE 把使用者導回來的瀏覽器頁面，被 429
        // 擋掉會直接看到錯誤頁，所以額度給寬一點。這兩支本身不驗證密碼、不能被拿來
        // 猜密碼，限制只是避免有人狂打去消耗對 Google/LINE 的呼叫與建立帳號。
        RateLimiter::for('social-login', fn (Request $request) => Limit::perMinute(20)->by(self::clientIp($request)));
    }

    /**
     * 取得用來當 rate limit key 的用戶端 IP。
     *
     * Cloud Run 的 Google 前端會把真實連線 IP 「附加」在 X-Forwarded-For 最右邊，
     * 左邊的值則是用戶端自己送來的、不可信。所以優先取最右邊那個合法 IP；
     * 沒有這個 header（本機、測試、直連）時退回 $request->ip()。
     *
     * 若之後在 Cloud Run 前面再加 Load Balancer，最右邊會變成 LB 的 IP，
     * 這裡要改成往左數一個。
     */
    public static function clientIp(Request $request): string
    {
        $forwarded = (string) $request->headers->get('X-Forwarded-For', '');
        $last = trim(Arr::last(explode(',', $forwarded)) ?? '');

        if ($last !== '' && filter_var($last, FILTER_VALIDATE_IP) !== false) {
            return $last;
        }

        return (string) $request->ip();
    }
}
