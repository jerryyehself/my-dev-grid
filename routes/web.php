<?php

use App\Http\Controllers\RelationController;
use App\Http\Controllers\ScopeController;
use Illuminate\Support\Facades\Route;

Route::get('/{any}', fn() => view('app'))->where('any', '.*');

Route::resources([
    'scopes' => ScopeController::class,
    'relations' => RelationController::class
]);
