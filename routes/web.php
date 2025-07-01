<?php

use App\Http\Controllers\RelationController;
use App\Http\Controllers\ScopeController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('main');
});

Route::resources([
    '/scopes' => ScopeController::class,
    'relations' => RelationController::class
]);
