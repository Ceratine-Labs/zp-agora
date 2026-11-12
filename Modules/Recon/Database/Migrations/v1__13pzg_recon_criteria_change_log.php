<?php

use App\Support\Database\ProcedureMigration;

/**
 * Recon (T027): usp_Recon_SaveCriteria writes one agora.MasterChange row per
 * extraction position it moved — before and after the rule in force, the user
 * and the reason — inside the transaction of the write, so an override shows
 * in Setup → Governance → Master data changes.
 */
return new class extends ProcedureMigration
{
    /** Deploy only this procedure; preserve other live recon customisations. */
    protected function files(): array
    {
        return [$this->path().'/usp_Recon_SaveCriteria.sql'];
    }
};
