/*
 * agora.usp_StockRecon_GetDuplicates — the duplicate issues one run found,
 * each with the shifts that prove it and what is being done about it.
 *
 * Read by:  Stock recon centre -> a run -> the "Duplicate issues" panel, on the
 *           proposals and on the exceptions screen (Ryan, 1 Oct 2026: "a view
 *           like the customer report on the stock recon itself, identifying
 *           what is what and what is being corrected").
 * Reads:    agora.StockReconRunLine (agora.vw_StockReconRunLine only for a run
 *           made before the labels were stored), agora.StockReconRun, agora.Branch,
 *           agora.StockReconAmendment, agora.StockReconDuplicateRelease,
 *           agora.[User]. Nothing in PumpIT: every figure is the one the run
 *           recorded, so the panel says what the run was judged on.
 * Writes:   nothing.
 *
 * A duplicate issue is judged on a PAIR of shifts — the one keyed twice and
 * its twin, the identical issue on a neighbouring shift — so a row on its own
 * cannot show it. This returns each candidate with both, laid out the way the
 * customer report reads them: the shift where the issue arrived, the shift as
 * keyed, and for a clear one (D1) the same shift corrected, its issue at 0.
 *
 * THE FIGURES ARE THE BASIS THE DUPLICATE WAS JUDGED ON: the opening is the
 * previous closing (PrevClose) where the chain carried one, exactly as the
 * preview's stage 2b reads it, and the counted opening travels beside it so a
 * repaired one is visible. Expected = Open + Issued - Sold, and the variance
 * is Counted - Expected — the shift's short or over, valued at the sell price
 * the run recorded.
 *
 * Result set 1: one row per candidate (D1 then D2), with its STATE —
 *   D1  will_remove   the commit sets the issue to 0
 *       removed       set to 0 by this run's commit (an active amendment)
 *       reversed      set to 0, then put back by a reversal
 *       skipped       the commit declined it; BlockReason says why
 *       left_out      the run was committed without this shift
 *       not_removed   found, but not taken out; DupReason says why
 *   D2  held          holding its chain back: nothing on it is written
 *       released      released with a reason when the run was made
 *       released_since released after the run was made: preview again
 *       withdrawn     released when the run was made, withdrawn since
 *       listed        holding chains back was switched off for this run
 * Result set 2: the shifts behind each candidate, in date and shift order,
 *   one Role each — twin, keyed, corrected — and a Result: nil, short, over.
 *   A twin outside the run's window (the day either side is read for twins)
 *   carries only its variance; OnRun says so.
 * Result set 3: the totals the panel heads with.
 */
CREATE OR ALTER PROCEDURE [agora].[usp_StockRecon_GetDuplicates]
    @BranchId  INT,
    @RunId     INT
AS
BEGIN
    SET NOCOUNT ON;

    /* Counts are floats on the source, so nothing is compared with = 0 —
       the same tolerance the preview uses. */
    DECLARE @eps DECIMAL(18,4) = 0.005;

    IF NOT EXISTS (SELECT 1 FROM agora.StockReconRun WHERE BranchId = @BranchId AND Id = @RunId)
        THROW 51000, 'AGORA:NO_RUN:That run does not exist for this branch.', 1;

    IF OBJECT_ID('tempdb..#case') IS NOT NULL DROP TABLE #case;

    SELECT
        rl.Id                                                               AS LineId,
        rl.DupTier,
        CASE rl.DupTier WHEN 'D1' THEN 'clear' ELSE 'possible' END         AS Grp,
        b.Name                                                              AS BranchName,
        rl.AreaNo,
        rl.AreaDescription,
        rl.StockItemNo,
        rl.ItemDescription,
        rl.SellPrice,
        rl.TransactionDate,
        rl.ShiftNo,
        rl.QtyIssued                                                        AS IssueKeyed,
        rl.DupQty,
        rl.QtyIssuedNew,
        /* The short as the duplicate was judged, and what it is worth. */
        CONVERT(decimal(18,3), rl.QtyClose - (ISNULL(rl.PrevClose, rl.QtyOpen) + rl.QtyIssued - rl.QtyPOS)) AS ShortQty,
        CONVERT(decimal(18,2), (rl.QtyClose - (ISNULL(rl.PrevClose, rl.QtyOpen) + rl.QtyIssued - rl.QtyPOS))
                               * ISNULL(rl.SellPrice, 0))                   AS ShortValue,
        /* What taking the issue out is worth: nobody is charged for it. */
        CONVERT(decimal(18,2), ISNULL(rl.DupQty, rl.QtyIssued) * ISNULL(rl.SellPrice, 0)) AS IssueValue,
        rl.DupTwinDate                                                      AS TwinDate,
        rl.DupTwinShift                                                     AS TwinShift,
        tw.Id                                                               AS TwinLineId,
        tw.DupTier                                                          AS TwinTier,
        /* The twin's variance on the same basis, off the run where the twin is
           on it; the preview's own reading where it is outside the window. */
        CONVERT(decimal(18,3), CASE WHEN tw.Id IS NOT NULL
                                    THEN tw.QtyClose - (ISNULL(tw.PrevClose, tw.QtyOpen) + tw.QtyIssued - tw.QtyPOS)
                                    ELSE rl.DupTwinVar END)                 AS TwinVar,
        rl.DupReason,
        rl.DupHold,
        rl.ChainBlocked,
        rl.WouldAmend,
        rl.Selected,
        rl.CommitState,
        rl.BlockReason,
        r.[Status]                                                          AS RunStatus,
        r.StampMode                                                         AS RunStampMode,
        /* A D1 the commit wrote: when, by whom, and whether it still stands. */
        am.Id                                                               AS AmendmentId,
        am.[State]                                                          AS AmendmentState,
        am.StampMode                                                        AS AmendmentStampMode,
        am.CreatedAt                                                        AS AmendedAt,
        au.UserName                                                         AS AmendedBy,
        am.ReversedAt,
        ru.UserName                                                         AS ReversedBy,
        /* The release a D2 had when the run was made, and the one it has now
           — at the issue the run read. They differ when somebody released or
           withdrew since, which is what decides "preview again". */
        rl.DupReleaseId,
        cur.Id                                                              AS ReleaseId,
        cur.Reason                                                          AS ReleaseReason,
        cur.CreatedAt                                                       AS ReleasedAt,
        cu.UserName                                                         AS ReleasedBy,
        pr.WithdrawnAt,
        wu.UserName                                                         AS WithdrawnBy,
        CASE
            WHEN rl.DupTier = 'D1' THEN
                CASE
                    WHEN am.Id IS NOT NULL AND am.[State] = 'reversed'          THEN 'reversed'
                    WHEN am.Id IS NOT NULL                                     THEN 'removed'
                    WHEN rl.QtyIssuedNew IS NULL                               THEN 'not_removed'
                    WHEN rl.CommitState = 'skipped'                            THEN 'skipped'
                    WHEN r.[Status] IN ('previewed', 'reversed')               THEN 'will_remove'
                    ELSE 'left_out'
                END
            ELSE
                CASE
                    WHEN cur.Id IS NOT NULL AND cur.Id = rl.DupReleaseId       THEN 'released'
                    WHEN cur.Id IS NOT NULL                                    THEN 'released_since'
                    WHEN rl.DupReleaseId IS NOT NULL                           THEN 'withdrawn'
                    WHEN rl.DupHold = 1                                        THEN 'held'
                    ELSE 'listed'
                END
        END                                                                 AS [State],
        /* Kept for the shift rows below. */
        rl.QtyOpen, rl.PrevClose, rl.QtyIssued, rl.QtyPOS, rl.QtyClose,
        tw.QtyOpen    AS TwinQtyOpen,
        tw.PrevClose  AS TwinPrevClose,
        tw.QtyIssued  AS TwinQtyIssued,
        tw.QtyPOS     AS TwinQtyPOS,
        tw.QtyClose   AS TwinQtyClose
    INTO #case
    /* The TABLE, not agora.vw_StockReconRunLine: the view resolves six
       labels against the estate for every row it touches, which is most of
       the cost of this read, and a run made since v1__14a stores them. The
       view fills in only what a run did not store, below. */
    FROM agora.StockReconRunLine rl
    JOIN agora.StockReconRun r
      ON r.BranchId = rl.BranchId AND r.Id = rl.RunId
    LEFT JOIN agora.Branch b
      ON b.BranchId = rl.BranchId
    /* The twin, where it is on this run: same item and area, the shift the
       preview named. */
    OUTER APPLY (SELECT TOP 1 t.Id, t.DupTier, t.QtyOpen, t.PrevClose, t.QtyIssued, t.QtyPOS, t.QtyClose
                   FROM agora.StockReconRunLine t
                  WHERE t.BranchId = rl.BranchId AND t.RunId = rl.RunId
                    AND t.AreaNo = rl.AreaNo AND t.StockItemNo = rl.StockItemNo
                    AND t.TransactionDate = rl.DupTwinDate AND t.ShiftNo = rl.DupTwinShift) tw
    LEFT JOIN agora.StockReconAmendment am
      ON am.BranchId = rl.BranchId AND am.RunId = rl.RunId AND am.RunLineId = rl.Id
     AND am.NewQtyIssued IS NOT NULL
    LEFT JOIN agora.[User] au ON au.Id = am.CreatedBy
    LEFT JOIN agora.[User] ru ON ru.Id = am.ReversedBy
    /* Standing now, at the issue the run read — the same match the preview
       and usp_StockRecon_DrillChain make. */
    OUTER APPLY (SELECT TOP 1 x.Id, x.Reason, x.CreatedAt, x.CreatedBy
                   FROM agora.StockReconDuplicateRelease x
                  WHERE rl.DupTier = 'D2'
                    AND x.BranchId = rl.BranchId AND x.AreaNo = rl.AreaNo AND x.StockItemNo = rl.StockItemNo
                    AND x.TransactionDate = rl.TransactionDate AND x.ShiftNo = rl.ShiftNo
                    AND x.WithdrawnAt IS NULL
                    AND ABS(x.QtyIssued - rl.QtyIssued) <= 0.0005
                  ORDER BY x.Id DESC) cur
    LEFT JOIN agora.[User] cu ON cu.Id = cur.CreatedBy
    LEFT JOIN agora.StockReconDuplicateRelease pr
      ON pr.BranchId = rl.BranchId AND pr.Id = rl.DupReleaseId
    LEFT JOIN agora.[User] wu ON wu.Id = pr.WithdrawnBy
    WHERE rl.BranchId = @BranchId AND rl.RunId = @RunId
      AND rl.DupTier IN ('D1', 'D2');

    /* The labels a run did not store, the way every other reader gets them:
       stored first, the estate second (agora.vw_StockReconRunLine). */
    UPDATE c SET
        c.ItemDescription = v.ItemDescription,
        c.AreaDescription = v.AreaDescription
    FROM #case c
    JOIN agora.vw_StockReconRunLine v
      ON v.BranchId = @BranchId AND v.Id = c.LineId
    WHERE c.ItemDescription IS NULL OR c.AreaDescription IS NULL;

    /* ------------------------------------------------------- 1: candidates */
    SELECT
        LineId, DupTier, Grp, BranchName, AreaNo,
        ISNULL(AreaDescription, N'area ' + CONVERT(nvarchar(10), AreaNo))   AS AreaDescription,
        StockItemNo,
        ISNULL(ItemDescription, StockItemNo)                                AS ItemDescription,
        SellPrice, TransactionDate, ShiftNo, IssueKeyed, DupQty, QtyIssuedNew,
        ShortQty, ShortValue, IssueValue,
        TwinDate, TwinShift, TwinLineId, TwinTier, TwinVar,
        CASE WHEN TwinVar IS NULL               THEN NULL
             WHEN ABS(TwinVar) <= @eps          THEN 'nil'
             WHEN TwinVar < 0                   THEN 'short'
             ELSE 'over' END                                                AS TwinResult,
        DupReason, DupHold, ChainBlocked, WouldAmend, Selected, CommitState, BlockReason,
        RunStatus, RunStampMode,
        AmendmentId, AmendmentState, AmendmentStampMode, AmendedAt, AmendedBy, ReversedAt, ReversedBy,
        DupReleaseId, ReleaseId, ReleaseReason, ReleasedAt, ReleasedBy, WithdrawnAt, WithdrawnBy,
        [State]
    FROM #case
    ORDER BY CASE DupTier WHEN 'D1' THEN 0 ELSE 1 END, TransactionDate, ShiftNo, ItemDescription, LineId;

    /* ------------------------------------------------------ 2: the shifts
       Each candidate's twin, the shift as keyed, and — for a D1 the commit
       takes out or took out — the same shift corrected: the issue at 0, the
       counts and the till exactly as recorded. */
    ;WITH shifts AS (
        SELECT c.LineId, 'twin' AS [Role], 0 AS RoleOrder,
               c.TwinDate AS TransactionDate, c.TwinShift AS ShiftNo,
               CONVERT(bit, CASE WHEN c.TwinLineId IS NULL THEN 0 ELSE 1 END) AS OnRun,
               c.TwinQtyOpen AS OpenCounted,
               ISNULL(c.TwinPrevClose, c.TwinQtyOpen) AS QtyOpen,
               c.TwinQtyIssued AS QtyIssued,
               CONVERT(decimal(18,3), NULL) AS IssuedBefore,
               c.TwinQtyPOS AS QtyPOS,
               c.TwinQtyClose AS QtyClose,
               c.TwinVar AS QtyVar,
               c.SellPrice
        FROM #case c
        WHERE c.TwinDate IS NOT NULL

        UNION ALL

        SELECT c.LineId, 'keyed', 1,
               c.TransactionDate, c.ShiftNo, CONVERT(bit, 1),
               c.QtyOpen,
               ISNULL(c.PrevClose, c.QtyOpen),
               c.QtyIssued,
               NULL,
               c.QtyPOS, c.QtyClose,
               c.ShortQty,
               c.SellPrice
        FROM #case c

        UNION ALL

        SELECT c.LineId, 'corrected', 2,
               c.TransactionDate, c.ShiftNo, CONVERT(bit, 1),
               c.QtyOpen,
               ISNULL(c.PrevClose, c.QtyOpen),
               c.QtyIssuedNew,
               c.QtyIssued,
               c.QtyPOS, c.QtyClose,
               CONVERT(decimal(18,3), c.QtyClose - (ISNULL(c.PrevClose, c.QtyOpen) + c.QtyIssuedNew - c.QtyPOS)),
               c.SellPrice
        FROM #case c
        WHERE c.DupTier = 'D1' AND c.QtyIssuedNew IS NOT NULL
          AND c.[State] IN ('will_remove', 'removed')
    )
    SELECT
        s.LineId,
        ROW_NUMBER() OVER (PARTITION BY s.LineId ORDER BY s.TransactionDate, s.ShiftNo, s.RoleOrder) AS Seq,
        s.[Role],
        s.TransactionDate,
        s.ShiftNo,
        s.OnRun,
        s.OpenCounted,
        s.QtyOpen,
        s.QtyIssued,
        s.IssuedBefore,
        s.QtyPOS,
        CONVERT(decimal(18,3), s.QtyOpen + s.QtyIssued - s.QtyPOS)            AS QtyExpected,
        s.QtyClose,
        s.QtyVar,
        CONVERT(decimal(18,2), s.QtyVar * ISNULL(s.SellPrice, 0))            AS VarValue,
        CASE WHEN s.QtyVar IS NULL         THEN NULL
             WHEN ABS(s.QtyVar) <= @eps    THEN 'nil'
             WHEN s.QtyVar < 0             THEN 'short'
             ELSE 'over' END                                                 AS Result
    FROM shifts s
    ORDER BY s.LineId, Seq;

    /* --------------------------------------------------------- 3: totals */
    SELECT
        SUM(CASE WHEN DupTier = 'D1' THEN 1 ELSE 0 END)                                       AS Clear,
        SUM(CASE WHEN DupTier = 'D1' AND [State] IN ('will_remove', 'removed') THEN 1 ELSE 0 END) AS ClearCorrected,
        CONVERT(decimal(18,2), ISNULL(SUM(CASE WHEN DupTier = 'D1' AND [State] IN ('will_remove', 'removed')
                                               THEN IssueValue END), 0))                      AS ClearValue,
        SUM(CASE WHEN DupTier = 'D2' THEN 1 ELSE 0 END)                                       AS Possible,
        SUM(CASE WHEN DupTier = 'D2' AND [State] = 'held' THEN 1 ELSE 0 END)                   AS PossibleHeld,
        SUM(CASE WHEN DupTier = 'D2' AND [State] IN ('released', 'released_since') THEN 1 ELSE 0 END) AS PossibleReleased,
        /* What the possible ones leave on the shifts as counted: a short, so
           negative, at the sell price. */
        CONVERT(decimal(18,2), ISNULL(SUM(CASE WHEN DupTier = 'D2' THEN ShortValue END), 0))  AS PossibleValue,
        (SELECT COUNT(*) FROM (SELECT DISTINCT AreaNo, StockItemNo FROM #case WHERE DupTier = 'D2' AND DupHold = 1) h) AS ChainsHeld
    FROM #case;

    DROP TABLE #case;
END
