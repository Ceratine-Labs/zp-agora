<?php

use App\Support\Database\ProcedureMigration;

/**
 * Reports — the two governance grids over the audit tables (T027, slot 22ph).
 *
 *   usp_Reports_GridMasterChanges  agora.MasterChange and the master ledgers
 *                                  of agora.vw_PumpItChange, one row per field
 *   usp_Reports_GridUserActivity   agora.UserActivity
 *
 * Both read and nothing else, take the grid template's nine parameters plus
 * the caller's permission and sites, and are shown under Setup → Governance.
 */
return new class extends ProcedureMigration
{
    protected function files(): array
    {
        return [
            $this->path().'/usp_Reports_GridMasterChanges.sql',
            $this->path().'/usp_Reports_GridUserActivity.sql',
        ];
    }
};
