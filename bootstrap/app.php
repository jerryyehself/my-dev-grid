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
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
