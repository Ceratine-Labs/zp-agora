<?php

use Illuminate\Support\Facades\Route;
use Modules\Product\Http\Controllers\CriticalLineController;
use Modules\Product\Http\Controllers\StockMasterController;

// Registered under the /app prefix with the web middleware stack by
// ModuleServiceProvider — do not repeat the prefix here.
Route::middleware('auth')->group(function () {

    /*
     | ---- T025: product masters -------------------------------------------
     |
     | Setup -> Masters -> Stock master. `view` reads the listing and one
     | line; `edit` writes an override. The two are separate because reading
     | the master is what a count, a GP query and a price review all start
     | with, while changing it alters what a count is VALUED at, at one site,
     | for everybody.
     |
     | The route carries branch AND item because the item number is per
     | branch: 653 numbers are reused across 22 sites and "item 10" alone is
     | twenty-two different products. That is the legacy estate's shape, not a
     | choice made here.
     |
     | {item} is NOT whereNumber. StockItemNo is NVARCHAR(5) in PumpIT and
     | every live value happens to be numeric — but constraining the route to
     | digits would mean the first non-numeric item anybody creates 404s
     | instead of opening, and nothing in the schema prevents one.
     */
    Route::prefix('master')->name('master.')->group(function () {
        Route::get('stock', [StockMasterController::class, 'index'])
            ->middleware('can:master.stock.view')->name('stock.index');
        Route::get('stock/{branch}/{item}', [StockMasterController::class, 'show'])
            ->middleware('can:master.stock.view')->whereNumber('branch')->name('stock.show');

        // No separate edit route. The editor is a modal on the detail page,
        // and that page load is the fresh read — modal.js's fetch-per-open
        // earns its keep when the row is chosen from a grid, not here.
        Route::put('stock/{branch}/{item}', [StockMasterController::class, 'update'])
            ->middleware('can:master.stock.edit')->whereNumber('branch')->name('stock.update');

        /*
         | Critical lines. No {branch}/{code} in the path: a POS code can be a
         | 13-digit barcode or a short PLU and it is not URL-safe to assume
         | which — the branch, system and code arrive in the form body, which
         | is also where the reason has to be.
         */
        Route::get('critical', [CriticalLineController::class, 'index'])
            ->middleware('can:master.stock.view')->name('critical.index');
        Route::put('critical', [CriticalLineController::class, 'update'])
            ->middleware('can:master.stock.edit')->name('critical.update');
    });
});
