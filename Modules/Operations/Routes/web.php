<?php

use Illuminate\Support\Facades\Route;
use Modules\Operations\Http\Controllers\OperationsWallController;

Route::middleware(['auth', 'can:operations.wall.view'])->prefix('operations-wall')->name('operations.')->group(function () {
    Route::get('/', [OperationsWallController::class, 'index'])->name('wall');
    Route::get('snapshot', [OperationsWallController::class, 'snapshot'])->name('snapshot');
});
