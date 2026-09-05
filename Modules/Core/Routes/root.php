<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\Auth\LoginController;
use Modules\Core\Http\Controllers\StyleguideController;

// Outside /app: these are the only pages reachable without a session.
Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:10,1');
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
}

Route::redirect('/', '/app');
