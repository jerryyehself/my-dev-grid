<?php

namespace App\Providers;

use App\Http\RefreshTokenCookie;
use App\OpenApi\ApiSecurity;
use App\OpenApi\ProblemDetailsResponses;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\SecurityScheme;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
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

        $this->configureApiDocs();

        // Cloud Run 在前端（Google Front End）終止 HTTPS，轉給容器的是 http 請求，
        // 所以 route()／url() 產生的網址會是 http://。OAuth 的 redirect_uri 必須
        // 跟 Google／LINE 後台登記的 https:// 網址一字不差，否則登入被拒。
        // bootstrap/app.php 的 trustProxies 刻意只信任 X-Forwarded-For（不信任
        // Proto），所以這裡依 APP_URL 決定：APP_URL 是 https 就強制所有產生的網址用
        // https。本機 APP_URL 是 http://localhost，不受影響。
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }
    }

    /**
     * 定義具名 rate limiter（Laravel 官方文件：Routing → Rate Limiting）。
     * 超過上限時 throttle middleware 回 HTTP 429（含 Retry-After header）。
     *
     * 各 limiter 在快取裡各用自己的 key 前綴，桶子互不影響。
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

        // refresh token 換發（POST /api/auth/refresh、POST /api/auth/session）：每分鐘 30 次，依 IP。
        //
        // 不共用 5 次的 login limiter：前端每次整頁重新整理都會打一次 refresh，
        // access token 過期（401）時也會打一次，作者自己開幾個分頁、連按幾次重新整理
        // 就會用完 5 次，被擋下來等於被登出；反過來也不該讓重新整理吃掉帳密登入的額度。
        // refresh token 是 40 字元隨機值（資料庫只存雜湊），用猜的不可行，這裡的限制
        // 只是擋異常的大量請求。整組 api 每分鐘 60 次的上限照樣疊在上面。
        RateLimiter::for('token-refresh', fn (Request $request) => Limit::perMinute(30)->by(self::clientIp($request)));
    }

    /**
     * OpenAPI 文件（Scramble，D-118）裡程式碼推不出來的部分。
     *
     * - 驗證方式：Sanctum bearer token（文件層級預設）跟 refresh token cookie；
     *   每支端點實際用哪一種由 ApiSecurity 依路由的 middleware 標出來。
     * - 錯誤格式：4xx／5xx 一律是 problem details，另外每支端點都可能回 429
     *   （ProblemDetailsResponses）。
     *
     * 文件頁本身的設定（路徑、公開與否、頻率限制）在 config/scramble.php。
     */
    protected function configureApiDocs(): void
    {
        Scramble::configure()
            ->withDocumentTransformers(function (OpenApi $openApi) {
                $openApi->secure(
                    SecurityScheme::http('bearer')
                        ->as(ApiSecurity::BEARER)
                        ->setDescription('Sanctum API token。由 `POST /auth/login`、`POST /auth/refresh`、`POST /auth/session` 取得，有效 15 分鐘。')
                );
                $openApi->components->addSecurityScheme(
                    ApiSecurity::REFRESH_COOKIE,
                    SecurityScheme::apiKey('cookie', RefreshTokenCookie::NAME)
                        ->as(ApiSecurity::REFRESH_COOKIE)
                        ->setDescription('refresh token（有效 30 天、單次使用），登入時由伺服器以 HttpOnly cookie 設定，前端 JS 讀不到。')
                );
            })
            ->withDocumentTransformers(ProblemDetailsResponses::class)
            ->withOperationTransformers(ApiSecurity::class);
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
