<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stock recon — the columns the client's rules need (slot 14e).
 *
 * On 23 Sep 2026 ZRP's CEO issued the Weekly Stock Recon pack (guide, rules,
 * scenario matrix and a Python engine) for the Group Operations Executive to run
 * each week. Its rules and ours disagreed in one place that matters and were
 * silent in several others; v1__14pf deploys the procedures that follow them,
 * and this adds what those procedures record. The rules themselves are written
 * out in docs/stock-recon.md → "The client's rules".
 *
 * StockReconRunLine gains:
 *
 *   PrevClose, ChainBreak  The closing the opening was checked against, and by
 *                          how much the counted opening missed it. A break is
 *                          now REPAIRED (the opening is amended to the previous
 *                          closing, which stands) instead of blocking the chain.
 *   QtyIssuedOrig          The issue as captured on the shift, so an issue added
 *                          or cut at balancing (S4a, S4b) carries its value.
 *   QtyOpenNow, QtyCloseNow  What PumpIT held at preview time. The balanced pair
 *                          is compared against THIS, not the original counts, so
 *                          a commit leaves the source chain intact even where
 *                          somebody had already balanced by hand.
 *   IssueMultiple          The pack size, from agora.vw_StockItem.
 *   FlagPackOver           An over within 10% of a whole pack: an issue not
 *                          written up. Captured, never balanced away.
 *   FlagNoPosLink          Stock falling with no till sales against it: a PLU or
 *                          recipe link fault. Nobody is charged for it.
 *   RestoresHand           This row is written to REVERSE a hand amendment the
 *                          client's rules forbid, on a chain Agora does not
 *                          balance: back to the staff count, opening repaired.
 *   FlagAuditConflict      Writing this chain would trip one of the client's
 *                          audit checks on a shift that trips none today —
 *                          always an issue or POS changed at balancing, which
 *                          counts alone cannot square. The chain is held back.
 *   AuditCode              What the HAND balancing already in PumpIT did to this
 *                          shift, measured against the pack's rules (S7, S8, S11,
 *                          S12, S13, S4, S4a, S4b).
 *
 * StockReconRun gains CompleteTo and HeldBackRows (rule 10: a site is analysed
 * only up to its last complete recon day), RepairedOpenings, and AuditRows.
 *
 * AND IT RELABELS THE CODES ALREADY RECORDED, for the reason v1__14c gives. Our
 * nine classes were A1–A4, B1–B2, C1–C2, D1; the pack uses A1–D4 for the
 * storeroom scenarios with DIFFERENT meanings — its A1 is "confirmed loss at the
 * recon point, charge the staff", ours was "unrecorded issue, a paperwork
 * fault". The same code meaning opposite things in the Monday meeting is how
 * somebody gets charged for a missing issue sheet. Ours move to letters the
 * pack does not use (U, F, N, L); the tests are unchanged, so the same row
 * carries the same finding under a code that cannot be misread. Idempotent:
 * each mapping matches the old code exactly.
 */
return new class extends Migration
{
    /** Was → now. The test behind each is unchanged. */
    private const RELABEL = [
        'A1' => 'U1', 'A2' => 'U2', 'A3' => 'U3', 'A4' => 'U4',
        'B1' => 'F1', 'B2' => 'F2',
        'C1' => 'N1', 'C2' => 'N2',
        'D1' => 'L1',
    ];

    public function up(): void
    {
        $schema = config('agora.schema');

        DB::statement("
            ALTER TABLE [{$schema}].[StockReconRunLine]
                ADD [PrevClose]     DECIMAL(18,3) NULL,
                    [ChainBreak]    DECIMAL(18,3) NOT NULL CONSTRAINT [DF_StockReconRunLine_ChainBreak] DEFAULT 0,
                    [QtyOpenNow]    DECIMAL(18,3) NULL,
                    [QtyCloseNow]   DECIMAL(18,3) NULL,
                    [QtyIssuedOrig] DECIMAL(18,3) NULL,
                    [IssueMultiple] DECIMAL(18,3) NULL,
                    [FlagPackOver]  BIT NOT NULL CONSTRAINT [DF_StockReconRunLine_FlagPackOver]  DEFAULT 0,
                    [FlagNoPosLink] BIT NOT NULL CONSTRAINT [DF_StockReconRunLine_FlagNoPosLink] DEFAULT 0,
                    [RestoresHand]  BIT NOT NULL CONSTRAINT [DF_StockReconRunLine_RestoresHand]  DEFAULT 0,
                    [FlagAuditConflict] BIT NOT NULL CONSTRAINT [DF_StockReconRunLine_FlagAuditConflict] DEFAULT 0,
                    [AuditCode]     NVARCHAR(4) NULL;
        ");

        DB::statement("
            ALTER TABLE [{$schema}].[StockReconRun]
                ADD [CompleteTo]       DATE NULL,
                    [HeldBackRows]     INT NOT NULL CONSTRAINT [DF_StockReconRun_HeldBackRows]     DEFAULT 0,
                    [RepairedOpenings] INT NOT NULL CONSTRAINT [DF_StockReconRun_RepairedOpenings] DEFAULT 0,
                    [AuditRows]        INT NOT NULL CONSTRAINT [DF_StockReconRun_AuditRows]        DEFAULT 0;
        ");

        // Enumerated, not a star — a view binds its column list at creation
        // (see v1__14b), so the new columns are invisible until it is redefined.
        DB::unprepared("CREATE OR ALTER VIEW [{$schema}].[vw_StockReconRunLine] AS".$this->view($schema).';');

        $rows = 0;

        foreach (self::RELABEL as $was => $now) {
            $rows += DB::update(
                "UPDATE [{$schema}].[StockReconRunLine] SET ExceptionCode = ? WHERE ExceptionCode = ?",
                [$now, $was],
            );
        }

        echo "  v1__14e: {$rows} recorded exception code(s) relabelled".PHP_EOL;
    }

    /** Local sandbox only. Live and staging are forward-only — see CLAUDE.md. */
    public function down(): void
    {
        $schema = config('agora.schema');

        foreach (self::RELABEL as $was => $now) {
            DB::update("UPDATE [{$schema}].[StockReconRunLine] SET ExceptionCode = ? WHERE ExceptionCode = ?", [$was, $now]);
        }

        foreach (['ChainBreak', 'FlagPackOver', 'FlagNoPosLink', 'RestoresHand', 'FlagAuditConflict'] as $c) {
            DB::statement("ALTER TABLE [{$schema}].[StockReconRunLine] DROP CONSTRAINT IF EXISTS [DF_StockReconRunLine_{$c}];");
        }

        // One column per statement: IF EXISTS covers only the first name in a list.
        foreach (['PrevClose', 'ChainBreak', 'QtyOpenNow', 'QtyCloseNow', 'QtyIssuedOrig', 'IssueMultiple',
            'FlagPackOver', 'FlagNoPosLink', 'RestoresHand', 'FlagAuditConflict', 'AuditCode'] as $c) {
            DB::statement("ALTER TABLE [{$schema}].[StockReconRunLine] DROP COLUMN IF EXISTS [{$c}];");
        }

        foreach (['HeldBackRows', 'RepairedOpenings', 'AuditRows'] as $c) {
            DB::statement("ALTER TABLE [{$schema}].[StockReconRun] DROP CONSTRAINT IF EXISTS [DF_StockReconRun_{$c}];");
        }

        foreach (['CompleteTo', 'HeldBackRows', 'RepairedOpenings', 'AuditRows'] as $c) {
            DB::statement("ALTER TABLE [{$schema}].[StockReconRun] DROP COLUMN IF EXISTS [{$c}];");
        }
    }

    /** v1__14d's definition plus the eleven columns above. Nothing else changes. */
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
