/* ============================================================================
   agora.usp_Recon_Reverse

   Undo a reconciliation Agora executed — exactly, and nothing else.

   The PumpIT executable has no reversal at all. Once it stamps a line the line
   drops out of the outstanding list, which is why finding 2 is described as
   self-concealing: the mistake removes its own evidence. This procedure exists
   so that stops being true.

   It can only undo what Agora wrote, because it works from agora.ReconMatch —
   one row per SOURCE ROW touched, carrying that row's key and the state it
   held BEFORE. A reversal restores the prior values rather than assuming zero:
   a bank line that the executable had already reconciled, and that Agora then
   re-reconciled, must go back to the executable's batch number and not to
   unreconciled.

   WRITES, in live mode only:
     PumpIT.dbo.RCN_BankStatementLinesPumpIT ReconBatchNo, ReconState
     PumpIT.dbo.BRN_DailyBanking{Area}       ReconBatchNoPumpIT = 0
     PumpIT.dbo.BRN_DailyBankingFNB          Amount, AdjustedBy, Adjusted back to
                                             their prior values, on the rows a
                                             forced FNB match adjusted (5 Oct
                                             2026) — refused (ADJUST_MOVED) if
                                             any has changed since

   The batch number is NOT returned to SS_UniqueNumber. Gaps in a counter are
   harmless; handing the same number out twice is not.

   THE SPLIT BY CASHIER (task 01a084fd-07f8, 27 Sep 2026). A cash-bag manual
   match keeps its split in agora.ReconAllocation. Reversing the batch marks
   those lines reversed, in the same transaction — ReversedAt, ReversedBy and
   the reason, as the batch carries them — and never deletes them: the numbers
   the clerk confirmed stay readable beside the reversal. A line whose staff
   short was due and not yet raised ('pending') becomes 'cancelled', because
   nothing is due once the match is undone.

   A SHORT THE MATCH RAISED (T040; Ryan, 27 Sep 2026, PM decision 01a0e388):
     · still waiting for approval  DECLINED, inside this transaction, through
                                   agora.usp_Cash_DeclineStaffShort, with a
                                   reason naming the reversal; its line
                                   becomes 'cancelled' and keeps StaffShortsNo
     · already declined            nothing to do; its line becomes 'cancelled'
     · approved                    the reversal is REFUSED (SHORT_RAISED),
                                   naming the short and telling the clerk to
                                   WITHDRAW it in Cash first
                                   (usp_Cash_WithdrawStaffShort, PM decision
                                   01a0e5b5); once withdrawn it is declined, and
                                   the reversal goes through as below
     · posted to a cashup          REFUSED (SHORT_RAISED): a posted short is not
                                   withdrawn. Agora never posts one a match
                                   raised (CASH_SHORT_FROM_RECON), so only a
                                   post made in PumpIT's own client gets here
   So the only PumpIT write this adds is the decline's (its header lists it:
   BRN_StaffShorts IsDeclined, DeclinedReason, DeclinedByUserId, DeclinedDate).

   Refusals: BATCH_NOT_FOUND · ALREADY_REVERSED · REASON_REQUIRED ·
             SHORT_RAISED · ADJUST_MOVED — and agora.usp_Cash_DeclineStaffShort's own, which
             roll the whole reversal back
   ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Recon_Reverse]
    @BranchId int,
    @BatchId  bigint      = NULL,
    @RunId    bigint      = NULL,
    @Reason   nvarchar(300),
    @UserId   int         = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @Reason IS NULL OR LTRIM(RTRIM(@Reason)) = ''
        THROW 51000, 'AGORA:REASON_REQUIRED:A reversal has to say why. It is the only record of why a reconciliation was undone.', 1;

    IF @BatchId IS NULL AND @RunId IS NULL
        THROW 51000, 'AGORA:BATCH_NOT_FOUND:Name a batch or a run to reverse.', 1;

    DECLARE @Targets TABLE (BatchId bigint PRIMARY KEY, BatchNo int, ReconArea nvarchar(20), StampMode varchar(10));

    INSERT INTO @Targets (BatchId, BatchNo, ReconArea, StampMode)
    SELECT b.Id, b.BatchNo, b.ReconArea, r.StampMode
    FROM agora.ReconBatch b
    JOIN agora.ReconRun r ON r.Id = b.RunId AND r.BranchId = b.BranchId
    WHERE b.BranchId = @BranchId
      AND b.State = 'committed'
      AND (@BatchId IS NULL OR b.Id = @BatchId)
      AND (@RunId IS NULL OR b.RunId = @RunId);

    IF NOT EXISTS (SELECT 1 FROM @Targets)
    BEGIN
        IF EXISTS (SELECT 1 FROM agora.ReconBatch WHERE BranchId = @BranchId
                     AND ((@BatchId IS NOT NULL AND Id = @BatchId) OR (@RunId IS NOT NULL AND RunId = @RunId))
                     AND State = 'reversed')
            THROW 51000, 'AGORA:ALREADY_REVERSED:That reconciliation has already been reversed.', 1;

        THROW 51000, 'AGORA:BATCH_NOT_FOUND:No committed reconciliation matches that on this branch.', 1;
    END

    /*
     * T040 CALL SITE — the staff shorts this match raised.
     *
     * Reversing a match must not leave a cashier charged for a variance that
     * no longer exists. Ryan, 27 Sep 2026 (PM decision 01a0e388): a short
     * still waiting for approval is DECLINED automatically, in the
     * transaction below; one that has been approved or posted stops the
     * reversal until it is dealt with in Cash — an approved one by
     * withdrawing it there (PM decision 01a0e5b5), after which it is declined
     * and this goes through. Read here, before anything is
     * written, like every other refusal; re-checked under lock by the decline.
     */
    DECLARE @Raised TABLE (AllocId bigint PRIMARY KEY, BatchNo int, StaffShortsNo int, CashierCode nvarchar(50),
                           Found bit, Approved bit, Declined bit, Posted bit);

    INSERT INTO @Raised (AllocId, BatchNo, StaffShortsNo, CashierCode, Found, Approved, Declined, Posted)
    SELECT a.Id, a.BatchNo, a.StaffShortsNo, a.CashierCode,
           CASE WHEN s.StaffShortsNo IS NULL THEN 0 ELSE 1 END,
           ISNULL(s.Approved, 0), ISNULL(s.IsDeclined, 0),
           CASE WHEN ISNULL(s.IsSelectedForDailyBanking, 0) = 1 OR p.StaffShortsNo IS NOT NULL THEN 1 ELSE 0 END
    FROM agora.ReconAllocation a
    JOIN @Targets t ON t.BatchId = a.BatchId
    LEFT JOIN [PumpIT].dbo.BRN_StaffShorts s ON s.SSBranchId = a.BranchId AND s.StaffShortsNo = a.StaffShortsNo
    OUTER APPLY (SELECT TOP 1 x.StaffShortsNo FROM [PumpIT].dbo.BRN_DailyBankingEmployees x
                 WHERE x.SSBranchId = a.BranchId AND x.StaffShortsNo = a.StaffShortsNo) p
    WHERE a.BranchId = @BranchId AND a.ReversedAt IS NULL AND a.ShortStatus = 'raised';

    IF EXISTS (SELECT 1 FROM @Raised WHERE Declined = 0 AND (Approved = 1 OR Posted = 1))
    BEGIN
        DECLARE @Held nvarchar(2048) = (
            SELECT TOP 1 CONCAT(N'AGORA:SHORT_RAISED:This match raised staff short ', r.StaffShortsNo, N' for ', r.CashierCode,
                                CASE WHEN r.Posted = 1
                                     THEN N', and it is on a cashup, which counts it — it cannot be withdrawn, so this match cannot be reversed until the short is taken off that cashup.'
                                     ELSE N', and it has already been approved. Withdraw it in Cash first (Cash → Staff shorts → the short → Withdraw), then reverse the match.' END,
                                N' Nothing was changed.')
            FROM @Raised r WHERE r.Declined = 0 AND (r.Approved = 1 OR r.Posted = 1) ORDER BY r.StaffShortsNo);
        THROW 51000, @Held, 1;
    END

    /*
     * THE FNB ADJUSTMENT — what a forced FNB match wrote to the deposits
     * (Amount, AdjustedBy, Adjusted; see usp_Recon_ManualMatch). Each row goes
     * back to the values it held before, by its primary key — but only if it
     * still holds exactly what the match left there. A deposit somebody has
     * edited since is not written over: the reversal stops, before anything
     * moves, and says which. Ryan's go-ahead, 5 Oct 2026.
     */
    DECLARE @Adjusted TABLE (
        MatchId bigint PRIMARY KEY, BatchNo int, TillNo int, ShiftNo int, TxnAt datetime,
        BatchNoRaw nvarchar(20), MerchantNo nvarchar(20),
        Adjustment money, PriorAmount money, PriorAdjusted bit, PriorAdjustedBy money,
        NowAmount money NULL, NowAdjustedBy money NULL, NowBatchNo int NULL
    );

    INSERT INTO @Adjusted (MatchId, BatchNo, TillNo, ShiftNo, TxnAt, BatchNoRaw, MerchantNo,
                           Adjustment, PriorAmount, PriorAdjusted, PriorAdjustedBy)
    SELECT m.Id, m.BatchNo,
           TRY_CONVERT(int, JSON_VALUE(m.SourceKeyJson, '$.till')),
           TRY_CONVERT(int, JSON_VALUE(m.SourceKeyJson, '$.shift')),
           TRY_CONVERT(datetime, JSON_VALUE(m.SourceKeyJson, '$.txn'), 126),
           JSON_VALUE(m.SourceKeyJson, '$.batch'),
           JSON_VALUE(m.SourceKeyJson, '$.merchant'),
           m.Adjustment, m.PriorAmount, m.PriorAdjusted, m.PriorAdjustedBy
    FROM agora.ReconMatch m
    JOIN @Targets t ON t.BatchId = m.BatchId AND t.ReconArea = 'FNB' AND t.StampMode = 'live'
    WHERE m.BranchId = @BranchId AND m.Side = 'mops' AND m.Adjustment IS NOT NULL;

    UPDATE a SET a.NowAmount = d.Amount, a.NowAdjustedBy = ISNULL(d.AdjustedBy, 0), a.NowBatchNo = d.ReconBatchNoPumpIT
    FROM @Adjusted a
    JOIN [PumpIT].dbo.BRN_DailyBankingFNB d
      ON d.SSBranchId = @BranchId AND d.TillNo = a.TillNo AND d.ShiftNo = a.ShiftNo
     AND d.TransactionDate = a.TxnAt AND d.BatchNo = a.BatchNoRaw AND d.MerchantNo = a.MerchantNo;

    IF EXISTS (SELECT 1 FROM @Adjusted
               WHERE NowBatchNo IS NULL OR NowBatchNo <> BatchNo
                  OR NowAmount <> PriorAmount + Adjustment
                  OR NowAdjustedBy <> ISNULL(PriorAdjustedBy, 0) + Adjustment)
    BEGIN
        DECLARE @Moved nvarchar(2048) = (
            SELECT TOP 1 CONCAT(N'AGORA:ADJUST_MOVED:The FNB deposit on till ', TillNo, N' shift ', ShiftNo,
                                N', batch ', LTRIM(RTRIM(BatchNoRaw)), N' has changed since this match adjusted it',
                                CASE WHEN NowAmount IS NOT NULL
                                     THEN CONCAT(N' (it now holds R', CONVERT(nvarchar(30), CONVERT(decimal(18,2), NowAmount)),
                                                 N', the match left R', CONVERT(nvarchar(30), CONVERT(decimal(18,2), PriorAmount + Adjustment)), N')')
                                     ELSE N' (it is no longer there)' END,
                                N'. Putting the old amount back would write over that change, so nothing was reversed. Correct it in PumpIT first.')
            FROM @Adjusted
            WHERE NowBatchNo IS NULL OR NowBatchNo <> BatchNo
               OR NowAmount <> PriorAmount + Adjustment
               OR NowAdjustedBy <> ISNULL(PriorAdjustedBy, 0) + Adjustment
            ORDER BY TillNo, ShiftNo);
        THROW 51000, @Moved, 1;
    END

    /* UTC, the application's clock — see usp_Recon_Commit. */
    DECLARE @Now datetime2(0) = SYSUTCDATETIME(), @Bank int = 0, @Mops int = 0, @Lines int = 0;

    BEGIN TRANSACTION;

    /* Bank side, by id, restoring what each line held before. Guarded on the
       batch number Agora wrote: a line that something else has since
       reconciled under a different number is left alone. */
    IF EXISTS (SELECT 1 FROM @Targets WHERE StampMode = 'live')
    BEGIN
        UPDATE l
        SET l.ReconBatchNo = ISNULL(m.PriorBatchNo, 0),
            l.ReconState   = ISNULL(m.PriorReconState, 1)
        FROM [PumpIT].dbo.RCN_BankStatementLinesPumpIT l
        JOIN agora.ReconMatch m
          ON m.SourceId = l.BankStatementLineID
         AND m.BranchId = @BranchId
         AND m.Side = 'bank'
        JOIN @Targets t ON t.BatchId = m.BatchId
        WHERE l.SSBranchId = @BranchId
          AND l.ReconBatchNo = m.BatchNo;
        SET @Bank = @@ROWCOUNT;

        /* Deposit side. One statement per area, each addressing the row the
           way the commit addressed it, and each guarded on the batch number
           so nothing else's reconciliation is unpicked. */
        UPDATE d SET d.ReconBatchNoPumpIT = 0
        FROM [PumpIT].dbo.BRN_DailyBankingABSA d
        JOIN @Targets t ON t.ReconArea = 'ABSA' AND t.StampMode = 'live'
        WHERE d.SSBranchId = @BranchId AND d.ReconBatchNoPumpIT = t.BatchNo;
        SET @Mops = @Mops + @@ROWCOUNT;

        /* FNB rows a forced match adjusted: the prior values back, by key, in
           the same statement as the stamp (checked above, before the
           transaction). The rest of the batch follows in the next one. */
        UPDATE d
        SET d.ReconBatchNoPumpIT = 0,
            d.Amount     = a.PriorAmount,
            d.AdjustedBy = a.PriorAdjustedBy,
            d.Adjusted   = a.PriorAdjusted
        FROM [PumpIT].dbo.BRN_DailyBankingFNB d
        JOIN @Adjusted a
          ON d.SSBranchId = @BranchId AND d.TillNo = a.TillNo AND d.ShiftNo = a.ShiftNo
         AND d.TransactionDate = a.TxnAt AND d.BatchNo = a.BatchNoRaw AND d.MerchantNo = a.MerchantNo
        WHERE d.ReconBatchNoPumpIT = a.BatchNo
          AND d.Amount = a.PriorAmount + a.Adjustment;
        SET @Mops = @Mops + @@ROWCOUNT;

        UPDATE d SET d.ReconBatchNoPumpIT = 0
        FROM [PumpIT].dbo.BRN_DailyBankingFNB d
        JOIN @Targets t ON t.ReconArea = 'FNB' AND t.StampMode = 'live'
        WHERE d.SSBranchId = @BranchId AND d.ReconBatchNoPumpIT = t.BatchNo;
        SET @Mops = @Mops + @@ROWCOUNT;

        UPDATE d SET d.ReconBatchNoPumpIT = 0
        FROM [PumpIT].dbo.BRN_DailyBankingDeposita d
        JOIN @Targets t ON t.ReconArea = 'CashMachine' AND t.StampMode = 'live'
        WHERE d.SSBranchId = @BranchId AND d.ReconBatchNoPumpIT = t.BatchNo;
        SET @Mops = @Mops + @@ROWCOUNT;

        UPDATE d SET d.ReconBatchNoPumpIT = 0
        FROM [PumpIT].dbo.BRN_DailyBankingCashBags d
        JOIN @Targets t ON t.ReconArea = 'CashBags' AND t.StampMode = 'live'
        WHERE d.SSBranchId = @BranchId AND d.ReconBatchNoPumpIT = t.BatchNo;
        SET @Mops = @Mops + @@ROWCOUNT;

        UPDATE d SET d.ReconBatchNoPumpIT = 0
        FROM [PumpIT].dbo.BRN_DailyBankingSmartATM d
        JOIN @Targets t ON t.ReconArea = 'SmartATM' AND t.StampMode = 'live'
        WHERE d.SSBranchId = @BranchId AND d.ReconBatchNoPumpIT = t.BatchNo;
        SET @Mops = @Mops + @@ROWCOUNT;
    END

    UPDATE s
    SET s.State = 'reversed', s.UpdatedAt = @Now, s.UpdatedBy = @UserId
    FROM agora.ReconStamp s
    JOIN @Targets t ON t.BatchNo = s.BatchNo
    WHERE s.BranchId = @BranchId;

    UPDATE b
    SET b.State = 'reversed', b.ReversedAt = @Now, b.ReversedBy = @UserId,
        b.ReversalReason = @Reason, b.UpdatedAt = @Now, b.UpdatedBy = @UserId
    FROM agora.ReconBatch b
    JOIN @Targets t ON t.BatchId = b.Id
    WHERE b.BranchId = @BranchId;

    /* The shorts it raised that are still waiting: declined, through Cash,
       with a reason naming this reversal. */
    DECLARE @DeclineNo int, @DeclineBatch int, @DeclineReason nvarchar(400), @Declined int = 0;

    DECLARE waiting CURSOR LOCAL FAST_FORWARD FOR
        SELECT StaffShortsNo, BatchNo FROM @Raised
        WHERE Found = 1 AND Declined = 0 AND Approved = 0 AND Posted = 0
        ORDER BY StaffShortsNo;
    OPEN waiting;
    FETCH NEXT FROM waiting INTO @DeclineNo, @DeclineBatch;

    WHILE @@FETCH_STATUS = 0
    BEGIN
        SET @DeclineReason = LEFT(CONCAT(N'Recon batch ', @DeclineBatch, N' reversed: ', @Reason), 400);

        EXEC agora.usp_Cash_DeclineStaffShort
             @BranchId      = @BranchId,
             @StaffShortsNo = @DeclineNo,
             @Reason        = @DeclineReason,
             @WriteMode     = 'live',
             @UserId        = @UserId,
             @Quiet         = 1;

        SET @Declined = @Declined + 1;
        FETCH NEXT FROM waiting INTO @DeclineNo, @DeclineBatch;
    END

    CLOSE waiting;
    DEALLOCATE waiting;

    /* The split by cashier goes with its batch: marked, never deleted, the
       figures left as they were confirmed. A short that was due and never
       raised is no longer due, and neither is one the reversal declined (or
       an approver had): 'cancelled', keeping the number it was raised as. */
    UPDATE a
    SET a.ReversedAt = @Now, a.ReversedBy = @UserId, a.ReversalReason = @Reason,
        a.ShortStatus = CASE WHEN a.ShortStatus IN ('pending', 'raised') THEN 'cancelled' ELSE a.ShortStatus END,
        a.UpdatedAt = @Now, a.UpdatedBy = @UserId
    FROM agora.ReconAllocation a
    JOIN @Targets t ON t.BatchId = a.BatchId
    WHERE a.BranchId = @BranchId
      AND a.ReversedAt IS NULL;
    SET @Lines = @@ROWCOUNT;

    /* The proposals go back to pending, so the run can be executed again once
       whatever caused the reversal is dealt with. */
    UPDATE l
    SET l.CommitState = 'pending', l.ReconBatchNo = NULL,
        l.BlockReason = 'Reversed: ' + @Reason,
        l.UpdatedAt = @Now, l.UpdatedBy = @UserId
    FROM agora.ReconRunLine l
    JOIN agora.ReconMatch m ON m.RunLineId = l.Id AND m.BranchId = l.BranchId
    JOIN @Targets t ON t.BatchId = m.BatchId
    WHERE l.BranchId = @BranchId;

    /* A run with nothing left committed is a preview again. */
    UPDATE r
    SET r.Status = 'reversed', r.ReversedAt = @Now, r.ReversedBy = @UserId,
        r.ReversalReason = @Reason, r.UpdatedAt = @Now, r.UpdatedBy = @UserId
    FROM agora.ReconRun r
    WHERE r.BranchId = @BranchId
      AND r.Status = 'committed'
      AND NOT EXISTS (SELECT 1 FROM agora.ReconBatch b
                      WHERE b.BranchId = r.BranchId AND b.RunId = r.Id AND b.State = 'committed');

    COMMIT TRANSACTION;

    SELECT CONVERT(bit, 1) AS Ok,
           'REVERSED'       AS Code,
           CONVERT(nvarchar(10), (SELECT COUNT(*) FROM @Targets)) + ' batch(es) reversed — '
             + CONVERT(nvarchar(10), @Bank) + ' bank lines and '
             + CONVERT(nvarchar(10), @Mops) + ' deposit rows put back.'
             + CASE WHEN @Lines = 0 THEN ''
                    ELSE ' The split by cashier (' + CONVERT(nvarchar(10), @Lines) + ' line(s)) is kept, marked reversed.' END
             + CASE WHEN @Declined = 0 THEN ''
                    ELSE ' Staff short' + CASE WHEN @Declined = 1 THEN ' ' ELSE 's ' END
                         + (SELECT STRING_AGG(CONVERT(nvarchar(12), StaffShortsNo), ', ') WITHIN GROUP (ORDER BY StaffShortsNo)
                            FROM @Raised WHERE Found = 1 AND Declined = 0 AND Approved = 0 AND Posted = 0)
                         + ' declined with it.' END AS Message,
           CONVERT(bigint, (SELECT COUNT(*) FROM @Targets)) AS Id;
END
