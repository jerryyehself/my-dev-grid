<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

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
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
