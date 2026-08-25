<?php

use App\Http\Controllers\DocumentationController;
use App\Http\Controllers\ImplementationController;
use App\Http\Controllers\RelationController;
use App\Http\Controllers\ScopeController;
use App\Http\Controllers\TechniqueController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');

Route::apiResources([
    'scopes' => ScopeController::class,
    'relations' => RelationController::class,
    'documentations' => DocumentationController::class,
    'techniques' => TechniqueController::class,
    'implementations' => ImplementationController::class,
]);
