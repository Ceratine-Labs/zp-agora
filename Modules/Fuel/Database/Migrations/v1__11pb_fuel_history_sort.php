<?php

use App\Support\Database\ProcedureMigration;

/**
 * Fuel: usp_Fuel_GridHistory names the report's sortable columns, one CASE arm
 * each, before handing the sort to usp_Fuel_GridPrices (slot 11pb). Deploys
 * only that procedure.
 */
return new class extends ProcedureMigration
{
    protected function files(): array
    {
        return [$this->path().'/usp_Fuel_GridHistory.sql'];
    }
};
