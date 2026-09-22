<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | 這個 app 之前沒有 config/cors.php——Triple 後台跟這個 Laravel app 同源，
    | 從來沒有真的跨網域打過這個 API，所以沒人發現這個檔案根本不存在。
    | `my-dev-grid-front` 是跨 origin 的（decision-register.md D-49/D-56），
    | 沒有這份設定，瀏覽器會直接擋下它打 /api/* 的請求，跟登入模式選哪個無關。
    |
    | `supports_credentials` 刻意維持 false：my-dev-grid-front 走的是 Sanctum
    | API token（`Authorization: Bearer`），不是 cookie，不需要瀏覽器帶
    | credentials；Triple 後台的 cookie 模式是同源請求，本來就不受 CORS 管。
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter([env('FRONTEND_URL')]),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
