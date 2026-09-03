<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\Auth\LoginController;

// Outside /app: these are the only pages reachable without a session.
Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:10,1');
});

Route::post('logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

Route::redirect('/', '/app');
