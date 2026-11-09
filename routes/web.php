<?php

use App\Http\Controllers\Auth\SessionAuthController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\Auth\TokenSocialAuthController;
use Illuminate\Support\Facades\Route;

// 這裡刻意不註冊任何資源路由。所有 CRUD 都只走 `routes/api.php`，
// 它把 store/update/destroy 收在 `auth:sanctum` 底下。
//
// 2026-09-16 移除了原本的 `Route::resources(['scopes', 'relations', 'projects'])`：
// 它們回傳的 JSON 跟 api.php 完全相同，差別只在**少了 auth:sanctum**——寫入之所以
// 沒被打穿，純粹是因為每個 controller 方法內部有 $this->authorize()，等於安全靠
// 「controller 剛好有寫」而不是路由層。不要加回來（tests/Feature/WebRoutesTest.php 釘著）。
//
// 這個檔案也不再渲染任何網頁：舊的內嵌 Vue 後台 Triple 跟它的 `/{any}` catch-all
// 已經移除，管理介面在 my-dev-grid-front（D-48）。沒有註冊的路徑一律 404。

// 登入/登出（Google/LINE OAuth + email+password 備援，見 D-34），session cookie 模式。
//
// {provider} 不加 ->whereIn(...) 路由層級限制，先原封不動吃下任何值，實際驗證交給
// controller 自己的 abort_unless()：未知 provider 回 404 的行為集中在 controller 一處，
// 不分散到路由定義。
//
// Rate limit（2026-10-02 站長決定，limiter 定義在 AppServiceProvider）：
// email+password 登入用 'login'（每分鐘 5 次/IP）；社群登入用較寬的
// 'social-login'（每分鐘 20 次/IP），原因見該 limiter 的註解。
// logout 不限制。
Route::middleware('throttle:social-login')->group(function () {
    Route::get('/auth/{provider}/redirect', [SocialAuthController::class, 'redirect'])
        ->name('auth.social.redirect');
    Route::get('/auth/{provider}/callback', [SocialAuthController::class, 'callback'])
        ->name('auth.social.callback');
});
Route::post('/auth/login', [SessionAuthController::class, 'login'])
    ->middleware('throttle:login')
    ->name('auth.login');
Route::post('/auth/logout', [SessionAuthController::class, 'logout'])->name('auth.logout');

// my-dev-grid-front（跨 origin SPA）版的 OAuth 登入——發 Sanctum API token，
// 不是 session（D-56）。放在 web.php 而不是 api.php，
// 是因為 Socialite 的 state 防偽驗證需要 session 支援，api.php 走的是
// 無 session 的 stateless 'api' middleware group；email/password 登入
// 跟登出不需要 Socialite，所以那兩支在 routes/api.php（見該檔案）。
// 路徑多一段 /token/，跟上面 session 模式的 /auth/{provider}/* 區分開，
// 兩組 controller 刻意不共用（見 TokenSocialAuthController 的類別註解）。
Route::middleware('throttle:social-login')->group(function () {
    Route::get('/auth/token/{provider}/redirect', [TokenSocialAuthController::class, 'redirect'])
        ->name('auth.token.social.redirect');
    Route::get('/auth/token/{provider}/callback', [TokenSocialAuthController::class, 'callback'])
        ->name('auth.token.social.callback');
});
