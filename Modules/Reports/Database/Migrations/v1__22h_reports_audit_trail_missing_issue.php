<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reports — the audit trail names a captured missing issue (slot 22h).
 *
 * From v1__14pr a stock recon commit also RAISES QtyIssued on a shift left
 * over after balancing — the missing issue, captured (Ryan's named go-ahead,
 * 13 Oct 2026: "customer wants over to balance"). The issue write is no longer
 * only a duplicate taken out, so the Action says which: an issue that went up
 * is "Missing issue captured", one that went down "Duplicate issue removed".
 * And stock recon quantities read to three places here too (Ryan, 13 Oct
 * 2026: "sometimes 1, sometimes 1.000"). Restates v1__22g's view with those
 * two changes. Same rows, same joins (AuditTrailTest).
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = config('agora.schema');
        $sast = 'DATEPART(tzoffset, SYSDATETIMEOFFSET())';
        $shift = fn (string $col) => "CASE {$col} WHEN 1 THEN 'Day' WHEN 2 THEN 'Afternoon' WHEN 3 THEN 'Night' ELSE CONCAT('Shift ', {$col}) END";
        $qty = fn (string $col) => "ISNULL(FORMAT({$col}, '0.###'), 'blank')";
        $units = fn (string $col) => "ISNULL(FORMAT({$col}, '0.000'), 'blank')";

        DB::unprepared("CREATE OR ALTER VIEW [{$schema}].[vw_PumpItChange] AS

            /* Stock recon: the counts a commit wrote, the issue where it took
               out a duplicate or captured a missing one, and a reset to the
               originals. UTC since v1__14po. */
            SELECT a.BranchId,
                   CONVERT(datetime2(0), DATEADD(minute, {$sast}, a.CreatedAt)) AS ChangedAt,
                   a.CreatedBy                                                AS ChangedBy,
                   CONVERT(nvarchar(40), N'Stock recon')                      AS Module,
                   CONVERT(nvarchar(60), CASE WHEN r.CommitKind = 'reset'
                                              THEN N'Reset to original'
                                              WHEN a.NewQtyIssued > a.PriorQtyIssued
                                              THEN N'Missing issue captured'
                                              WHEN a.NewQtyIssued IS NOT NULL
                                              THEN N'Duplicate issue removed'
                                              ELSE N'Count amended' END)      AS Action,
                   CONVERT(nvarchar(60), N'STK_StockReconLine')               AS PumpItTable,
                   CONVERT(nvarchar(400), CONCAT(ISNULL(rl.AreaDescription, CONCAT(N'Area ', a.AreaNo)), N' · ',
                          ISNULL(rl.ItemDescription, a.StockItemNo), N' · ', CONVERT(char(10), a.TransactionDate, 23), N' · ',
                          {$shift('a.ShiftNo')}))                             AS RowKey,
                   CONVERT(nvarchar(1000), ISNULL(NULLIF(CONCAT_WS(N'; ',
                          CASE WHEN a.PriorQtyOpen <> a.NewQtyOpen
                               THEN CONCAT(N'Opening ', {$units('a.PriorQtyOpen')}, N' → ', {$units('a.NewQtyOpen')}) END,
                          CASE WHEN a.PriorQtyClose <> a.NewQtyClose
                               THEN CONCAT(N'Closing ', {$units('a.PriorQtyClose')}, N' → ', {$units('a.NewQtyClose')}) END,
                          CASE WHEN a.NewQtyIssued IS NOT NULL
                               THEN CONCAT(N'Issue ', {$units('a.PriorQtyIssued')}, N' → ', {$units('a.NewQtyIssued')}) END,
                          CASE WHEN a.NewQtyPOS IS NOT NULL
                               THEN CONCAT(N'POS ', {$units('a.PriorQtyPOS')}, N' → ', {$units('a.NewQtyPOS')}) END), N''),
                          N'Counts confirmed, unchanged'))                    AS Change,
                   CONVERT(nvarchar(400), CASE WHEN r.CommitKind = 'reset'
                                               THEN N'Hand balancing taken off: open, issue, close and POS set back to the originals'
                                               ELSE rl.Outcome END)           AS Reason,
                   CONVERT(nvarchar(60), CONCAT(N'Stock recon run ', a.RunId)) AS Ref,
                   CONVERT(nvarchar(20), a.StampMode)                         AS WriteMode,
                   CONVERT(nvarchar(20), a.State)                             AS State,
                   CONVERT(datetime2(0), DATEADD(minute, {$sast}, a.ReversedAt)) AS ReversedAt,
                   a.ReversedBy,
                   CONVERT(nvarchar(400), CASE WHEN a.State = 'reversed' THEN r.ReversalReason END) AS ReversalReason,
                   CONVERT(nvarchar(40), N'StockReconAmendment')              AS Ledger,
                   a.Id                                                       AS LedgerId,
                   CONVERT(nvarchar(max), NULL)                               AS Detail
            FROM [{$schema}].StockReconAmendment a
            LEFT JOIN [{$schema}].StockReconRunLine rl ON rl.BranchId = a.BranchId AND rl.Id = a.RunLineId
            LEFT JOIN [{$schema}].StockReconRun r ON r.BranchId = a.BranchId AND r.Id = a.RunId

            UNION ALL

            /* Bank recon: one stamp per bank line or deposit row. UTC. */
            SELECT s.BranchId,
                   CONVERT(datetime2(0), DATEADD(minute, {$sast}, ISNULL(s.AppliedAt, s.CreatedAt))),
                   ISNULL(s.AppliedBy, s.CreatedBy),
                   N'Bank recon',
                   CASE WHEN s.TargetTable = 'RCN_BankStatementLinesPumpIT' THEN N'Bank line reconciled' ELSE N'Deposit reconciled' END,
                   s.TargetTable,
                   CASE WHEN bl.BankStatementLineID IS NOT NULL
                        THEN CONCAT_WS(N' · ', CONVERT(char(10), bl.LineDate, 23), NULLIF(LEFT(LTRIM(RTRIM(bl.Description)), 80), N''),
                                       N'R' + CONVERT(nvarchar(30), CONVERT(decimal(18,2), bl.Amount)),
                                       N'line ' + CONVERT(nvarchar(20), bl.BankStatementLineID))
                        WHEN ISJSON(s.TargetKeyJson) = 1
                        THEN CONCAT_WS(N' · ', NULLIF(JSON_VALUE(s.TargetKeyJson, '$.ref'), ''),
                                       LEFT(JSON_VALUE(s.TargetKeyJson, '$.dt'), 10),
                                       N'R' + CONVERT(nvarchar(30), TRY_CONVERT(decimal(18,2), JSON_VALUE(s.TargetKeyJson, '$.amt'))),
                                       N'id ' + COALESCE(JSON_VALUE(s.TargetKeyJson, '$.key'), JSON_VALUE(s.TargetKeyJson, '$.BankStatementLineID')))
                        ELSE s.TargetKeyJson END,
                   CASE WHEN s.TargetTable = 'RCN_BankStatementLinesPumpIT'
                        THEN CONCAT(N'ReconState 1 → 2; ReconBatchNo → ', s.BatchNo)
                        ELSE CONCAT(N'ReconBatchNoPumpIT 0 → ', s.BatchNo, N'; ' + s.Detail) END,
                   CONCAT(N'Bank recon', N' · ' + b.ReconArea, N' · batch ', s.BatchNo),
                   CONCAT(N'Recon run ', s.RunId),
                   CASE s.State WHEN 'journal' THEN N'journal' ELSE N'live' END,
                   CASE s.State WHEN 'reversed' THEN N'reversed' ELSE N'active' END,
                   CONVERT(datetime2(0), DATEADD(minute, {$sast}, b.ReversedAt)),
                   b.ReversedBy,
                   CASE WHEN s.State = 'reversed' THEN b.ReversalReason END,
                   N'ReconStamp', s.Id, NULL
            FROM [{$schema}].ReconStamp s
            /* Both on their unique keys, so each is one seek per stamp: the
               batch on UX_ReconBatch_Natural (BranchId, BatchNo), the bank line
               on PumpIT's PK (SSBranchId, BankStatementLineID). v1__22a looked
               the line up by its id alone, which the PK cannot seek, and so
               scanned all 347,045 bank lines once per stamp. */
            LEFT JOIN [{$schema}].ReconBatch b
                   ON b.BranchId = s.BranchId AND b.BatchNo = s.BatchNo
            LEFT JOIN [{$schema}].vw_BankStatementLine bl
                   ON s.TargetTable = 'RCN_BankStatementLinesPumpIT'
                  AND bl.BranchId = s.BranchId
                  AND bl.BankStatementLineID = CASE WHEN ISJSON(s.TargetKeyJson) = 1
                                                    THEN TRY_CONVERT(int, JSON_VALUE(s.TargetKeyJson, '$.BankStatementLineID')) END

            UNION ALL

            /* Z-read allocation: the three columns, live stamps only. UTC. */
            SELECT z.BranchId,
                   CONVERT(datetime2(0), DATEADD(minute, {$sast}, z.StampedAt)),
                   z.AllocatedBy,
                   N'Z-read allocation',
                   N'Z-read allocated',
                   N'DBF_P3TRANS_ZREAD',
                   CONCAT_WS(N' · ', z.Location, CONVERT(char(10), z.ZReadDate, 23), N'EOD ' + z.EodCounter,
                             N'terminal ' + z.TerminalNo, N'POS user ' + z.PosUserId),
                   ISNULL(NULLIF(CONCAT_WS(N'; ',
                       CASE WHEN ISNULL(z.StampPriorTillNo, -1) <> ISNULL(z.TillNo, -1)
                            THEN CONCAT(N'Till ', ISNULL(CONVERT(nvarchar(12), z.StampPriorTillNo), N'blank'), N' → ', z.TillNo) END,
                       CASE WHEN ISNULL(z.StampPriorShiftNo, -1) <> ISNULL(z.ShiftNo, -1)
                            THEN CONCAT(N'Shift ', ISNULL(CONVERT(nvarchar(12), z.StampPriorShiftNo), N'blank'), N' → ', z.ShiftNo) END,
                       CASE WHEN ISNULL(z.StampPriorEmployeeCode, N'') <> ISNULL(z.EmployeeCode, N'')
                            THEN CONCAT(N'Employee ', ISNULL(NULLIF(z.StampPriorEmployeeCode, N''), N'blank'), N' → ', z.EmployeeCode) END), N''),
                       N'Allocation confirmed, unchanged'),
                   CONCAT(N'Allocated', N' (' + z.Source + ISNULL(N', ' + z.Confidence, N'') + N')'),
                   CONCAT(N'Z-read press ', LEFT(CONVERT(nvarchar(36), z.CommitRef), 8)),
                   N'live',
                   CASE WHEN z.ReversedAt IS NOT NULL THEN N'reversed' ELSE N'active' END,
                   CONVERT(datetime2(0), DATEADD(minute, {$sast}, z.ReversedAt)),
                   z.ReversedBy,
                   z.ReversalReason,
                   N'ZreadAllocation', z.Id, NULL
            FROM [{$schema}].ZreadAllocation z
            WHERE z.StampMode = 'live' AND z.StampedAt IS NOT NULL

            UNION ALL

            /* Utilities: a reading added or updated. UTC. */
            SELECT u.BranchId,
                   CONVERT(datetime2(0), DATEADD(minute, {$sast}, u.CreatedAt)),
                   u.CreatedBy,
                   N'Utilities',
                   CASE u.Action WHEN 'insert' THEN N'Reading added' ELSE N'Reading updated' END,
                   N'BRN_UtilityTransaction',
                   CONCAT_WS(N' · ', u.UtilityType, N'meter ' + u.MeterNo, CONVERT(char(10), u.ReadingDate, 23)),
                   ISNULL(NULLIF(CONCAT_WS(N'; ',
                       CASE WHEN ISNULL(u.PriorOpening, -1) <> ISNULL(u.NewOpening, -1)
                            THEN CONCAT(N'Opening ', {$qty('u.PriorOpening')}, N' → ', {$qty('u.NewOpening')}) END,
                       CASE WHEN ISNULL(u.PriorClosing, -1) <> ISNULL(u.NewClosing, -1)
                            THEN CONCAT(N'Closing ', {$qty('u.PriorClosing')}, N' → ', {$qty('u.NewClosing')}) END,
                       CASE WHEN ISNULL(u.PriorPrepaid, -1) <> ISNULL(u.NewPrepaid, -1)
                            THEN CONCAT(N'Prepaid ', {$qty('u.PriorPrepaid')}, N' → ', {$qty('u.NewPrepaid')}) END,
                       CASE WHEN ISNULL(u.PriorUsage, -1) <> ISNULL(u.NewUsage, -1)
                            THEN CONCAT(N'Usage ', {$qty('u.PriorUsage')}, N' → ', {$qty('u.NewUsage')}) END), N''),
                       N'Reading confirmed, unchanged'),
                   N'Utility reading saved',
                   CONCAT(N'Utility save ', u.SaveId),
                   N'live', N'active', NULL, NULL, NULL,
                   N'UtilityReadingWrite', u.Id, NULL
            FROM [{$schema}].UtilityReadingWrite u

            UNION ALL

            /* The captures: one row per press that wrote PumpIT. UTC. */
            SELECT e.BranchId, CONVERT(datetime2(0), DATEADD(minute, {$sast}, e.CreatedAt)), e.CreatedBy,
                   N'Daily banking', CONCAT(N'Cashup ', e.Event), N'BRN_DailyBanking',
                   CONCAT_WS(N' · ', CONVERT(char(10), e.TransactionDate, 23), N'till ' + CONVERT(nvarchar(12), e.TillNo),
                             N'shift ' + CONVERT(nvarchar(12), e.ShiftNo)),
                   CONCAT(e.Lines, N' line(s) written', N' · variance R' + FORMAT(e.Variance, 'N2')),
                   e.Note, CONCAT(N'Cashup ', e.CashupId), N'live', N'active', NULL, NULL, NULL,
                   N'CashupEvent', e.Id, e.WritesJson
            FROM [{$schema}].CashupEvent e
            WHERE e.WriteMode = 'live' AND e.WritesJson IS NOT NULL

            UNION ALL

            SELECT e.BranchId, CONVERT(datetime2(0), DATEADD(minute, {$sast}, e.CreatedAt)), e.CreatedBy,
                   N'Till balance', CONCAT(N'Till balance ', e.Event), N'BRN_TillBalance',
                   CONCAT(N'Till balance ', e.TillBalanceNo, N' · till ', e.FromTillNo, N'/', e.FromShiftNo, N' → ', e.ToTillNo, N'/', e.ToShiftNo),
                   CONCAT(N'R', FORMAT(e.Amount, 'N2')),
                   e.Note, CONCAT(N'Till balance ', e.TillBalanceNo), N'live', N'active', NULL, NULL, NULL,
                   N'TillBalanceEvent', e.Id, e.WritesJson
            FROM [{$schema}].TillBalanceEvent e
            WHERE e.WriteMode = 'live' AND e.WritesJson IS NOT NULL

            UNION ALL

            SELECT e.BranchId, CONVERT(datetime2(0), DATEADD(minute, {$sast}, e.CreatedAt)), e.CreatedBy,
                   N'Drop safe', CONCAT(N'Bag ', e.Event), N'BRN_DropSafe',
                   CONCAT(N'Bag ', e.BagNo, N' (was ' + NULLIF(e.PriorBagNo, e.BagNo) + N')'),
                   CONCAT(e.Bags, N' bag(s)', N' · R' + FORMAT(e.Amount, 'N2')),
                   e.Note, CONCAT(N'Drop safe bag ', e.BagNo), N'live', N'active', NULL, NULL, NULL,
                   N'DropSafeEvent', e.Id, e.WritesJson
            FROM [{$schema}].DropSafeEvent e
            WHERE e.WriteMode = 'live' AND e.WritesJson IS NOT NULL

            UNION ALL

            SELECT e.BranchId, CONVERT(datetime2(0), DATEADD(minute, {$sast}, e.CreatedAt)), e.CreatedBy,
                   N'Staff shorts', CONCAT(N'Short ', e.Event), N'BRN_StaffShorts',
                   CONCAT(N'Short ', e.StaffShortsNo, N' · ', e.EmployeeCode),
                   CONCAT(N'R', FORMAT(e.Amount, 'N2'), N' · ' + e.ReasonCode),
                   e.Note, CONCAT(N'Staff short ', e.StaffShortsNo), N'live', N'active', NULL, NULL, NULL,
                   N'StaffShortEvent', e.Id, e.WritesJson
            FROM [{$schema}].StaffShortEvent e
            WHERE e.WriteMode = 'live' AND e.WritesJson IS NOT NULL;");

        MigrationHelper::recordVersion(
            '1.35',
            'Reports: the audit trail names a captured missing issue, and stock recon quantities read to three places.'
        );
    }

    /** Back to v1__22g's definition. Local sandbox only. */
    public function down(): void
    {
        (require __DIR__.'/v1__22g_reports_audit_trail_reset_pos.php')->up();
    }
};
