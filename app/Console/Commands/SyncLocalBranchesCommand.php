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
            ->select('SSBranchId', 'BranchName', 'BrandId', 'RegionId', 'ClassId', 'IsActive', 'POSType', 'IsPOSImport')
            ->orderBy('SSBranchId')
            ->get();

        $database = config('agora.source_databases.erp');

        $stub->statement("DELETE FROM [{$database}].dbo.SS_Branch;");

        foreach ($rows as $row) {
            $stub->insert(
                "INSERT [{$database}].dbo.SS_Branch (SSBranchId, BranchName, BrandId, RegionId, ClassId, IsActive, POSType, IsPOSImport) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    (int) $row->SSBranchId,
                    trim((string) $row->BranchName),
                    $row->BrandId,
                    $row->RegionId,
                    $row->ClassId,
                    (int) $row->IsActive,
                    $row->POSType,
                    $row->IsPOSImport,
                ]
            );
        }

        $this->components->info("Copied {$rows->count()} branches into the local [{$database}] stub.");

        // The branch master's other sources (T021): the three groupings, the
        // profit centres and the category → profit centre map. Copied whole;
        // database/stubs/pumpit-branch.sql gives them their shape.
        foreach ([
            'SS_Region' => ['Regionid', 'Region', 'IsActive', 'CreateDateTime'],
            'SS_Brand' => ['Brandid', 'BrandName', 'IsActive', 'CreateDateTime'],
            'SS_Class' => ['Classid', 'ClassName', 'IsActive', 'CreateDateTime'],
            'SS_ProfitCentre' => ['ProfitCentreId', 'ProfitCentre', 'IsActive'],
            'SDK_CategoryGLCode' => ['SSBranchId', 'POSSystemId', 'Category', 'StandardCategory', 'SupplierGLCode',
                'SalesGLCode', 'ProfitCentre', 'IsActive', 'MINGP', 'MAXGP', 'MINDaysCover', 'MAXDaysCover'],
        ] as $table => $columns) {
            if ($stub->selectOne("SELECT OBJECT_ID('[{$database}].dbo.{$table}') AS Id")->Id === null) {
                $this->components->warn("[{$database}].dbo.{$table} is missing locally — run scripts/local-sql.sh up first.");

                continue;
            }

            $copied = $source->table("dbo.{$table}")->select($columns)->get();
            $stub->statement("DELETE FROM [{$database}].dbo.{$table};");
            $list = implode(', ', $columns);
            $marks = implode(', ', array_fill(0, count($columns), '?'));

            foreach ($copied as $row) {
                $stub->insert("INSERT [{$database}].dbo.{$table} ({$list}) VALUES ({$marks})",
                    array_map(fn (string $column) => $row->{$column}, $columns));
            }

            $this->components->info("Copied {$copied->count()} rows of {$table}.");
        }

        return self::SUCCESS;
    }
}
