<?php

/*
|--------------------------------------------------------------------------
| Masters
|--------------------------------------------------------------------------
|
| Setup → The business. The branch master's tables are Core's (plan §4:
| agora.Branch, Region, Brand, Format); this module holds the screens and the
| procedures behind them. The reference masters' edits write PumpIT —
| see write_mode below.
|
*/

return [

    'name' => 'Masters',

    // A change to a branch or a grouping carries a reason this long, at least.
    // The procedures enforce it; the form only says so.
    'reason_min' => 10,

    /*
    | THE PUMPIT WRITE SWITCH for the reference masters — a named write
    | (CLAUDE.md rule 1).
    |
    | A literal and NOT an environment variable, as people.write_mode and
    | cash.*.write_mode are: Agora starting to write the production ERP is a
    | commit with a name on it.
    |
    |   'live'  an edit writes the master's PumpIT table — SDK_CategoryGLCode's
    |           GP and days-cover band and active flag — every prior value
    |           recorded first in agora.MasterWrite, and Put back reverses a
    |           press
    |   'off'   the masters are read-only listings: no write procedure is
    |           called and the Edit and Put back controls are not drawn
    |
    | 'live' on Ryan's standing rule of 12 Nov 2026, "agora is replacing
    | pumpit, always write back to pumpit with confirmation" (PM decision
    | 01a1cecf-8e08).
    */
    'write_mode' => 'live',

];
