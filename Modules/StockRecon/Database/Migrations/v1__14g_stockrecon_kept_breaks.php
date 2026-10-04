<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stock recon — a small break keeps the chain going (slot 14g).
 *
 * ZRP, 4 Oct 2026: where a break of 1, 2 or 3 units would split a chain, keep
 * the chain going and report the qty. v1__14pg deploys the procedure that does
 * it (the size is the run option KeepBreaksUpTo, default 3); this adds what it
 * records.
 *
 *   StockReconRunLine.BreakKept  The opening missed the previous closing by
 *                                no more than KeepBreaksUpTo. It is repaired
 *                                like any break and reported as S10a or S10b,
 *                                but the chain is balanced straight through it.
 *   StockReconRun.KeptBreaks     How many, for the header.
 *
 * Agora's own tables. Nothing in the customer's estate changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = config('agora.schema');

        DB::statement("
            ALTER TABLE [{$schema}].[StockReconRunLine]
                ADD [BreakKept] BIT NOT NULL CONSTRAINT [DF_StockReconRunLine_BreakKept] DEFAULT 0;
        ");

        DB::statement("
            ALTER TABLE [{$schema}].[StockReconRun]
                ADD [KeptBreaks] INT NOT NULL CONSTRAINT [DF_StockReconRun_KeptBreaks] DEFAULT 0;
        ");

        // Enumerated, not a star — a view binds its column list at creation
        // (see v1__14b), so the new column is invisible until it is redefined.
        DB::unprepared("CREATE OR ALTER VIEW [{$schema}].[vw_StockReconRunLine] AS".$this->view($schema).';');
    }

    /** Local sandbox only. Live and staging are forward-only — see CLAUDE.md. */
    public function down(): void
    {
        $schema = config('agora.schema');

        DB::statement("ALTER TABLE [{$schema}].[StockReconRunLine] DROP CONSTRAINT IF EXISTS [DF_StockReconRunLine_BreakKept];");
        DB::statement("ALTER TABLE [{$schema}].[StockReconRunLine] DROP COLUMN IF EXISTS [BreakKept];");
        DB::statement("ALTER TABLE [{$schema}].[StockReconRun] DROP CONSTRAINT IF EXISTS [DF_StockReconRun_KeptBreaks];");
        DB::statement("ALTER TABLE [{$schema}].[StockReconRun] DROP COLUMN IF EXISTS [KeptBreaks];");
    }

    /** v1__14e's definition plus BreakKept. Nothing else changes. */
    private function view(string $schema): string
    {
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
