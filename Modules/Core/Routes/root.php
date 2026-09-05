<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\Auth\LoginController;
use Modules\Core\Http\Controllers\Auth\PasswordResetController;
use Modules\Core\Http\Controllers\ChartGalleryController;
use Modules\Core\Http\Controllers\StyleguideController;

// Outside /app: these are the only pages reachable without a session.
Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:10,1');

    /*
     | Forgot password. Reachable without a session by definition — and, on the
     | day of the cutover, the way most of the customer's 85 migrated users get
     | in for the first time, because none of them has a usable password.
     |
     | Throttled twice: `throttle:10,1` here stops a flood from one address,
     | and PasswordResetController limits requests per EMAIL per hour, which is
     | the one that matters when the flood is spread across addresses.
     */
    Route::get('password/forgot', [PasswordResetController::class, 'request'])->name('password.request');
    Route::post('password/forgot', [PasswordResetController::class, 'email'])
        ->middleware('throttle:10,1')->name('password.email');
    Route::get('password/reset/{token}', [PasswordResetController::class, 'reset'])->name('password.reset');
    Route::post('password/reset', [PasswordResetController::class, 'update'])
        ->middleware('throttle:10,1')->name('password.update');
});

Route::post('logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

/*
 | The component gallery. Not registered outside local and testing: it is a
 | development surface, and shipping a page that enumerates the whole design to
 | a production URL invites it to be treated as documentation for people who
 | should be looking at the real screens.
 |
 | Two URLs, one controller, one view — deliberately not two galleries.
 | /dev/components is the name the work is filed under; /dev/theme is kept
 | because tests/e2e/format.spec.js loads it five times and is the only guard
 | on App\Support\Format agreeing with resources/js/format.js. Retiring that
 | URL would mean editing that guard, and a guard edited without a browser to
 | prove the edit is a guard nobody should trust.
 */
if (app()->environment('local', 'testing')) {
    Route::get('dev/components', StyleguideController::class)->name('dev.components');
    Route::get('dev/theme', StyleguideController::class)->name('dev.theme');
    Route::get('dev/charts', ChartGalleryController::class)->name('dev.charts');
}

Route::redirect('/', '/app');
