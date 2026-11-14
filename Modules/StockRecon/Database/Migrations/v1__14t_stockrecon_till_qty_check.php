<?php

use App\Support\Database\ProcedureMigration;
use Illuminate\Support\Facades\DB;

/**
 * Stock recon — which of a run's days carry a till quantity PumpIT has not
 * rolled into the recon lines (slot 14t).
 *
 * Chantal Peens, 13 Nov 2026 ("COMPUTER QTY"): build her missing computer
 * quantity report into Agora, so a site running a period and area is told
 * that balancing cannot be achieved until PumpIT's quantities are updated.
 *
 * agora.vw_StockReconTillQty is what the tills sold, per site, day, area and
 * item, at day level, read the way PumpIT reads it for each kind of area:
 * a shift-based area (STK_Area.Shift_TimeBased = 0) matches
 * DBF_P3TRANS_STKRECON to its item by POS code and location; a time-based one
 * reads DBF_P3TRANS_STOCKRECON_ShiftTimeBased by area and item, leaving out
 * stock adjustments. Reads only.
 */
return new class extends ProcedureMigration
{
    public function up(): void
    {
        $erp = config('database.connections.'.config('agora.connections.erp').'.database');
        $schema = config('agora.schema');
        DB::unprepared("CREATE OR ALTER VIEW [{$schema}].[vw_StockReconTillQty] AS
            SELECT f.SSBranchId AS BranchId, f.[DATE] AS TransactionDate, m.AreaNo, m.StockItemNo,
                   CONVERT(decimal(18, 4), f.Qty) AS Qty
            FROM [{$erp}].dbo.DBF_P3TRANS_STKRECON f
            JOIN [{$erp}].dbo.STK_StockMaster m
              ON m.SSBranchId = f.SSBranchId AND m.POSCode = f.CODE AND m.[Location] = f.[LOCATION]
            JOIN [{$erp}].dbo.STK_Area a
              ON a.SSBranchId = m.SSBranchId AND a.AreaNo = m.AreaNo AND a.Shift_TimeBased = 0
            UNION ALL
            SELECT f.SSBranchId, f.[DATE], f.AreaNo, f.StockItemNo,
                   CONVERT(decimal(18, 4), f.quantity)
            FROM [{$erp}].dbo.DBF_P3TRANS_STOCKRECON_ShiftTimeBased f
            JOIN [{$erp}].dbo.STK_Area a
              ON a.SSBranchId = f.SSBranchId AND a.AreaNo = f.AreaNo AND a.Shift_TimeBased = 1
            WHERE ISNULL(f.origindescription, '') <> 'Stock Adjustment'");
        parent::up();
    }

    protected function files(): array
    {
        return [$this->path().'/usp_StockRecon_TillQtyCheck.sql'];
    }
};
