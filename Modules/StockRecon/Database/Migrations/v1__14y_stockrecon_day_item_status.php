<?php

use App\Support\Database\ProcedureMigration;

/**
 * Stock recon — each item's own state on the reconciled-day page (slot 14y).
 *
 * Ryan, 15 Nov 2026: "green to posted, yellow for run but not amended, red for
 * no run". usp_StockRecon_DayStatus's detail mode gains a fourth result set,
 * the area's done / partial / open read item by item. Reads only.
 */
return new class extends ProcedureMigration
{
    protected function files(): array
    {
        return [$this->path().'/usp_StockRecon_DayStatus.sql'];
    }
};
