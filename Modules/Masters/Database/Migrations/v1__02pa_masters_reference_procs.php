<?php

use App\Support\Database\ProcedureMigration;

/**
 * The reference masters' procedures (T022): one read-only grid procedure per
 * master, over the views v1__02a creates. None of them writes anything.
 * Deployed after v1__02p, which already ran with T021's five.
 */
return new class extends ProcedureMigration
{
    /** @return array<int, string> */
    protected function files(): array
    {
        return [
            $this->path().'/usp_Masters_GridProfitCentres.sql',
            $this->path().'/usp_Masters_GridStandardCategories.sql',
            $this->path().'/usp_Masters_GridCategoryGlCodes.sql',
            $this->path().'/usp_Masters_GridExpenses.sql',
            $this->path().'/usp_Masters_GridTills.sql',
            $this->path().'/usp_Masters_GridShifts.sql',
            $this->path().'/usp_Masters_GridTransactionTypes.sql',
            $this->path().'/usp_Masters_GridFuelTypes.sql',
            $this->path().'/usp_Masters_GridTanks.sql',
            $this->path().'/usp_Masters_GridPumps.sql',
        ];
    }
};
