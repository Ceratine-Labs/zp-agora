<?php

use App\Support\Database\ProcedureMigration;

/**
 * Stock recon — the items on a run whose standing amendments were worked out
 * against a POS quantity PumpIT has since changed (slot 14u).
 *
 * Caltex Ulundi, 14 Nov 2026: amendments committed before head office's
 * update script filled in the till quantities stood on a POS of 0. The run
 * page now lists those items so each can be reversed and previewed again.
 * Reads only.
 */
return new class extends ProcedureMigration
{
    protected function files(): array
    {
        return [$this->path().'/usp_StockRecon_PosMovedCheck.sql'];
    }
};
