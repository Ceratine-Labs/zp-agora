/*
 * agora.usp_StockRecon_Commit — apply what the operator ticked on one run.
 *
 * Read by:  Stock recon centre -> a run -> the Balance press.
 * Reads:    agora.StockReconRun, agora.StockReconRunLine, agora.vw_StockReconLine,
 *           agora.StockReconDuplicateRelease
 * Writes:   agora.StockReconAmendment, agora.StockReconRunLine, agora.StockReconRun
 *           and — in 'live' stamp mode ONLY — QtyOpen / QtyClose on
 *           [PumpIT].dbo.STK_StockReconLine, and QtyIssued — set to 0 on a
 *           CLEAR DUPLICATE ISSUE (D1) (Ryan's named go-ahead, 1 Oct 2026, PM
 *           decision 01a0f60b-0459), or raised by the over on a shift whose
 *           MISSING ISSUE is captured (Ryan's named go-ahead, 13 Oct 2026:
 *           "customer wants over to balance") — when @IssueMode is 'live' too.
 *
 * @StampMode  'journal' records every amendment here and writes NOTHING to
 *             PumpIT. The ledger is then the worklist an admin applies, and the
 *             evidence for turning live on. It is the shipped setting.
 *             'live'    additionally writes the two counts back.
 * @IssueMode  'live' lets a run that removed a clear duplicate (QtyIssuedNew
 *             on the line) be committed: the issue is recorded with its prior
 *             beside it and, in live stamp mode, written. 'off' refuses such a
 *             run (ISSUE_MODE_OFF) — its counts were balanced with the issue
 *             gone, so they cannot be written without it.
 *
 * Both modes are PASSED IN rather than read here, so there is exactly one place
 * in the codebase — config('stockrecon.stamp_mode') and
 * config('stockrecon.issue_mode') — that decides whether Agora writes to the
 * customer's estate.
 *
 * WHAT MAKES THIS DIFFERENT FROM THE LEGACY. dbo.sp_UpdateAUTOStockReconBalancing
 * opens a cursor and calls sp_UpdateStockReconLine_QtyOpen_xQtyClose per row.
 * That procedure writes the closing on this shift and the opening on the NEXT
 * one, and works out which shift that is from STK_Area's day/afternoon/night
 * flags — an inference that is wrong the moment a shift is missing from the
 * data. Nothing records what was there before, and because the amendments go
 * to the live columns while the balancing reads the _Original ones, re-running
 * compounds them silently.
 *
 * Here every figure was settled by the preview, both columns of every row are
 * named explicitly rather than inferred from a shift table, and the row's PRIOR
 * pair is written to agora.StockReconAmendment before anything moves — which is
 * what makes agora.usp_StockRecon_Reverse exact. The legacy has no reversal at
 * all.
 *
 * IT RE-CHECKS EVERY ROW FIRST. A preview is a photograph; between the press
 * and the read somebody may have counted again, or balanced by hand. Any row
 * whose counts — the pair the preview worked from, or the pair PumpIT held —
 * no longer match what the preview saw is SKIPPED and says so, rather than
 * being overwritten with an amendment derived from figures that have gone.
 *
 * WHAT IT WRITES follows the client's rules (docs/stock-recon.md): openings
 * repaired to the previous closing, closings before a repair and the latest
 * closing left standing, and — because a row is proposed wherever its answer
 * differs from PumpIT — a hand amendment the rules do not produce put back.
 * Every write keeps the row's PRIOR pair, so a reversal restores it exactly.
 *
 * A POSSIBLE DUPLICATE HOLDS ITS CHAIN BACK (Ryan, 5 Oct 2026, PM decision
 * 01a10b21). The preview writes no proposal on a chain an unreleased D2 holds
 * (DupHold), and this checks it AGAIN at the press, against the release
 * ledger and PumpIT as they stand now: a candidate whose chain carries a D2
 * with no standing release for the issue PumpIT holds today is skipped. That
 * covers a release withdrawn since the preview, an issue re-keyed since, and a
 * run previewed while D2 did not block. @BlockPossibleDuplicates is
 * config('stockrecon.exceptions.D2.blocks'), passed in like the modes.
 *
 * @UseOriginalCounts must be the value the PREVIEW ran with, because it decides
 * which pair of columns the re-check compares against. It is replayed from the
 * run's stored parameters by the service, never defaulted at the call site.
 *
 * Result set 1: the status row.
 * Result set 2: one row per candidate, saying what happened to it — so a
 *               screen can explain why four of forty were skipped.
 */
CREATE OR ALTER PROCEDURE [agora].[usp_StockRecon_Commit]
    @RunId             INT,
    @BranchId          INT,
    @StampMode         NVARCHAR(20)  = 'journal',
    @UseOriginalCounts BIT           = 1,
    @IssueMode         NVARCHAR(20)  = 'off',
    /* Defaults ON, so a call that forgets it errs towards writing nothing. */
    @BlockPossibleDuplicates BIT     = 1,
    @UserId            INT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @eps DECIMAL(18,4) = 0.005;

    IF @StampMode NOT IN ('journal', 'live')
        THROW 51000, 'AGORA:BAD_STAMP_MODE:The stamp mode must be journal or live.', 1;

    IF @IssueMode NOT IN ('off', 'live')
        THROW 51000, 'AGORA:BAD_ISSUE_MODE:The issue mode must be off or live.', 1;

    DECLARE @Status NVARCHAR(20) = (
        SELECT [Status] FROM agora.StockReconRun WHERE BranchId = @BranchId AND Id = @RunId);

    IF @Status IS NULL
        THROW 51000, 'AGORA:NO_RUN:That run does not exist for this branch.', 1;

    IF @Status = 'committed'
        THROW 51000, 'AGORA:RUN_COMMITTED:This run has already been committed. Reverse it first.', 1;

    IF @Status NOT IN ('previewed', 'reversed')
        THROW 51000, 'AGORA:RUN_NOT_READY:Only a previewed run can be committed.', 1;

    /* Reject old previews as well: a saved run may predate the shift guard or
       the area's configured shifts may have changed since it was created. */
    IF EXISTS (
        SELECT 1 FROM (
            SELECT rl.AreaNo, rl.StockItemNo, rl.TransactionDate, rl.ShiftNo,
                   LAG(rl.TransactionDate, 1, prior.TransactionDate) OVER (PARTITION BY rl.AreaNo, rl.StockItemNo ORDER BY rl.TransactionDate, rl.ShiftNo) AS PriorDate,
                   LAG(rl.ShiftNo, 1, prior.ShiftNo) OVER (PARTITION BY rl.AreaNo, rl.StockItemNo ORDER BY rl.TransactionDate, rl.ShiftNo) AS PriorShift
            FROM agora.StockReconRunLine rl
            JOIN agora.StockReconRun run ON run.BranchId = rl.BranchId AND run.Id = rl.RunId
            OUTER APPLY (SELECT TOP 1 CONVERT(date, l.TransactionDate) AS TransactionDate, l.ShiftNo
                FROM agora.vw_StockReconLine l
                WHERE l.BranchId = rl.BranchId AND l.AreaNo = rl.AreaNo AND l.StockItemNo = rl.StockItemNo
                  AND l.TransactionDate >= DATEADD(day, -35, run.FromDate) AND l.TransactionDate < run.FromDate
                  AND NOT EXISTS (SELECT 1 FROM agora.StockReconItemRemoval removal WHERE removal.BranchId = l.BranchId
                      AND removal.AreaNo = l.AreaNo AND removal.StockItemNo = l.StockItemNo AND removal.WithdrawnAt IS NULL
                      AND (removal.LastDate > CONVERT(date, l.TransactionDate)
                        OR (removal.LastDate = CONVERT(date, l.TransactionDate) AND removal.LastShift >= l.ShiftNo))
                      AND removal.LastDate < run.FromDate)
                ORDER BY l.TransactionDate DESC, l.ShiftNo DESC) prior
            WHERE rl.BranchId = @BranchId AND rl.RunId = @RunId
        ) r
        JOIN agora.vw_StockArea a ON a.BranchId = @BranchId AND a.AreaNo = r.AreaNo
        CROSS APPLY (
            SELECT TOP 1 s.ShiftNo + CASE WHEN s.ShiftNo <= r.PriorShift THEN 3 ELSE 0 END AS NextOffset
            FROM (VALUES (1, a.DayShift), (2, a.AfternoonShift), (3, a.NightShift)) s(ShiftNo, Enabled)
            WHERE s.Enabled = 1
            ORDER BY s.ShiftNo + CASE WHEN s.ShiftNo <= r.PriorShift THEN 3 ELSE 0 END
        ) expected
        WHERE r.PriorDate IS NOT NULL
          AND expected.NextOffset < DATEDIFF(day, r.PriorDate, r.TransactionDate) * 3 + r.ShiftNo
          AND EXISTS (SELECT 1 FROM agora.StockReconRunLine selected
              WHERE selected.BranchId = @BranchId AND selected.RunId = @RunId
                AND selected.AreaNo = r.AreaNo AND selected.StockItemNo = r.StockItemNo
                AND selected.Selected = 1 AND selected.WouldAmend = 1
                AND selected.CommitState IN ('pending', 'skipped'))
    ) THROW 51000, 'AGORA:MISSING_SHIFT:A required shift is missing from this item chain. Correct it and preview again.', 1;

    /* Recheck tail gaps against live area activity and removal records. */
    IF EXISTS (
        SELECT 1 FROM (
            SELECT rl.*, ROW_NUMBER() OVER (PARTITION BY rl.AreaNo, rl.StockItemNo
                ORDER BY rl.TransactionDate DESC, rl.ShiftNo DESC) AS rn
            FROM agora.StockReconRunLine rl WHERE rl.BranchId = @BranchId AND rl.RunId = @RunId
        ) r
        JOIN agora.StockReconRun run ON run.BranchId = r.BranchId AND run.Id = r.RunId
        JOIN agora.vw_StockArea a ON a.BranchId = @BranchId AND a.AreaNo = r.AreaNo
        CROSS APPLY (
            SELECT TOP 1 s.ShiftNo + CASE WHEN s.ShiftNo <= r.ShiftNo THEN 3 ELSE 0 END AS NextOffset
            FROM (VALUES (1, a.DayShift), (2, a.AfternoonShift), (3, a.NightShift)) s(ShiftNo, Enabled)
            WHERE s.Enabled = 1
            ORDER BY s.ShiftNo + CASE WHEN s.ShiftNo <= r.ShiftNo THEN 3 ELSE 0 END
        ) expected
        WHERE r.rn = 1
          AND (EXISTS (SELECT 1 FROM agora.vw_StockReconLine later WHERE later.BranchId = @BranchId
              AND later.AreaNo = r.AreaNo AND later.TransactionDate < DATEADD(day, 1, run.ToDate)
              AND NOT EXISTS (SELECT 1 FROM agora.StockReconMeter meter WHERE meter.BranchId = later.BranchId
                  AND meter.AreaNo = later.AreaNo AND meter.StockItemNo = later.StockItemNo AND meter.IsActive = 1)
              AND DATEDIFF(day, r.TransactionDate, later.TransactionDate) * 3 + later.ShiftNo >= expected.NextOffset)
            OR EXISTS (SELECT 1 FROM agora.StockReconAreaShiftCompletion done WHERE done.BranchId = @BranchId
              AND done.AreaNo = r.AreaNo AND done.WithdrawnAt IS NULL AND done.TransactionDate <= run.ToDate
              AND DATEDIFF(day, r.TransactionDate, done.TransactionDate) * 3 + done.ShiftNo >= expected.NextOffset))
          AND NOT EXISTS (SELECT 1 FROM agora.StockReconItemRemoval removal
              WHERE removal.BranchId = @BranchId AND removal.AreaNo = r.AreaNo AND removal.StockItemNo = r.StockItemNo
                AND removal.WithdrawnAt IS NULL AND removal.LastDate = r.TransactionDate AND removal.LastShift = r.ShiftNo)
          AND EXISTS (SELECT 1 FROM agora.StockReconRunLine selected
              WHERE selected.BranchId = @BranchId AND selected.RunId = @RunId
                AND selected.AreaNo = r.AreaNo AND selected.StockItemNo = r.StockItemNo
                AND selected.Selected = 1 AND selected.WouldAmend = 1 AND selected.CommitState IN ('pending', 'skipped'))
    ) THROW 51000, 'AGORA:MISSING_SHIFT:A required continuation is missing. Correct it or record removal and preview again.', 1;

    /* Completed shifts also require items absent for the whole window. */
    IF EXISTS (
        SELECT 1 FROM (
            SELECT l.BranchId, l.AreaNo, l.StockItemNo, CONVERT(date, l.TransactionDate) AS LastDate, l.ShiftNo AS LastShift,
                   ROW_NUMBER() OVER (PARTITION BY l.AreaNo, l.StockItemNo ORDER BY l.TransactionDate DESC, l.ShiftNo DESC) AS rn
            FROM agora.vw_StockReconLine l
            JOIN agora.StockReconRun run ON run.BranchId = l.BranchId AND run.Id = @RunId
            WHERE l.BranchId = @BranchId AND l.TransactionDate >= DATEADD(day, -35, run.FromDate) AND l.TransactionDate < run.FromDate
              AND EXISTS (SELECT 1 FROM agora.StockReconRunLine selected WHERE selected.BranchId = @BranchId AND selected.RunId = @RunId
                  AND selected.AreaNo = l.AreaNo AND selected.Selected = 1 AND selected.WouldAmend = 1 AND selected.CommitState IN ('pending', 'skipped'))
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

    /* A non-amended intermediate row can disappear after preview too. The
       candidate-only source check below would not see that broken chain. */
    IF EXISTS (
        SELECT 1 FROM agora.StockReconRunLine r
        WHERE r.BranchId = @BranchId AND r.RunId = @RunId
          AND NOT EXISTS (SELECT 1 FROM agora.vw_StockReconLine l
              WHERE l.BranchId = r.BranchId AND l.AreaNo = r.AreaNo AND l.StockItemNo = r.StockItemNo
                AND CONVERT(date, l.TransactionDate) = r.TransactionDate AND l.ShiftNo = r.ShiftNo)
          AND EXISTS (SELECT 1 FROM agora.StockReconRunLine selected
              WHERE selected.BranchId = r.BranchId AND selected.RunId = r.RunId
                AND selected.AreaNo = r.AreaNo AND selected.StockItemNo = r.StockItemNo
                AND selected.Selected = 1 AND selected.WouldAmend = 1
                AND selected.CommitState IN ('pending', 'skipped'))
    ) THROW 51000, 'AGORA:MISSING_SHIFT:A shift in this item chain no longer exists. Correct it and preview again.', 1;

    /* Authorizations can be withdrawn or a source capture can be changed
       after preview. Never commit counts or issues against an old decision. */
    IF EXISTS (
        SELECT 1 FROM agora.StockReconRunLine rl
        LEFT JOIN agora.vw_StockWasteLine w ON w.BranchId = rl.BranchId AND CONVERT(date, w.TransactionDate) = rl.TransactionDate
          AND w.ShiftNo = rl.ShiftNo AND w.AreaNo = rl.AreaNo AND w.StockItemNo = rl.StockItemNo
        OUTER APPLY (SELECT TOP 1 z.Id, z.IsAuthorized, z.CapturedGood, z.CapturedAt
            FROM agora.StockWasteAuthorization z
            WHERE z.BranchId = rl.BranchId AND z.TransactionDate = rl.TransactionDate
              AND z.ShiftNo = rl.ShiftNo AND z.AreaNo = rl.AreaNo AND z.StockItemNo = rl.StockItemNo
            ORDER BY z.Id DESC) wa
        WHERE rl.BranchId = @BranchId AND rl.RunId = @RunId
          AND ISNULL(rl.WasteAuthorizationId, 0) <> ISNULL(CASE WHEN wa.IsAuthorized = 1
              AND wa.CapturedGood = CONVERT(decimal(18,4), ISNULL(w.QtyGoodWaste, 0))
              AND (wa.CapturedAt = w.CreateDateTime OR (wa.CapturedAt IS NULL AND w.CreateDateTime IS NULL))
              THEN wa.Id END, 0)
          AND EXISTS (SELECT 1 FROM agora.StockReconRunLine selected
              WHERE selected.BranchId = rl.BranchId AND selected.RunId = rl.RunId
                AND selected.AreaNo = rl.AreaNo AND selected.StockItemNo = rl.StockItemNo
                AND selected.Selected = 1 AND selected.WouldAmend = 1
                AND selected.CommitState IN ('pending', 'skipped'))
    ) THROW 51000, 'AGORA:WASTE_CHANGED:Waste authorization changed since this preview. Preview again.', 1;

    /* An older preview can have captured an issue from an opening correction
       while the preceding shift remained short. Re-preview instead of writing
       that stale proposal. */
    IF EXISTS (
        SELECT 1 FROM (
            SELECT rl.*,
                   LAG(rl.QtyVar + rl.ChainBreak) OVER (PARTITION BY rl.AreaNo, rl.StockItemNo ORDER BY rl.TransactionDate, rl.ShiftNo) AS PriorVariance
            FROM agora.StockReconRunLine rl WHERE rl.BranchId = @BranchId AND rl.RunId = @RunId
        ) r
        WHERE r.ChainBreak > @eps AND r.PriorVariance < -@eps AND r.QtyIssuedNew > r.QtyIssued + @eps
          AND EXISTS (SELECT 1 FROM agora.StockReconRunLine selected
              WHERE selected.BranchId = @BranchId AND selected.RunId = @RunId
                AND selected.AreaNo = r.AreaNo AND selected.StockItemNo = r.StockItemNo
                AND selected.Selected = 1 AND selected.WouldAmend = 1
                AND selected.CommitState IN ('pending', 'skipped'))
    ) THROW 51000, 'AGORA:STALE_OPENING_ISSUE:This saved run may issue stock before offsetting a previous short. Preview again.', 1;

    /* Preserve counted shorts except for the opening-break offset described
       above. Check all rows of a selected chain, not just the ticked rows. */
    IF EXISTS (
        SELECT 1 FROM agora.StockReconRunLine changed
        WHERE changed.BranchId = @BranchId AND changed.RunId = @RunId
          AND ((changed.QtyVar < -0.0005 AND ABS(changed.QtyVarNew -
                (changed.QtyVar - (COALESCE(changed.QtyIssuedNew, changed.QtyIssued) - changed.QtyIssued)
                  + ISNULL(changed.AppliedGoodWaste, 0))) > 0.0005)
               OR (changed.QtyVar >= -0.0005 AND changed.QtyVarNew < -0.0005))
          AND EXISTS (SELECT 1 FROM agora.StockReconRunLine selected
              WHERE selected.BranchId = changed.BranchId AND selected.RunId = changed.RunId
                AND selected.AreaNo = changed.AreaNo AND selected.StockItemNo = changed.StockItemNo
                AND selected.Selected = 1 AND selected.WouldAmend = 1
                AND selected.CommitState IN ('pending', 'skipped'))
          AND NOT EXISTS (
              SELECT 1 FROM (
                  SELECT x.AreaNo, x.StockItemNo, x.TransactionDate, x.ShiftNo, x.ChainBreak, x.QtyVar,
                         LAG(x.QtyVar + x.ChainBreak) OVER (PARTITION BY x.AreaNo, x.StockItemNo ORDER BY x.TransactionDate, x.ShiftNo) AS PriorVariance,
                         LAG(x.TransactionDate) OVER (PARTITION BY x.AreaNo, x.StockItemNo ORDER BY x.TransactionDate, x.ShiftNo) AS PriorDate,
                         LAG(x.ShiftNo) OVER (PARTITION BY x.AreaNo, x.StockItemNo ORDER BY x.TransactionDate, x.ShiftNo) AS PriorShift
                  FROM agora.StockReconRunLine x WHERE x.BranchId = @BranchId AND x.RunId = @RunId
              ) o WHERE o.AreaNo = changed.AreaNo AND o.StockItemNo = changed.StockItemNo
                  AND o.ChainBreak > @eps AND o.PriorVariance < -@eps AND o.QtyVar + o.ChainBreak > @eps
                  AND ((changed.TransactionDate = o.TransactionDate AND changed.ShiftNo = o.ShiftNo)
                    OR (changed.TransactionDate = o.PriorDate AND changed.ShiftNo = o.PriorShift))
          )
    ) THROW 51000, 'AGORA:SHORT_MOVED:This saved run moves a shift shortage. Preview again and review the chain.', 1;

    /* Old saved previews may propose a boundary issue. Re-preview the whole
       run so counts are never committed using an issue we no longer capture. */
    IF EXISTS (
        SELECT 1 FROM agora.StockReconRunLine rl
        WHERE rl.BranchId = @BranchId AND rl.RunId = @RunId
          AND rl.Selected = 1 AND rl.WouldAmend = 1 AND rl.CommitState IN ('pending', 'skipped')
          AND (rl.QtyIssuedNew > rl.QtyIssued OR (rl.QtyIssuedNew < rl.QtyIssued AND ISNULL(rl.DupTier, '') <> 'D1'))
          AND (NOT EXISTS (SELECT 1 FROM agora.StockReconRunLine p
               WHERE p.BranchId = rl.BranchId AND p.RunId = rl.RunId
                 AND p.AreaNo = rl.AreaNo AND p.StockItemNo = rl.StockItemNo
                 AND (p.TransactionDate < rl.TransactionDate OR (p.TransactionDate = rl.TransactionDate AND p.ShiftNo < rl.ShiftNo)))
            OR NOT EXISTS (SELECT 1 FROM agora.StockReconRunLine n
               WHERE n.BranchId = rl.BranchId AND n.RunId = rl.RunId
                 AND n.AreaNo = rl.AreaNo AND n.StockItemNo = rl.StockItemNo
                 AND (n.TransactionDate > rl.TransactionDate OR (n.TransactionDate = rl.TransactionDate AND n.ShiftNo > rl.ShiftNo))))
    ) THROW 51000, 'AGORA:BOUNDARY_ISSUE:This saved run proposes an issue on the first or last shift. Preview again.', 1;

    IF EXISTS (SELECT 1 FROM agora.StockReconRunLine rl
        OUTER APPLY (SELECT TOP 1 x.IssueMultiple FROM agora.vw_StockItem x
            WHERE x.BranchId = rl.BranchId AND x.StockItemNo = rl.StockItemNo
            ORDER BY CASE WHEN x.AreaNo = rl.AreaNo THEN 0 ELSE 1 END) si
        OUTER APPLY (SELECT TOP 1 x.IssueMultiple FROM agora.StockIssueMultiple x
            WHERE x.BranchId = rl.BranchId AND x.AreaNo = rl.AreaNo AND x.StockItemNo = rl.StockItemNo) im
        WHERE rl.BranchId = @BranchId AND rl.RunId = @RunId
          AND rl.Selected = 1 AND rl.WouldAmend = 1 AND rl.CommitState IN ('pending', 'skipped')
          AND (rl.QtyIssuedNew > rl.QtyIssued OR (rl.QtyIssuedNew < rl.QtyIssued AND ISNULL(rl.DupTier, '') <> 'D1'))
          AND (rl.QtyIssuedNew < 0 OR ISNULL(rl.IssueMultiple, 0) <= 1 OR
               ABS(ISNULL(rl.IssueMultiple, 0) - COALESCE(si.IssueMultiple, im.IssueMultiple, 0)) > 0.0005 OR
               ABS((rl.QtyIssuedNew - rl.QtyIssued) - ROUND((rl.QtyIssuedNew - rl.QtyIssued) / NULLIF(rl.IssueMultiple, 0), 0) * rl.IssueMultiple) > 0.0005))
        THROW 51000, 'AGORA:ISSUE_MULTIPLE:This saved run proposes an issue outside its issue multiple. Preview again.', 1;

    /* A saved preview predating the explicit measurement mapping may still
       contain meter proposals. Refuse BEFORE any run/source mutation. A new
       preview excludes those keys; reversal of historic amendments is separate. */
    IF EXISTS (SELECT 1 FROM agora.StockReconRunLine rl
        JOIN agora.StockReconMeter meter ON meter.BranchId = rl.BranchId AND meter.AreaNo = rl.AreaNo
            AND meter.StockItemNo = rl.StockItemNo AND meter.IsActive = 1
        WHERE rl.BranchId = @BranchId AND rl.RunId = @RunId)
        THROW 51000, 'AGORA:CUMULATIVE_METER:This saved run includes cumulative water-meter readings. Preview again; review these readings in Water meter reconciliation.', 1;

    /* A SKIP IS THE OUTCOME OF THE LAST ATTEMPT, NOT A PERMANENT STATE.

       Everything that skips a row is about the world as it stood a moment ago
       — the counts were re-taken, another run is holding an amendment on this
       shift. Reverse that run, or re-preview, and the row becomes writable
       again. Leaving it marked 'skipped' means the second press finds no
       candidates at all and refuses with NOTHING_SELECTED, which reads as
       "you ticked nothing" about a screen full of ticks. So the slate is
       cleared first and the re-check decides again. */
    UPDATE agora.StockReconRunLine
       SET CommitState = 'pending', BlockReason = NULL
    WHERE BranchId = @BranchId AND RunId = @RunId AND CommitState = 'skipped';

    /* The candidates: ticked, actually moves something, and not already dealt
       with. A tick on a row that does not move is not an error and is not a
       candidate — the preview only ever writes one on a row that does. */
    IF OBJECT_ID('tempdb..#cand') IS NOT NULL DROP TABLE #cand;

    SELECT
        rl.Id, rl.[LineNo], rl.AreaNo, rl.StockItemNo, rl.TransactionDate, rl.ShiftNo,
        rl.QtyOpen, rl.QtyClose, rl.QtyOpenNew, rl.QtyCloseNew,
        rl.QtyOpenNow, rl.QtyCloseNow,
        /* The issue as the preview read it, and what it becomes: 0 on a clear
           duplicate (D1), NULL where the issue is not touched. */
        rl.QtyIssued, rl.QtyIssuedNew,
        rl.CountsMove,
        CONVERT(nvarchar(200), NULL) AS SkipReason
    INTO #cand
    FROM agora.StockReconRunLine rl
    WHERE rl.BranchId    = @BranchId
      AND rl.RunId       = @RunId
      AND rl.Selected    = 1
      AND rl.WouldAmend  = 1
      /* A blocked chain is written only to REVERSE a hand amendment the
         client's rules forbid, back to the staff counts — never balanced
         (usp_StockRecon_PreviewBalancing, stage 7d) — or to take out a clear
         duplicate issue on its own, the counts untouched (stage 7f). */
      AND (rl.ChainBlocked = 0 OR rl.RestoresHand = 1 OR rl.QtyIssuedNew IS NOT NULL)
      /* And never on a chain a possible duplicate holds back: the preview
         proposes nothing there, and this says so again. */
      AND rl.DupHold = 0
      AND rl.CommitState = 'pending';

    IF NOT EXISTS (SELECT 1 FROM #cand)
        THROW 51000, 'AGORA:NOTHING_SELECTED:Nothing on this run is ticked and amendable.', 1;

    /* A run that removed a duplicate balanced its counts with the issue gone.
       With the issue write switched off since, those counts are not the
       answer any more — re-preview rather than write half of it. */
    IF @IssueMode <> 'live' AND EXISTS (SELECT 1 FROM #cand WHERE QtyIssuedNew IS NOT NULL)
        THROW 51000, 'AGORA:ISSUE_MODE_OFF:This run writes an issue (a duplicate removed or a missing issue captured), and writing issues is switched off. Preview again.', 1;

    /* ---------------------------------------------------------- the re-check
       The source row still has to be there, and still has to hold the counts
       the preview read. Compared against the _Original pair or the live pair
       depending on how the preview was run — the same choice, replayed. */
    UPDATE c SET SkipReason = 'The recon line is no longer in the source for this shift.'
    FROM #cand c
    WHERE NOT EXISTS (
        SELECT 1 FROM agora.vw_StockReconLine l
        WHERE l.BranchId = @BranchId
          AND CONVERT(date, l.TransactionDate) = c.TransactionDate
          AND l.ShiftNo = c.ShiftNo AND l.AreaNo = c.AreaNo AND l.StockItemNo = c.StockItemNo);

    UPDATE c SET SkipReason = 'The counts on this shift have changed since the preview.'
    FROM #cand c
    WHERE c.SkipReason IS NULL
      AND EXISTS (
        SELECT 1 FROM agora.vw_StockReconLine l
        WHERE l.BranchId = @BranchId
          AND CONVERT(date, l.TransactionDate) = c.TransactionDate
          AND l.ShiftNo = c.ShiftNo AND l.AreaNo = c.AreaNo AND l.StockItemNo = c.StockItemNo
          AND (ABS(CONVERT(decimal(18,4), ISNULL(CASE WHEN @UseOriginalCounts = 1
                        THEN l.QtyOpen_Original  ELSE l.QtyOpen  END, 0)) - c.QtyOpen)  > @eps
            OR ABS(CONVERT(decimal(18,4), ISNULL(CASE WHEN @UseOriginalCounts = 1
                        THEN l.QtyClose_Original ELSE l.QtyClose END, 0)) - c.QtyClose) > @eps));

    /* AND THE LIVE PAIR. Since v1__14pf a row is proposed because its answer
       differs from what PumpIT holds NOW, which is what lets a commit put back
       a hand amendment the rules do not produce. So a hand edit made between
       the preview and the press is a different starting point, and the row
       is re-previewed rather than written over it. A run made before v1__14pf
       recorded no live pair and is checked as it always was. */
    UPDATE c SET SkipReason = 'The counts on this shift were edited in PumpIT since the preview.'
    FROM #cand c
    WHERE c.SkipReason IS NULL
      AND c.QtyOpenNow IS NOT NULL
      AND EXISTS (
        SELECT 1 FROM agora.vw_StockReconLine l
        WHERE l.BranchId = @BranchId
          AND CONVERT(date, l.TransactionDate) = c.TransactionDate
          AND l.ShiftNo = c.ShiftNo AND l.AreaNo = c.AreaNo AND l.StockItemNo = c.StockItemNo
          AND (ABS(CONVERT(decimal(18,4), ISNULL(l.QtyOpen,  0)) - c.QtyOpenNow)  > @eps
            OR ABS(CONVERT(decimal(18,4), ISNULL(l.QtyClose, 0)) - c.QtyCloseNow) > @eps));

    /* This shift already carries an amendment nobody has reversed. Two live
       amendments on one row means two "prior" values and no way to know which
       is the real one, so the second is refused rather than stacked. */
    UPDATE c SET SkipReason = 'This shift already carries an amendment from another run. Reverse that first.'
    FROM #cand c
    WHERE c.SkipReason IS NULL
      AND EXISTS (
        SELECT 1 FROM agora.StockReconAmendment a
        WHERE a.BranchId = @BranchId AND a.[State] = 'active'
          AND a.AreaNo = c.AreaNo AND a.StockItemNo = c.StockItemNo
          AND a.TransactionDate = c.TransactionDate AND a.ShiftNo = c.ShiftNo
          /* A RESET TO THE ORIGINALS is the starting point this balancing was
             previewed from, not a competing amendment (7 Oct 2026). Stacking is
             safe: the reset's Reverse refuses (COUNT_MOVED) while this commit's
             counts stand, so the two unwind in order. */
          AND NOT EXISTS (SELECT 1 FROM agora.StockReconRun x
                          WHERE x.BranchId = a.BranchId AND x.Id = a.RunId AND x.CommitKind = 'reset'));

    /* A duplicate is written as 0 because the issue the preview read was
       keyed twice, and a missing issue as that issue plus the over. If the
       issue has been changed since, that is no longer what is there. */
    UPDATE c SET SkipReason = 'The issue on this shift has changed since the preview.'
    FROM #cand c
    WHERE c.SkipReason IS NULL
      AND c.QtyIssuedNew IS NOT NULL
      AND EXISTS (
        SELECT 1 FROM agora.vw_StockReconLine l
        WHERE l.BranchId = @BranchId
          AND CONVERT(date, l.TransactionDate) = c.TransactionDate
          AND l.ShiftNo = c.ShiftNo AND l.AreaNo = c.AreaNo AND l.StockItemNo = c.StockItemNo
          AND ABS(CONVERT(decimal(18,4), ISNULL(l.QtyIssued, 0)) - c.QtyIssued) > @eps);

    /* A POSSIBLE DUPLICATE STILL HOLDS ITS CHAIN BACK. Every D2 the run found
       on the candidate's chain must have a release standing NOW, at the issue
       the run read and the issue PumpIT holds today. A release withdrawn since
       the preview, an issue re-keyed since, or a run previewed while D2 did
       not block: the whole chain is skipped, and preview again. Before the
       chain-wide skip below, so a duplicate this leaves unwritten makes the
       rest of its chain wait. */
    IF @BlockPossibleDuplicates = 1
        UPDATE c SET SkipReason = N'This item carries a possible duplicate issue (D2) with no release standing for it. '
                                + N'Correct it in PumpIT or release it with a reason, then preview again.'
        FROM #cand c
        WHERE c.SkipReason IS NULL
          AND EXISTS (
            SELECT 1 FROM agora.StockReconRunLine d
            WHERE d.BranchId = @BranchId AND d.RunId = @RunId
              AND d.AreaNo = c.AreaNo AND d.StockItemNo = c.StockItemNo
              AND d.DupTier = 'D2'
              AND NOT EXISTS (
                  SELECT 1
                  FROM agora.StockReconDuplicateRelease x
                  JOIN agora.vw_StockReconLine l
                    ON l.BranchId = x.BranchId
                   AND CONVERT(date, l.TransactionDate) = x.TransactionDate
                   AND l.ShiftNo = x.ShiftNo AND l.AreaNo = x.AreaNo AND l.StockItemNo = x.StockItemNo
                  WHERE x.BranchId = @BranchId
                    AND x.AreaNo = d.AreaNo AND x.StockItemNo = d.StockItemNo
                    AND x.TransactionDate = d.TransactionDate AND x.ShiftNo = d.ShiftNo
                    AND x.WithdrawnAt IS NULL
                    AND ABS(x.QtyIssued - d.QtyIssued) <= 0.0005
                    AND ABS(CONVERT(decimal(18,4), ISNULL(l.QtyIssued, 0)) - x.QtyIssued) <= 0.0005));

    /* A CHAIN BALANCED WITH A DUPLICATE TAKEN OUT IS WRITTEN WITH IT. Its
       counts were worked out with that issue gone; writing them while the
       issue stays turns the duplicate's shift short again under counts that
       were moved on the assumption it was not — the pack's S8 on a shift that
       has none today. So when that shift is not being written now (not
       ticked, or skipped above) and was not written before, the rest of its
       chain waits for it. */
    UPDATE c SET SkipReason = 'This item''s chain removes a duplicate issue on another shift. Write that shift with it.'
    FROM #cand c
    WHERE c.SkipReason IS NULL
      AND EXISTS (
        SELECT 1 FROM agora.StockReconRunLine d
        WHERE d.BranchId = @BranchId AND d.RunId = @RunId
          AND d.AreaNo = c.AreaNo AND d.StockItemNo = c.StockItemNo
          AND d.Id <> c.Id
          AND d.QtyIssuedNew IS NOT NULL
          /* A DUPLICATE only: the chain's counts were balanced with it gone. A
             captured missing issue changes no count, so its chain does not
             wait for it. */
          AND d.DupTier = 'D1'
          AND (d.ChainBlocked = 0 OR d.RestoresHand = 1)
          AND d.CommitState <> 'committed'
          AND NOT EXISTS (SELECT 1 FROM #cand x WHERE x.Id = d.Id AND x.SkipReason IS NULL));

    /* Counts may be balanced around a proposed missing issue. Never write
       only part of such a chain, including when a source re-check failed. */
    UPDATE c SET SkipReason = 'This chain includes a missing issue. Select every amendable shift and preview again if any shift has changed.'
    FROM #cand c
    WHERE c.SkipReason IS NULL
      AND EXISTS (SELECT 1 FROM agora.StockReconRunLine i
          WHERE i.BranchId = @BranchId AND i.RunId = @RunId AND i.AreaNo = c.AreaNo AND i.StockItemNo = c.StockItemNo
            AND i.QtyIssuedNew IS NOT NULL AND ISNULL(i.DupTier, '') <> 'D1')
      AND EXISTS (SELECT 1 FROM agora.StockReconRunLine d
          WHERE d.BranchId = @BranchId AND d.RunId = @RunId AND d.AreaNo = c.AreaNo AND d.StockItemNo = c.StockItemNo
            AND d.WouldAmend = 1 AND d.CommitState <> 'committed'
            AND NOT EXISTS (SELECT 1 FROM #cand x WHERE x.Id = d.Id AND x.SkipReason IS NULL));

    /* ------------------------------------------------------- ONE TRANSACTION
       Everything below writes, and from here on it is all-or-nothing.

       This matters more since live writes were enabled (8 Sep 2026), because
       the work now spans TWO DATABASES: the amendment record lands in Agora
       and the counts land in PumpIT. Without a transaction those are separate
       autocommits, so a failure on the second — a deadlock, a row lock, a
       permission — leaves agora.StockReconAmendment claiming a prior pair for
       counts that never moved. A reversal would then write those "prior"
       values over counts nobody had touched, which is worse than the failure
       it was recovering from.

       XACT_ABORT is on, so any error dooms the transaction and the CATCH
       rolls the whole thing back and re-raises with the code intact. */
    BEGIN TRY
    BEGIN TRANSACTION;

    /* --------------------------------------------------------- the amendment
       Written in BOTH modes. In journal mode this IS the output: the list an
       admin works, with the prior pair beside the new one. */
    INSERT INTO agora.StockReconAmendment (
        BranchId, RunId, RunLineId, AreaNo, StockItemNo, TransactionDate, ShiftNo,
        PriorQtyOpen, PriorQtyClose, NewQtyOpen, NewQtyClose,
        PriorQtyIssued, NewQtyIssued, StampMode, [State],
        CreatedAt, CreatedBy)
    SELECT
        @BranchId, @RunId, c.Id, c.AreaNo, c.StockItemNo, c.TransactionDate, c.ShiftNo,
        /* The PRIOR pair is what is in the source RIGHT NOW — the live columns,
           never the _Original ones, because those are not what a reversal has
           to put back. */
        CONVERT(decimal(18,3), ISNULL(l.QtyOpen, 0)),
        CONVERT(decimal(18,3), ISNULL(l.QtyClose, 0)),
        /* A duplicate taken out on a chain Agora does not otherwise write
           leaves the counts where they are: new = prior. */
        CASE WHEN c.CountsMove = 1 THEN c.QtyOpenNew  ELSE CONVERT(decimal(18,3), ISNULL(l.QtyOpen, 0))  END,
        CASE WHEN c.CountsMove = 1 THEN c.QtyCloseNew ELSE CONVERT(decimal(18,3), ISNULL(l.QtyClose, 0)) END,
        /* The issue, only where it is written: what is there now, and 0. */
        CASE WHEN c.QtyIssuedNew IS NOT NULL THEN CONVERT(decimal(18,3), ISNULL(l.QtyIssued, 0)) END,
        c.QtyIssuedNew,
        @StampMode, 'active',
        SYSUTCDATETIME(), @UserId
    FROM #cand c
    JOIN agora.vw_StockReconLine l
      ON l.BranchId = @BranchId
     AND CONVERT(date, l.TransactionDate) = c.TransactionDate
     AND l.ShiftNo = c.ShiftNo AND l.AreaNo = c.AreaNo AND l.StockItemNo = c.StockItemNo
    WHERE c.SkipReason IS NULL;

    /* ------------------------------------------------------------- the write
       Live mode only, and both columns of the row are named. Balancing amends
       counts; amending an issue changes T itself, which is capturing a missing
       issue, a different act that has to stay visible as one. So QtyIssued is
       written in TWO cases only: a clear duplicate (D1), set to 0 — the issue
       was keyed twice and the count proves the second never arrived — and a
       missing issue captured (13 Oct 2026), raised by the over the shift sold
       or held without it. Each prior is on the amendment above, so
       usp_StockRecon_Reverse puts it back, and the audit trail names which it
       was. @IssueMode is 'live' here or the run was refused above. */
    IF @StampMode = 'live'
    BEGIN
        UPDATE l
           SET l.QtyOpen   = CASE WHEN c.CountsMove = 1 THEN c.QtyOpenNew  ELSE l.QtyOpen  END,
               l.QtyClose  = CASE WHEN c.CountsMove = 1 THEN c.QtyCloseNew ELSE l.QtyClose END,
               l.QtyIssued = CASE WHEN c.QtyIssuedNew IS NOT NULL THEN c.QtyIssuedNew ELSE l.QtyIssued END
        FROM [PumpIT].dbo.STK_StockReconLine l
        JOIN #cand c
          ON l.SSBranchId = @BranchId
         AND CONVERT(date, l.TransactionDate) = c.TransactionDate
         AND l.ShiftNo = c.ShiftNo AND l.AreaNo = c.AreaNo AND l.StockItemNo = c.StockItemNo
        WHERE c.SkipReason IS NULL;
    END

    /* --------------------------------------------------------- the run lines */
    UPDATE rl
       SET rl.CommitState = 'committed',
           rl.BlockReason = NULL,
           rl.UpdatedAt   = SYSUTCDATETIME(),
           rl.UpdatedBy   = @UserId
    FROM agora.StockReconRunLine rl
    JOIN #cand c ON c.Id = rl.Id
    WHERE rl.BranchId = @BranchId AND c.SkipReason IS NULL;

    UPDATE rl
       SET rl.CommitState = 'skipped',
           rl.BlockReason = c.SkipReason,
           rl.UpdatedAt   = SYSUTCDATETIME(),
           rl.UpdatedBy   = @UserId
    FROM agora.StockReconRunLine rl
    JOIN #cand c ON c.Id = rl.Id
    WHERE rl.BranchId = @BranchId AND c.SkipReason IS NOT NULL;

    DECLARE @Written INT = (SELECT COUNT(*) FROM #cand WHERE SkipReason IS NULL),
            @Skipped INT = (SELECT COUNT(*) FROM #cand WHERE SkipReason IS NOT NULL),
            @Issues  INT = (SELECT COUNT(*) FROM #cand c JOIN agora.StockReconRunLine rl ON rl.Id = c.Id AND rl.BranchId = @BranchId
                            WHERE c.SkipReason IS NULL AND c.QtyIssuedNew < c.QtyIssued AND rl.DupTier = 'D1'),
            @Reduced INT = (SELECT COUNT(*) FROM #cand c JOIN agora.StockReconRunLine rl ON rl.Id = c.Id AND rl.BranchId = @BranchId
                            WHERE c.SkipReason IS NULL AND c.QtyIssuedNew < c.QtyIssued AND ISNULL(rl.DupTier, '') <> 'D1'),
            @Captured INT = (SELECT COUNT(*) FROM #cand WHERE SkipReason IS NULL AND QtyIssuedNew IS NOT NULL
                                                          AND QtyIssuedNew > QtyIssued);

    DECLARE @Units DECIMAL(18,3) = (
        SELECT ISNULL(SUM(ABS(rl.AmendClose)), 0)
        FROM agora.StockReconRunLine rl
        JOIN #cand c ON c.Id = rl.Id
        WHERE rl.BranchId = @BranchId AND c.SkipReason IS NULL);

    /* ------------------------------------------------------------ the header
       'committed' when SOMETHING was written, even if other rows were skipped:
       the run HAS been acted on, and the skipped rows say so individually. A
       run that reported itself as still previewed after writing thirty
       amendments would invite a second press.

       BUT NOT WHEN NOTHING WAS WRITTEN. A run marked committed with zero rows
       and zero amendments is the worst of both readings — it cannot be pressed
       again (the guard above refuses a committed run) and there is nothing to
       reverse, so the work is simply stranded. It stays previewed, the caller
       gets ALL_SKIPPED, and the skipped rows carry the reason. Found on the
       first real double-press, 8 September 2026: run 9 sat committed with
       nothing written because run 8 already held the amendments. */
    IF @Written > 0
    BEGIN
        UPDATE agora.StockReconRun
           SET [Status]       = 'committed',
               CommitKind     = NULL,
               StampMode      = @StampMode,
               CommittedAt    = SYSUTCDATETIME(),
               CommittedBy    = @UserId,
               CommittedRows  = @Written,
               CommittedUnits = @Units,
               UpdatedAt      = SYSUTCDATETIME(),
               UpdatedBy      = @UserId
        WHERE BranchId = @BranchId AND Id = @RunId;
    END

    COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;

        /* Re-raised rather than swallowed, and with the original text, so a
           refusal keeps its AGORA:{Code} shape and a fault stays a fault.
           Nothing has been written by the time this returns. */
        DECLARE @Err NVARCHAR(2048) = ERROR_MESSAGE();
        THROW 51000, @Err, 1;
    END CATCH

    /* The reason the operator is actually shown when nothing went through.
       "Every ticked shift had moved since the preview" is one of three
       possible answers and was the wrong one two times out of three — the
       common case is another run still holding the amendment. Reporting the
       reason the rows actually carry is the difference between a message that
       tells somebody what to do next and one that sends them looking for a
       recount that never happened. */
    DECLARE @Why NVARCHAR(200) = (
        SELECT TOP 1 SkipReason FROM #cand WHERE SkipReason IS NOT NULL
        GROUP BY SkipReason ORDER BY COUNT(*) DESC);

    SELECT CONVERT(bit, CASE WHEN @Written > 0 THEN 1 ELSE 0 END) AS Ok,
           CASE WHEN @Written > 0 THEN 'COMMITTED' ELSE 'ALL_SKIPPED' END AS Code,
           CASE WHEN @Written = 0
                THEN 'Nothing was written. ' + ISNULL(@Why, 'Every ticked shift was skipped.')
                     + ' The run is still open, so it can be pressed again once that is dealt with.'
                ELSE CONVERT(nvarchar(20), @Written) + ' shift(s) amended'
                     + CASE WHEN @Issues > 0
                            THEN ' (' + CONVERT(nvarchar(20), @Issues) + ' duplicate issue(s) set to 0)'
                            ELSE '' END
                     + CASE WHEN @Captured > 0
                            THEN ' (' + CONVERT(nvarchar(20), @Captured) + ' missing issue(s) captured)'
                            ELSE '' END
                     + CASE WHEN @Reduced > 0
                            THEN ' (' + CONVERT(nvarchar(20), @Reduced) + ' issue(s) reduced)'
                            ELSE '' END
                     + CASE WHEN @Skipped > 0
                            /* Not "because they had moved": a skip is also a
                               chain a possible duplicate holds, or one waiting
                               for its duplicate. Each row says which. */
                            THEN ', ' + CONVERT(nvarchar(20), @Skipped) + ' skipped (each says why)'
                            ELSE '' END
                     + CASE WHEN @StampMode = 'live'
                            THEN ', written to PumpIT.'
                            ELSE ', recorded in Agora only — PumpIT was not touched.' END
           END AS [Message],
           CONVERT(bigint, @RunId) AS Id;

    SELECT c.Id AS RunLineId, c.[LineNo], c.AreaNo, c.StockItemNo, c.TransactionDate, c.ShiftNo,
           CASE WHEN c.SkipReason IS NULL THEN 'committed' ELSE 'skipped' END AS Result,
           c.SkipReason
    FROM #cand c
    ORDER BY c.[LineNo];

    DROP TABLE #cand;
END
