<?php

use App\Support\Database\ProcedureMigration;
use Illuminate\Support\Facades\DB;

return new class extends ProcedureMigration
{
    public function up(): void
    {
        $pos = config('database.connections.'.config('agora.connections.pos_landing').'.database');
        $erp = config('database.connections.'.config('agora.connections.erp').'.database');
        // A development estate may omit a source family. Its absence is
        // reported as unavailable, never silently converted into a nil count.
        $sources = [
            'WeeklyWinbranchCount' => [$pos, 'WINBRANCH_INVHISTF', 'MIST_BranchId AS BranchId, [DATE] AS CountDate, CODE AS POSCode, TYPE, REF, DETAILS, QTY, QTYREC'],
            'WeeklyWinbranchLastCount' => [$erp, 'DBF_STDB', 'SSBranchId AS BranchId, CODE AS POSCode, LOCATION, L_STKTAKE AS LastCountDate'],
            'WeeklyAuraCount' => [$pos, 'AURA_STOCK1', 'MIST_BranchId AS BranchId, [Date] AS CountDate, KeyStockNo AS POSCode, fkStockLocationID AS LocationId, Entered, Prepped, Unprepped, BulkUnitPrice'],
            'WeeklyAuraPurchase' => [$pos, 'AURA_STOCKPCH', 'MIST_BranchId AS BranchId, [DATE] AS MovementDate, STOCKNO AS POSCode, fkStockLocationID AS LocationId, BULKPCH, QUANTITY, UNIT_PRICE, PRICEPCH'],
            'WeeklyAuraSale' => [$pos, 'AURA_LINEITEM', 'MIST_BranchId AS BranchId, [DATE] AS MovementDate, CODE, QUANTITY, PREVOID'],
            'WeeklyAuraPrice' => [$pos, 'AURA_PRICES', 'MIST_BranchId AS BranchId, CODE, RECIPE, fkStockLocationID AS LocationId'],
            'WeeklyAuraRecipe' => [$pos, 'AURA_RECIPE', 'MIST_BranchId AS BranchId, RECIPE, STOCKNO AS POSCode, QUANTITY'],
            'WeeklyPilotCount' => [$pos, 'PILOT_PrepsheetHistory', 'MIST_BranchId AS BranchId, PrepsheetHistoryId, StoreId, TDATE AS CountDate, PROD AS POSCode, OSTK, TRANS, MAKE, ISSUE, SALES, CSTK, COST, [Updated] AS CapturedAt'],
        ];
        foreach ($sources as $name => [$database, $table, $columns]) {
            if (DB::selectOne('SELECT OBJECT_ID(?) AS Id', [$database.'.dbo.'.$table])->Id !== null) {
                $available = array_map(fn ($row) => strtolower($row->name), DB::select("SELECT name FROM [{$database}].sys.columns WHERE object_id=OBJECT_ID(?)", [$database.'.dbo.'.$table]));
                $required = array_map(fn ($column) => strtolower(trim(preg_replace('/\s+AS\s+.*/i', '', trim($column)), '[]')), explode(',', $columns));
                if (array_diff($required, $available) !== []) {
                    continue;
                }
                DB::unprepared("CREATE OR ALTER VIEW agora.vw_{$name} AS SELECT {$columns} FROM [{$database}].dbo.[{$table}]");
            }
        }
        parent::up();
    }

    protected function files(): array
    {
        return array_map(fn ($name) => $this->path().'/usp_StockRecon_'.$name.'.sql', [
            'AuthorizeWeekly', 'WeeklyRecon', 'WeeklyCounts', 'CreateWeeklySnapshot',
            'GridWeeklyCases', 'GetWeeklyCase', 'SaveWeeklyCase', 'GetWeeklyOverview',
        ]);
    }
};
