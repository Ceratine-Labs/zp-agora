<?php

use App\Support\Database\ProcedureMigration;

/**
 * usp_Migrate_Branch (T021) — dbo.SS_Branch into the branch master v1__01j
 * built. Reads PumpIT through agora.vw_Legacy* only; writes Agora's own tables.
 */
return new class extends ProcedureMigration
{
    /** @return array<int, string> */
    protected function files(): array
    {
        return [$this->path().'/usp_Migrate_Branch.sql'];
    }
};
