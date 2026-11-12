<?php

use Illuminate\Support\Facades\Route;
use Modules\Partners\Http\Controllers\CustomerController;
use Modules\Partners\Http\Controllers\PartnerSearchController;
use Modules\Partners\Http\Controllers\SupplierController;

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
     | VIEW ONLY. The rows are PumpIT's BRN_Suppliers and BRN_Customers, and
     | PumpIT keeps trading on them; no edit, save, create or delete route
     | exists until Ryan decides where an edit lands. Where a debtor's
     | statement goes is edited on the Debtors statement (debtors.contacts.edit),
     | not here.
     |
     | A supplier is (branch, code) and a customer is (branch, code) — each
     | table's primary key on the customer's instance. Supplier codes are
     | APS-#### / APE-####, customer codes a site prefix and five digits
     | (12 Nov 2026); the pattern allows the hyphen and nothing a URL mangles.
     */
    Route::get('suppliers', [SupplierController::class, 'index'])
        ->middleware('can:partners.suppliers.view')->name('suppliers.index');
    Route::get('suppliers/{branch}/{code}', [SupplierController::class, 'show'])
        ->middleware('can:partners.suppliers.view')->whereNumber('branch')->where('code', '[A-Za-z0-9_\-]{1,8}')
        ->name('suppliers.show');

    Route::get('customers', [CustomerController::class, 'index'])
        ->middleware('can:partners.customers.view')->name('customers.index');
    Route::get('customers/{branch}/{code}', [CustomerController::class, 'show'])
        ->middleware('can:partners.customers.view')->whereNumber('branch')->where('code', '[A-Za-z0-9_\-]{1,10}')
        ->name('customers.show');

    // The palette's Partners source: suppliers and customers matching ?q=, as
    // JSON. Each kind is offered only to whoever may view it; neither is 403.
    Route::get('partners/search', PartnerSearchController::class)->name('partners.search');
});
