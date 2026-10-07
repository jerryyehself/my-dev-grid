<?php

use App\Http\Middleware\EnsureFrontendOrigin;
use App\Http\ProblemDetails;
use App\Http\RefreshTokenCookie;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Sanctum SPA session-cookie 模式（不是 API token 模式）：
        // frontend（Triple 後台，resources/js）跟這個 Laravel app 同源，
        // 官方文件（Laravel 13 / Sanctum 4.x）指定用這個 helper 方法，
        // 它會把 EnsureFrontendRequestsAreStateful 中間件 prepend 進 'api' group。
        $middleware->statefulApi();

        // `body`（文章內文,Markdown 原文）不要被 TrimStrings 動到。
        //
        // Laravel 預設會 trim 掉每個字串輸入的前後空白,對一般欄位是好事,
        // 對 Markdown 不是:文件開頭的四個空白縮排在 Markdown 裡是「程式碼區塊」,
        // 被 trim 掉等於默默改掉了文件的意思。實測確認過預設行為會把
        // "  前導\n\n內文\n\n" 變成 "前導\n\n內文"。
        //
        // 內文是使用者寫的內容,不是表單欄位,後端不該在存進去之前替它做任何修改。
        $middleware->trimStrings(except: ['body']);

        // 信任 Cloud Run 前端（Google Front End）轉發的 X-Forwarded-For。
        //
        // 正式環境跑在 Cloud Run，服務只能從 Google 的前端進來，Laravel 看到的
        // 連線來源（REMOTE_ADDR）永遠是那一層的內部位址。沒設定信任 proxy 的話，
        // $request->ip() 回傳的就是它，所有訪客會共用同一個 rate limit 桶子——
        // 第 6 個人登入就全站被鎖。所以要告訴 Laravel 信任 X-Forwarded-For。
        //
        // 依據 Laravel 官方文件（Requests → Configuring Trusted Proxies →
        // 「Trusting All Proxies」，`at: '*'`）；只信任 X-Forwarded-For 一個 header，
        // 不連 Host／Proto／Port 一起信任，避免順帶改變 URL 產生與 Sanctum
        // stateful 網域判斷的既有行為。
        //
        // 注意：`at: '*'` 會讓 $request->ip() 取 X-Forwarded-For 「最左邊」那個值，
        // 而那一段是用戶端自己可以填的（Google 前端是把真實 IP 附加在最右邊）。
        // 所以 rate limit 的 key 不直接用 $request->ip()，改用
        // AppServiceProvider::clientIp() 取最右邊那個，避免偽造 header 繞過限制。
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR,
        );

        // 整組 routes/api.php 套用名為 'api' 的 limiter（定義在
        // AppServiceProvider::boot：每分鐘 60 次、依 IP）。
        $middleware->throttleApi('api');

        // refresh token cookie 不經過 EncryptCookies。routes/api.php 平常沒有
        // EncryptCookies（那是 web group 的），但 statefulApi() 會在請求來自
        // SANCTUM_STATEFUL_DOMAINS 時把它加進來——到時候讀到的 cookie 會被當成
        // 加密值解密失敗、變成 null。值本身是套件發的隨機 token（資料庫只存雜湊），
        // 不需要再加密一層；列進 except，不管走哪條路徑讀寫的都是同一個原始值。
        $middleware->encryptCookies(except: [RefreshTokenCookie::NAME]);

        // 未登入時不導向任何頁面，一律回 401。Laravel 預設會在請求沒帶
        // `Accept: application/json` 時導去名為 `login` 的路由，但這個 app 沒有這條路由
        // （登入頁在前端），結果是 route() 丟例外、回 500——前端的 DELETE 不帶 Accept，
        // access token 過期時拿到的會是 500 而不是觸發換發的 401。
        $middleware->redirectGuestsTo(null);

        // 會讀寫 refresh cookie 的端點要求 Origin＝FRONTEND_URL，見 EnsureFrontendOrigin。
        $middleware->alias([
            'frontend.origin' => EnsureFrontendOrigin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // API 的錯誤一律回 JSON，不看 Accept header（理由見 ProblemDetails::wanted）。
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => ProblemDetails::wanted($request)
        );

        // 例外變成回應之後的最後一關（Laravel 官方文件：Error Handling →
        // Customizing the Exception Response → `respond()`）。所有路徑都會經過這裡：
        // Laravel 內建的轉換（驗證 422、未登入 401、無權限 403、查無資料 404、429、500）、
        // 例外自己的 render()（RelationLockedException）、HttpResponseException。
        // 所以只要在這裡把 JSON 錯誤回應改成 RFC 9457 problem details，不用每種例外各寫一份。
        //
        // 500 不會帶出 stack trace：APP_DEBUG=false 時 Laravel 只放 "Server Error"，
        // 這裡沿用它的內容、只加上 problem details 欄位。
        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            if ($response instanceof JsonResponse
                && $response->getStatusCode() >= 400
                && ProblemDetails::wanted($request)) {
                return ProblemDetails::fromJsonResponse($response, $request);
            }

            return $response;
        });
    })->create();
