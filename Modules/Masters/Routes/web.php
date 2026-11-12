<?php

use Illuminate\Support\Facades\Route;
use Modules\Masters\Http\Controllers\BranchController;
use Modules\Masters\Http\Controllers\BranchGroupingController;

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
});
