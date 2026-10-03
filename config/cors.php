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
    | `supports_credentials` 2026-10-03 起改成 true：my-dev-grid-front 平常的
    | API 呼叫仍然用 Sanctum API token（`Authorization: Bearer`），但「重新整理後
    | 維持登入」把 refresh token 放在 API 網域的 httpOnly cookie（見
    | App\Http\RefreshTokenCookie）。跨 origin 的 fetch 要讓瀏覽器存下 Set-Cookie、
    | 之後再把 cookie 帶回來，前端要用 `credentials: 'include'`，後端回應就必須帶
    | `Access-Control-Allow-Credentials: true`，否則瀏覽器會擋掉整個回應。
    |
    | 開了 credentials，allowed_origins 就絕對不能是 `*`（規範也不允許）——
    | 這裡維持只放行 FRONTEND_URL 一個 origin。`api/*` 已經涵蓋新的
    | /api/auth/refresh、/api/auth/session。Triple 後台的 cookie 模式是同源請求，
    | 本來就不受 CORS 管。
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter([env('FRONTEND_URL')]),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
