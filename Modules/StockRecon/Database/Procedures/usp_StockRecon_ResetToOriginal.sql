/*
 * agora.usp_StockRecon_ResetToOriginal — put one run's shifts back to the
 * staff's original counts in PumpIT.
 *
 * Read by:  Stock recon centre -> a run -> Reset to original.
 * Reads:    agora.StockReconRun, agora.StockReconRunLine, agora.vw_StockReconLine,
 *           agora.StockReconAmendment
 * Writes:   agora.StockReconAmendment, agora.StockReconRunLine, agora.StockReconRun
 *           and — in 'live' stamp mode ONLY — on [PumpIT].dbo.STK_StockReconLine:
 *           QtyOpen = QtyOpen_Original, QtyClose = QtyClose_Original, and, when
 *           @IssueMode is 'live' too, QtyIssued = QtyIssued_Original (Ryan's
 *           named go-ahead, 7 Oct 2026: "update the amended with the original
 *           column values", with head office's own three UPDATEs).
 *
 * WHY. Head office balances by hand in PumpIT: the live columns move, the
 * _Original ones keep what staff counted. Where the hand balancing already
 * holds the answer, a preview has nothing to write and a balanceable chain
 * cannot be pressed. Head office's way back is a script per branch-day that
 * sets every live column to its original. This is that script, for the shifts
 * one run covers, with three things the script does not have:
 *
 *   - Every row's PRIOR open, close and issue is written to
 *     agora.StockReconAmendment first, so the run's Reverse puts back exactly
 *     what was there (and refuses — COUNT_MOVED, ISSUE_MOVED — over anything
 *     changed since).
 *   - A chain a possible duplicate holds back (DupHold, PM decision 01a10b21)
 *     is left alone: nothing on a held chain is written, and the staff counts
 *     are exactly what would put a hidden short back on pay.
 *   - A shift carrying a balancing amendment from another run is left alone
 *     and says so: reverse that run first.
 *
 * Only shifts whose original is recorded and differs. A run made since is the
 * one to balance from: the preview that follows reads PumpIT as reset.
 *
 * Result set 1: the status row.
 * Result set 2: one row per shift considered, written or skipped and why.
 */
CREATE OR ALTER PROCEDURE [agora].[usp_StockRecon_ResetToOriginal]
    @RunId      INT,
    @BranchId   INT,
    @StampMode  NVARCHAR(20) = 'journal',
    @IssueMode  NVARCHAR(20) = 'off',
    @UserId     INT          = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @eps DECIMAL(18,4) = 0.0005;

    IF @StampMode NOT IN ('journal', 'live')
        THROW 51000, 'AGORA:BAD_STAMP_MODE:The stamp mode must be journal or live.', 1;

    IF @IssueMode NOT IN ('off', 'live')
        THROW 51000, 'AGORA:BAD_ISSUE_MODE:The issue mode must be off or live.', 1;

    DECLARE @Status NVARCHAR(20) = (
        SELECT [Status] FROM agora.StockReconRun WHERE BranchId = @BranchId AND Id = @RunId);

    IF @Status IS NULL
        THROW 51000, 'AGORA:NO_RUN:That run does not exist for this branch.', 1;

    IF @Status = 'committed'
        THROW 51000, 'AGORA:RUN_COMMITTED:This run has already been committed. Reverse it first, or reset from a new preview.', 1;

    IF @Status NOT IN ('previewed', 'reversed')
        THROW 51000, 'AGORA:RUN_NOT_READY:Only a previewed run can be reset.', 1;

    /* The shifts: on this run, not on a held chain, with a recorded original
       that PumpIT no longer holds. */
    IF OBJECT_ID('tempdb..#reset') IS NOT NULL DROP TABLE #reset;

    SELECT
        rl.Id, rl.[LineNo], rl.AreaNo, rl.StockItemNo, rl.TransactionDate, rl.ShiftNo,
        CONVERT(decimal(18,3), ISNULL(l.QtyOpen,   0))                AS PriorOpen,
        CONVERT(decimal(18,3), ISNULL(l.QtyClose,  0))                AS PriorClose,
        CONVERT(decimal(18,3), ISNULL(l.QtyIssued, 0))                AS PriorIssued,
        CONVERT(decimal(18,3), ISNULL(l.QtyOpen_Original,   l.QtyOpen))   AS NewOpen,
        CONVERT(decimal(18,3), ISNULL(l.QtyClose_Original,  l.QtyClose))  AS NewClose,
        CONVERT(decimal(18,3), CASE WHEN @IssueMode = 'live'
                                    THEN ISNULL(l.QtyIssued_Original, l.QtyIssued)
                                    ELSE l.QtyIssued END)                 AS NewIssued,
        CONVERT(nvarchar(200), NULL)                                      AS SkipReason
    INTO #reset
    FROM agora.StockReconRunLine rl
    JOIN agora.vw_StockReconLine l
      ON l.BranchId = rl.BranchId
     AND CONVERT(date, l.TransactionDate) = rl.TransactionDate
     AND l.ShiftNo = rl.ShiftNo AND l.AreaNo = rl.AreaNo AND l.StockItemNo = rl.StockItemNo
    WHERE rl.BranchId = @BranchId
      AND rl.RunId    = @RunId
      AND rl.DupHold  = 0
      AND (   (l.QtyOpen_Original  IS NOT NULL AND ABS(ISNULL(l.QtyOpen,  0) - l.QtyOpen_Original)  > @eps)
           OR (l.QtyClose_Original IS NOT NULL AND ABS(ISNULL(l.QtyClose, 0) - l.QtyClose_Original) > @eps)
           OR (@IssueMode = 'live' AND l.QtyIssued_Original IS NOT NULL
               AND ABS(ISNULL(l.QtyIssued, 0) - l.QtyIssued_Original) > @eps));

    /* A balancing amendment from another run still standing on the shift: two
       priors on one row and no way to know which is real. Reverse that first.
       An earlier reset is not one of them — its "new" is the original, so a
       shift it covered is not a candidate again unless something moved it. */
    UPDATE r SET SkipReason = 'This shift carries an amendment from another run. Reverse that run first.'
    FROM #reset r
    WHERE EXISTS (
        SELECT 1 FROM agora.StockReconAmendment a
        WHERE a.BranchId = @BranchId AND a.[State] = 'active' AND a.RunId <> @RunId
          AND a.AreaNo = r.AreaNo AND a.StockItemNo = r.StockItemNo
          AND a.TransactionDate = r.TransactionDate AND a.ShiftNo = r.ShiftNo);

    DECLARE @Written INT = (SELECT COUNT(*) FROM #reset WHERE SkipReason IS NULL),
            @Skipped INT = (SELECT COUNT(*) FROM #reset WHERE SkipReason IS NOT NULL);

    IF NOT EXISTS (SELECT 1 FROM #reset)
        THROW 51000, 'AGORA:NOTHING_TO_RESET:Every shift on this run already holds the original counts.', 1;

    BEGIN TRY
    BEGIN TRANSACTION;

    INSERT INTO agora.StockReconAmendment (
        BranchId, RunId, RunLineId, AreaNo, StockItemNo, TransactionDate, ShiftNo,
        PriorQtyOpen, PriorQtyClose, NewQtyOpen, NewQtyClose,
        PriorQtyIssued, NewQtyIssued, StampMode, [State],
        CreatedAt, CreatedBy)
    SELECT
        @BranchId, @RunId, r.Id, r.AreaNo, r.StockItemNo, r.TransactionDate, r.ShiftNo,
        r.PriorOpen, r.PriorClose, r.NewOpen, r.NewClose,
        /* The issue only where it changes, as a duplicate's is: NULL means the
           reversal leaves it alone. */
        CASE WHEN ABS(r.NewIssued - r.PriorIssued) > @eps THEN r.PriorIssued END,
        CASE WHEN ABS(r.NewIssued - r.PriorIssued) > @eps THEN r.NewIssued END,
        @StampMode, 'active',
        SYSDATETIME(), @UserId
    FROM #reset r
    WHERE r.SkipReason IS NULL;

    IF @StampMode = 'live'
        UPDATE l
           SET l.QtyOpen   = r.NewOpen,
               l.QtyClose  = r.NewClose,
               l.QtyIssued = CASE WHEN ABS(r.NewIssued - r.PriorIssued) > @eps THEN r.NewIssued ELSE l.QtyIssued END
        FROM [PumpIT].dbo.STK_StockReconLine l
        JOIN #reset r
          ON l.SSBranchId = @BranchId
         AND CONVERT(date, l.TransactionDate) = r.TransactionDate
         AND l.ShiftNo = r.ShiftNo AND l.AreaNo = r.AreaNo AND l.StockItemNo = r.StockItemNo
        WHERE r.SkipReason IS NULL;

    UPDATE rl
       SET rl.CommitState = CASE WHEN r.SkipReason IS NULL THEN 'committed' ELSE 'skipped' END,
           rl.BlockReason = r.SkipReason,
           rl.UpdatedAt   = SYSDATETIME(),
           rl.UpdatedBy   = @UserId
    FROM agora.StockReconRunLine rl
    JOIN #reset r ON r.Id = rl.Id
    WHERE rl.BranchId = @BranchId;

    IF @Written > 0
        UPDATE agora.StockReconRun
           SET [Status]       = 'committed',
               CommitKind     = 'reset',
               StampMode      = @StampMode,
               CommittedAt    = SYSDATETIME(),
               CommittedBy    = @UserId,
               CommittedRows  = @Written,
               CommittedUnits = (SELECT ISNULL(SUM(ABS(NewClose - PriorClose)), 0) FROM #reset WHERE SkipReason IS NULL),
               UpdatedAt      = SYSDATETIME(),
               UpdatedBy      = @UserId
        WHERE BranchId = @BranchId AND Id = @RunId;

    COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        DECLARE @Err NVARCHAR(2048) = ERROR_MESSAGE();
        THROW 51000, @Err, 1;
    END CATCH

    SELECT CONVERT(bit, CASE WHEN @Written > 0 THEN 1 ELSE 0 END) AS Ok,
           CASE WHEN @Written > 0 THEN 'RESET' ELSE 'ALL_SKIPPED' END AS Code,
           CASE WHEN @Written = 0
                THEN 'Nothing was reset. ' + ISNULL((SELECT TOP 1 SkipReason FROM #reset WHERE SkipReason IS NOT NULL), '')
                ELSE CONVERT(nvarchar(20), @Written) + ' shift(s) put back to the original counts'
                     + CASE WHEN @Skipped > 0 THEN ', ' + CONVERT(nvarchar(20), @Skipped) + ' skipped (each says why)' ELSE '' END
                     + CASE WHEN @StampMode = 'live' THEN ', written to PumpIT.'
                            ELSE ', recorded in Agora only — PumpIT was not touched.' END
           END AS [Message],
           CONVERT(bigint, @RunId) AS Id;

    SELECT r.Id AS RunLineId, r.[LineNo], r.AreaNo, r.StockItemNo, r.TransactionDate, r.ShiftNo,
           CASE WHEN r.SkipReason IS NULL THEN 'reset' ELSE 'skipped' END AS Result,
           r.SkipReason
    FROM #reset r
    ORDER BY r.[LineNo];

    DROP TABLE #reset;
END
