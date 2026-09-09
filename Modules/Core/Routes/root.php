<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\Auth\LoginController;
use Modules\Core\Http\Controllers\Auth\PasswordResetController;
use Modules\Core\Http\Controllers\Auth\SsoController;
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

    // The code from the mail. Throttled on top of the model's five-attempt
    // lock: the lock is the real defence, and this is what stops a script
    // burning somebody's five attempts in a second and calling it a denial of
    // service.
    Route::post('password/verify', [PasswordResetController::class, 'verify'])
        ->middleware('throttle:10,1')->name('password.verify');

    Route::post('password/reset', [PasswordResetController::class, 'update'])
        ->middleware('throttle:10,1')->name('password.update');
});

Route::post('logout', [LoginController::class, 'destroy'])->middleware('auth')->name('logout');

/*
 | Cross-app single sign-on with ZP-NQL (config/sso.php).
 |
 | Outside /app and outside `guest` both: `emit` is ZP asking whether we have
 | a session, which is a question worth answering either way, and `logout`
 | must work for somebody who IS signed in. What authorises them is the HMAC
 | on the query string, checked against the shared secret — there is no state
 | here a caller without that secret can move.
 |
 | `directory` is read by `php artisan sso:link` on the ZP box. Same
 | signature, no session, and it returns three fields per user and nothing
 | else — see the controller.
 |
 | Throttled: these are the only unauthenticated endpoints doing
 | cryptographic work, and a signature check is a cheap thing to ask for a
 | million times.
 */
Route::middleware('throttle:60,1')->group(function () {
    Route::get('sso/emit', [SsoController::class, 'emit'])->name('sso.emit');
    Route::get('sso/accept', [SsoController::class, 'accept'])->name('sso.accept');
    Route::get('sso/logout', [SsoController::class, 'logout'])->name('sso.logout');
    Route::get('sso/directory', [SsoController::class, 'directory'])->name('sso.directory');
});

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
