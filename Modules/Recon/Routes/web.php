<?php

use Illuminate\Support\Facades\Route;
use Modules\Recon\Http\Controllers\ReconController;

// Registered under the /app prefix with the web middleware stack by
// ModuleServiceProvider — do not repeat the prefix here.
Route::middleware('auth')->prefix('recon')->name('recon.')->group(function () {
    Route::get('/', [ReconController::class, 'index'])->name('index');

    // A run is the record of a preview, addressable on its own so a figure
    // can be sent to someone rather than described to them.
    Route::get('runs/{run}', [ReconController::class, 'show'])->name('run');
    // The fragment a proposal row expands into. Its own URL, so the detail is
    // fetched only when somebody asks for it — a month of ABSA is a few hundred
    // proposals and drilling all of them up front is a few hundred queries.
    Route::get('runs/{run}/lines/{line}', [ReconController::class, 'line'])->name('line');
    Route::post('runs/{run}/execute', [ReconController::class, 'execute'])->name('execute');
    Route::post('runs/{run}/reverse', [ReconController::class, 'reverse'])->name('reverse');

    // Discarding previews. One run, or every uncommitted run in scope — the
    // procedure refuses a committed one either way.
    Route::delete('runs/{run}', [ReconController::class, 'discard'])->name('discard');
    Route::delete('runs', [ReconController::class, 'discard'])->name('clear');

    // Last, so `auto/{area}` cannot swallow `runs/{run}`.
    Route::get('auto/{area}', [ReconController::class, 'area'])->name('area');
    Route::post('auto', [ReconController::class, 'preview'])->name('preview');
});
