<?php

use App\Http\Controllers\DocumentationController;
use App\Http\Controllers\GraphController;
use App\Http\Controllers\ImplementationController;
use App\Http\Controllers\RelationController;
use App\Http\Controllers\ScopeController;
use App\Http\Controllers\TechniqueController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// 前端（SPA）開機時拿這支確認目前的登入狀態——未登入回 401，
// 已登入回目前的 User。
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// 知識圖譜資料，維持完全公開，這個 PR 不動它。
Route::get('/graph', [GraphController::class, 'index']);
// 起訖點之間的最短路徑查詢，跟 /graph 同一份公開資料、同一種公開等級。
Route::get('/graph/path', [GraphController::class, 'path']);

// 表單欄位定義（Triple 後台的新增/編輯表單靠這兩支決定欄位、型別與選項）。
//
// 一定要註冊在下面的 apiResources 之前——那組會註冊 /api/scopes/{scope}，
// 「create」會被當成 {scope} 的值去查資料庫，回 404。
//
// 這兩支在 2026-09-17 之前是靠 routes/web.php 的 Route::resources 提供的
// （Triple 呼叫的是不帶 /api 前綴的 /scopes/create）。PR #55 把那組當成死碼移除，
// 沒注意到這個呼叫點——當時的 grep 只找含 `/api` 的字串，所以看不到它。
// 移到 api.php 並讓 Triple 改打帶前綴的路徑，跟它其餘 11 處呼叫一致。
Route::get('scopes/create', [ScopeController::class, 'create']);
Route::get('relations/create', [RelationController::class, 'create']);

// 讀（index/show）維持完全公開——my-dev-grid-front 跟 Triple 後台都要
// 在不登入的情況下讀得到這些資料。
Route::apiResources([
    'scopes' => ScopeController::class,
    'relations' => RelationController::class,
    'documentations' => DocumentationController::class,
    'techniques' => TechniqueController::class,
    'implementations' => ImplementationController::class,
], ['only' => ['index', 'show']]);

// 寫（store/update/destroy）收斂到「有沒有登入」，實際擋權邏輯在
// 對應的 Policy（見 app/Policies），這裡的 middleware 只負責先擋掉
// 完全沒有登入態的請求，回 401。
Route::middleware('auth:sanctum')->group(function () {
    Route::apiResources([
        'scopes' => ScopeController::class,
        'relations' => RelationController::class,
        'documentations' => DocumentationController::class,
        'techniques' => TechniqueController::class,
        'implementations' => ImplementationController::class,
    ], ['except' => ['index', 'show']]);
});
