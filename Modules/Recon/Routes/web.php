<?php

use Illuminate\Support\Facades\Route;
use Modules\Recon\Http\Controllers\ReconController;

// Registered under the /app prefix with the web middleware stack by
// ModuleServiceProvider — do not repeat the prefix here.
/*
 | Every route carries its own permission (T008). The split that matters is
 | between looking and committing: `view` and `create` let a person run a
 | preview and read what it found, while `execute` and `reverse` stamp rows in
 | the customer's estate and are granted to Finance and Admin alone. Operations
 | can preview and discard; the Auditor can only look. feature-rules is explicit
 | that edit, save and delete each sit behind their own permission per resource,
 | and this is that rule applied to the one module that already writes.
 */
Route::middleware('auth')->prefix('recon')->name('recon.')->group(function () {
    Route::get('/', [ReconController::class, 'index'])->middleware('can:recon.runs.view')->name('index');

    // A run is the record of a preview, addressable on its own so a figure
    // can be sent to someone rather than described to them.
    Route::get('runs/{run}', [ReconController::class, 'show'])->middleware('can:recon.runs.view')->name('run');
    // The fragment a proposal row expands into. Its own URL, so the detail is
    // fetched only when somebody asks for it — a month of ABSA is a few hundred
    // proposals and drilling all of them up front is a few hundred queries.
    Route::get('runs/{run}/lines/{line}', [ReconController::class, 'line'])->middleware('can:recon.runs.view')->name('line');
    Route::post('runs/{run}/execute', [ReconController::class, 'execute'])->middleware('can:recon.runs.execute')->name('execute');
    Route::post('runs/{run}/reverse', [ReconController::class, 'reverse'])->middleware('can:recon.runs.reverse')->name('reverse');

    // Discarding previews. One run, or every uncommitted run in scope — the
    // procedure refuses a committed one either way.
    Route::delete('runs/{run}', [ReconController::class, 'discard'])->middleware('can:recon.runs.delete')->name('discard');
    Route::delete('runs', [ReconController::class, 'discard'])->middleware('can:recon.runs.delete')->name('clear');

    // Last, so `auto/{area}` cannot swallow `runs/{run}`.
    Route::get('auto/{area}', [ReconController::class, 'area'])->middleware('can:recon.runs.view')->name('area');
    Route::post('auto', [ReconController::class, 'preview'])->middleware('can:recon.runs.create')->name('preview');
});
