<?php

use App\Grid\Definitions\BranchGrid;
use App\Grid\Definitions\DayCloseGrid;

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
        'app.dev.grids:dayclose' => DayCloseGrid::class,
        'app.dev.grids:branches' => BranchGrid::class,
    ],

];
