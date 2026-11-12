<?php

use App\Support\Database\ProcedureMigration;

/**
 * Stock recon — a committed run reads "Posted" on the runs grid (slot 14pzy).
 *
 * Tessa Coetzee, Ngwenya Convenience, 12 Nov 2026: "Can we change committed to
 * Posted if possible". usp_StockRecon_GridRuns now returns the word on screen
 * in [Status] and the stored value as StatusCode, which its Status filter
 * compares. agora.StockReconRun.Status stays 'committed'; nothing else moves.
 */
return new class extends ProcedureMigration
{
    protected function files(): array
    {
        return [$this->path().'/usp_StockRecon_GridRuns.sql'];
    }
};
