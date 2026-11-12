<?php

use App\Support\Database\ProcedureMigration;

/** Bank accounts (T022): the read-only listing over vw_MasterBankAccount (v1__02c). */
return new class extends ProcedureMigration
{
    /** @return array<int, string> */
    protected function files(): array
    {
        return [$this->path().'/usp_Masters_GridBankAccounts.sql'];
    }
};
