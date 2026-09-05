<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\DashboardController;
use Modules\Core\Http\Controllers\GridDemoController;
use Modules\Core\Http\Controllers\GridExtractController;
use Modules\Core\Http\Controllers\GridStateController;
use Modules\Core\Http\Controllers\PreferenceController;

// Registered under /app with the web stack by ModuleServiceProvider.
Route::middleware('auth')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::post('preferences/theme', [PreferenceController::class, 'theme'])->name('preferences.theme');

    /*
     | The two endpoints every grid in the system shares.
     |
     | Here in Core rather than in each module because the GridKey already says
     | which grid is meant, and a per-module copy of "save my columns" is a
     | per-module copy of the whitelist that guards it.
     |
     | The key carries dots and a colon (`app.cash.dropsafe:bags`), so the
     | placeholder has to allow them — the default segment pattern would reject
     | the qualified form and the second grid on a screen would silently stop
     | persisting.
     */
    Route::prefix('grids/{grid}')->name('grids.')->where(['grid' => '[A-Za-z0-9._:-]+'])->group(function () {
        Route::post('columns', [GridStateController::class, 'store'])->name('columns.store');
        Route::delete('columns', [GridStateController::class, 'destroy'])->name('columns.destroy');
        Route::get('extract', GridExtractController::class)->name('extract');
    });

    /*
     | The grid gallery. Not registered outside local and testing, for the same
     | reason /dev/theme is not: it enumerates a component rather than answering
     | a question the business has.
     */
    if (app()->environment('local', 'testing')) {
        Route::get('dev/grids', GridDemoController::class)->name('dev.grids');
    }
});
