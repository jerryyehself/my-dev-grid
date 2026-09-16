<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

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
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
