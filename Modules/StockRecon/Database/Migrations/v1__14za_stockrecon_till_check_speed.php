<?php

use App\Support\Database\ProcedureMigration;

/**
 * Stock recon — the till check made fast (slot 14za).
 *
 * Ryan, 16 Nov 2026: run 80527 (Pongola, every counting area) stalled. The
 * check joined the recon-line and till views in one statement; it took 74 to
 * 76 seconds a run, single-site runs included. Each side now goes to a temp
 * table first, with RECOMPILE so the dates are known when it is planned:
 * 8.6 seconds on 80527, 10.6 on 80506 with the same answer. Reads only.
 */
return new class extends ProcedureMigration
{
    protected function files(): array
    {
        return [$this->path().'/usp_StockRecon_TillQtyCheck.sql'];
    }
};
