<?php

use App\Support\Database\ProcedureMigration;

/** Keep cumulative water readings out of physical stock-loss summaries. */
return new class extends ProcedureMigration
{
    protected function files(): array
    {
        return [$this->path().'/usp_Operations_Snapshot.sql'];
    }
};
