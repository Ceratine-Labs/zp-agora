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
 *           column values", with head office's own three UPDATEs), and
 *           QtyComputer = QtyComputer_Original, the POS (Ryan's named go-ahead,
 *           8 Oct 2026: "clear any amendments, they will not be inputting
 *           anything on v2"). Every amended column goes back.
 *
 * WHY. Head office balances by hand in PumpIT: the live columns move, the
 * _Original ones keep what staff counted. Where the hand balancing already
 * holds the answer, a preview has nothing to write and a balanceable chain
 * cannot be pressed. Head office's way back is a script per branch-day that
 * sets every live column to its original. This is that script, for the shifts
 * one run covers, with three things the script does not have:
 *
 *   - Every row's PRIOR open, close, issue and POS is written to
 *     agora.StockReconAmendment first, so the run's Reverse puts back exactly
 *     what was there (and refuses — COUNT_MOVED, ISSUE_MOVED — over anything
 *     changed since).
 *   - A chain a possible duplicate holds back (DupHold, PM decision 01a10b21)
 *     is left alone: nothing on a held chain is written, and the staff counts
 *     are exactly what would put a hidden short back on pay.
 *   - A shift carrying a balancing amendment from another run is left alone
 *     and says so: reverse that run first.
 *
 * Only shifts whose original is recorded and differs — and, for each item,
 * the closing of the shift just before the week, which the preview reads as
 * the first previous closing (7 Oct 2026). A run made since is the one to
 * balance from: the preview that follows reads PumpIT as reset.
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

    /* Reset relies on the same source and removal evidence as balancing.
       Keep its validation and writes in one serializable transaction. */
    SET TRANSACTION ISOLATION LEVEL SERIALIZABLE;
    BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @Status NVARCHAR(20) = (
        SELECT [Status] FROM agora.StockReconRun WHERE BranchId = @BranchId AND Id = @RunId);

    IF @Status IS NULL
        THROW 51000, 'AGORA:NO_RUN:That run does not exist for this branch.', 1;

    IF @Status = 'committed'
        THROW 51000, 'AGORA:RUN_COMMITTED:This run has already been committed. Reverse it first, or reset from a new preview.', 1;

    IF @Status NOT IN ('previewed', 'reversed')
        THROW 51000, 'AGORA:RUN_NOT_READY:Only a previewed run can be reset.', 1;

    /* Reset writes live counts too. A missing continuation or changed removal
       makes the saved chain unsafe even when the requested write is a reset. */
    IF EXISTS (SELECT 1 FROM agora.StockReconRunLine rl
        CROSS APPLY (SELECT COUNT(*) AS Segment FROM agora.StockReconItemRemoval boundary
            WHERE boundary.BranchId = rl.BranchId AND boundary.AreaNo = rl.AreaNo AND boundary.StockItemNo = rl.StockItemNo
              AND boundary.WithdrawnAt IS NULL AND (boundary.LastDate < rl.TransactionDate
                OR (boundary.LastDate = rl.TransactionDate AND boundary.LastShift < rl.ShiftNo))) currentBoundary
        WHERE rl.BranchId = @BranchId AND rl.RunId = @RunId
          AND (rl.ContinuitySegment IS NULL OR rl.ContinuitySegment <> currentBoundary.Segment))
        THROW 51000, 'AGORA:REMOVAL_CHANGED:The item removal history changed since this preview. Preview again.', 1;

    IF EXISTS (SELECT 1 FROM agora.StockReconRunLine rl
        WHERE rl.BranchId = @BranchId AND rl.RunId = @RunId AND rl.Outcome LIKE 'Missing shift:%')
        THROW 51000, 'AGORA:MISSING_SHIFT:A required shift is missing. Correct it and preview again before resetting.', 1;

    /* A completed area shift or later activity may have appeared since the
       preview, proving that an item's final continuation is missing. */
    IF EXISTS (SELECT 1 FROM (
        SELECT rl.*, ROW_NUMBER() OVER (PARTITION BY rl.AreaNo, rl.StockItemNo, rl.ContinuitySegment
            ORDER BY rl.TransactionDate DESC, rl.ShiftNo DESC) AS rn
        FROM agora.StockReconRunLine rl WHERE rl.BranchId = @BranchId AND rl.RunId = @RunId
    ) r
    JOIN agora.StockReconRun run ON run.BranchId = r.BranchId AND run.Id = r.RunId
    JOIN agora.vw_StockArea area ON area.BranchId = r.BranchId AND area.AreaNo = r.AreaNo
    CROSS APPLY (SELECT TOP 1 s.ShiftNo + CASE WHEN s.ShiftNo <= r.ShiftNo THEN 3 ELSE 0 END AS NextOffset
        FROM (VALUES (1, area.DayShift), (2, area.AfternoonShift), (3, area.NightShift)) s(ShiftNo, Enabled)
        WHERE s.Enabled = 1 ORDER BY s.ShiftNo + CASE WHEN s.ShiftNo <= r.ShiftNo THEN 3 ELSE 0 END) expected
    WHERE r.rn = 1
      AND (EXISTS (SELECT 1 FROM agora.vw_StockReconLine later WHERE later.BranchId = r.BranchId
          AND later.AreaNo = r.AreaNo AND later.TransactionDate < DATEADD(day, 1, run.ToDate)
          AND NOT EXISTS (SELECT 1 FROM agora.StockReconMeter meter WHERE meter.BranchId = later.BranchId
              AND meter.AreaNo = later.AreaNo AND meter.StockItemNo = later.StockItemNo AND meter.IsActive = 1)
          AND DATEDIFF(day, r.TransactionDate, later.TransactionDate) * 3 + later.ShiftNo >= expected.NextOffset)
        OR EXISTS (SELECT 1 FROM agora.StockReconAreaShiftCompletion done WHERE done.BranchId = r.BranchId
          AND done.AreaNo = r.AreaNo AND done.WithdrawnAt IS NULL AND done.TransactionDate <= run.ToDate
          AND DATEDIFF(day, r.TransactionDate, done.TransactionDate) * 3 + done.ShiftNo >= expected.NextOffset))
      AND NOT EXISTS (SELECT 1 FROM agora.StockReconItemRemoval removal WHERE removal.BranchId = r.BranchId
          AND removal.AreaNo = r.AreaNo AND removal.StockItemNo = r.StockItemNo AND removal.WithdrawnAt IS NULL
          AND removal.LastDate = r.TransactionDate AND removal.LastShift = r.ShiftNo))
        THROW 51000, 'AGORA:MISSING_SHIFT:A required continuation is missing. Correct it or record removal and preview again.', 1;

    /* Completed shifts also require items absent for the whole window. */
    IF EXISTS (
        SELECT 1 FROM (
            SELECT l.BranchId, l.AreaNo, l.StockItemNo, CONVERT(date, l.TransactionDate) AS LastDate, l.ShiftNo AS LastShift,
                   ROW_NUMBER() OVER (PARTITION BY l.AreaNo, l.StockItemNo ORDER BY l.TransactionDate DESC, l.ShiftNo DESC) AS rn
            FROM agora.vw_StockReconLine l
            JOIN agora.StockReconRun run ON run.BranchId = l.BranchId AND run.Id = @RunId
            WHERE l.BranchId = @BranchId AND l.TransactionDate < run.FromDate
              AND EXISTS (SELECT 1 FROM agora.StockReconRunLine affected WHERE affected.BranchId = @BranchId AND affected.RunId = @RunId
                  AND affected.AreaNo = l.AreaNo)
              AND NOT EXISTS (SELECT 1 FROM agora.StockReconMeter meter WHERE meter.BranchId = l.BranchId
                  AND meter.AreaNo = l.AreaNo AND meter.StockItemNo = l.StockItemNo AND meter.IsActive = 1)
        ) p
        JOIN agora.StockReconRun run ON run.BranchId = p.BranchId AND run.Id = @RunId
        JOIN agora.vw_StockArea a ON a.BranchId = p.BranchId AND a.AreaNo = p.AreaNo
        CROSS APPLY (SELECT TOP 1 s.ShiftNo + CASE WHEN s.ShiftNo <= p.LastShift THEN 3 ELSE 0 END AS NextOffset
            FROM (VALUES (1, a.DayShift), (2, a.AfternoonShift), (3, a.NightShift)) s(ShiftNo, Enabled)
            WHERE s.Enabled = 1 ORDER BY s.ShiftNo + CASE WHEN s.ShiftNo <= p.LastShift THEN 3 ELSE 0 END) expected
        WHERE p.rn = 1
          AND NOT EXISTS (SELECT 1 FROM agora.vw_StockReconLine present WHERE present.BranchId = p.BranchId
              AND present.AreaNo = p.AreaNo AND present.StockItemNo = p.StockItemNo
              AND present.TransactionDate >= run.FromDate AND present.TransactionDate < DATEADD(day, 1, run.ToDate))
          AND EXISTS (SELECT 1 FROM agora.StockReconAreaShiftCompletion done WHERE done.BranchId = p.BranchId
              AND done.AreaNo = p.AreaNo AND done.WithdrawnAt IS NULL AND done.TransactionDate BETWEEN run.FromDate AND run.ToDate
              AND DATEDIFF(day, p.LastDate, done.TransactionDate) * 3 + done.ShiftNo >= expected.NextOffset)
          AND NOT EXISTS (SELECT 1 FROM agora.StockReconItemRemoval removal WHERE removal.BranchId = p.BranchId
              AND removal.AreaNo = p.AreaNo AND removal.StockItemNo = p.StockItemNo AND removal.WithdrawnAt IS NULL
              AND removal.LastDate = p.LastDate AND removal.LastShift = p.LastShift)
    ) THROW 51000, 'AGORA:MISSING_SHIFT:An item is absent from a completed area shift. Correct it or record removal and preview again.', 1;

    IF EXISTS (SELECT 1 FROM agora.StockReconItemRemoval removal
        JOIN agora.StockReconRun run ON run.BranchId = removal.BranchId AND run.Id = @RunId
        WHERE removal.BranchId = @BranchId AND removal.WithdrawnAt IS NULL AND removal.LastDate <= run.ToDate
          AND EXISTS (SELECT 1 FROM agora.StockReconRunLine rl WHERE rl.BranchId = @BranchId AND rl.RunId = @RunId
            AND rl.AreaNo = removal.AreaNo AND rl.StockItemNo = removal.StockItemNo)
          AND NOT EXISTS (SELECT 1 FROM agora.vw_StockReconLine source WHERE source.BranchId = removal.BranchId
            AND source.AreaNo = removal.AreaNo AND source.StockItemNo = removal.StockItemNo
            AND source.TransactionDate >= removal.LastDate AND source.TransactionDate < DATEADD(day, 1, removal.LastDate)
            AND source.ShiftNo = removal.LastShift))
        THROW 51000, 'AGORA:MISSING_SHIFT:The recorded removal count is missing. Correct it and preview again.', 1;

    IF EXISTS (SELECT 1 FROM agora.StockReconRunLine rl
        WHERE rl.BranchId = @BranchId AND rl.RunId = @RunId
          AND NOT EXISTS (SELECT 1 FROM agora.vw_StockReconLine source
              WHERE source.BranchId = rl.BranchId AND source.AreaNo = rl.AreaNo AND source.StockItemNo = rl.StockItemNo
                AND CONVERT(date, source.TransactionDate) = rl.TransactionDate AND source.ShiftNo = rl.ShiftNo))
        THROW 51000, 'AGORA:MISSING_SHIFT:A shift disappeared since this preview. Correct it and preview again before resetting.', 1;

    /* A saved preview predating the explicit measurement mapping may still
       contain meter proposals. Refuse BEFORE any run/source mutation. A new
       preview excludes those keys; reversal of historic amendments is separate. */
    IF EXISTS (SELECT 1 FROM agora.StockReconRunLine rl
        JOIN agora.StockReconMeter meter ON meter.BranchId = rl.BranchId AND meter.AreaNo = rl.AreaNo
            AND meter.StockItemNo = rl.StockItemNo AND meter.IsActive = 1
        WHERE rl.BranchId = @BranchId AND rl.RunId = @RunId)
        THROW 51000, 'AGORA:CUMULATIVE_METER:This saved run includes cumulative water-meter readings. Preview again; review these readings in Water meter reconciliation.', 1;

    /* The shifts: on this run, not on a held chain, with a recorded original
       that PumpIT no longer holds. */
    IF OBJECT_ID('tempdb..#reset') IS NOT NULL DROP TABLE #reset;

    SELECT
        rl.Id, rl.[LineNo], rl.AreaNo, rl.StockItemNo, rl.TransactionDate, rl.ShiftNo,
        CONVERT(decimal(18,3), ISNULL(l.QtyOpen,   0))                AS PriorOpen,
        CONVERT(decimal(18,3), ISNULL(l.QtyClose,  0))                AS PriorClose,
        CONVERT(decimal(18,3), ISNULL(l.QtyIssued, 0))                AS PriorIssued,
        CONVERT(decimal(18,3), ISNULL(l.QtyComputer, 0))              AS PriorPOS,
        CONVERT(decimal(18,3), ISNULL(l.QtyOpen_Original,   l.QtyOpen))   AS NewOpen,
        CONVERT(decimal(18,3), ISNULL(l.QtyClose_Original,  l.QtyClose))  AS NewClose,
        CONVERT(decimal(18,3), CASE WHEN @IssueMode = 'live'
                                    THEN ISNULL(l.QtyIssued_Original, l.QtyIssued)
                                    ELSE l.QtyIssued END)                 AS NewIssued,
        CONVERT(decimal(18,3), ISNULL(l.QtyComputer_Original, ISNULL(l.QtyComputer, 0))) AS NewPOS,
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
               AND ABS(ISNULL(l.QtyIssued, 0) - l.QtyIssued_Original) > @eps)
           OR (l.QtyComputer_Original IS NOT NULL
               AND ABS(ISNULL(l.QtyComputer, 0) - l.QtyComputer_Original) > @eps));

    /* AND THE SHIFT JUST BEFORE THE WEEK, its closing only (7 Oct 2026). The
       preview reads the ORIGINAL closing of the last shift before the window
       as the first opening's previous closing; where head office changed it
       by hand (about one item in seven, September), the chain cannot be
       written without breaking PumpIT at the week's edge. So its closing goes
       back too — the opening, the issue and the rest of that shift's week are
       not touched. It is not a line of this run: its amendment carries the
       NEGATIVE id of the item's first line, so the run's Reverse finds it and
       the ledger's key stays unique. */
    DECLARE @From DATE = (SELECT FromDate FROM agora.StockReconRun WHERE BranchId = @BranchId AND Id = @RunId);

    ;WITH items AS (
        SELECT AreaNo, StockItemNo, MIN(Id) AS FirstLineId, MAX(CONVERT(int, DupHold)) AS Held
        FROM agora.StockReconRunLine
        WHERE BranchId = @BranchId AND RunId = @RunId
        GROUP BY AreaNo, StockItemNo
    ),
    before AS (
        SELECT l.AreaNo, l.StockItemNo, CONVERT(date, l.TransactionDate) AS TransactionDate, l.ShiftNo,
               l.QtyOpen, l.QtyClose, l.QtyClose_Original, l.QtyIssued, l.QtyComputer,
               ROW_NUMBER() OVER (PARTITION BY l.AreaNo, l.StockItemNo
                                  ORDER BY l.TransactionDate DESC, l.ShiftNo DESC) AS rn
        FROM agora.vw_StockReconLine l
        JOIN items i ON i.AreaNo = l.AreaNo AND i.StockItemNo = l.StockItemNo
        WHERE l.BranchId = @BranchId
          AND l.TransactionDate <  @From
          AND NOT EXISTS (SELECT 1 FROM agora.StockReconItemRemoval removal
              WHERE removal.BranchId = l.BranchId AND removal.AreaNo = l.AreaNo AND removal.StockItemNo = l.StockItemNo
                AND removal.WithdrawnAt IS NULL AND removal.LastDate < @From
                AND (removal.LastDate > CONVERT(date, l.TransactionDate)
                     OR (removal.LastDate = CONVERT(date, l.TransactionDate) AND removal.LastShift >= l.ShiftNo)))
    )
    INSERT INTO #reset (Id, [LineNo], AreaNo, StockItemNo, TransactionDate, ShiftNo,
                        PriorOpen, PriorClose, PriorIssued, PriorPOS, NewOpen, NewClose, NewIssued, NewPOS, SkipReason)
    SELECT -i.FirstLineId, 0, b.AreaNo, b.StockItemNo, b.TransactionDate, b.ShiftNo,
           CONVERT(decimal(18,3), ISNULL(b.QtyOpen, 0)), CONVERT(decimal(18,3), ISNULL(b.QtyClose, 0)),
           CONVERT(decimal(18,3), ISNULL(b.QtyIssued, 0)), CONVERT(decimal(18,3), ISNULL(b.QtyComputer, 0)),
           CONVERT(decimal(18,3), ISNULL(b.QtyOpen, 0)), CONVERT(decimal(18,3), b.QtyClose_Original),
           CONVERT(decimal(18,3), ISNULL(b.QtyIssued, 0)), CONVERT(decimal(18,3), ISNULL(b.QtyComputer, 0)), NULL
    FROM before b
    JOIN items i ON i.AreaNo = b.AreaNo AND i.StockItemNo = b.StockItemNo
    WHERE b.rn = 1 AND i.Held = 0
      AND b.QtyClose_Original IS NOT NULL
      AND ABS(ISNULL(b.QtyClose, 0) - b.QtyClose_Original) > @eps;

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
            @Skipped INT = (SELECT COUNT(*) FROM #reset WHERE SkipReason IS NOT NULL),
            @Anchors INT = (SELECT COUNT(*) FROM #reset WHERE SkipReason IS NULL AND Id < 0);

    IF NOT EXISTS (SELECT 1 FROM #reset)
        THROW 51000, 'AGORA:NOTHING_TO_RESET:Every shift on this run already holds the original counts.', 1;

    INSERT INTO agora.StockReconAmendment (
        BranchId, RunId, RunLineId, AreaNo, StockItemNo, TransactionDate, ShiftNo,
        PriorQtyOpen, PriorQtyClose, NewQtyOpen, NewQtyClose,
        PriorQtyIssued, NewQtyIssued, PriorQtyPOS, NewQtyPOS, StampMode, [State],
        CreatedAt, CreatedBy)
    SELECT
        @BranchId, @RunId, r.Id, r.AreaNo, r.StockItemNo, r.TransactionDate, r.ShiftNo,
        r.PriorOpen, r.PriorClose, r.NewOpen, r.NewClose,
        /* The issue only where it changes, as a duplicate's is: NULL means the
           reversal leaves it alone. */
        CASE WHEN ABS(r.NewIssued - r.PriorIssued) > @eps THEN r.PriorIssued END,
        CASE WHEN ABS(r.NewIssued - r.PriorIssued) > @eps THEN r.NewIssued END,
        CASE WHEN ABS(r.NewPOS - r.PriorPOS) > @eps THEN r.PriorPOS END,
        CASE WHEN ABS(r.NewPOS - r.PriorPOS) > @eps THEN r.NewPOS END,
        @StampMode, 'active',
        SYSUTCDATETIME(), @UserId
    FROM #reset r
    WHERE r.SkipReason IS NULL;

    IF @StampMode = 'live'
        UPDATE l
           SET l.QtyOpen   = r.NewOpen,
               l.QtyClose  = r.NewClose,
               l.QtyIssued = CASE WHEN ABS(r.NewIssued - r.PriorIssued) > @eps THEN r.NewIssued ELSE l.QtyIssued END,
               l.QtyComputer = CASE WHEN ABS(r.NewPOS - r.PriorPOS) > @eps THEN r.NewPOS ELSE l.QtyComputer END
        FROM [PumpIT].dbo.STK_StockReconLine l
        JOIN #reset r
          ON l.SSBranchId = @BranchId
         AND CONVERT(date, l.TransactionDate) = r.TransactionDate
         AND l.ShiftNo = r.ShiftNo AND l.AreaNo = r.AreaNo AND l.StockItemNo = r.StockItemNo
        WHERE r.SkipReason IS NULL;

    UPDATE rl
       SET rl.CommitState = CASE WHEN r.SkipReason IS NULL THEN 'committed' ELSE 'skipped' END,
           rl.BlockReason = r.SkipReason,
           rl.UpdatedAt   = SYSUTCDATETIME(),
           rl.UpdatedBy   = @UserId
    FROM agora.StockReconRunLine rl
    JOIN #reset r ON r.Id = rl.Id
    WHERE rl.BranchId = @BranchId;

    IF @Written > 0
        UPDATE agora.StockReconRun
           SET [Status]       = 'committed',
               CommitKind     = 'reset',
               StampMode      = @StampMode,
               CommittedAt    = SYSUTCDATETIME(),
               CommittedBy    = @UserId,
               CommittedRows  = @Written,
               CommittedUnits = (SELECT ISNULL(SUM(ABS(NewClose - PriorClose)), 0) FROM #reset WHERE SkipReason IS NULL),
               UpdatedAt      = SYSUTCDATETIME(),
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
                     + CASE WHEN @Anchors > 0
                            THEN ' (' + CONVERT(nvarchar(20), @Anchors) + ' of them the closing just before the week)'
                            ELSE '' END
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
