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
    // App\Service\RefreshTokenFamilies 發 token 時也是讀這個值。
    'expiration' => (int) env('SANCTUM_ACCESS_TOKEN_EXPIRATION', 15),

    // refresh token 壽命（分鐘，預設 30 天）。每次換發新的那支都從換發當下起算，
    // 等於滑動 30 天，但不會超過下面的絕對上限（App\Service\RefreshTokenFamilies）。
    // `_no_remember` 是套件自己的預設值路徑（remember=false 時讀，套件預設只有 1 天）；
    // 這個專案不走套件的發行邏輯，兩個 key 仍設成同一個值，萬一走到套件也是同樣的壽命。
    'refresh_token_expiration' => (int) env('SANCTUM_REFRESH_TOKEN_EXPIRATION', 43200),
    'refresh_token_expiration_no_remember' => (int) env('SANCTUM_REFRESH_TOKEN_EXPIRATION', 43200),

    // 一次登入最長能維持多久（分鐘，預設 90 天），從原始登入時間（family_started_at）
    // 算起，換發不會延後。超過就不再換發、要重新登入。
    'refresh_token_max_lifetime' => (int) env('SANCTUM_REFRESH_TOKEN_MAX_LIFETIME', 129600),

    // 已經用掉的 refresh token 在幾秒內又被送來，算「同一個瀏覽器兩個分頁同時換發」
    // 而不是被偷（回 409、不撤銷）；超過這個秒數才觸發整個家族撤銷。前端平常用
    // Web Locks 讓分頁排隊，只有不支援的瀏覽器才會撞到這個寬限期。取捨見
    // App\Service\RefreshTokenFamilies 的類別註解。
    'refresh_token_reuse_grace_seconds' => (int) env('SANCTUM_REFRESH_TOKEN_REUSE_GRACE', 10),

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
