<?php

use Illuminate\Support\Facades\Route;
use Modules\Masters\Http\Controllers\BranchController;
use Modules\Masters\Http\Controllers\BranchGroupingController;
use Modules\Masters\Http\Controllers\ReferenceMasterController;
use Modules\Masters\Http\Controllers\ReferenceMasterWriteController;

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
     | slug => permission resource. Each lists a PumpIT master through
     | masters.{resource}.view, with two routes: the listing and one record
     | (`{branch}?key=` — the record's identity within its branch, as the
     | procedure's RowKey). Edits are the write-backs below.
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
        'meters' => 'meters',
        'terminals' => 'terminals',
        'statement-criteria' => 'reconcriteria',
        'bank-accounts' => 'bankaccounts',
    ] as $slug => $resource) {
        Route::get($slug, [ReferenceMasterController::class, 'index'])
            ->defaults('resource', $slug)->middleware("can:masters.{$resource}.view")->name("{$slug}.index");
        Route::get("{$slug}/{branch}", [ReferenceMasterController::class, 'show'])
            ->defaults('resource', $slug)->middleware("can:masters.{$resource}.view")->whereNumber('branch')->name("{$slug}.show");
    }

    /*
     | The masters that write back to PumpIT (CLAUDE.md rule 1, PM decision
     | 01a1cecf-8e08): masters.{resource}.edit opens the form, saves it and
     | puts a press back. The controller 404s every one of these while
     | masters.write_mode is not 'live'. No delete: no PumpIT row is removed.
     */
    foreach ([
        'categories' => 'categorygl',
        'profit-centres' => 'profitcentres',
        'standard-categories' => 'standardcategories',
        'expense-codes' => 'expenses',
        'tills' => 'tills',
        'shifts' => 'shifts',
        'transaction-types' => 'transactiontypes',
        'fuel-types' => 'fueltypes',
        'tanks' => 'tanks',
        'pumps' => 'pumps',
        'meters' => 'meters',
        'terminals' => 'terminals',
    ] as $slug => $resource) {
        Route::get("{$slug}/{branch}/edit", [ReferenceMasterWriteController::class, 'edit'])
            ->defaults('resource', $slug)->middleware("can:masters.{$resource}.edit")->whereNumber('branch')->name("{$slug}.edit");
        Route::put("{$slug}/{branch}", [ReferenceMasterWriteController::class, 'update'])
            ->defaults('resource', $slug)->middleware("can:masters.{$resource}.edit")->whereNumber('branch')->name("{$slug}.update");
        Route::post("{$slug}/{branch}/writes/{write}/reverse", [ReferenceMasterWriteController::class, 'reverse'])
            ->defaults('resource', $slug)->middleware("can:masters.{$resource}.edit")->whereNumber('branch')
            ->whereUuid('write')->name("{$slug}.writes.reverse");
    }
});
