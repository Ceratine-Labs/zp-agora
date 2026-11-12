<?php

use App\Support\Database\ProcedureMigration;

/**
 * The site and forecourt masters' write-backs to PumpIT (T022; CLAUDE.md
 * rule 1, PM decision 01a1cecf-8e08), on the frame v1__02b / v1__02pb built:
 * one save procedure per master over its narrowest columns, priors in
 * agora.MasterWrite, and usp_Masters_ReverseWrite redeployed with a branch
 * for each. No new ledger, so agora.vw_PumpItChange (Reports v1__22o) already
 * reads them. Redeploys usp_Masters_GridCategoryGlCodes too, which now names
 * each category's POS system.
 */
return new class extends ProcedureMigration
{
    /** @return array<int, string> */
    protected function files(): array
    {
        return [
            $this->path().'/usp_Masters_SaveExpense.sql',
            $this->path().'/usp_Masters_SaveTill.sql',
            $this->path().'/usp_Masters_SaveShift.sql',
            $this->path().'/usp_Masters_SaveTransactionType.sql',
            $this->path().'/usp_Masters_SaveFuelType.sql',
            $this->path().'/usp_Masters_SaveTank.sql',
            $this->path().'/usp_Masters_SavePump.sql',
            $this->path().'/usp_Masters_SaveUtilityMeter.sql',
            $this->path().'/usp_Masters_SaveTerminal.sql',
            $this->path().'/usp_Masters_ReverseWrite.sql',
            // The category listing names its POS system (1 Aura … 10 Nights Bridge).
            $this->path().'/usp_Masters_GridCategoryGlCodes.sql',
        ];
    }
};
