<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Read-only original/live count evidence, captured on each new preview. */
return new class extends Migration
{
    public function up(): void
    {
        $schema = config('agora.schema');

        // Existing runs stay unknown: today’s source cannot reconstruct a prior preview.
        DB::statement("ALTER TABLE [{$schema}].[StockReconRunLine]
            ADD [QtyOpenOrig] DECIMAL(18,3) NULL,
                [QtyCloseOrig] DECIMAL(18,3) NULL,
                [CountDriftReason] NVARCHAR(24) NULL;");
        DB::unprepared("CREATE OR ALTER VIEW [{$schema}].[vw_StockReconRunLine] AS".$this->runLineView($schema, true).';');

        MigrationHelper::recordVersion('1.38',
            'Stock recon: save original/live count drift evidence on new runs, without changing balancing eligibility.');
    }

    /** Local sandbox only; production migrations are forward-only. */
    public function down(): void
    {
        $schema = config('agora.schema');
        DB::unprepared("CREATE OR ALTER VIEW [{$schema}].[vw_StockReconRunLine] AS".$this->runLineView($schema, false).';');
        DB::statement("ALTER TABLE [{$schema}].[StockReconRunLine]
            DROP COLUMN [QtyOpenOrig], [QtyCloseOrig], [CountDriftReason];");
    }

    /** The resolved run-line view, with only the new snapshot evidence appended. */
    private function runLineView(string $schema, bool $withDrift): string
    {
        $drift = $withDrift ? '
                   rl.QtyOpenOrig,
                   rl.QtyCloseOrig,
                   rl.CountDriftReason,' : '';

        return "
            SELECT rl.BranchId,
                   rl.Id,
                   rl.RunId,
                   rl.[LineNo],
                   rl.AreaNo,
                   rl.StockItemNo,
                   rl.TransactionDate,
                   rl.ShiftNo,
                   rl.SellPrice,
                   rl.QtyOpen,
                   rl.QtyIssued,
                   rl.QtyClose,
                   rl.QtyPOS,
                   rl.QtyVar,
                   rl.QtyOpenNew,
                   rl.QtyCloseNew,
                   rl.QtyVarNew,
                   rl.AmendClose,
                   rl.IsDormant,
                   rl.ActiveSeq,
                   rl.ActiveLen,
                   rl.ChainNetVar,
                   rl.FlagNetOver,
                   rl.FlagSoldMoreThanOnHand,
                   rl.FlagCloseExceedsOnHand,
                   rl.FlagIssueWentNowhere,
                   rl.FlagBigAmendment,
                   rl.FlagPctAmendment,
                   rl.FlagNegativeClose,
                   rl.FlagShortChain,
                   rl.FlagDormantMoved,
                   rl.FlagChainBroken,
                   rl.ChainBlocked,
                   rl.ExceptionCode,
                   rl.Outcome,
                   rl.WouldAmend,
                   rl.Selected,
                   rl.CommitState,
                   rl.BlockReason,
                   rl.CreatedAt,
                   rl.CreatedBy,
                   rl.UpdatedAt,
                   rl.UpdatedBy,
                   rl.PrevClose,
                   rl.ChainBreak,
                   rl.QtyOpenNow,
                   rl.QtyCloseNow,
                   rl.QtyIssuedOrig,
                   rl.IssueMultiple,
                   rl.FlagPackOver,
                   rl.FlagNoPosLink,
                   rl.RestoresHand,
                   rl.FlagAuditConflict,
                   rl.AuditCode,
                   rl.BreakKept,
                   rl.DupTier,
                   rl.DupTwinDate,
                   rl.DupTwinShift,
                   rl.DupTwinVar,
                   rl.DupQty,
                   rl.DupReason,
                   rl.QtyIssuedNew,
                   rl.CountsMove,
                   rl.DupHold,
                   rl.DupReleaseId,{$drift}
                   ISNULL(rl.ItemDescription, m.StockItemDescription) AS ItemDescription,
                   ISNULL(rl.POSCode,         m.POSCode)              AS POSCode,
                   ISNULL(rl.StockLocation,   m.StockLocation)        AS StockLocation,
                   ISNULL(rl.AreaDescription, a.AreaDescription)      AS AreaDescription,
                   m.UOMCode,
                   CASE WHEN rl.EmployeeCodes IS NOT NULL THEN rl.EmployeeCodes
                        ELSE se.EmployeeCodes END                     AS EmployeeCodes,
                   CASE WHEN rl.EmployeeCodes IS NOT NULL THEN rl.EmployeeNames
                        ELSE se.EmployeeNames END                     AS EmployeeNames,
                   CASE WHEN rl.EmployeeCodes IS NOT NULL THEN rl.EmployeeCount
                        ELSE ISNULL(se.EmployeeCount, 0) END          AS EmployeeCount
            FROM [{$schema}].StockReconRunLine rl
            OUTER APPLY (SELECT TOP 1 m.StockItemDescription, m.POSCode, m.StockLocation, m.UOMCode
                           FROM [{$schema}].vw_StockMaster m
                          WHERE m.BranchId = rl.BranchId AND m.StockItemNo = rl.StockItemNo
                          ORDER BY CASE WHEN m.AreaNo = rl.AreaNo THEN 0 ELSE 1 END, m.StockLocation) m
            LEFT JOIN [{$schema}].vw_StockArea a
                   ON a.BranchId = rl.BranchId AND a.AreaNo = rl.AreaNo
            LEFT JOIN [{$schema}].vw_StockReconShiftEmployee se
                   ON se.BranchId        = rl.BranchId
                  AND se.TransactionDate = rl.TransactionDate
                  AND se.ShiftNo         = rl.ShiftNo
                  AND se.AreaNo          = rl.AreaNo";
    }
};
