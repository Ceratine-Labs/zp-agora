<?php

/*
|--------------------------------------------------------------------------
| Masters
|--------------------------------------------------------------------------
|
| Setup → The business. The branch master's tables are Core's (plan §4:
| agora.Branch, Region, Brand, Format); this module holds the screens and the
| procedures behind them. Nothing here writes PumpIT, so there is no
| write_mode switch.
|
*/

return [

    'name' => 'Masters',

    // A change to a branch or a grouping carries a reason this long, at least.
    // The procedures enforce it; the form only says so.
    'reason_min' => 10,

];
