<?php

use App\Http\Controllers\Auth\SessionAuthController;
use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Controllers\RelationController;
use App\Http\Controllers\ScopeController;
use App\Http\Controllers\TechniqueController;
use Illuminate\Support\Facades\Route;

Route::resources([
    'scopes' => ScopeController::class,
    'relations' => RelationController::class,
    'projects' => TechniqueController::class,
]);

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
Route::get('/auth/{provider}/redirect', [SocialAuthController::class, 'redirect'])
    ->name('auth.social.redirect');
Route::get('/auth/{provider}/callback', [SocialAuthController::class, 'callback'])
    ->name('auth.social.callback');
Route::post('/auth/login', [SessionAuthController::class, 'login'])->name('auth.login');
Route::post('/auth/logout', [SessionAuthController::class, 'logout'])->name('auth.logout');

Route::get('/{any}', fn () => view('app'))->where('any', '.*');
