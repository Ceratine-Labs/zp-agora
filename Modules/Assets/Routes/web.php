<?php

use Illuminate\Support\Facades\Route;
use Modules\Assets\Http\Controllers\AssetMastersController;
use Modules\Assets\Http\Controllers\AssetMovementController;
use Modules\Assets\Http\Controllers\AssetRegisterController;
use Modules\Assets\Http\Controllers\AssetRepairController;

// Registered under the /app prefix with the web middleware stack by
// ModuleServiceProvider — do not repeat the prefix here.
Route::middleware('auth')->prefix('assets')->name('assets.')->group(function () {

    /*
     | ---- T057: assets -------------------------------------------------------
     |
     | One permission per action, per resource (feature-rules §4):
     |
     |   assets.register.view    the register, an asset's page, the masters
     |   assets.register.save    register a new asset
     |   assets.register.edit    change a registered asset
     |   assets.register.delete  take an asset registered IN ERROR off the
     |                           register — refused by the procedure once it
     |                           has any history
     |   assets.movement.view    the movement list
     |   assets.movement.save    record a movement
     |   assets.movement.edit    reverse the latest movement of an asset
     |   assets.repair.view      job cards and comebacks
     |   assets.repair.save      raise a job card
     |   assets.repair.edit      update or cancel a job card
     |
     | There is no delete for a movement or a job card: they are transactions,
     | reversed or cancelled, never removed. An asset is identified by its
     | owning site AND its id — {branch}/{asset} — because every key in Agora
     | leads with the branch.
     */
    Route::get('register', [AssetRegisterController::class, 'index'])
        ->middleware('can:assets.register.view')->name('register');
    Route::post('register', [AssetRegisterController::class, 'store'])
        ->middleware('can:assets.register.save')->name('register.store');
    Route::get('register/{branch}/{asset}', [AssetRegisterController::class, 'show'])
        ->middleware('can:assets.register.view')->whereNumber(['branch', 'asset'])->name('register.show');
    Route::put('register/{branch}/{asset}', [AssetRegisterController::class, 'update'])
        ->middleware('can:assets.register.edit')->whereNumber(['branch', 'asset'])->name('register.update');
    Route::delete('register/{branch}/{asset}', [AssetRegisterController::class, 'destroy'])
        ->middleware('can:assets.register.delete')->whereNumber(['branch', 'asset'])->name('register.destroy');

    Route::post('register/{branch}/{asset}/movements/{movement}/reverse', [AssetMovementController::class, 'reverse'])
        ->middleware('can:assets.movement.edit')->whereNumber(['branch', 'asset', 'movement'])->name('movement.reverse');

    Route::get('movement', [AssetMovementController::class, 'index'])
        ->middleware('can:assets.movement.view')->name('movement');
    Route::post('movement', [AssetMovementController::class, 'store'])
        ->middleware('can:assets.movement.save')->name('movement.store');

    Route::get('repairs', [AssetRepairController::class, 'index'])
        ->middleware('can:assets.repair.view')->name('repairs');
    Route::post('repairs', [AssetRepairController::class, 'store'])
        ->middleware('can:assets.repair.save')->name('repairs.store');
    Route::get('repairs/{branch}/{repair}', [AssetRepairController::class, 'show'])
        ->middleware('can:assets.repair.view')->whereNumber(['branch', 'repair'])->name('repairs.show');
    Route::put('repairs/{branch}/{repair}', [AssetRepairController::class, 'update'])
        ->middleware('can:assets.repair.edit')->whereNumber(['branch', 'repair'])->name('repairs.update');
    Route::post('repairs/{branch}/{repair}/cancel', [AssetRepairController::class, 'cancel'])
        ->middleware('can:assets.repair.edit')->whereNumber(['branch', 'repair'])->name('repairs.cancel');

    // Read-only: the customer's four masters and the provisional periods.
    Route::get('masters', [AssetMastersController::class, 'index'])
        ->middleware('can:assets.register.view')->name('masters');
});
