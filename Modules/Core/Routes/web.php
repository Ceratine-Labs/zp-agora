<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\DashboardController;
use Modules\Core\Http\Controllers\PreferenceController;

// Registered under /app with the web stack by ModuleServiceProvider.
Route::middleware('auth')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::post('preferences/theme', [PreferenceController::class, 'theme'])->name('preferences.theme');
});
