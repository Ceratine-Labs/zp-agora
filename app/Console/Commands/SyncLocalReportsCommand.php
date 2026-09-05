<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Fill the local PumpIT stub with a slice of the customer's real rows.
 *
 * WHY THIS EXISTS. `agora:sync-local-branches` copies the 31 branch rows, which
 * is enough for the shell and not enough for a report: a local checkout renders
 * every Today screen empty, and an empty screen is indistinguishable from a
 * broken one. That is exactly how this landed — fifteen reports verified
 * against the live instance, and every one of them blank in the browser,
 * because the browser was reading a stub that has shape and no rows.
 *
 * WHAT IT IS ALLOWED TO DO. It READS the customer's databases and WRITES only
 * into the local container. It refuses outright unless the app connection is a
 * local host — copying rows INTO the customer's PumpIT would be an incident,
 * and "I meant to run it against Docker" is not a control. It is the same guard
 * `agora:sync-local-branches` carries, for the same reason.
 *
 * WHAT IT COPIES. Only the columns the `agora.vw_*` views enumerate — the same
 * list `database/stubs/pumpit-reports.sql` was generated from, so the stub, the
 * views and this command cannot drift apart. Transaction tables are cut to a
 * date window; reference tables come whole, because they are small and a report
 * with no stock master reads as a report with no stock.
 *
 *     php artisan agora:sync-local-reports                 # last 14 days
 *     php artisan agora:sync-local-reports --days=30
 *     php artisan agora:sync-local-reports --only=BRN_DailyBanking
 */
class SyncLocalReportsCommand extends Command
{
    protected $signature = 'agora:sync-local-reports
                            {--days=14 : How many days of transactions to copy}
                            {--lines-days=3 : Days of stock recon LINES — 7.1m rows live, so this is separate}
                            {--only= : One table name, for a re-run after a schema change}';

    protected $description = 'Copy a slice of the customer\'s rows into the local PumpIT stub so the reports have something to show';

    /** Hosts this is allowed to write to. */
    private const LOCAL = ['127.0.0.1', 'localhost', '::1', 'host.docker.internal'];

    /**
     * Reference data: small, and a report without it renders as a report with
     * no products, no people and no meters. Copied whole.
     *
     * @var array<int, string>
     */
    private const REFERENCE = [
        'BRN_DayEndType', 'BRN_FuelType', 'BRN_Pump', 'BRN_Tills', 'BRN_Shifts',
        'BRN_Employee', 'BRN_TransactionType', 'BRN_Suppliers', 'BRN_Expenses',
        'BRN_ApproverUserlevel', 'SS_Users', 'STK_Area', 'STK_StockMaster',
        'SS_UtilityType', 'BRN_UtilityMeter', 'BRN_POSImportSelection',
        'BRN_DropSafe_ReasonForManualBag',
    ];

    /**
     * Transaction data, and the column that dates it.
     *
     * BRN_FuelPrice is here even though it is a price HISTORY rather than a
     * transaction: it holds 71,946 rows, and the fuel report needs the price in
     * force on the day, which is the latest change ON OR BEFORE it. So its
     * window is deliberately much wider than the rest — a 14-day slice of a
     * table that only changes when a price changes would leave most branches
     * with no price at all.
     *
     * @var array<string, string>
     */
    private const DATED = [
        'RCN_ReconImports' => 'ReconDate',
        'BRN_DayEnd' => 'DayEndDate',
        'BRN_PumpReadings' => 'ReadingDate',
        'BRN_FuelInput' => 'FuelDate',
        'DBF_P3TRANS_ZREAD' => 'DATE',
        'BRN_DailyBanking' => 'TransactionDate',
        'BRN_DailyBankingEmployees' => 'TransactionDate',
        'BRN_StaffShorts' => 'TransactionDate',
        'BRN_Transaction' => 'PurchaseRequestDate',
        'BRN_TransactionLine' => 'CreateDateTime',
        'BRN_DropSafe' => 'DropDate',
        'BRN_DropSafe_Collection' => 'CollectionDate',
        'STK_StockRecon' => 'TransactionDate',
        'STK_StockWasteLine' => 'TransactionDate',
        'BRN_UtilityTransaction' => 'TransactionDate',
    ];

    /** Line grain, and enormous. Its own, much shorter window. */
    private const LINE_GRAIN = ['STK_StockReconLine' => 'TransactionDate'];

    /** Its own window, for the reason in the DATED docblock. */
    private const WIDE = ['BRN_FuelPrice' => 'Date'];

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

        $columns = $this->columnManifest();

        if ($columns === []) {
            $this->components->error('No column manifest — database/stubs/pumpit-reports.sql is missing or unreadable.');

            return self::FAILURE;
        }

        $stub = DB::connection($app);
        $source = DB::connection(config('agora.connections.erp'));
        $database = (string) config('agora.source_databases.erp');

        $only = $this->option('only');
        $days = max(1, (int) $this->option('days'));
        $lineDays = max(1, (int) $this->option('lines-days'));

        $plan = [];
        foreach (self::REFERENCE as $table) {
            $plan[$table] = null;
        }
        foreach (self::DATED as $table => $column) {
            $plan[$table] = [$column, $days];
        }
        foreach (self::LINE_GRAIN as $table => $column) {
            $plan[$table] = [$column, $lineDays];
        }
        foreach (self::WIDE as $table => $column) {
            // Two years, so every branch and grade has a price on or before the
            // window rather than a NULL that reads as R0.00.
            $plan[$table] = [$column, 730];
        }

        if ($only) {
            if (! array_key_exists($only, $plan)) {
                $this->components->error("[{$only}] is not one of the tables this command knows about.");

                return self::FAILURE;
            }
            $plan = [$only => $plan[$only]];
        }

        $total = 0;

        foreach ($plan as $table => $window) {
            if (! isset($columns[$table])) {
                $this->components->warn("{$table} is not in the stub manifest — skipped.");

                continue;
            }

            $copied = $this->copy($source, $stub, $database, $table, $columns[$table], $window);
            $total += $copied;

            $this->components->twoColumnDetail(
                $table.($window ? "  ({$window[1]}d on {$window[0]})" : '  (all)'),
                number_format($copied).' rows'
            );
        }

        $this->newLine();
        $this->components->info(number_format($total)." rows copied into the local [{$database}] stub.");
        $this->components->warn('Real customer data now sits in your Docker container. It is a slice, not a backup, and it is not anonymised.');

        return self::SUCCESS;
    }

    /**
     * Copy one table.
     *
     * The stub table is emptied first, so a re-run is a refresh rather than a
     * pile-up — the stub has no keys, so nothing would refuse a duplicate.
     *
     * @param  array<int, string>  $columns
     * @param  array{0: string, 1: int}|null  $window
     */
    private function copy(
        Connection $source,
        Connection $stub,
        string $database,
        string $table,
        array $columns,
        ?array $window,
    ): int {
        $select = collect($columns)->map(fn (string $c) => "[{$c}]")->implode(', ');

        $sql = "SELECT {$select} FROM dbo.[{$table}]";
        $bindings = [];

        if ($window !== null) {
            [$dateColumn, $days] = $window;
            $sql .= " WHERE [{$dateColumn}] >= ?";
            $bindings[] = now()->subDays($days)->startOfDay()->toDateTimeString();
        }

        $rows = $source->select($sql, $bindings);

        $stub->statement("DELETE FROM [{$database}].dbo.[{$table}];");

        if ($rows === []) {
            return 0;
        }

        $placeholders = '('.implode(', ', array_fill(0, count($columns), '?')).')';

        // 1,000 parameters is the practical sqlsrv ceiling per statement, so the
        // batch size follows the column count rather than being a round number
        // that works for narrow tables and fails on BRN_Employee.
        $perBatch = max(1, (int) floor(900 / count($columns)));

        foreach (array_chunk($rows, $perBatch) as $chunk) {
            $values = [];
            foreach ($chunk as $row) {
                foreach ($columns as $column) {
                    $values[] = ((array) $row)[$column] ?? null;
                }
            }

            $stub->insert(
                "INSERT INTO [{$database}].dbo.[{$table}] ({$select}) VALUES "
                .implode(', ', array_fill(0, count($chunk), $placeholders)),
                $values
            );
        }

        return count($rows);
    }

    /**
     * The columns each stub table has, read out of the stub file itself.
     *
     * Read rather than declared, so this command cannot copy a column the stub
     * does not have — which is the failure that would leave a table half-filled
     * and a report subtly wrong rather than obviously empty.
     *
     * @return array<string, array<int, string>>
     */
    private function columnManifest(): array
    {
        $path = database_path('stubs/pumpit-reports.sql');

        if (! is_readable($path)) {
            return [];
        }

        $sql = (string) file_get_contents($path);
        $manifest = [];

        // CREATE TABLE blocks.
        preg_match_all('/CREATE TABLE dbo\.(\w+) \((.*?)\n\);/s', $sql, $creates, PREG_SET_ORDER);
        foreach ($creates as [, $table, $body]) {
            preg_match_all('/^\s{4}\[?(\w+)\]?\s/m', $body, $cols);
            $manifest[$table] = $cols[1];
        }

        // The three tables the other stub files create, widened by ALTER here.
        preg_match_all("/IF COL_LENGTH\('dbo\.(\w+)', '(\w+)'\)/", $sql, $alters, PREG_SET_ORDER);
        foreach ($alters as [, $table, $column]) {
            $manifest[$table][] = $column;
        }

        return array_map(fn (array $c) => array_values(array_unique($c)), $manifest);
    }
}
