<?php

use App\Support\Database\ProcedureMigration;
use Illuminate\Support\Facades\DB;

/** Shared bank extraction, conservative run validity and reversible bulk archive. */
return new class extends ProcedureMigration
{
    public function up(): void
    {
        $path = __DIR__.'/../Functions/fn_Recon_DrillBank.sql';
        $sql = trim(file_get_contents($path));

        if (preg_match('/CREATE\s+OR\s+ALTER\s+FUNCTION\s+\[?'.preg_quote(config('agora.schema'), '/').'\]?\.\[?fn_Recon_DrillBank\]?\s*\(/i', $sql) !== 1) {
            throw new RuntimeException('The shared bank extraction must remain in the Agora schema.');
        }

        // SQL Server's Laravel grammar does not wrap schema migrations. Keep
        // the shared function and every dependent procedure one atomic change.
        DB::transaction(function () use ($sql): void {
            DB::unprepared($sql);
            parent::up();
        });
    }

    /** @return array<int, string> */
    protected function files(): array
    {
        return array_map(fn (string $name): string => __DIR__.'/../Procedures/'.$name.'.sql', [
            'usp_Recon_DrillBank',
            'usp_Recon_RunScope',
            'usp_Recon_RunValidity',
            'usp_Recon_GridRuns',
            'usp_Recon_ArchiveInvalidRuns',
            'usp_Recon_Commit',
        ]);
    }
};
