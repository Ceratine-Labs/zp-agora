<?php

use App\Support\Database\ProcedureMigration;
use Illuminate\Support\Facades\DB;

/**
 * Stock recon — a till whose whole day never reached PumpIT (slot 14z).
 *
 * Ryan, 15 Nov 2026, Pongola run 80516: the Debonairs till (location AURA)
 * has no computer quantities on 13 and 14 Nov, so every Debonairs FOH shift
 * read short and balancing proposed taking a case off an Energade issue. The
 * till check said nothing: it compares the recon lines with what the tills
 * sold, and when a whole feed is missing both sides are empty.
 *
 * agora.vw_StockReconComputerQty is PumpIT's own source for QtyComputer
 * (DBF_COMPUTER_QTY, which sp_UpdateDBF_COMPUTER_QTY_StockReconLine copies onto
 * the recon lines), with MistEod, its only index (the day as yyyymmdd and the
 * site id), so a reader can seek it. Reads only.
 */
return new class extends ProcedureMigration
{
    public function up(): void
    {
        $erp = config('database.connections.'.config('agora.connections.erp').'.database');
        $schema = config('agora.schema');
        DB::unprepared("CREATE OR ALTER VIEW [{$schema}].[vw_StockReconComputerQty] AS
            SELECT f.SSBranchId AS BranchId, f.[DATE] AS TransactionDate, f.MIST_EOD AS MistEod,
                   RTRIM(f.[LOCATION]) AS Location, f.StockItemNo, f.ShiftNo,
                   CONVERT(decimal(18, 4), f.Qty) AS Qty
            FROM [{$erp}].dbo.DBF_COMPUTER_QTY f");
        parent::up();
    }

    protected function files(): array
    {
        return [$this->path().'/usp_StockRecon_TillQtyCheck.sql'];
    }
};
