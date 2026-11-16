<?php

use App\Support\Database\ProcedureMigration;

/**
 * Stock recon — a missed count is unticked only where the caps would have
 * held it back (slot 14zc, 16 Nov 2026). 14zb unticked every missed count,
 * the small ones that always balanced ticked included. Reloads
 * usp_StockRecon_PreviewBalancing; Agora's own tables only.
 */
return new class extends ProcedureMigration
{
    protected function files(): array
    {
        return [$this->path().'/usp_StockRecon_PreviewBalancing.sql'];
    }
};
