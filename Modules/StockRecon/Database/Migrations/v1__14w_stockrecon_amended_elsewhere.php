<?php

use App\Support\Database\ProcedureMigration;

/**
 * Stock recon — the shifts on a run another run has already amended, and
 * which run holds each (slot 14w).
 *
 * Ryan, 14 Nov 2026, on run #70235 (Engen Manguzi): 58 of its 81 items were
 * already amended elsewhere and nothing said so until the commit skipped them.
 * The run page now badges those items with the run that holds them and leaves
 * their shifts unticked. Reads only.
 */
return new class extends ProcedureMigration
{
    protected function files(): array
    {
        return [$this->path().'/usp_StockRecon_AmendedElsewhere.sql'];
    }
};
