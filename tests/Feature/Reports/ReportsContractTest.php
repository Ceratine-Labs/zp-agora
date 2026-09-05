<?php

namespace Tests\Feature\Reports;

use Illuminate\Support\Facades\DB;
use Modules\Reports\Services\ReportsService;
use Tests\TestCase;

/**
 * The contract between the registry, the procedures and the navigation.
 *
 * Every one of these is mechanical, and every one of them is a thing that has
 * already gone wrong once somewhere in this repository: a column that names a
 * result-set key the procedure does not return, a sort key the ORDER BY has no
 * CASE arm for, a menu entry pointing at a report that was renamed.
 *
 * READ-ONLY throughout. Nothing here writes to any database — these tests ask
 * SQL Server to DESCRIBE the procedures rather than to run them, so they are
 * safe against the customer's instance as well as the local container.
 */
class ReportsContractTest extends TestCase
{
    private ReportsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(ReportsService::class);
    }

    public function test_every_report_names_a_procedure_that_ships_as_a_file(): void
    {
        foreach (config('reports.reports') as $key => $report) {
            $this->assertFileExists(
                base_path("Modules/Reports/Database/Procedures/{$report['procedure']}.sql"),
                "Report [{$key}] names [{$report['procedure']}], which has no .sql file. "
                .'A procedure nothing deploys reads like the rule the system follows, and the database has never seen it.'
            );
        }
    }

    public function test_every_procedure_returns_every_column_the_registry_declares(): void
    {
        $schema = config('agora.schema');
        $pdo = DB::connection(config('agora.connections.app'))->getPdo();

        foreach (config('reports.reports') as $key => $report) {
            /*
             * The column NAMES, from the driver's result metadata rather than
             * from the rows. That distinction is the test: locally most of
             * these reports return nothing, so reading the keys off the first
             * row would assert nothing at all and pass.
             *
             * sp_describe_first_result_set would have been the cheaper probe
             * and it cannot be used here — it refuses any procedure that builds
             * a temp table, which is all fifteen of them.
             */
            $statement = $pdo->prepare("EXEC [{$schema}].[{$report['procedure']}] @PageSize = 1");
            $statement->execute();

            $columns = [];
            for ($i = 0; $i < $statement->columnCount(); $i++) {
                $columns[] = $statement->getColumnMeta($i)['name'] ?? '';
            }
            $statement->closeCursor();

            $this->assertNotEmpty($columns, "[{$report['procedure']}] returned no result set at all.");

            foreach ($report['columns'] as $column) {
                $this->assertContains(
                    $column['key'],
                    $columns,
                    "Report [{$key}] shows column [{$column['key']}], which [{$report['procedure']}] does not return. "
                    .'The grid would render an empty column and say nothing about why.'
                );
            }
        }
    }

    public function test_every_sortable_column_has_an_arm_in_its_procedures_order_by(): void
    {
        /*
         * An unrecognised @SortColumn falls through every CASE arm to NULL and
         * the procedure silently applies its natural order instead. The user
         * clicks a header, the rows do not move, and nothing anywhere says why.
         * The service already refuses to pass a sort key the registry does not
         * declare; this is the other half — that the PROCEDURE knows it too.
         */
        foreach (config('reports.reports') as $key => $report) {
            $sql = file_get_contents(
                base_path("Modules/Reports/Database/Procedures/{$report['procedure']}.sql")
            );

            foreach ($report['columns'] as $column) {
                if (empty($column['sort'])) {
                    continue;
                }

                $this->assertStringContainsString(
                    "WHEN '{$column['sort']}'",
                    $sql,
                    "Report [{$key}] offers a sort on [{$column['sort']}], but [{$report['procedure']}] "
                    .'has no CASE arm for it — clicking that header would do nothing at all.'
                );
            }
        }
    }

    public function test_no_reports_procedure_writes_anything_anywhere(): void
    {
        /*
         * The rule above all, as a check rather than as prose. A reporting
         * procedure that acquires a write is not a style problem: PumpIT is a
         * 249 GB live system in SIMPLE recovery, so there is no point-in-time
         * restore to undo it with.
         *
         * Table variables are exempt — a read-only procedure stages its sides
         * in one — and so are temp tables, which live in tempdb.
         */
        foreach (glob(base_path('Modules/Reports/Database/Procedures/*.sql')) as $file) {
            $sql = preg_replace('#/\*.*?\*/#s', '', file_get_contents($file));
            $name = basename($file);

            $this->assertDoesNotMatchRegularExpression(
                '/\bINSERT\s+INTO\s+(?!@)(?!#)/i', $sql,
                "{$name} INSERTs into something that is not a table variable or a temp table."
            );
            $this->assertDoesNotMatchRegularExpression(
                '/\bUPDATE\s+(?!@)[\[a-zA-Z]/i', $sql, "{$name} contains an UPDATE."
            );
            $this->assertDoesNotMatchRegularExpression(
                '/\bDELETE\s+FROM\b|\bMERGE\s+INTO\b|\bTRUNCATE\s+TABLE\b/i', $sql,
                "{$name} contains a DELETE, MERGE or TRUNCATE."
            );
            $this->assertDoesNotMatchRegularExpression(
                '/\b(DROP|ALTER)\s+(TABLE|VIEW|INDEX|SCHEMA|DATABASE)\b/i', $sql,
                "{$name} contains DDL."
            );
        }
    }

    public function test_no_procedure_reaches_the_legacy_estate_except_through_a_view(): void
    {
        /*
         * Three-part names belong in the views, where the column list is
         * enumerated, the branch column is renamed once and ntext is converted
         * once. A procedure that reaches past them re-acquires all three
         * problems privately.
         */
        foreach (glob(base_path('Modules/Reports/Database/Procedures/*.sql')) as $file) {
            $sql = preg_replace('#/\*.*?\*/#s', '', file_get_contents($file));

            $this->assertDoesNotMatchRegularExpression(
                '/\[?(PumpIT|MIST_Import|Alteryx)\]?\s*\.\s*\[?dbo\]?\s*\./i',
                $sql,
                basename($file).' names a legacy table directly. Every read goes through an agora.vw_* view.'
            );
        }
    }

    public function test_every_report_hangs_off_a_menu_path_core_already_seeded(): void
    {
        $paths = DB::table(config('agora.schema').'.MenuItem')->pluck('Path')->all();

        foreach (config('reports.reports') as $key => $report) {
            $this->assertContains(
                $report['menu'],
                $paths,
                "Report [{$key}] hangs off menu path [{$report['menu']}], which is not in agora.MenuItem. "
                .'Core seeds the Today menu; this attaches routes to it rather than seeding a second copy.'
            );
        }
    }

    public function test_the_service_refuses_a_sort_column_the_registry_does_not_declare(): void
    {
        $method = new \ReflectionMethod($this->service, 'parameters');
        $method->setAccessible(true);

        $report = $this->service->definition('day-close');

        $this->assertSame(
            'BranchName',
            $method->invoke($this->service, $report, ['sort' => 'BranchName'])['SortColumn'],
        );

        // A column that is not sortable, and one that never existed, both land
        // on NULL rather than being handed to the procedure to ignore.
        $this->assertNull($method->invoke($this->service, $report, ['sort' => 'DayEnds'])['SortColumn']);
        $this->assertNull($method->invoke($this->service, $report, ['sort' => 'DROP TABLE'])['SortColumn']);
    }
}
