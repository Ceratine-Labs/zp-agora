<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\DashboardController;

// Registered under /app with the web stack by ModuleServiceProvider.
Route::middleware('auth')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
});
