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

   The batch number is NOT returned to SS_UniqueNumber. Gaps in a counter are
   harmless; handing the same number out twice is not.

   Refusals: BATCH_NOT_FOUND · ALREADY_REVERSED · REASON_REQUIRED
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

    DECLARE @Now datetime2(0) = SYSDATETIME(), @Bank int = 0, @Mops int = 0;

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
             + CONVERT(nvarchar(10), @Mops) + ' deposit rows put back.' AS Message,
           CONVERT(bigint, (SELECT COUNT(*) FROM @Targets)) AS Id;
END
