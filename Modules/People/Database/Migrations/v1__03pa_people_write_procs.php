<?php

use App\Support\Database\ProcedureMigration;

/**
 * People's named write (T023; Ryan's go-ahead, PM decision 01a1ceba-4d74):
 * the employee save, the employee areas save, adding a post, putting a
 * press back, and the two reads the edit screen needs. The writes reach
 * [PumpIT].dbo.BRN_Employee (IsActive, PostNo, NickName), BRN_EmployeeArea
 * and BRN_EmployeePost, and nothing else — CLAUDE.md rule 1.
 */
return new class extends ProcedureMigration
{
    /** @return array<int, string> */
    protected function files(): array
    {
        return [
            $this->path().'/usp_People_GetEmployeeForm.sql',
            $this->path().'/usp_People_GetEmployeeChanges.sql',
            $this->path().'/usp_People_SaveEmployee.sql',
            $this->path().'/usp_People_SaveEmployeeAreas.sql',
            $this->path().'/usp_People_AddPost.sql',
            $this->path().'/usp_People_ReverseWrite.sql',
        ];
    }
};
