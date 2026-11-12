<?php

use App\Support\Database\ProcedureMigration;

/**
 * People's procedures (T023): the employee, post and employee-area listings,
 * one employee with the shifts they worked, and the next post number. Every
 * one reads; none writes, here or in PumpIT.
 */
return new class extends ProcedureMigration
{
    /** @return array<int, string> */
    protected function files(): array
    {
        return [
            $this->path().'/usp_People_GridEmployees.sql',
            $this->path().'/usp_People_GetEmployee.sql',
            $this->path().'/usp_People_GridPosts.sql',
            $this->path().'/usp_People_GridEmployeeAreas.sql',
            $this->path().'/usp_People_NextPost.sql',
        ];
    }
};
