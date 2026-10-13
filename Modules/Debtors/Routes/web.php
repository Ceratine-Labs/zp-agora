<?php

use Illuminate\Support\Facades\Route;
use Modules\Debtors\Http\Controllers\DebtorStatementController;

// Registered under the /app prefix with the web middleware stack by
// ModuleServiceProvider — do not repeat the prefix here.
Route::middleware('auth')->group(function () {

    /*
     | ---- Debtors statements ---------------------------------------------------
     |
     | `view` reads the list, a statement and its PDF. `send` mails one — and
     | whether that reaches the debtor is config('debtors.send_mode'), not this
     | permission. `edit` changes where a debtor's statement goes and whether
     | the account is on the monthly run. Nothing here writes PumpIT.
     */
    Route::get('debtors/statements', [DebtorStatementController::class, 'index'])
        ->middleware('can:debtors.statements.view')->name('debtors.index');

    Route::post('debtors/statements/send-selected', [DebtorStatementController::class, 'sendSelected'])
        ->middleware('can:debtors.statements.send')->name('debtors.sendSelected');

    Route::get('debtors/statements/{branch}/{account}', [DebtorStatementController::class, 'show'])
        ->middleware('can:debtors.statements.view')->whereNumber('branch')->name('debtors.show');

    Route::get('debtors/statements/{branch}/{account}/pdf', [DebtorStatementController::class, 'pdf'])
        ->middleware('can:debtors.statements.view')->whereNumber('branch')->name('debtors.pdf');

    Route::get('debtors/statements/{branch}/{account}/preview', [DebtorStatementController::class, 'preview'])
        ->middleware('can:debtors.statements.view')->whereNumber('branch')->name('debtors.preview');

    Route::post('debtors/statements/{branch}/{account}/send', [DebtorStatementController::class, 'send'])
        ->middleware('can:debtors.statements.send')->whereNumber('branch')->name('debtors.send');

    Route::post('debtors/statements/{branch}/{account}/contact', [DebtorStatementController::class, 'saveContact'])
        ->middleware('can:debtors.contacts.edit')->whereNumber('branch')->name('debtors.contact');
});
