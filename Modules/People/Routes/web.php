<?php

use Illuminate\Support\Facades\Route;
use Modules\People\Http\Controllers\EmployeeAreaController;
use Modules\People\Http\Controllers\EmployeeController;
use Modules\People\Http\Controllers\EmployeePostController;

// Registered under the /app prefix with the web middleware stack by
// ModuleServiceProvider — do not repeat the prefix here.
Route::middleware('auth')->prefix('setup')->name('setup.')->group(function () {

    /*
     | ---- T023: the people master ---------------------------------------------
     |
     | Setup → People and assets. One permission per action, per resource
     | (feature-rules §4):
     |
     |   people.employees.view   the list and one employee's page
     |   people.posts.view       the posts at each branch
     |   people.areas.view       who signs on to which counting area
     |
     | VIEW ONLY. The rows are PumpIT's (BRN_Employee and friends); no edit,
     | save or delete route exists until Ryan decides where an edit lands.
     |
     | An employee is (branch, code) — BRN_Employee's primary key. Codes are
     | letters and digits on the customer's instance (12 Nov 2026); a trailing
     | space in the stored code is trimmed by the view.
     */
    Route::get('employees', [EmployeeController::class, 'index'])
        ->middleware('can:people.employees.view')->name('employees.index');
    Route::get('employees/{branch}/{code}', [EmployeeController::class, 'show'])
        ->middleware('can:people.employees.view')->whereNumber('branch')->where('code', '[A-Za-z0-9_\-]{1,11}')
        ->name('employees.show');

    Route::get('employee-posts', [EmployeePostController::class, 'index'])
        ->middleware('can:people.posts.view')->name('employee-posts.index');

    Route::get('employee-areas', [EmployeeAreaController::class, 'index'])
        ->middleware('can:people.areas.view')->name('employee-areas.index');
});
