<?php

use App\Support\Database\ProcedureMigration;
use Illuminate\Support\Facades\DB;

return new class extends ProcedureMigration
{
    public function up(): void
    {
        $landing = str_replace(']', ']]', config('agora.source_databases.pos_landing'));
        if (DB::selectOne('SELECT OBJECT_ID(?) AS Id', [config('agora.source_databases.pos_landing').'.dbo.WINBRANCH_STDB'])->Id !== null) {
            DB::unprepared("CREATE OR ALTER VIEW agora.vw_WeeklyWinbranchCost AS
                SELECT MIST_BranchId AS BranchId,CODE AS POSCode,PACKCODE,[DESC] AS Description,CAT,LATEST AS UnitCost
                FROM [{$landing}].dbo.WINBRANCH_STDB");
        }
        if (DB::selectOne('SELECT OBJECT_ID(?) AS Id', [config('agora.source_databases.pos_landing').'.dbo.WINBRANCH_INVHISTF'])->Id !== null) {
            DB::unprepared("CREATE OR ALTER VIEW agora.vw_WeeklySalesCost AS
                SELECT MIST_BranchId AS BranchId,[DATE] AS SalesDate,CODE AS POSCode,MCODE,TYPE,DRCR,DRC,QTY,SALES,COST,ExportRegisterId,EOD_CNTR
                FROM [{$landing}].dbo.WINBRANCH_INVHISTF");
        }
        parent::up();
    }

    protected function files(): array
    {
        return array_map(fn ($name) => $this->path().'/usp_StockRecon_'.$name.'.sql', ['WeeklyCounts', 'WeeklyCosts', 'BuildPeriodRegisters', 'SaveWeeklyCase']);
    }
};
