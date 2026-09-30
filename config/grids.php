<?php

use App\Grid\Definitions\BranchGrid;
use App\Grid\Definitions\DayCloseGrid;
use Modules\Assets\Grids\AssetMovementGrid;
use Modules\Assets\Grids\AssetRegisterGrid;
use Modules\Assets\Grids\AssetRepairGrid;
use Modules\Cash\Grids\CashupGrid;
use Modules\Cash\Grids\Deductions\CashShortGrid as DeductionCashShortGrid;
use Modules\Cash\Grids\Deductions\NonIntDetailGrid as DeductionNonIntDetailGrid;
use Modules\Cash\Grids\Deductions\NonIntSummaryGrid as DeductionNonIntSummaryGrid;
use Modules\Cash\Grids\Deductions\ScheduleGrid as DeductionScheduleGrid;
use Modules\Cash\Grids\Deductions\StockLossDetailGrid as DeductionStockLossDetailGrid;
use Modules\Cash\Grids\Deductions\StockLossSummaryGrid as DeductionStockLossSummaryGrid;
use Modules\Cash\Grids\DropSafeGrid;
use Modules\Cash\Grids\PumpShortageGrid;
use Modules\Cash\Grids\StaffShortGrid;
use Modules\Cash\Grids\ZreadGrid;
use Modules\Cash\Grids\ZreadNoCashupGrid;
use Modules\Core\Grids\UserGrid;
use Modules\Product\Grids\CriticalLineGrid;
use Modules\Product\Grids\StockItemGrid;
use Modules\Recon\Grids\ReconBankSideGrid;
use Modules\Recon\Grids\ReconCriteriaGrid;
use Modules\Recon\Grids\ReconMopsSideGrid;
use Modules\Recon\Grids\ReconRunGrid;
use Modules\Recon\Grids\ReconRunLineGrid;
use Modules\StockRecon\Grids\StockReconExceptionGrid;
use Modules\StockRecon\Grids\StockReconRunGrid;
use Modules\StockRecon\Grids\StockReconRunLineGrid;
use Modules\Utilities\Grids\UtilityReadingGrid;

/*
|--------------------------------------------------------------------------
| Grids
|--------------------------------------------------------------------------
|
| Every data grid in the system, by its GridKey, and the handful of numbers
| every grid shares.
|
| The GridKey is the ROUTE NAME (feature-rules §3.6), qualified where a screen
| carries two grids: `app.cash.dropsafe` and `app.cash.dropsafe:bags`. It is
| what `agora.UserGridColumn` stores, so it is also the one string that must
| never be changed casually — a rename orphans every saved layout under the old
| key with nothing anywhere reporting it. GridRegistry refuses a definition
| whose own key() disagrees with the line it is registered on, which is the
| cheapest place to catch that.
|
| A register rather than a scan, deliberately. The key is a route name, the
| class lives in a module, and the pairing is exactly the thing worth being able
| to read on one screen — it is also what lets the saved layouts be audited
| against a list of keys that are supposed to exist.
|
| This file is MACHINERY, not exposure (feature-rules §2): nothing in it is a
| figure the customer reads, so it does not have to come out of T-SQL. What
| they read comes out of each grid's procedure, and the grid puts that name on
| screen so they can open it (§3.4).
|
*/

return [

    /*
    | Rows per page, and the sizes the user may choose between. A size that is
    | not on this list is refused and the grid's default is used — the value
    | reaches an OFFSET/FETCH and a procedure should never be handed a page
    | size somebody typed into a URL.
    */
    'page_size' => 50,

    'page_sizes' => [25, 50, 100, 200],

    /*
    | Above this many rows an extract is refused and the user is sent to the
    | Export centre, which produces the same file out of the request cycle
    | (feature-rules §3.2).
    */
    'export_ceiling' => 100000,

    /*
    | What a saved column width may be. ZP rejected 5px and 5000px and was
    | right to: a width out of range is a bug or a fiddle, and either way it
    | produces a grid the user then cannot use to fix it.
    */
    'width' => [
        'min' => 60,
        'max' => 640,
    ],

    /*
    | GridKey => GridDefinition. Module grids belong under Modules/{M}/Grids;
    | the two below are the development surface at /dev/grids, which is not
    | registered outside local and testing.
    */
    'grids' => [
        'app.setup.users' => UserGrid::class,

        // The per-branch stock master: the customer's row unless Agora holds
        // a live override for it, with cost, GP, stock on hand and last
        // counted joined on from places the master itself does not carry.
        'app.master.stock' => StockItemGrid::class,

        // The lines that must never be out of stock, and whether they are.
        // Keyed to the POS file rather than the stock master — a third of the
        // list is not counted at all.
        'app.master.critical' => CriticalLineGrid::class,

        // The extraction configuration, ours beside the customer's. A row
        // opens a modal edit form rather than editing in place — the grid
        // framework stays read-and-export only.
        'app.recon.config' => ReconCriteriaGrid::class,

        // The runs made in one area — the workbench's Runs tab. Scoped to
        // the person who made them unless they ask for everyone's, and the
        // area comes off the request the same way the run does below.
        'app.recon.runs' => ReconRunGrid::class,

        // The recon run screen keeps its own table — it carries tick boxes, an
        // execute form and a row-expand panel the grid shell cannot express —
        // and registers here only so the standard extract endpoint serves it.
        // See Modules/Recon/Grids/ReconRunLineGrid.
        'app.recon.run' => ReconRunLineGrid::class,

        // The two SIDES of those proposals — the bank lines and the deposits
        // behind them. Registered for the extract only; nothing renders them
        // as a grid, because on screen they belong next to each other inside
        // the expand panel. The `:side` qualification is the convention this
        // file's header describes.
        'app.recon.run:bank' => ReconBankSideGrid::class,
        'app.recon.run:mops' => ReconMopsSideGrid::class,

        // The balancing runs made at a site — the centre's Runs tab and its
        // hub. Scoped to the person who made them unless they ask for
        // everyone's, and the counting area comes off the request.
        'app.stockrecon.runs' => StockReconRunGrid::class,

        // What balancing refused to hide. A real grid: thousands of rows,
        // header filters, an export and a footer total somebody takes to a
        // branch meeting.
        'app.stockrecon.exceptions' => StockReconExceptionGrid::class,

        // The run screen keeps its own table — tick boxes decide what a commit
        // writes and a row expands into its chain — and registers here only so
        // the standard extract endpoint serves it. In journal mode that
        // extract IS the deliverable: the worklist an admin applies by hand.
        'app.stockrecon.run' => StockReconRunLineGrid::class,

        // Z-reads for a site and period with the proposer's call on each —
        // who, which till, which shift — and the clerk's decision beside it.
        'app.cash.zread' => ZreadGrid::class,

        // Cashups by till, shift and day with the legacy variance, the Z-read
        // check and Agora's confirm/close state; and the Z-reads no cashup
        // was captured against.
        'app.cash.cashups' => CashupGrid::class,
        'app.cash.cashups.nocashup' => ZreadNoCashupGrid::class,

        // Each pump attendant's short by shift: the cashups' pump shortages leg,
        // with the staff short behind each line.
        'app.cash.pumpshortages' => PumpShortageGrid::class,

        // Bags dropped at a site, in the safe or collected, with the reason
        // and manager a bag over the threshold carries.
        'app.cash.dropsafe.bags' => DropSafeGrid::class,

        // Staff shorts raised, approved, declined and posted, with the reason
        // code and the note as typed.
        'app.cash.staffshorts.list' => StaffShortGrid::class,

        // The head-office deduction tabs (T041) — PumpIT's five payroll
        // reports, row for row — and the built schedule the payroll file is
        // taken from.
        'app.cash.deductions' => DeductionCashShortGrid::class,
        'app.cash.deductions.stockloss' => DeductionStockLossSummaryGrid::class,
        'app.cash.deductions.stocklossdetail' => DeductionStockLossDetailGrid::class,
        'app.cash.deductions.nonint' => DeductionNonIntSummaryGrid::class,
        'app.cash.deductions.nonintdetail' => DeductionNonIntDetailGrid::class,
        'app.cash.deductions.payroll' => DeductionScheduleGrid::class,

        // One row per meter per day off BRN_UtilityTransaction, usage
        // computed once in agora.vw_UtilityReading.
        'app.utilities.index' => UtilityReadingGrid::class,

        // The asset register at the provisional SARS periods, what has moved
        // (reversed movements stay, marked), and the repair job cards with
        // the 30-day comeback flag and the approved request's cost.
        'app.assets.register' => AssetRegisterGrid::class,
        'app.assets.movement' => AssetMovementGrid::class,
        'app.assets.repairs' => AssetRepairGrid::class,

        'app.dev.grids:dayclose' => DayCloseGrid::class,
        'app.dev.grids:branches' => BranchGrid::class,
    ],

];
