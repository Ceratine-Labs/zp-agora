<?php

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Controllers\Auth\ChangePasswordController;
use Modules\Core\Http\Controllers\DashboardController;
use Modules\Core\Http\Controllers\GridDemoController;
use Modules\Core\Http\Controllers\GridExtractController;
use Modules\Core\Http\Controllers\GridStateController;
use Modules\Core\Http\Controllers\LandingStubController;
use Modules\Core\Http\Controllers\ModuleMenuController;
use Modules\Core\Http\Controllers\PreferenceController;
use Modules\Core\Http\Controllers\RoleAdminController;
use Modules\Core\Http\Controllers\TableLayoutController;
use Modules\Core\Http\Controllers\UserAdminController;
use Modules\Core\Http\Middleware\AuthorizeAccessGrid;

// Registered under /app with the web stack by ModuleServiceProvider.
Route::middleware('auth')->group(function () {
    Route::post('table-layouts/read', [TableLayoutController::class, 'read'])->name('table-layouts.read');
    Route::get('table-layouts/{key}', [TableLayoutController::class, 'show'])->name('table-layouts.show');
    Route::post('table-layouts/{key}', [TableLayoutController::class, 'store'])->name('table-layouts.store');
    Route::delete('table-layouts/{key}', [TableLayoutController::class, 'destroy'])->name('table-layouts.destroy');
    Route::view('access-required', 'core::access-required')->name('access-required');
    Route::get('/', DashboardController::class)->name('dashboard');
    Route::get('modules/{module}', ModuleMenuController::class)->where('module', '[a-z][a-z0-9-]*')->name('modules.show');
    Route::post('preferences/theme', [PreferenceController::class, 'theme'])->name('preferences.theme');

    /*
     | ---- T007: identity ------------------------------------------------
     |
     | Changing your own password, and the two role landing pages. The
     | landings exist because agora.Role.LandingRoute is DATA: a branch
     | manager's row says app.console and an executive's says app.exco, and a
     | named route that resolves to nothing would 404 the first screen after
     | sign-in. T030 and T077 replace the controller, not these names.
     */
    Route::get('password/change', [ChangePasswordController::class, 'edit'])->name('password.change');
    Route::put('password/change', [ChangePasswordController::class, 'update'])->name('password.change.update');

    /*
     | ---- T028: users and access ------------------------------------------
     |
     | Setup -> People and assets -> Users and access. Each route carries its
     | own permission: `view` reads the list and one person's access, `edit`
     | changes it.
     |
     */
    Route::prefix('setup')->name('setup.')->group(function () {
        Route::get('roles', [RoleAdminController::class, 'index'])->middleware('can:setup.roles.view')->name('roles.index');
        Route::get('roles/create', [RoleAdminController::class, 'create'])->middleware('can:setup.roles.create')->name('roles.create');
        Route::post('roles', [RoleAdminController::class, 'store'])->middleware('can:setup.roles.create')->name('roles.store');
        Route::get('roles/{role}', [RoleAdminController::class, 'show'])->whereNumber('role')->middleware('can:setup.roles.view')->name('roles.show');
        Route::get('roles/{role}/edit', [RoleAdminController::class, 'edit'])->whereNumber('role')->middleware('can:setup.roles.edit')->name('roles.edit');
        Route::put('roles/{role}', [RoleAdminController::class, 'update'])->whereNumber('role')->middleware('can:setup.roles.edit')->name('roles.update');
        Route::delete('roles/{role}', [RoleAdminController::class, 'destroy'])->whereNumber('role')->middleware('can:setup.roles.delete')->name('roles.destroy');
        Route::post('users/bulk', [UserAdminController::class, 'updateBulk'])
            ->middleware('can:setup.users.edit')->name('users.bulk.update');
        Route::put('users/{user}/roles', [UserAdminController::class, 'updateRoles'])->whereNumber('user')->middleware('can:setup.users.edit')->name('users.roles.update');

        Route::get('users', [UserAdminController::class, 'index'])
            ->middleware('can:setup.users.view')->name('users.index');
        Route::get('users/{user}', [UserAdminController::class, 'show'])
            ->middleware('can:setup.users.view')->whereNumber('user')->name('users.show');

        // The editor saves all access sets together; legacy section routes
        // remain available for existing integrations and bookmarked clients.
        Route::get('users/{user}/edit', [UserAdminController::class, 'edit'])
            ->middleware('can:setup.users.edit')->whereNumber('user')->name('users.edit');
        Route::put('users/{user}', [UserAdminController::class, 'update'])
            ->middleware('can:setup.users.edit')->whereNumber('user')->name('users.update');
        Route::put('users/{user}/details', [UserAdminController::class, 'updateDetails'])
            ->middleware('can:setup.users.edit')->whereNumber('user')->name('users.details.update');
        Route::put('users/{user}/branches', [UserAdminController::class, 'updateBranches'])
            ->middleware('can:setup.users.edit')->whereNumber('user')->name('users.branches.update');
        Route::put('users/{user}/permissions', [UserAdminController::class, 'updatePermissions'])
            ->middleware('can:setup.users.edit')->whereNumber('user')->name('users.permissions.update');

        // POST, not PUT: mailing a link and minting a credential are things
        // that HAPPEN rather than a resource being replaced, and neither is
        // safe to repeat by refreshing.
        Route::post('users/{user}/password', [UserAdminController::class, 'updatePassword'])
            ->middleware('can:setup.users.edit')->whereNumber('user')->name('users.password.update');
    });

    Route::get('console', [LandingStubController::class, 'console'])->name('console');
    Route::get('exco', [LandingStubController::class, 'exco'])->name('exco');

    /*
     | The two endpoints every grid in the system shares.
     |
     | Here in Core rather than in each module because the GridKey already says
     | which grid is meant, and a per-module copy of "save my columns" is a
     | per-module copy of the whitelist that guards it.
     |
     | The key carries dots and a colon (`app.cash.dropsafe:bags`), so the
     | placeholder has to allow them — the default segment pattern would reject
     | the qualified form and the second grid on a screen would silently stop
     | persisting.
     */
    Route::prefix('grids/{grid}')->name('grids.')->middleware(AuthorizeAccessGrid::class)->where(['grid' => '[A-Za-z0-9._:-]+'])->group(function () {
        Route::post('columns', [GridStateController::class, 'store'])->name('columns.store');
        Route::delete('columns', [GridStateController::class, 'destroy'])->name('columns.destroy');
        Route::get('extract', GridExtractController::class)->name('extract');
    });

    /*
     | The grid gallery. Not registered outside local and testing, for the same
     | reason /dev/theme is not: it enumerates a component rather than answering
     | a question the business has.
     */
    if (app()->environment('local', 'testing')) {
        Route::get('dev/grids', GridDemoController::class)->name('dev.grids');
    }
});
