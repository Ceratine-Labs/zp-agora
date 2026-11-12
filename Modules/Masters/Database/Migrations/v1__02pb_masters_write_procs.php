<?php

use App\Support\Database\ProcedureMigration;

/**
 * The reference masters' write-backs to PumpIT (T022; CLAUDE.md rule 1, PM
 * decision 01a1cecf-8e08): one save procedure per master, the reversal, and
 * the read of a record's writes. Each save writes the narrowest columns of
 * one PumpIT row and records the priors in agora.MasterWrite (v1__02b).
 */
return new class extends ProcedureMigration
{
    /** @return array<int, string> */
    protected function files(): array
    {
        return [
            $this->path().'/usp_Masters_SaveCategoryBand.sql',
            $this->path().'/usp_Masters_SaveProfitCentre.sql',
            $this->path().'/usp_Masters_SaveStandardCategory.sql',
            $this->path().'/usp_Masters_ReverseWrite.sql',
            $this->path().'/usp_Masters_GetWrites.sql',
        ];
    }
};
