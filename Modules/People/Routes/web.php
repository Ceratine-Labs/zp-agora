<?php

use Illuminate\Support\Facades\Route;
use Modules\People\Http\Controllers\EmployeeAreaController;
use Modules\People\Http\Controllers\EmployeeController;
use Modules\People\Http\Controllers\EmployeeEditController;
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
     |   people.employees.edit   change status, post, known-as   } a NAMED WRITE
     |   people.areas.edit       change the counting areas       } to PumpIT,
     |   people.posts.save       add a post                      } CLAUDE.md rule 1
     |
     | The edits write BRN_Employee / BRN_EmployeeArea / BRN_EmployeePost
     | through the People procedures (PM decision 01a1ceba-4d74), switched by
     | people.write_mode. No delete route: nothing removes a person or a post.
     | The edit screen and Put back take either edit permission; each form's
     | own route takes its own, and so does the procedure.
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

    Route::get('employees/{branch}/{code}/edit', [EmployeeEditController::class, 'edit'])
        ->whereNumber('branch')->where('code', '[A-Za-z0-9_\-]{1,11}')->name('employees.edit');
    Route::put('employees/{branch}/{code}', [EmployeeEditController::class, 'update'])
        ->middleware('can:people.employees.edit')->whereNumber('branch')->where('code', '[A-Za-z0-9_\-]{1,11}')
        ->name('employees.update');
    Route::put('employees/{branch}/{code}/areas', [EmployeeEditController::class, 'updateAreas'])
        ->middleware('can:people.areas.edit')->whereNumber('branch')->where('code', '[A-Za-z0-9_\-]{1,11}')
        ->name('employees.areas.update');
    Route::post('employees/{branch}/{code}/changes/{write}/reverse', [EmployeeEditController::class, 'reverse'])
        ->whereNumber('branch')->where('code', '[A-Za-z0-9_\-]{1,11}')->whereUuid('write')
        ->name('employees.changes.reverse');

    Route::get('employee-posts', [EmployeePostController::class, 'index'])
        ->middleware('can:people.posts.view')->name('employee-posts.index');
    Route::post('employee-posts', [EmployeePostController::class, 'store'])
        ->middleware('can:people.posts.save')->name('employee-posts.store');

    Route::get('employee-areas', [EmployeeAreaController::class, 'index'])
        ->middleware('can:people.areas.view')->name('employee-areas.index');
});
