<?php

use App\Support\Database\ProcedureMigration;

/**
 * Stock recon — a missed closing count can be corrected (slot 14zb).
 *
 * Ryan, 16 Nov 2026, run 80529 (D Cheddar Cheese Kg): a closing counted 0
 * after an issue, the next shift opening 0 and counting the stock back, was
 * held back as an implausible amendment with no way to correct it. The caps no
 * longer hold such a chain; it is proposed unticked and the row says
 * "Missed count". usp_StockRecon_PreviewBalancing only; Agora's own tables.
 */
return new class extends ProcedureMigration
{
    protected function files(): array
    {
        return [$this->path().'/usp_StockRecon_PreviewBalancing.sql'];
    }
};
