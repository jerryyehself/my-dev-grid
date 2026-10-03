<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;
use Laravel\Sanctum\Sanctum;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Requests from the following domains / hosts will receive stateful API
    | authentication cookies. Typically, these should include your local
    | and production domains which access your API via a frontend SPA.
    |
    */

    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
        Sanctum::currentApplicationUrlWithPort(),
        // Sanctum::currentRequestHost(),
    ))),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | This array contains the authentication guards that will be checked when
    | Sanctum is trying to authenticate a request. If none of these guards
    | are able to authenticate the request, Sanctum will use the bearer
    | token that's present on an incoming request for authentication.
    |
    */

    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | This value controls the number of minutes until an issued token will be
    | considered expired. This will override any values set in the token's
    | "expires_at" attribute, but first-party sessions are not affected.
    |
    */

    // my-dev-grid-front 的 access token 壽命（分鐘）。原本是 null（永不過期）；
    // 改成短效 access token＋長效 refresh token（httpOnly cookie）之後，
    // access token 只活 15 分鐘，過期由前端用 refresh token 換新的。
    //
    // 這個值 Sanctum 會套用到「所有」API token（看 created_at），包含改版前發出、
    // 沒有 expires_at 的舊 token——部署後那些舊 token 會一併失效，這是刻意的。
    // Triple 後台走 session cookie，不受這個值影響（上面註解的 first-party sessions）。
    // d076/sanctum-refresh-tokens 發 token 時也是讀這個值。
    'expiration' => (int) env('SANCTUM_ACCESS_TOKEN_EXPIRATION', 15),

    // refresh token 壽命（分鐘，預設 30 天），d076/sanctum-refresh-tokens 讀這兩個 key。
    // 套件在 remember=false 時讀 `_no_remember`（套件預設只有 1 天）；這個專案
    // 不分「記得我」，兩個 key 刻意設成同一個值，不管走哪條路徑都是同樣的壽命。
    // 換發（refresh）時套件會沿用原本那支 refresh token 的壽命，等於滑動 30 天。
    'refresh_token_expiration' => (int) env('SANCTUM_REFRESH_TOKEN_EXPIRATION', 43200),
    'refresh_token_expiration_no_remember' => (int) env('SANCTUM_REFRESH_TOKEN_EXPIRATION', 43200),

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | Sanctum can prefix new tokens in order to take advantage of numerous
    | security scanning initiatives maintained by open source platforms
    | that notify developers if they commit tokens into repositories.
    |
    | See: https://docs.github.com/en/code-security/secret-scanning/about-secret-scanning
    |
    */

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | When authenticating your first-party SPA with Sanctum you may need to
    | customize some of the middleware Sanctum uses while processing the
    | request. You may change the middleware listed below as required.
    |
    */

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
