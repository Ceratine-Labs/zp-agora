<?php

/*
|--------------------------------------------------------------------------
| People
|--------------------------------------------------------------------------
|
| Setup → People and assets: employees, posts and employee areas (T023).
| The rows are PumpIT's (BRN_Employee, BRN_EmployeePost, BRN_EmployeeArea),
| read through agora.vw_People* views. Nothing here writes, so there is no
| write_mode switch: who may change an employee, and where the change lands,
| is not decided yet.
|
*/

return [

    'name' => 'People',

    // How many recent shifts an employee's page lists. The procedure caps
    // it at 500 whatever is asked.
    'shift_limit' => 30,

];
