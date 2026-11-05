<?php

use Illuminate\Support\Facades\Route;
use Modules\StockRecon\Http\Controllers\CountingAreaController;
use Modules\StockRecon\Http\Controllers\StockReconController;
use Modules\StockRecon\Http\Controllers\StockReconGroupController;
use Modules\StockRecon\Http\Controllers\StockReconHelperController;
use Modules\StockRecon\Http\Controllers\StockReconTutorialController;
use Modules\StockRecon\Http\Controllers\StockReconVarianceController;
use Modules\StockRecon\Http\Controllers\WaterMeterController;
use Modules\StockRecon\Http\Controllers\WeeklyStockController;
use Modules\StockRecon\Http\Middleware\CountingAreaAccess;
use Modules\StockRecon\Http\Middleware\WaterMeterAccess;
use Modules\StockRecon\Http\Middleware\WeeklyStockAccess;

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
    Route::get('tutorial', StockReconTutorialController::class)
        ->middleware('can:stockrecon.runs.view')->name('tutorial');
    Route::get('runs/{run}/helper', [StockReconHelperController::class, 'show'])
        ->middleware('can:stockrecon.runs.view')->name('helper');

    Route::prefix('weekly')->middleware(WeeklyStockAccess::class)->group(function () {
        Route::get('/', [WeeklyStockController::class, 'index'])->name('weekly');
        Route::get('create', [WeeklyStockController::class, 'create'])->middleware('can:stockrecon.weekly.create')->name('weekly.create');
        Route::post('/', [WeeklyStockController::class, 'store'])->middleware('can:stockrecon.weekly.create')->name('weekly.store');
        Route::get('{branch}/{case}', [WeeklyStockController::class, 'show'])->whereNumber(['branch', 'case'])->name('weekly.show');
        Route::get('{branch}/{case}/edit', [WeeklyStockController::class, 'edit'])->whereNumber(['branch', 'case'])
            ->middleware('can:stockrecon.weekly.edit')->name('weekly.edit');
        Route::put('{branch}/{case}', [WeeklyStockController::class, 'update'])->whereNumber(['branch', 'case'])
            ->middleware(['can:stockrecon.weekly.edit', 'can:stockrecon.weekly.save'])->name('weekly.update');
    });
    Route::get('groups', [StockReconGroupController::class, 'form'])
        ->middleware('can:stockrecon.runs.view')->name('groups.form');
    Route::post('groups', [StockReconGroupController::class, 'start'])
        ->middleware(['can:stockrecon.runs.view', 'can:stockrecon.runs.create'])->name('groups.start');
    Route::get('groups/{group}', [StockReconGroupController::class, 'show'])
        ->whereUuid('group')->middleware('can:stockrecon.runs.view')->name('group');
    Route::post('groups/{group}/branches/{branch}', [StockReconGroupController::class, 'runBranch'])
        ->whereUuid('group')->whereNumber('branch')
        ->middleware(['can:stockrecon.runs.view', 'can:stockrecon.runs.create'])->name('groups.branch');
    Route::prefix('counting-areas')->middleware(CountingAreaAccess::class)->group(function () {
        Route::get('/', [CountingAreaController::class, 'index'])->name('countingareas');
        Route::get('{branch}/{area}', [CountingAreaController::class, 'show'])->whereNumber(['branch', 'area'])->name('countingareas.show');
        Route::get('{branch}/{area}/edit', [CountingAreaController::class, 'edit'])->whereNumber(['branch', 'area'])
            ->middleware('can:stockrecon.countingareas.edit')->name('countingareas.edit');
        Route::post('{branch}/{area}/removals', [CountingAreaController::class, 'recordRemoval'])->whereNumber(['branch', 'area'])
            ->middleware(['can:stockrecon.countingareas.edit', 'can:stockrecon.countingareas.save'])->name('countingareas.removals.store');
        Route::post('{branch}/{area}/removals/{removal}/withdraw', [CountingAreaController::class, 'withdrawRemoval'])->whereNumber(['branch', 'area', 'removal'])
            ->middleware(['can:stockrecon.countingareas.edit', 'can:stockrecon.countingareas.save'])->name('countingareas.removals.withdraw');
        Route::post('{branch}/{area}/completions', [CountingAreaController::class, 'recordCompletion'])->whereNumber(['branch', 'area'])
            ->middleware(['can:stockrecon.countingareas.edit', 'can:stockrecon.countingareas.save'])->name('countingareas.completions.store');
        Route::post('{branch}/{area}/completions/{completion}/withdraw', [CountingAreaController::class, 'withdrawCompletion'])->whereNumber(['branch', 'area', 'completion'])
            ->middleware(['can:stockrecon.countingareas.edit', 'can:stockrecon.countingareas.save'])->name('countingareas.completions.withdraw');
        Route::put('{branch}/{area}', [CountingAreaController::class, 'update'])->whereNumber(['branch', 'area'])
            ->middleware(['can:stockrecon.countingareas.edit', 'can:stockrecon.countingareas.save'])->name('countingareas.update');
    });

    Route::put('weekly/{branch}/{case}/approve', [WeeklyStockController::class, 'update'])->whereNumber(['branch', 'case'])
        ->middleware([WeeklyStockAccess::class, 'can:stockrecon.weekly.approve'])->name('weekly.approve');
    Route::put('weekly/{branch}/{case}/reopen', [WeeklyStockController::class, 'reopen'])->whereNumber(['branch', 'case'])
        ->middleware([WeeklyStockAccess::class, 'can:stockrecon.weekly.reopen'])->name('weekly.reopen');

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
    Route::get('runs/{run}/overview', [StockReconController::class, 'overview'])
        ->middleware('can:stockrecon.runs.view')->name('overview');
    Route::get('runs/{run}/proposals/{metric}', [StockReconController::class, 'proposalMetric'])
        ->middleware('can:stockrecon.runs.view')->name('proposal-metric');
    Route::get('runs/{run}/global', [StockReconController::class, 'globalOverview'])
        ->middleware('can:stockrecon.runs.view')->name('global');
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
    Route::get('water-meters', [WaterMeterController::class, 'index'])
        ->middleware(WaterMeterAccess::class)->name('water');
});
