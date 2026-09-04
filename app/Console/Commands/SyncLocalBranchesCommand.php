<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fill the local PumpIT stub's SS_Branch from the real instance.
 *
 * Agora's `agora.vw_*` views reach the legacy estate across databases
 * (`PumpIT.dbo.SS_Branch`), and three-part naming is same-instance only — so a
 * local Agora database needs a local PumpIT beside it or every view is broken.
 * The stub holds the shape; this fills it with the 31 real rows so what renders
 * locally is what renders on the customer's instance.
 *
 * It refuses to run unless the target is a local host. Writing branch rows into
 * the customer's PumpIT would be an incident, and "I meant to run it against
 * Docker" is not a control.
 */
class SyncLocalBranchesCommand extends Command
{
    protected $signature = 'agora:sync-local-branches';

    protected $description = 'Copy dbo.SS_Branch from the real instance into the local PumpIT stub';

    /** Hosts this is allowed to write to. */
    private const LOCAL = ['127.0.0.1', 'localhost', '::1', 'host.docker.internal'];

    public function handle(): int
    {
        $app = config('agora.connections.app');
        $host = (string) config("database.connections.{$app}.host");

        if (! in_array($host, self::LOCAL, true)) {
            $this->components->error(
                "Refusing to run: the [{$app}] connection points at {$host}, which is not a local host. "
                .'This command writes rows into a PumpIT stub and must never reach the customer instance.'
            );

            return self::FAILURE;
        }

        $stub = DB::connection($app);
        $source = DB::connection(config('agora.connections.erp'));

        $rows = $source->table('dbo.SS_Branch')
            ->select('SSBranchId', 'BranchName', 'BrandId', 'RegionId', 'ClassId', 'IsActive')
            ->orderBy('SSBranchId')
            ->get();

        $database = config('agora.source_databases.erp');

        $stub->statement("DELETE FROM [{$database}].dbo.SS_Branch;");

        foreach ($rows as $row) {
            $stub->insert(
                "INSERT [{$database}].dbo.SS_Branch (SSBranchId, BranchName, BrandId, RegionId, ClassId, IsActive) VALUES (?, ?, ?, ?, ?, ?)",
                [
                    (int) $row->SSBranchId,
                    trim((string) $row->BranchName),
                    $row->BrandId,
                    $row->RegionId,
                    $row->ClassId,
                    (int) $row->IsActive,
                ]
            );
        }

        $this->components->info("Copied {$rows->count()} branches into the local [{$database}] stub.");

        return self::SUCCESS;
    }
}
