<?php

use App\Support\Database\ProcedureMigration;

/** Recon follow-up: run identifiers, batch reversal and audited cash-bag adjustments. */
return new class extends ProcedureMigration
{
    /** Deploy only this release's procedures; preserve other live recon customisations. */
    protected function files(): array
    {
        return array_map(fn (string $name): string => __DIR__.'/../Procedures/'.$name.'.sql', [
            'usp_Recon_GetBatch',
            'usp_Recon_GridRuns',
            'usp_Recon_ManualMatch',
            'usp_Recon_Reverse',
        ]);
    }
};
