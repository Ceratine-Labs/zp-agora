<?php

use App\Support\Database\ProcedureMigration;
use Illuminate\Support\Facades\DB;

/** Skip impossible whole-run candidates before the Invalid filter reads bank rows. */
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
            'usp_Recon_RunValidity',
            'usp_Recon_GridRuns',
            'usp_Recon_ArchiveInvalidRuns',
        ]);
    }
};
