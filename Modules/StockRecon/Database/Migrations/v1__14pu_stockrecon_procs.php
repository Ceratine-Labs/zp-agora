<?php

use App\Support\Database\ProcedureMigration;

/**
 * Adds agora.usp_StockRecon_GridVariance (slot 14pu), 19 Oct 2026: the
 * original vs amended variance over any period, line by line with PumpIT's
 * Stock Recon Balancing arithmetic, for the managers after balancing (ZRP:
 * "to easily see the difference in figures before and after balancing").
 *
 * Deploys that one file: nothing else in the module changed.
 */
return new class extends ProcedureMigration
{
    protected function files(): array
    {
        return [$this->path().'/usp_StockRecon_GridVariance.sql'];
    }
};
