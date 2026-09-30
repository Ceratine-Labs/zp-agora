<?php

use Illuminate\Support\Facades\Route;
use Modules\Utilities\Http\Controllers\UtilitiesController;

// Registered under the /app prefix with the web middleware stack by
// ModuleServiceProvider — do not repeat the prefix here.
Route::middleware('auth')->group(function () {

    /*
     | ---- T056: utility meters ----------------------------------------------
     |
     | `view` reads the screen. `save` records a reading for a day that has
     | none — which writes the customer's PumpIT.dbo.BRN_UtilityTransaction.
     | Changing a reading somebody already captured additionally needs
     | `utilities.readings.edit`: that is checked on the form and again inside
     | agora.usp_Utilities_Save, because a route cannot see what a line is
     | about to overwrite. There is no delete: a reading is a record of
     | something that was seen, and it is corrected, never removed.
     */
    Route::get('utilities', [UtilitiesController::class, 'index'])
        ->middleware('can:utilities.readings.view')->name('utilities.index');

    Route::post('utilities/readings', [UtilitiesController::class, 'save'])
        ->middleware('can:utilities.readings.save')->name('utilities.save');
});
