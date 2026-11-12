<?php

use Illuminate\Support\Facades\Route;
use Modules\Reports\Http\Controllers\GovernanceController;
use Modules\Reports\Http\Controllers\ReportsController;

// Registered under the /app prefix with the web middleware stack by
// ModuleServiceProvider — do not repeat the prefix here.
Route::middleware('auth')->prefix('reports')->name('reports.')->group(function () {
    Route::get('/', [ReportsController::class, 'index'])->name('index');

    // Last, so the catalogue is not swallowed by the slug.
    // One audit trail change, opened. Before {report}: a fixed first segment
    // must be matched ahead of the catch-all.
    Route::get('audit-trail/{ledger}/{id}', [ReportsController::class, 'auditChange'])
        ->where(['ledger' => '[A-Za-z]+', 'id' => '[0-9]+'])->name('audit.change');
    Route::get('{report}', [ReportsController::class, 'show'])->name('show');
});

// Setup → Governance (T027): the master data change log and user activity.
// Under setup/ because that is where they hang; Reports owns their procedures.
Route::middleware('auth')->prefix('setup')->name('setup.')->group(function () {
    Route::get('master-changes', [GovernanceController::class, 'masterChanges'])
        ->middleware('can:reports.master-changes.view')->name('master-changes.index');
    Route::get('user-activity', [GovernanceController::class, 'userActivity'])
        ->middleware('can:reports.user-activity.view')->name('user-activity.index');
});
