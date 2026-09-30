<?php

/*
|--------------------------------------------------------------------------
| Utilities (T056)
|--------------------------------------------------------------------------
|
| Three of these are SETTINGS that live here only because the Settings screen
| (T026) is not built yet. They are named as the Settings keys will be named,
| so moving them is a change of where they are read from and nothing else:
|
|   utilities.tariffs               -> Setting `utilities.tariff.{type}`
|   utilities.zero_usage_run_days   -> Setting `utilities.zero_usage_run_days`
|
| Nothing here is a figure the customer reads; each one is passed INTO the
| procedures, which is where every number on the screen is computed.
|
*/

return [

    'name' => 'Utilities',

    /*
    | Whether Agora writes the customer's PumpIT.dbo.BRN_UtilityTransaction.
    |
    | 'live'  agora.usp_Utilities_Save inserts and updates readings there.
    | 'off'   the screen is read-only and the procedure is never called; the
    |         procedure refuses anything but 'live' on its own as well.
    |
    | A literal, never an env var — the same rule as recon's stamp_mode. Ryan
    | approved the write on 27 Sep 2026 (PM decisions 01a0e22a-97be /
    | 01a0e22a-9aa9); CLAUDE.md rule 1 lists exactly what it may touch.
    */
    'write_mode' => 'live',

    /*
    | Rand per unit, per meter TYPE (SS_UtilityType.Code).
    |
    | The plan names four: B Electricity Main, L Generator 1, R Water Mun,
    | S Water ZP. NULL means "no tariff" and the screen says so — cost is shown
    | as a dash, never as R0.00. PumpIT stores no tariff anywhere (searched
    | 27 Sep 2026: no table, column or view carries one), so these are not
    | known yet and are deliberately NOT guessed. Ryan to supply.
    |
    | Any other type code may be added here and is costed the same way.
    |
    | Still NULL on 27 Sep 2026: the rates have been asked of ZP. Cost is
    | Usage x rate, where Usage is the customer's own formula, Closing -
    | Opening - Prepaid. That sign is kept deliberately (coordinator's default,
    | 27 Sep 2026) with no per-meter "counts down" flag: a prepaid meter that
    | counts DOWN (branch 2's) therefore shows negative usage, as it does in
    | PumpIT today, and a site that books its monthly reset as prepaid =
    | -opening (branch 25's) reads correctly only this way round.
    */
    'tariffs' => [
        'B' => null,
        'L' => null,
        'R' => null,
        'S' => null,
    ],

    /*
    | How many days a meter may go without moving — or without being read —
    | before it is flagged, per meter TYPE (SS_UtilityType.Code).
    |
    | `default` applies to every type not named. A type set to NULL is never
    | flagged: the generators, L (Generator 1) and M (Generator 2), stand
    | still for weeks whenever there is no load-shedding — 17 of the estate's
    | 31 runs in September 2026 were generators, and a list that is mostly
    | noise is a list nobody reads. Add a type with a number to give it a
    | length of its own. The coordinator's default of 27 Sep 2026; moves to
    | Settings with the rest.
    |
    | A run is measured from the last reading at which the meter moved to the
    | latest reading at which it still had not, so a meter read weekly that
    | did not move in a week is 7 days, not 1. A run of days PumpIT holds as
    | 0 / 0 is a run of "No reading" (a 0 / 0 day is not a reading — Ryan, PM
    | decision 01a0e312-07a0) and uses the same length.
    */
    'zero_usage_run_days' => [
        'default' => 3,
        'L' => null,
        'M' => null,
    ],

    /*
    | The longest range the capture grid prepares in one go. A month and a
    | bit — the legacy screen prepares a calendar month.
    */
    'max_capture_days' => 31,

];
