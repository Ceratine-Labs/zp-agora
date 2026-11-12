<?php

use App\Support\Database\ProcedureMigration;

/**
 * Partners' named writes (T024; CLAUDE.md rule 1, PM decision 01a1cecf-8e08):
 * a supplier's and a customer's edit, adding either, the reversal of an edit,
 * and the changes a partner's page lists. NextSupplierCode is deployed again
 * for its new Sites column.
 */
return new class extends ProcedureMigration
{
    /** @return array<int, string> */
    protected function files(): array
    {
        return [
            $this->path().'/usp_Partners_NextSupplierCode.sql',
            $this->path().'/usp_Partners_SaveSupplier.sql',
            $this->path().'/usp_Partners_AddSupplier.sql',
            $this->path().'/usp_Partners_SaveCustomer.sql',
            $this->path().'/usp_Partners_NextCustomerCode.sql',
            $this->path().'/usp_Partners_AddCustomer.sql',
            $this->path().'/usp_Partners_ReverseWrite.sql',
            $this->path().'/usp_Partners_GetChanges.sql',
        ];
    }
};
