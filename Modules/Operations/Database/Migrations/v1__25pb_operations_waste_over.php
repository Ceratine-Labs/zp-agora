<?php

use App\Support\Database\ProcedureMigration;

return new class extends ProcedureMigration
{
    protected function files(): array
    {
        return [$this->path().'/usp_Operations_Snapshot.sql'];
    }
};
