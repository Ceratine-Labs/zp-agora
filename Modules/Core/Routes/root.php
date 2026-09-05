<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\Auth\LoginController;
use Modules\Core\Http\Controllers\ChartGalleryController;
use Modules\Core\Http\Controllers\StyleguideController;

// Outside /app: these are the only pages reachable without a session.
Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->middleware('throttle:10,1');
});

Route::post('logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

/*
 | The styleguide. Not registered outside local and testing: it is a
 | development surface, and shipping a page that enumerates the whole design to
 | a production URL invites it to be treated as documentation for people who
 | should be looking at the real screens.
 */
if (app()->environment('local', 'testing')) {
    Route::get('dev/theme', StyleguideController::class)->name('dev.theme');
    Route::get('dev/charts', ChartGalleryController::class)->name('dev.charts');
}

Route::redirect('/', '/app');
