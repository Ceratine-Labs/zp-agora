<?php

namespace Modules\Masters\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Give the local PumpIT stub the reference masters' tables (T022), and fill
 * them with the customer's real rows.
 *
 * Masters' v1__02a names these tables across databases, and a view will not
 * CREATE over a table that is not there — so a local Agora needs them in the
 * local PumpIT beside it. The shape comes from
 * Modules/Masters/Database/Stubs/pumpit-masters.sql; the rows are READ from
 * the customer's instance (about 1,600 of them, a dozen small tables).
 *
 * ADDITIVE ONLY. A row the stub already holds — another module's fixture, a
 * TEST- row — is left exactly as it is; only rows missing by their natural key
 * are inserted. Nothing local is deleted, so another module's tests over the
 * same stub tables see what they saw before plus the real estate.
 *
 * It refuses to run unless the target is a local host. Writing into the
 * customer's PumpIT would be an incident.
 */
class SyncLocalMastersCommand extends Command
{
    protected $signature = 'agora:sync-local-masters';

    protected $description = 'Create the reference masters\' tables in the local PumpIT stub and copy their rows from the real instance';

    private const LOCAL = ['127.0.0.1', 'localhost', '::1', 'host.docker.internal'];

    /** Table => [natural key columns, every column copied]. */
    private const TABLES = [
        'SS_StandardCategory' => [['StandardCategoryId'], ['StandardCategoryId', 'StandardCategory', 'SupplierGLCode', 'SalesGLCode', 'IsActive']],
        'BRN_Expenses' => [['SSBranchId', 'ExpenseNo'], ['SSBranchId', 'ExpenseNo', 'ExpenseDescription', 'GLCode', 'ApproverUserId', 'IsAsset', 'IsActive', 'Vat']],
        'BRN_TransactionType' => [['SSBranchId', 'TypeCode'], ['SSBranchId', 'TypeCode', 'TypeDescription', 'AlpNum', 'HighNum', 'CreateDateTime']],
        'BRN_Tills' => [['SSBranchId', 'TillNo'], ['SSBranchId', 'TillNo', 'TillDescription', 'Location']],
        'BRN_Shifts' => [['SSBranchId', 'ShiftNo'], ['SSBranchId', 'ShiftNo', 'ShiftDescription', 'IsActive']],
        'BRN_FuelType' => [['SSBranchId', 'FuelTypeNo'], ['SSBranchId', 'FuelTypeNo', 'FuelTypeDescription', 'CostPrice', 'SellingPrice', 'IsFleetCard', 'POSType', 'POSIdentifier', 'HandlingFee', 'Code', 'CreateDateTime']],
        'BRN_Tank' => [['SSBranchId', 'TankNo'], ['SSBranchId', 'TankNo', 'TankDescription', 'CreateDateTime']],
        'BRN_Pump' => [['SSBranchId', 'PumpNo'], ['SSBranchId', 'PumpNo', 'FuelTypeNo', 'TankNo', 'Order', 'POSType', 'POSIdentifier', 'IsActive', 'CreateDateTime']],
        'BRN_UtilityMeter' => [['UtilityMeterId'], ['UtilityMeterId', 'SSBranchId', 'UtilityType', 'MeterNo', 'Description']],
        'SS_Branch_ABSA_MerchantNo' => [['ABSAMerchantNoId'], ['ABSAMerchantNoId', 'SSBranchId', 'ABSA_MerchantNo', 'Description', 'IsActive']],
        'SS_Branch_SMARTATM_TerminalNo' => [['SMARTATMTerminalNoId'], ['SMARTATMTerminalNoId', 'SSBranchId', 'SMARTATM_TerminalNo', 'Description', 'IsActive']],
        'SS_Branch_CASHDEVICE_TerminalNo' => [['CASHDEVICETerminalNoId'], ['CASHDEVICETerminalNoId', 'SSBranchId', 'CASHDEVICE_TerminalNo', 'Description']],
        'SS_ReconCriteriaGroup' => [['CriteriaGroupId'], ['CriteriaGroupId', 'Code', 'Description', 'SQLTableName', 'SQLFieldName', 'ReconFieldName', 'DataType', 'IsActive']],
        'SS_ReconCriteria' => [['SSBranchId', 'CriteriaId'], ['SSBranchId', 'CriteriaId', 'CriteriaGroupId', 'Code', 'Description', 'Reference', 'ReferenceStartPosition', 'ReferenceEndPosition', 'ReconStartPosition', 'ReconEndPosition', 'IsAutoReconcile']],
    ];

    public function handle(): int
    {
        $app = config('agora.connections.app');
        $host = (string) config("database.connections.{$app}.host");

        if (! in_array($host, self::LOCAL, true)) {
            $this->components->error("Refusing to run: [{$app}] points at {$host}, which is not a local host. This writes into a PumpIT stub.");

            return self::FAILURE;
        }

        $stub = DB::connection($app);
        $source = DB::connection(config('agora.connections.erp'));
        $database = (string) config('agora.source_databases.erp');

        if ((string) config('database.connections.'.config('agora.connections.erp').'.host') === $host) {
            $this->components->error('The erp connection is the local container too, so there is nothing real to copy from.');

            return self::FAILURE;
        }

        $this->shape($stub, $database);

        foreach (self::TABLES as $table => [$key, $columns]) {
            $rows = $source->table("dbo.{$table}")->select($columns)->get();
            $inserted = 0;

            foreach ($rows as $row) {
                $where = implode(' AND ', array_map(fn (string $c) => "[{$c}] = ?", $key));
                $exists = $stub->selectOne("SELECT 1 AS Found FROM [{$database}].dbo.[{$table}] WHERE {$where}",
                    array_map(fn (string $c) => $row->{$c}, $key));
                if ($exists !== null) {
                    continue;
                }

                $list = implode(', ', array_map(fn (string $c) => "[{$c}]", $columns));
                $marks = implode(', ', array_fill(0, count($columns), '?'));
                $stub->insert("INSERT [{$database}].dbo.[{$table}] ({$list}) VALUES ({$marks})",
                    array_map(fn (string $c) => $row->{$c}, $columns));
                $inserted++;
            }

            $this->components->info("{$table}: {$rows->count()} on the real instance, {$inserted} added locally.");
        }

        return self::SUCCESS;
    }

    /** Run each batch of the stub file inside the local PumpIT database. */
    private function shape(Connection $stub, string $database): void
    {
        $file = (string) file_get_contents(dirname(__DIR__).'/Database/Stubs/pumpit-masters.sql');
        $batches = preg_split('/^\s*GO\s*$/mi', (string) preg_replace('{/\*.*?\*/}s', '', $file)) ?: [];

        foreach ($batches as $batch) {
            if (trim($batch) !== '') {
                $stub->statement("EXEC [{$database}].sys.sp_executesql ?", [trim($batch)]);
            }
        }
    }
}
