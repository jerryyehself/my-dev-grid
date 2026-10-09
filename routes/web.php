<?php

use App\Http\Controllers\Auth\SessionAuthController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\Auth\TokenSocialAuthController;
use Illuminate\Support\Facades\Route;

// 這裡刻意不註冊任何資源路由。所有 CRUD 都只走 `routes/api.php`，
// 它把 store/update/destroy 收在 `auth:sanctum` 底下。
//
// 2026-09-16 移除了原本的 `Route::resources(['scopes', 'relations', 'projects'])`：
// 那三組是死碼，沒有任何消費者——Triple 後台（resources/js）全部打 `/api/*`
// （11 處呼叫點，例如 useDataStore.js、AppTripleEdit.vue），tests/ 裡也沒有
// 任何測試打這些路徑，且整個專案沒有用過 route() 具名解析。它們回傳的 JSON
// 跟 api.php 完全相同（controller 一律 response()->json()），差別只在**少了
// auth:sanctum**——寫入之所以沒被打穿，純粹是因為每個 controller 方法內部
// 有 $this->authorize()，等於安全靠「controller 剛好有寫」而不是路由層。
//
// 順帶消掉一顆地雷：`projects` 是以完整 resource 註冊的，會生出
// `projects.create` 與 `projects.edit`，但 TechniqueController 根本沒有
// create()/edit() 方法，所以 GET /projects/create 一被打到就是 500。

// 登入/登出（Google/LINE OAuth + email+password 備援，見 decision-register.md
// D-34）。一定要放在下面的萬用 catch-all 之前——排在它後面的路由都是死碼。
//
// 這裡刻意不對 {provider} 加 ->whereIn(...) 路由層級限制：這個專案的
// catch-all `/{any}` 會吃掉任何在它之前沒有路由「匹配成功」的路徑，
// 如果在這裡用 whereIn 限制、遇到不認識的 provider 值，Laravel 不會回
// 404，而是直接跳過這條路由、讓請求落到後面的 catch-all，變成默默把
// SPA 殼頁面渲染出來，不是我們要的「未知 provider 就 404」效果。改成
// 讓 {provider} 先原封不動吃下任何值，實際驗證交給 controller 自己的
// abort_unless()，才能確保未知 provider 真的回 404。
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
// 不是 session（decision-register.md D-56）。放在 web.php 而不是 api.php，
// 是因為 Socialite 的 state 防偽驗證需要 session 支援，api.php 走的是
// 無 session 的 stateless 'api' middleware group；email/password 登入
// 跟登出不需要 Socialite，所以那兩支在 routes/api.php（見該檔案）。
// 路徑多一段 /token/，跟上面 Triple 用的 /auth/{provider}/* 區分開，
// 兩組 controller 刻意不共用（見 TokenSocialAuthController 的類別註解）。
Route::middleware('throttle:social-login')->group(function () {
    Route::get('/auth/token/{provider}/redirect', [TokenSocialAuthController::class, 'redirect'])
        ->name('auth.token.social.redirect');
    Route::get('/auth/token/{provider}/callback', [TokenSocialAuthController::class, 'callback'])
        ->name('auth.token.social.callback');
});

// SPA 殼頁面的 catch-all。排除兩種路徑：
// - `api`、`api/*`：不存在的 API 路徑要回 404（problem details），不是 200 的 HTML 殼頁面。
// - `docs/api`、`docs/api.json`：API 文件（Scramble）。它的路由在所有路由檔之後才註冊，
//   不排除的話會先被這條接走。
Route::get('/{any}', fn () => view('app'))->where('any', '(?!api(?:/|$)|docs/api(?:\.json)?$).*');
