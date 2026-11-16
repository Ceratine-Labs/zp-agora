<?php

use App\Support\Database\ProcedureMigration;

/**
 * Stock recon — a till is not called missing while its sales are on the recon
 * lines (slot 14zi).
 *
 * Nyala One Stop, support request #2, 16 Nov 2026 (run 80592, LIP ICE 1EA
 * MUSK): "There are actually no sales for this item on this day in pumpit.
 * Shouldnt be an error". The WINBRANCH till's rows for 2 and 3 Nov reached
 * PumpIT with MistEod NULL, so the whole-till check (read by MistEod) found
 * none, reported the till missing, and marked every item it sells with no
 * sales those days red. The recon lines carried that till's POS (87 and 94
 * units), which proves it reported. usp_StockRecon_TillQtyCheck now leaves
 * such a day alone. Read-only procedure; nothing is written anywhere.
 */
return new class extends ProcedureMigration
{
    protected function files(): array
    {
        return [$this->path().'/usp_StockRecon_TillQtyCheck.sql'];
    }
};
