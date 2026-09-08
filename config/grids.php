<?php

use App\Grid\Definitions\BranchGrid;
use App\Grid\Definitions\DayCloseGrid;
use Modules\Core\Grids\UserGrid;
use Modules\Recon\Grids\ReconBankSideGrid;
use Modules\Recon\Grids\ReconCriteriaGrid;
use Modules\Recon\Grids\ReconMopsSideGrid;
use Modules\Recon\Grids\ReconRunGrid;
use Modules\Recon\Grids\ReconRunLineGrid;
use Modules\StockRecon\Grids\StockReconExceptionGrid;
use Modules\StockRecon\Grids\StockReconRunGrid;
use Modules\StockRecon\Grids\StockReconRunLineGrid;

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

        'app.dev.grids:dayclose' => DayCloseGrid::class,
        'app.dev.grids:branches' => BranchGrid::class,
    ],

];
