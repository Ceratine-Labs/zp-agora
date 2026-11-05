<?php

use Illuminate\Support\Facades\Route;
use Modules\Reports\Http\Controllers\ReportsController;
use Modules\Reports\Http\Controllers\WasteAuthorizationController;

// Registered under the /app prefix with the web middleware stack by
// ModuleServiceProvider — do not repeat the prefix here.
Route::middleware('auth')->prefix('reports')->name('reports.')->group(function () {
    Route::get('/', [ReportsController::class, 'index'])->middleware('can:reports.catalogue.view')->name('index');
    Route::post('waste/authorization', WasteAuthorizationController::class)->middleware('can:reports.waste.approve')->name('waste.authorization');

    // Last, so the catalogue is not swallowed by the slug.
    // One audit trail change, opened. Before {report}: a fixed first segment
    // must be matched ahead of the catch-all.
    Route::get('audit-trail/{ledger}/{id}', [ReportsController::class, 'auditChange'])
        ->where(['ledger' => '[A-Za-z]+', 'id' => '[0-9]+'])->middleware('can:reports.audit-trail.view')->name('audit.change');
    Route::get('{report}', [ReportsController::class, 'show'])->name('show');
});
