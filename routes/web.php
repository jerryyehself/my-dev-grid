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
Route::get('/auth/{provider}/redirect', [SocialAuthController::class, 'redirect'])
    ->whereIn('provider', ['google', 'line'])
    ->name('auth.social.redirect');
Route::get('/auth/{provider}/callback', [SocialAuthController::class, 'callback'])
    ->whereIn('provider', ['google', 'line'])
    ->name('auth.social.callback');
Route::post('/auth/login', [SessionAuthController::class, 'login'])->name('auth.login');
Route::post('/auth/logout', [SessionAuthController::class, 'logout'])->name('auth.logout');

Route::get('/{any}', fn () => view('app'))->where('any', '.*');
