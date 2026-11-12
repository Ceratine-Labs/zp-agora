<?php

use Illuminate\Support\Facades\Route;
use Modules\Fuel\Http\Controllers\FuelController;

Route::middleware(['auth', 'can:fuel.prices.view'])->group(function () {
    Route::get('fuel/matrix', [FuelController::class, 'index'])->name('fuel.matrix');
    Route::get('fuel/history', [FuelController::class, 'index'])->name('fuel.history');
    Route::get('fuel/{dataset}/{key}', [FuelController::class, 'detail'])
        ->where('dataset', 'periods|observations')->where('key', '[A-Fa-f0-9]{64}')->name('fuel.detail');
});
