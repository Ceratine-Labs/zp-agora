<?php

use App\Support\Database\ProcedureMigration;

/** Adds full-scope per-type totals to My queue's third result set only. */
return new class extends ProcedureMigration
{
    protected function files(): array
    {
        return [$this->path().'/usp_Reports_GridMyQueue.sql'];
    }
};
