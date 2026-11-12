<?php

/*
|--------------------------------------------------------------------------
| People
|--------------------------------------------------------------------------
|
| Setup → People and assets: employees, posts and employee areas (T023).
| The rows are PumpIT's (BRN_Employee, BRN_EmployeePost, BRN_EmployeeArea),
| read through agora.vw_People* views.
|
*/

return [

    'name' => 'People',

    /*
    | THE PUMPIT WRITE SWITCH — a named write (CLAUDE.md rule 1).
    |
    | Deliberately a literal and NOT an environment variable, exactly as
    | cash.*.write_mode and utilities.write_mode are: Agora starting to write
    | the production ERP is a commit with a name on it, not a one-line change
    | somebody makes on a Friday.
    |
    |   'live'  an employee edit writes [PumpIT].dbo.BRN_Employee IsActive,
    |           PostNo and NickName; the areas save writes BRN_EmployeeArea;
    |           Add post inserts into BRN_EmployeePost; Put back reverses a
    |           press — every value recorded first in agora.PeopleWrite
    |   'off'   the screens are read-only, no write procedure is called, and
    |           the Edit, Add post and Put back controls are not drawn
    |
    | 'live' on Ryan's go-ahead of 12 Nov 2026 (PM decision 01a1ceba-4d74).
    */
    'write_mode' => 'live',

    // Every write carries a reason this long, at least. The procedures
    // enforce it; the forms only say so.
    'reason_min' => 10,

    // How many recent shifts an employee's page lists. The procedure caps
    // it at 500 whatever is asked.
    'shift_limit' => 30,

];
