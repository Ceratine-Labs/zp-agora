<?php

use App\Support\Database\ProcedureMigration;

/**
 * Partners' procedures (T024): the supplier and account-customer listings, one
 * of each, the palette's search, the next free supplier code and the
 * duplicate-code guard. Every one reads; none writes, here or in PumpIT.
 */
return new class extends ProcedureMigration
{
    /** @return array<int, string> */
    protected function files(): array
    {
        return [
            $this->path().'/usp_Partners_GridSuppliers.sql',
            $this->path().'/usp_Partners_GetSupplier.sql',
            $this->path().'/usp_Partners_GridCustomers.sql',
            $this->path().'/usp_Partners_GetCustomer.sql',
            $this->path().'/usp_Partners_Search.sql',
            $this->path().'/usp_Partners_NextSupplierCode.sql',
            $this->path().'/usp_Partners_CheckCode.sql',
        ];
    }
};
