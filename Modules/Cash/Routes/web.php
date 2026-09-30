<?php

use Illuminate\Support\Facades\Route;
use Modules\Cash\Http\Controllers\CashupController;
use Modules\Cash\Http\Controllers\DeductionController;
use Modules\Cash\Http\Controllers\DropSafeController;
use Modules\Cash\Http\Controllers\StaffShortController;
use Modules\Cash\Http\Controllers\TillBalanceController;
use Modules\Cash\Http\Controllers\ZreadController;

// Registered under the /app prefix with the web middleware stack by
// ModuleServiceProvider — do not repeat the prefix here.
/*
 | Z-read allocation (T036). Every route carries its own permission
 | (feature-rules §4), and the split is between proposing and deciding:
 |
 |   cash.zread.view      the screen, a row's panel, the phone list
 |   cash.zread.allocate  STAGE — Auto-allocate, accept, set by hand, clear
 |   cash.zread.commit    SAVE — Update ZREAD, and the phone's Confirm
 |                        (writes three columns to PumpIT in 'live' stamp mode)
 |   cash.zread.reverse   UNDO one Update ZREAD press, PumpIT included
 |
 | There is no delete: a Z-read allocation is a transactional entry, and is
 | superseded, never removed.
 |
 | `app.cash.zread` is the name Core's ZReadsUnallocatedBadge already links to.
 */
Route::middleware('auth')->prefix('cash/zread')->name('cash.zread')->group(function () {
    Route::get('/', [ZreadController::class, 'index'])
        ->middleware('can:cash.zread.view');

    Route::get('row', [ZreadController::class, 'row'])
        ->middleware('can:cash.zread.view')->name('.row');

    Route::get('confirm', [ZreadController::class, 'confirm'])
        ->middleware('can:cash.zread.view')->name('.confirm');

    Route::post('auto', [ZreadController::class, 'autoAllocate'])
        ->middleware('can:cash.zread.allocate')->name('.auto');
    Route::post('clear', [ZreadController::class, 'clear'])
        ->middleware('can:cash.zread.allocate')->name('.clear');
    Route::post('accept', [ZreadController::class, 'accept'])
        ->middleware('can:cash.zread.allocate')->name('.accept');
    Route::post('set', [ZreadController::class, 'set'])
        ->middleware('can:cash.zread.allocate')->name('.set');

    Route::post('commit', [ZreadController::class, 'commit'])
        ->middleware('can:cash.zread.commit')->name('.commit');
    Route::post('reverse', [ZreadController::class, 'reverse'])
        ->middleware('can:cash.zread.reverse')->name('.reverse');

    Route::post('confirm', [ZreadController::class, 'confirmOne'])
        ->middleware(['can:cash.zread.allocate', 'can:cash.zread.commit'])->name('.confirm.store');
});

/*
 | Daily banking and cashups (T037).
 |
 |   cash.cashup.view     Daily Banking, Cashups, Z-reads with no cashup, a
 |                        row's panel, the phone list
 |   cash.cashup.save     Save card amounts, Add from PumpIT — WRITE PUMPIT (the
 |                        header, the leg, the pool row's banked mark) while
 |                        cash.cashup.write_mode is 'live'
 |   cash.cashup.confirm  agree to a cashup's figures — Agora only
 |   cash.cashup.close    a manager's sign-off, and reopening — Agora only
 |
 | No delete: a cashup is a transactional entry.
 */
Route::middleware('auth')->prefix('cash/banking')->name('cash.banking')->group(function () {
    Route::get('/', [CashupController::class, 'banking'])
        ->middleware('can:cash.cashup.view');

    Route::get('phone', [CashupController::class, 'phone'])
        ->middleware('can:cash.cashup.view')->name('.phone');

    Route::post('cards', [CashupController::class, 'saveCards'])
        ->middleware('can:cash.cashup.save')->name('.cards');
    Route::post('legs', [CashupController::class, 'saveLeg'])
        ->middleware('can:cash.cashup.save')->name('.legs');
    Route::post('confirm', [CashupController::class, 'confirm'])
        ->middleware('can:cash.cashup.confirm')->name('.confirm');
    Route::post('close', [CashupController::class, 'close'])
        ->middleware('can:cash.cashup.close')->name('.close');
    Route::post('reopen', [CashupController::class, 'reopen'])
        ->middleware('can:cash.cashup.close')->name('.reopen');
});

Route::middleware('auth')->prefix('cash/cashups')->name('cash.cashups')->group(function () {
    Route::get('/', [CashupController::class, 'cashups'])
        ->middleware('can:cash.cashup.view');

    Route::get('row', [CashupController::class, 'row'])
        ->middleware('can:cash.cashup.view')->name('.row');

    Route::get('no-cashup', [CashupController::class, 'noCashup'])
        ->middleware('can:cash.cashup.view')->name('.nocashup');
});

/*
 | Till balance and pump shortages (T038).
 |
 |   cash.tillbalance.view     the Till balance screen
 |   cash.tillbalance.save     Capture — WRITES PUMPIT (the BRN_TillBalance pair
 |                             and the SS_UniqueNumber section 3 counter) while
 |                             cash.tillbalance.write_mode is 'live'
 |   cash.pumpshortages.view   the Pump shortages grid
 |
 | No edit, no delete: a till balance is a transactional entry, declined by the
 | approver in PumpIT and captured again when it is wrong.
 */
Route::middleware('auth')->prefix('cash/till-balance')->name('cash.tillbalance')->group(function () {
    Route::get('/', [TillBalanceController::class, 'index'])
        ->middleware('can:cash.tillbalance.view');

    Route::post('/', [TillBalanceController::class, 'store'])
        ->middleware('can:cash.tillbalance.save')->name('.store');
});

Route::middleware('auth')->get('cash/pump-shortages', [TillBalanceController::class, 'pumpShortages'])
    ->middleware('can:cash.pumpshortages.view')->name('cash.pumpshortages');

/*
 | The drop safe (T039).
 |
 |   cash.dropsafe.view     Drop safe (one site), its phone screen, and the
 |                          Drop safe bags grid
 |   cash.dropsafe.save     Drop a bag — WRITES PUMPIT (a BRN_DropSafe row)
 |   cash.dropsafe.edit     Correct a bag still in the safe and on no cashup —
 |                          WRITES PUMPIT (that row)
 |   cash.dropsafe.void     Void a bag captured in error, still in the safe and
 |                          on no cashup — DELETES its BRN_DropSafe row (the
 |                          whole row kept in agora.DropSafeEvent first)
 |   cash.dropsafe.collect  Collect ticked bags — WRITES PUMPIT (a
 |                          BRN_DropSafe_Collection row, and CollectionId on
 |                          each bag)
 |
 | each write only while cash.dropsafe.write_mode is 'live'. The void is the one
 | delete, on Ryan's named go-ahead of 27 Sep 2026, and nothing else deletes.
 */
Route::middleware('auth')->prefix('cash/drop-safe')->name('cash.dropsafe')->group(function () {
    Route::get('/', [DropSafeController::class, 'index'])
        ->middleware('can:cash.dropsafe.view');

    Route::get('phone', [DropSafeController::class, 'phone'])
        ->middleware('can:cash.dropsafe.view')->name('.phone');

    Route::get('bags', [DropSafeController::class, 'bags'])
        ->middleware('can:cash.dropsafe.view')->name('.bags');

    Route::post('/', [DropSafeController::class, 'store'])
        ->middleware('can:cash.dropsafe.save')->name('.store');
    Route::post('edit', [DropSafeController::class, 'update'])
        ->middleware('can:cash.dropsafe.edit')->name('.update');
    Route::post('void', [DropSafeController::class, 'void'])
        ->middleware('can:cash.dropsafe.void')->name('.void');
    Route::post('collect', [DropSafeController::class, 'collect'])
        ->middleware('can:cash.dropsafe.collect')->name('.collect');
});

/*
 | Staff shorts (T040).
 |
 |   cash.staffshort.view     Staff shorts (one site), its phone screen, and the
 |                            Staff shorts list
 |   cash.staffshort.raise    Raise a short — WRITES PUMPIT (a BRN_StaffShorts
 |                            row, and the SS_UniqueNumber section 2 counter)
 |   cash.staffshort.approve  Approve, or decline with a reason — WRITES PUMPIT
 |                            (the short's approval or decline columns)
 |   cash.staffshort.withdraw Withdraw an approved short not yet on a cashup —
 |                            WRITES PUMPIT (approval undone, short declined)
 |   cash.staffshort.post     Post an approved short to a cashup's pump
 |                            shortages — WRITES PUMPIT (the
 |                            BRN_DailyBankingEmployees line, the short's
 |                            IsSelectedForDailyBanking, the header's totals)
 |
 | each write only while cash.staffshort.write_mode is 'live' (a post also
 | needs cash.cashup.write_mode). No delete: a staff short is a transactional
 | entry, declined when wrong.
 */
Route::middleware('auth')->prefix('cash/staff-shorts')->name('cash.staffshorts')->group(function () {
    Route::get('/', [StaffShortController::class, 'index'])
        ->middleware('can:cash.staffshort.view');

    Route::get('phone', [StaffShortController::class, 'phone'])
        ->middleware('can:cash.staffshort.view')->name('.phone');

    Route::get('list', [StaffShortController::class, 'list'])
        ->middleware('can:cash.staffshort.view')->name('.list');

    Route::post('/', [StaffShortController::class, 'store'])
        ->middleware('can:cash.staffshort.raise')->name('.store');
    Route::post('approve', [StaffShortController::class, 'approve'])
        ->middleware('can:cash.staffshort.approve')->name('.approve');
    Route::post('decline', [StaffShortController::class, 'decline'])
        ->middleware('can:cash.staffshort.approve')->name('.decline');
    Route::post('withdraw', [StaffShortController::class, 'withdraw'])
        ->middleware('can:cash.staffshort.withdraw')->name('.withdraw');
    Route::post('post', [StaffShortController::class, 'post'])
        ->middleware('can:cash.staffshort.post')->name('.post');
});

/*
 | Deductions and the payroll file (T041).
 |
 |   cash.deductions.view    the five head-office tabs (PumpIT's payroll
 |                           reports, row for row) and the built schedule
 |   cash.deductions.build   build a site's schedule for a pay period — writes
 |                           agora.DeductionRun / agora.Deduction, Agora only
 |   cash.deductions.export  take the payroll file; marks the runs exported, so
 |                           the period is not rebuilt without somebody saying so
 |
 | Nothing here writes to the customer's databases. There is no delete: a run
 | is superseded by a rebuild, never removed.
 */
Route::middleware('auth')->prefix('cash/deductions')->name('cash.deductions')->group(function () {
    Route::get('/', [DeductionController::class, 'cashShorts'])
        ->middleware('can:cash.deductions.view');
    Route::get('stock-loss', [DeductionController::class, 'stockLoss'])
        ->middleware('can:cash.deductions.view')->name('.stockloss');
    Route::get('stock-loss/detail', [DeductionController::class, 'stockLossDetail'])
        ->middleware('can:cash.deductions.view')->name('.stocklossdetail');
    Route::get('non-integrated', [DeductionController::class, 'nonInt'])
        ->middleware('can:cash.deductions.view')->name('.nonint');
    Route::get('non-integrated/detail', [DeductionController::class, 'nonIntDetail'])
        ->middleware('can:cash.deductions.view')->name('.nonintdetail');
    Route::get('payroll', [DeductionController::class, 'payroll'])
        ->middleware('can:cash.deductions.view')->name('.payroll');

    Route::post('payroll/build', [DeductionController::class, 'build'])
        ->middleware('can:cash.deductions.build')->name('.build');
    Route::post('payroll/export', [DeductionController::class, 'export'])
        ->middleware('can:cash.deductions.export')->name('.export');
});
