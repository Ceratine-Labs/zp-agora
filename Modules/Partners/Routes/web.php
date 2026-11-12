<?php

use Illuminate\Support\Facades\Route;
use Modules\Partners\Http\Controllers\CustomerController;
use Modules\Partners\Http\Controllers\CustomerEditController;
use Modules\Partners\Http\Controllers\PartnerSearchController;
use Modules\Partners\Http\Controllers\SupplierController;
use Modules\Partners\Http\Controllers\SupplierEditController;

// Registered under the /app prefix with the web middleware stack by
// ModuleServiceProvider — do not repeat the prefix here.
Route::middleware('auth')->prefix('setup')->name('setup.')->group(function () {

    /*
     | ---- T024: trading partners -----------------------------------------------
     |
     | Setup → Trading rules. One permission per action, per resource
     | (feature-rules §4):
     |
     |   partners.suppliers.view   the supplier list and one supplier's page
     |   partners.customers.view   the account-customer list and one customer
     |
     |   partners.suppliers.edit     change a supplier; put a change back } a NAMED
     |   partners.suppliers.create   add a supplier at every site         } WRITE to
     |   partners.customers.edit     change a customer; put it back       } PumpIT,
     |   partners.customers.create   add a customer at a site             } rule 1
     |
     | The rows are PumpIT's BRN_Suppliers and BRN_Customers and the writes
     | land there (Ryan's standing rule, PM decision 01a1cecf-8e08), switched
     | by partners.write_mode. No delete route: nothing removes a partner.
     | Put back takes the edit permission of the kind it reverses — the
     | procedure checks which. Where a debtor's statement goes is edited on
     | the Debtors statement (debtors.contacts.edit), not here.
     |
     | A supplier is (branch, code) and a customer is (branch, code) — each
     | table's primary key on the customer's instance. Supplier codes are
     | APS-#### / APE-####, customer codes a site prefix and five digits
     | (12 Nov 2026); the pattern allows the hyphen and nothing a URL mangles.
     */
    Route::get('suppliers', [SupplierController::class, 'index'])
        ->middleware('can:partners.suppliers.view')->name('suppliers.index');
    Route::get('suppliers/create', [SupplierEditController::class, 'create'])
        ->middleware('can:partners.suppliers.create')->name('suppliers.create');
    Route::post('suppliers', [SupplierEditController::class, 'store'])
        ->middleware('can:partners.suppliers.create')->name('suppliers.store');
    Route::get('suppliers/{branch}/{code}/edit', [SupplierEditController::class, 'edit'])
        ->middleware('can:partners.suppliers.edit')->whereNumber('branch')->where('code', '[A-Za-z0-9_\-]{1,8}')
        ->name('suppliers.edit');
    Route::put('suppliers/{branch}/{code}', [SupplierEditController::class, 'update'])
        ->middleware('can:partners.suppliers.edit')->whereNumber('branch')->where('code', '[A-Za-z0-9_\-]{1,8}')
        ->name('suppliers.update');
    Route::post('suppliers/{branch}/{code}/changes/{write}/reverse', [SupplierEditController::class, 'reverse'])
        ->middleware('can:partners.suppliers.edit')->whereNumber('branch')->where('code', '[A-Za-z0-9_\-]{1,8}')
        ->whereUuid('write')->name('suppliers.changes.reverse');
    Route::get('suppliers/{branch}/{code}', [SupplierController::class, 'show'])
        ->middleware('can:partners.suppliers.view')->whereNumber('branch')->where('code', '[A-Za-z0-9_\-]{1,8}')
        ->name('suppliers.show');

    Route::get('customers', [CustomerController::class, 'index'])
        ->middleware('can:partners.customers.view')->name('customers.index');
    Route::get('customers/create', [CustomerEditController::class, 'create'])
        ->middleware('can:partners.customers.create')->name('customers.create');
    Route::post('customers', [CustomerEditController::class, 'store'])
        ->middleware('can:partners.customers.create')->name('customers.store');
    Route::get('customers/{branch}/{code}/edit', [CustomerEditController::class, 'edit'])
        ->middleware('can:partners.customers.edit')->whereNumber('branch')->where('code', '[A-Za-z0-9_\-]{1,10}')
        ->name('customers.edit');
    Route::put('customers/{branch}/{code}', [CustomerEditController::class, 'update'])
        ->middleware('can:partners.customers.edit')->whereNumber('branch')->where('code', '[A-Za-z0-9_\-]{1,10}')
        ->name('customers.update');
    Route::post('customers/{branch}/{code}/changes/{write}/reverse', [CustomerEditController::class, 'reverse'])
        ->middleware('can:partners.customers.edit')->whereNumber('branch')->where('code', '[A-Za-z0-9_\-]{1,10}')
        ->whereUuid('write')->name('customers.changes.reverse');
    Route::get('customers/{branch}/{code}', [CustomerController::class, 'show'])
        ->middleware('can:partners.customers.view')->whereNumber('branch')->where('code', '[A-Za-z0-9_\-]{1,10}')
        ->name('customers.show');

    // The palette's Partners source: suppliers and customers matching ?q=, as
    // JSON. Each kind is offered only to whoever may view it; neither is 403.
    Route::get('partners/search', PartnerSearchController::class)->name('partners.search');
});
