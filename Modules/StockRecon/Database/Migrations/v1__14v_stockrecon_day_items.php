<?php

use App\Support\Database\ProcedureMigration;

/**
 * Stock recon — one site's day, area by area, item by item, with what the
 * tills sold beside the POS on the recon lines (slot 14v).
 *
 * Ryan, 14 Nov 2026, for Chantal Peens's "COMPUTER QTY" check: clicking a day
 * on the reconciled-days calendar opens each counting area onto its items, and
 * an item whose till quantity is missing in PumpIT says so. Reads only.
 */
return new class extends ProcedureMigration
{
    protected function files(): array
    {
        return [$this->path().'/usp_StockRecon_DayItems.sql'];
    }
};
