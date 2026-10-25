<?php

use App\Support\Database\ProcedureMigration;
use Illuminate\Support\Facades\DB;

return new class extends ProcedureMigration
{
    public function up(): void
    {
        $erp = config('database.connections.'.config('agora.connections.erp').'.database');
        $schema = config('agora.schema');
        DB::unprepared("CREATE OR ALTER VIEW [{$schema}].[vw_StockReconProduction] AS
            SELECT SSBranchId AS BranchId, [DATE] AS TransactionDate, ShiftNo,
                   StockItemno AS StockItemNo, LOCATION AS PosSystem, basecode AS BaseCode,
                   TransactionTrackingNumber AS TrackingNumber, TotalQuantity, LandedCostPrice
            FROM [{$erp}].dbo.DBF_P3TRANS_STKRECON_PREPRODUCTION");
        parent::up();
    }

    protected function files(): array
    {
        return [$this->path().'/usp_StockRecon_PreviewGlobal.sql'];
    }
};
