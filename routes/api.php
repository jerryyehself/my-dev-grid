<?php

use App\Http\Controllers\RelationController;
use App\Http\Controllers\ScopeController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');

Route::apiResources([
    'scopes' => ScopeController::class,
    'relations' => RelationController::class
]);
