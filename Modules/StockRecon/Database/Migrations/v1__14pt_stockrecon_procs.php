<?php

use App\Support\Database\ProcedureMigration;

/** Adds the run-wide exception type inventory for the exceptions navigation. */
return new class extends ProcedureMigration
{
    protected function files(): array
    {
        return [$this->path().'/usp_StockRecon_ExceptionTypes.sql'];
    }
};
