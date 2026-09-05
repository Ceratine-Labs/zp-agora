<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\Auth\ChangePasswordController;
use Modules\Core\Http\Controllers\DashboardController;
use Modules\Core\Http\Controllers\LandingStubController;
use Modules\Core\Http\Controllers\PreferenceController;

// Registered under /app with the web stack by ModuleServiceProvider.
Route::middleware('auth')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::post('preferences/theme', [PreferenceController::class, 'theme'])->name('preferences.theme');

    /*
     | ---- T007: identity ------------------------------------------------
     |
     | Changing your own password, and the two role landing pages. The
     | landings exist because agora.Role.LandingRoute is DATA: a branch
     | manager's row says app.console and an executive's says app.exco, and a
     | named route that resolves to nothing would 404 the first screen after
     | sign-in. T030 and T077 replace the controller, not these names.
     */
    Route::get('password/change', [ChangePasswordController::class, 'edit'])->name('password.change');
    Route::put('password/change', [ChangePasswordController::class, 'update'])->name('password.change.update');

    Route::get('console', [LandingStubController::class, 'console'])->name('console');
    Route::get('exco', [LandingStubController::class, 'exco'])->name('exco');
});
