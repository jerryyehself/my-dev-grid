<?php

use App\Http\Controllers\RelationController;
use App\Http\Controllers\ScopeController;
use App\Http\Controllers\ImplementationController;
use App\Http\Controllers\TechniqueController;
use Illuminate\Support\Facades\Route;

Route::resources([
    'scopes' => ScopeController::class,
    'relations' => RelationController::class,
    'projects' => TechniqueController::class
]);

Route::get('/{any}', fn() => view('app'))->where('any', '.*');
