<?php

use App\Support\Database\ProcedureMigration;
use Illuminate\Support\Facades\DB;

/** Keep each manual card source pair available to the pending-preview warning. */
return new class extends ProcedureMigration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            parent::up();
        });
    }

    /** @return array<int, string> */
    protected function files(): array
    {
        return array_map(fn (string $name): string => __DIR__.'/../Procedures/'.$name.'.sql', [
            'usp_Recon_ManualMatch',
            'usp_Recon_RunFreshness',
        ]);
    }
};
