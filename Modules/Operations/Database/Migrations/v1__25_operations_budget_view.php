<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Operations Wall's read-only boundary to the existing monthly budget.
 *
 * A local stub need not have Alteryx. An absent source gets an empty typed
 * view, so missing budgets stay unknown. If connected later, rerun this
 * migration's up() to replace the empty view; it never creates source tables.
 * A present but incompatible table is an error, not silent empty data.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = str_replace(']', ']]', (string) config('agora.schema'));
        $database = (string) config('agora.source_databases.reporting');
        $quotedDatabase = str_replace(']', ']]', $database);
        $exists = DB::selectOne('SELECT DB_ID(?) AS DatabaseId', [$database]);
        $columns = [];

        if ($exists?->DatabaseId !== null) {
            $columns = DB::select("
                SELECT c.name
                FROM [{$quotedDatabase}].sys.tables t
                JOIN [{$quotedDatabase}].sys.schemas s ON s.schema_id = t.schema_id
                JOIN [{$quotedDatabase}].sys.columns c ON c.object_id = t.object_id
                WHERE s.name = 'dbo' AND t.name = 'Alteryx_Budget'
            ");
        }

        if ($columns === []) {
            DB::unprepared("
                CREATE OR ALTER VIEW [{$schema}].[vw_OperationsBudget] AS
                SELECT CONVERT(int, NULL) AS BranchId,
                       CONVERT(int, NULL) AS BudgetYear,
                       CONVERT(date, NULL) AS BudgetMonth,
                       CONVERT(nvarchar(200), NULL) AS Category,
                       CONVERT(nvarchar(200), NULL) AS SubCategory,
                       CONVERT(nvarchar(400), NULL) AS TableKey,
                       CONVERT(nvarchar(100), NULL) AS TableUnit,
                       CONVERT(decimal(22,4), NULL) AS Value
                WHERE 1 = 0
            ");

            return;
        }

        $present = array_map(fn ($column) => strtolower((string) $column->name), $columns);
        $required = ['BranchID', 'BudgetYear', 'BudgetMonth', 'Category', 'SubCategory', 'TableKey', 'TableUnit', 'Value'];
        $missing = array_filter($required, fn (string $column) => ! in_array(strtolower($column), $present, true));

        if ($missing !== []) {
            throw new RuntimeException('Operations budget source is missing columns: '.implode(', ', $missing));
        }

        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_OperationsBudget] AS
            SELECT CONVERT(int, b.BranchID) AS BranchId,
                   CONVERT(int, b.BudgetYear) AS BudgetYear,
                   CONVERT(date, b.BudgetMonth) AS BudgetMonth,
                   LTRIM(RTRIM(b.Category)) AS Category,
                   LTRIM(RTRIM(b.SubCategory)) AS SubCategory,
                   b.TableKey, LTRIM(RTRIM(b.TableUnit)) AS TableUnit,
                   CONVERT(decimal(22,4), b.Value) AS Value
            FROM [{$quotedDatabase}].dbo.Alteryx_Budget b
        ");
    }

    public function down(): void
    {
        // Forward only. The running wall depends on this read-only view.
    }
};
