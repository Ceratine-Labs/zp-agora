<?php

use Illuminate\Support\Facades\Route;
use Modules\Masters\Http\Controllers\BranchController;
use Modules\Masters\Http\Controllers\BranchGroupingController;
use Modules\Masters\Http\Controllers\ReferenceMasterController;

// Registered under the /app prefix with the web middleware stack by
// ModuleServiceProvider — do not repeat the prefix here.
Route::middleware('auth')->prefix('setup')->name('setup.')->group(function () {

    /*
     | ---- T021: the branch master ---------------------------------------------
     |
     | Setup → The business. One permission per action, per resource
     | (feature-rules §4):
     |
     |   masters.branches.view    the listing and one branch's page
     |   masters.branches.edit    change a branch (status, groupings, POS type,
     |                            profit centres) — refused by the procedure too
     |   masters.groupings.view   the regions, brands and formats
     |   masters.groupings.save   add a region, brand or format
     |   masters.groupings.edit   rename one or switch it off
     |
     | No create or delete for a branch: its id is PumpIT's, and it arrives by
     | migration. No delete for a grouping: it is switched off.
     */
    Route::get('branches', [BranchController::class, 'index'])
        ->middleware('can:masters.branches.view')->name('branches.index');
    Route::get('branches/{branch}', [BranchController::class, 'show'])
        ->middleware('can:masters.branches.view')->whereNumber('branch')->name('branches.show');
    Route::get('branches/{branch}/edit', [BranchController::class, 'edit'])
        ->middleware('can:masters.branches.edit')->whereNumber('branch')->name('branches.edit');
    Route::put('branches/{branch}', [BranchController::class, 'update'])
        ->middleware('can:masters.branches.edit')->whereNumber('branch')->name('branches.update');

    Route::get('branch-groupings', [BranchGroupingController::class, 'index'])
        ->middleware('can:masters.groupings.view')->name('branch-groupings.index');
    Route::post('branch-groupings/{kind}', [BranchGroupingController::class, 'store'])
        ->middleware('can:masters.groupings.save')->whereIn('kind', ['Region', 'Brand', 'Format'])->name('branch-groupings.store');
    Route::put('branch-groupings/{kind}/{id}', [BranchGroupingController::class, 'update'])
        ->middleware('can:masters.groupings.edit')->whereIn('kind', ['Region', 'Brand', 'Format'])->whereNumber('id')->name('branch-groupings.update');

    /*
     | ---- T022: the reference masters -----------------------------------------
     |
     | slug => permission resource. Each is a READ-ONLY listing over PumpIT
     | (Masters v1__02a says why), so each has exactly one permission,
     | masters.{resource}.view, and two routes: the listing and one record
     | (`{branch}?key=` — the record's identity within its branch, as the
     | procedure's RowKey). There is no edit, save or delete: those happen in
     | PumpIT until a master is adopted or migrated by name.
     |
     | The grid behind each is registered in config/grids.php as
     | app.setup.{slug}; the procedure refuses without the view permission too.
     */
    foreach ([
        'profit-centres' => 'profitcentres',
        'standard-categories' => 'standardcategories',
        'categories' => 'categorygl',
        'expense-codes' => 'expenses',
        'tills' => 'tills',
        'shifts' => 'shifts',
        'transaction-types' => 'transactiontypes',
        'fuel-types' => 'fueltypes',
        'tanks' => 'tanks',
        'pumps' => 'pumps',
    ] as $slug => $resource) {
        Route::get($slug, [ReferenceMasterController::class, 'index'])
            ->defaults('resource', $slug)->middleware("can:masters.{$resource}.view")->name("{$slug}.index");
        Route::get("{$slug}/{branch}", [ReferenceMasterController::class, 'show'])
            ->defaults('resource', $slug)->middleware("can:masters.{$resource}.view")->whereNumber('branch')->name("{$slug}.show");
    }
});
