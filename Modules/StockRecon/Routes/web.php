<?php

use Illuminate\Support\Facades\Route;
use Modules\StockRecon\Http\Controllers\StockReconController;
use Modules\StockRecon\Http\Controllers\StockReconVarianceController;

// Registered under the /app prefix with the web middleware stack by
// ModuleServiceProvider — do not repeat the prefix here.
/*
 | Every route carries its own permission (feature-rules §4). The split that
 | matters is between looking and writing: `view` and `create` let a person run
 | a preview and read every figure it produced, while `execute` and `reverse`
 | change counts — in Agora's ledger today and in the customer's estate the day
 | the stamp mode changes — and are granted to Finance and Admin alone.
 | Operations can preview and discard; the Auditor can only look.
 */
Route::middleware('auth')->prefix('stock-recon')->name('stockrecon.')->group(function () {
    Route::get('/', [StockReconController::class, 'index'])
        ->middleware('can:stockrecon.runs.view')->name('index');

    // Discarding previews across the site. Declared BEFORE runs/{run} so a
    // literal segment cannot be read as a run id.
    Route::delete('runs', [StockReconController::class, 'discard'])
        ->middleware('can:stockrecon.runs.delete')->name('clear');

    /*
     | A run is the record of a preview, addressable on its own so a figure can
     | be sent to somebody rather than described to them. Its two faces — the
     | proposals and the exceptions — are LINKS rather than panels, because
     | each is its own result set with its own scope, which is the rule
     | everywhere else in Agora.
     */
    Route::get('runs/{run}', [StockReconController::class, 'show'])
        ->middleware('can:stockrecon.runs.view')->name('run');
    Route::get('runs/{run}/exceptions', [StockReconController::class, 'exceptions'])
        ->middleware('can:stockrecon.exceptions.view')->name('exceptions');

    // The fragment a shift row expands into: the whole chain behind it. Its own
    // URL, so it is fetched only when somebody asks — a branch-month is
    // thousands of shifts and drilling all of them up front is thousands of
    // queries nobody wanted.
    Route::get('runs/{run}/lines/{line}', [StockReconController::class, 'line'])
        ->middleware('can:stockrecon.runs.view')->name('line');

    Route::post('runs/{run}/commit', [StockReconController::class, 'commit'])
        ->middleware('can:stockrecon.runs.execute')->name('commit');
    Route::post('runs/{run}/reverse', [StockReconController::class, 'reverse'])
        ->middleware('can:stockrecon.runs.reverse')->name('reverse');
    // Reset to original (7 Oct 2026): writes PumpIT as a commit does, so it
    // sits behind the commit's permission; its Reverse is the run's.
    Route::post('runs/{run}/reset', [StockReconController::class, 'resetToOriginal'])
        ->middleware('can:stockrecon.runs.execute')->name('reset');

    /*
     | A possible duplicate issue (D2) holds its chain back from the commit
     | (PM decision 01a10b21). Releasing one, with a reason, lets the next
     | preview commit the chain; withdrawing the release puts the hold back.
     | Each its own grant (feature-rules §4). The run in the URL scopes the
     | branch; the release itself belongs to the shift, not to the run.
     */
    Route::post('runs/{run}/duplicates/release', [StockReconController::class, 'releaseDuplicates'])
        ->middleware('can:stockrecon.duplicates.release')->name('duplicates.release');
    Route::post('runs/{run}/duplicates/releases/{release}/withdraw', [StockReconController::class, 'withdrawRelease'])
        ->whereNumber('release')
        ->middleware('can:stockrecon.duplicates.withdraw')->name('duplicates.withdraw');
    Route::delete('runs/{run}', [StockReconController::class, 'discard'])
        ->middleware('can:stockrecon.runs.delete')->name('discard');

    Route::post('preview', [StockReconController::class, 'preview'])
        ->middleware('can:stockrecon.runs.create')->name('preview');

    /*
     | Original vs amended variance (ZRP, 19 Oct 2026): every count line's
     | variance as counted beside its variance after balancing, over any
     | period. Read-only, and not a run, so it has its own grant.
     */
    Route::get('variance', [StockReconVarianceController::class, 'index'])
        ->middleware('can:stockrecon.variance.view')->name('variance');
});
