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
