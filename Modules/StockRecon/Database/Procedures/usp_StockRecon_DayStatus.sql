/* ============================================================================
   agora.usp_StockRecon_DayStatus — is each day reconciled? (13 Nov 2026)

   Ryan, 13 Nov 2026: runs overlap — rolling windows, and a site processes what
   it can while a problem waits for head office — and nothing said whether a
   day was fully done. "green for days that are fully reconciled, orange for
   partially reconciled ... red for not done." Amended is balancing doing its
   job, so it is a mark on the day, never a colour.

   THE UNIT IS AN AREA-DAY: one counting area at one site on one date that
   has recon lines (STK_StockReconLine, through agora.vw_StockReconLine), with
   the preview's own exclusions — the excluded area groups and the active
   cumulative meters — so a day is only ever asked for what a run could do.

   WHICH RUN DECIDES. The latest POSTED run (Status 'committed', not a reset)
   that covers the area — its own area, or every area — and actually carries
   lines for that area on that date, inside its complete window (CompleteTo:
   the days after it were held back as incomplete and never balanced). Later
   runs re-run the same days all the time; the latest one is what PumpIT now
   says, so it is the one that answers.

   WHAT IS STILL OPEN on that run, for that area and date:
     held      a line of a chain the run held back (ChainBlocked: a possible
               duplicate, a data fault, a missing shift, the caps) — the
               things that wait for somebody;
     left      a proposed amendment that was not written: skipped at the
               commit for a reason other than "another run already amended
               this shift" (that one is done, by the other run), or never
               ticked;
     reversed  an amendment on it reversed since (a chain reversal);
     late      counts captured after the run: the source holds more lines for
               the area-day than the run saw. On 13 Nov 2026 every area-day of
               Ngwenya's 5–11 Nov run matched the source exactly, so a
               difference is new counts, not noise.

   AN AREA-DAY is done (posted, nothing open), partial (posted, something
   open) or open (no posted run). A DAY, over its areas:
     done      every area done                                  green
     partial   some posted, not all; or anything open           orange
     open      counted, nothing posted                          red
     none      no counts at all                                 grey
     later     today or after — counting is not over            grey

   Two modes:
     calendar  @FromDate..@ToDate, the sites in @BranchIds: one row per site
               per day.
     detail    @DetailBranchId + @DetailDay: that day's areas, the lines that
               keep it open, and every run whose window includes it.

   Read-only. Reads Agora's run ledger and the PumpIT recon lines; writes
   nothing anywhere.
   ============================================================================ */
CREATE OR ALTER PROCEDURE agora.usp_StockRecon_DayStatus
    @BranchIds          NVARCHAR(MAX)  = NULL,   -- CSV; empty = every trading site the person may see
    @AllowedBranchIds   NVARCHAR(MAX)  = NULL,   -- CSV of the person's sites; empty = all
    @FromDate           DATE           = NULL,
    @ToDate             DATE           = NULL,
    @Today              DATE           = NULL,   -- the business date: it and later are not over
    @ExcludedAreaGroups NVARCHAR(400)  = NULL,   -- config('stockrecon.excluded_area_groups')
    @DetailBranchId     INT            = NULL,
    @DetailDay          DATE           = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @Detail bit = CASE WHEN @DetailBranchId IS NOT NULL AND @DetailDay IS NOT NULL THEN 1 ELSE 0 END;

    IF @Detail = 1
        SELECT @FromDate = @DetailDay, @ToDate = @DetailDay, @BranchIds = CONVERT(nvarchar(20), @DetailBranchId);

    SET @Today = ISNULL(@Today, CONVERT(date, SYSDATETIME()));

    IF @FromDate IS NULL OR @ToDate IS NULL
        THROW 51000, 'AGORA:BAD_RANGE:A from and a to date are both needed.', 1;
    IF @FromDate > @ToDate
        THROW 51000, 'AGORA:BAD_RANGE:The From date is after the To date.', 1;
    IF DATEDIFF(day, @FromDate, @ToDate) > 92
        THROW 51000, 'AGORA:BAD_RANGE:Ask for three months at most.', 1;

    DECLARE @Branch TABLE (BranchId int PRIMARY KEY);
    INSERT INTO @Branch (BranchId)
    SELECT DISTINCT TRY_CONVERT(int, s.value)
    FROM STRING_SPLIT(ISNULL(@BranchIds, ''), ',') s
    WHERE LTRIM(RTRIM(s.value)) <> '' AND TRY_CONVERT(int, s.value) IS NOT NULL;   -- TRY_CONVERT(int, '') is 0

    DECLARE @Allowed TABLE (BranchId int PRIMARY KEY);
    INSERT INTO @Allowed (BranchId)
    SELECT DISTINCT TRY_CONVERT(int, s.value)
    FROM STRING_SPLIT(ISNULL(@AllowedBranchIds, ''), ',') s
    WHERE LTRIM(RTRIM(s.value)) <> '' AND TRY_CONVERT(int, s.value) IS NOT NULL;   -- TRY_CONVERT(int, '') is 0

    DECLARE @Excluded TABLE (AreaGroup nvarchar(100) PRIMARY KEY);
    INSERT INTO @Excluded (AreaGroup)
    SELECT DISTINCT LTRIM(RTRIM(s.value))
    FROM STRING_SPLIT(ISNULL(@ExcludedAreaGroups, ''), ',') s
    WHERE LTRIM(RTRIM(s.value)) <> '';

    DECLARE @AllBranches bit = CASE WHEN EXISTS (SELECT 1 FROM @Branch)  THEN 0 ELSE 1 END;
    DECLARE @AllAllowed  bit = CASE WHEN EXISTS (SELECT 1 FROM @Allowed) THEN 0 ELSE 1 END;

    /* The sites: asked for, and allowed. Asked for nothing means every trading
       site — the rows of the calendar, including a site with no counts. */
    IF OBJECT_ID('tempdb..#Site') IS NOT NULL DROP TABLE #Site;
    SELECT b.BranchId, b.Name AS BranchName, b.SortOrder
    INTO #Site
    FROM agora.Branch b
    WHERE b.DeletedAt IS NULL
      AND (@AllBranches = 0 OR (b.IsTrading = 1 AND b.IsActive = 1))
      AND (@AllBranches = 1 OR b.BranchId IN (SELECT BranchId FROM @Branch))
      AND (@AllAllowed  = 1 OR b.BranchId IN (SELECT BranchId FROM @Allowed));

    IF @Detail = 1 AND NOT EXISTS (SELECT 1 FROM #Site)
        THROW 51000, 'AGORA:NOT_FOUND:That site is not one you can see.', 1;

    /* Area-days with counts, as a preview would read them. */
    IF OBJECT_ID('tempdb..#Counted') IS NOT NULL DROP TABLE #Counted;
    SELECT l.BranchId, CONVERT(date, l.TransactionDate) AS [Day], l.AreaNo, COUNT_BIG(*) AS CountedLines
    INTO #Counted
    FROM agora.vw_StockReconLine l
    JOIN #Site s ON s.BranchId = l.BranchId
    JOIN agora.vw_StockArea a ON a.BranchId = l.BranchId AND a.AreaNo = l.AreaNo
    WHERE l.TransactionDate >= @FromDate
      AND l.TransactionDate <  DATEADD(day, 1, @ToDate)
      AND NOT EXISTS (SELECT 1 FROM @Excluded e WHERE e.AreaGroup = a.AreaGroup)
      AND NOT EXISTS (SELECT 1 FROM agora.StockReconMeter meter
          WHERE meter.BranchId = l.BranchId AND meter.AreaNo = l.AreaNo
            AND meter.StockItemNo = l.StockItemNo AND meter.IsActive = 1)
    GROUP BY l.BranchId, CONVERT(date, l.TransactionDate), l.AreaNo;

    /* Every posted run's lines that fall in the range, one row per run per
       area-day, aggregated once. A run's lines carry their own area, so a
       one-area run only ever answers for that area. */
    IF OBJECT_ID('tempdb..#RunAreaDay') IS NOT NULL DROP TABLE #RunAreaDay;
    SELECT rl.BranchId, rl.RunId, rl.AreaNo, rl.TransactionDate AS [Day], r.CommittedAt,
           COUNT_BIG(*) AS RunLines,
           SUM(CASE WHEN rl.ChainBlocked = 1 THEN 1 ELSE 0 END) AS HeldLines,
           COUNT(DISTINCT CASE WHEN rl.ChainBlocked = 1 THEN rl.StockItemNo END) AS HeldItems,
           SUM(CASE WHEN rl.ChainBlocked = 0 AND rl.CommitState = 'skipped'
                         AND ISNULL(rl.BlockReason, N'') NOT LIKE N'%amendment from another run%' THEN 1
                    WHEN rl.ChainBlocked = 0 AND rl.CommitState = 'pending' AND rl.WouldAmend = 1 THEN 1
                    ELSE 0 END) AS LeftLines,
           SUM(CASE WHEN rl.CommitState = 'committed' THEN 1 ELSE 0 END) AS AmendedLines
    INTO #RunAreaDay
    FROM agora.StockReconRun r
    JOIN #Site s ON s.BranchId = r.BranchId
    JOIN agora.StockReconRunLine rl ON rl.BranchId = r.BranchId AND rl.RunId = r.Id
    WHERE r.Status = 'committed'
      AND ISNULL(r.CommitKind, '') <> 'reset'
      AND r.FromDate <= @ToDate AND r.ToDate >= @FromDate
      AND rl.TransactionDate >= @FromDate AND rl.TransactionDate <= @ToDate
      AND rl.TransactionDate <= ISNULL(r.CompleteTo, r.ToDate)
    GROUP BY rl.BranchId, rl.RunId, rl.AreaNo, rl.TransactionDate, r.CommittedAt;

    /* The run that decides each area-day — the latest posted — and what is
       still open on it. */
    IF OBJECT_ID('tempdb..#Open') IS NOT NULL DROP TABLE #Open;
    SELECT c.BranchId, c.[Day], c.AreaNo, c.CountedLines, d.RunId,
           ISNULL(d.RunLines, 0)     AS RunLines,
           ISNULL(d.HeldLines, 0)    AS HeldLines,
           ISNULL(d.HeldItems, 0)    AS HeldItems,
           ISNULL(d.LeftLines, 0)    AS LeftLines,
           ISNULL(d.AmendedLines, 0) AS AmendedLines,
           ISNULL(v.ReversedLines, 0) AS ReversedLines,
           CASE WHEN d.RunId IS NOT NULL AND c.CountedLines > d.RunLines
                THEN c.CountedLines - d.RunLines ELSE 0 END AS LateLines
    INTO #Open
    FROM #Counted c
    LEFT JOIN (
        SELECT x.*, ROW_NUMBER() OVER (PARTITION BY x.BranchId, x.AreaNo, x.[Day]
                                       ORDER BY x.CommittedAt DESC, x.RunId DESC) AS rn
        FROM #RunAreaDay x
    ) d ON d.BranchId = c.BranchId AND d.AreaNo = c.AreaNo AND d.[Day] = c.[Day] AND d.rn = 1
    OUTER APPLY (
        SELECT COUNT_BIG(*) AS ReversedLines
        FROM agora.StockReconAmendment am
        WHERE d.RunId IS NOT NULL
          AND am.BranchId = c.BranchId AND am.RunId = d.RunId
          AND am.AreaNo = c.AreaNo AND am.TransactionDate = c.[Day]
          AND am.State = 'reversed'
    ) v;

    ALTER TABLE #Open ADD AreaStatus varchar(10) NULL;
    UPDATE #Open SET AreaStatus =
        CASE WHEN RunId IS NULL THEN 'open'
             WHEN HeldLines + LeftLines + ReversedLines + LateLines > 0 THEN 'partial'
             ELSE 'done' END;

    /* ---- detail: one site, one day ------------------------------------------- */
    IF @Detail = 1
    BEGIN
        /* 1. The areas. */
        SELECT o.AreaNo, a.AreaDescription, o.AreaStatus, o.CountedLines, o.RunLines,
               o.HeldItems, o.HeldLines, o.LeftLines, o.ReversedLines, o.LateLines, o.AmendedLines,
               o.RunId, r.FromDate AS RunFrom, r.ToDate AS RunTo, r.Note AS RunNote,
               r.CommittedAt, u.UserName AS CommittedByName,
               /* The state is its own column; this says only what is behind it. */
               COALESCE(NULLIF(CONCAT_WS(N' · ',
                   CASE WHEN o.AreaStatus = 'open' THEN N'Counted, no posted run' END,
                   CASE WHEN o.HeldItems > 0 THEN CONCAT(o.HeldItems, N' item(s) held back') END,
                   CASE WHEN o.LeftLines > 0 THEN CONCAT(o.LeftLines, N' amendment(s) not written') END,
                   CASE WHEN o.ReversedLines > 0 THEN CONCAT(o.ReversedLines, N' amendment(s) reversed since') END,
                   CASE WHEN o.LateLines > 0 THEN CONCAT(o.LateLines, N' line(s) counted after the run') END,
                   CASE WHEN o.AmendedLines > 0 THEN CONCAT(o.AmendedLines, N' shift(s) amended') END
               ), N''), N'Nothing open') AS Summary
        FROM #Open o
        LEFT JOIN agora.vw_StockArea a ON a.BranchId = o.BranchId AND a.AreaNo = o.AreaNo
        LEFT JOIN agora.StockReconRun r ON r.BranchId = o.BranchId AND r.Id = o.RunId
        LEFT JOIN agora.[User] u ON u.Id = r.CommittedBy
        ORDER BY CASE o.AreaStatus WHEN 'open' THEN 0 WHEN 'partial' THEN 1 ELSE 2 END, o.AreaNo;

        /* 2. What keeps it open, line by line, on the deciding run. */
        SELECT rl.AreaNo, rl.AreaDescription, rl.StockItemNo, rl.ItemDescription, rl.ShiftNo,
               CASE WHEN rl.ChainBlocked = 1 THEN N'Held back' ELSE N'Not written' END AS Kind,
               COALESCE(NULLIF(rl.BlockReason, N''), rl.Outcome) AS Reason,
               rl.RunId, rl.Id AS RunLineId
        FROM #Open o
        JOIN agora.vw_StockReconRunLine rl
          ON rl.BranchId = o.BranchId AND rl.RunId = o.RunId AND rl.AreaNo = o.AreaNo AND rl.TransactionDate = o.[Day]
        WHERE rl.ChainBlocked = 1
           OR (rl.CommitState = 'skipped' AND ISNULL(rl.BlockReason, N'') NOT LIKE N'%amendment from another run%')
           OR (rl.CommitState = 'pending' AND rl.WouldAmend = 1)
        UNION ALL
        SELECT am.AreaNo, rl.AreaDescription, am.StockItemNo, rl.ItemDescription, am.ShiftNo,
               N'Reversed', COALESCE(NULLIF(am.ReversalReason, N''), N'Amendment reversed since the run was posted'),
               am.RunId, am.RunLineId
        FROM #Open o
        JOIN agora.StockReconAmendment am
          ON am.BranchId = o.BranchId AND am.RunId = o.RunId AND am.AreaNo = o.AreaNo AND am.TransactionDate = o.[Day]
         AND am.State = 'reversed'
        LEFT JOIN agora.vw_StockReconRunLine rl ON rl.BranchId = am.BranchId AND rl.Id = am.RunLineId
        ORDER BY 1, 3, 5;

        /* 3. Every run whose window includes the day, newest first, and which
              of them decides an area. */
        SELECT r.Id AS RunId, r.AreaNo, a.AreaDescription, r.FromDate, r.ToDate, r.CompleteTo,
               CASE r.Status WHEN 'committed' THEN CASE WHEN r.CommitKind = 'reset' THEN N'Reset to original' ELSE N'Posted' END
                             WHEN 'reversed' THEN N'Reversed'
                             WHEN 'failed' THEN N'Failed'
                             ELSE N'Preview, not posted' END AS StatusLabel,
               r.CommittedAt, u.UserName AS CommittedByName, r.Note,
               CONVERT(bit, CASE WHEN EXISTS (SELECT 1 FROM #Open o WHERE o.RunId = r.Id) THEN 1 ELSE 0 END) AS Decides
        FROM agora.StockReconRun r
        LEFT JOIN agora.vw_StockArea a ON a.BranchId = r.BranchId AND a.AreaNo = r.AreaNo
        LEFT JOIN agora.[User] u ON u.Id = r.CommittedBy
        WHERE r.BranchId = @DetailBranchId
          AND @DetailDay BETWEEN r.FromDate AND r.ToDate
          AND r.Status IN ('committed', 'reversed', 'previewed')
        ORDER BY ISNULL(r.CommittedAt, r.CreatedAt) DESC, r.Id DESC;

        RETURN;
    END;

    /* ---- calendar: every site, every day -------------------------------------- */
    ;WITH n AS (
        SELECT TOP (DATEDIFF(day, @FromDate, @ToDate) + 1)
               ROW_NUMBER() OVER (ORDER BY (SELECT NULL)) - 1 AS i
        FROM sys.all_objects
    ), d AS (
        SELECT DATEADD(day, n.i, @FromDate) AS [Day] FROM n
    ), agg AS (
        SELECT o.BranchId, o.[Day],
               COUNT(*) AS AreasCounted,
               SUM(CASE WHEN o.RunId IS NOT NULL THEN 1 ELSE 0 END) AS AreasPosted,
               SUM(CASE WHEN o.AreaStatus = 'done' THEN 1 ELSE 0 END) AS AreasDone,
               SUM(o.HeldItems) AS HeldItems,
               SUM(o.LeftLines) AS LeftLines,
               SUM(o.ReversedLines) AS ReversedLines,
               SUM(o.LateLines) AS LateLines,
               SUM(o.AmendedLines) AS AmendedLines
        FROM #Open o
        GROUP BY o.BranchId, o.[Day]
    ), cell AS (
        SELECT s.BranchId, s.BranchName, s.SortOrder, d.[Day],
               ISNULL(g.AreasCounted, 0) AS AreasCounted,
               ISNULL(g.AreasPosted, 0)  AS AreasPosted,
               ISNULL(g.AreasDone, 0)    AS AreasDone,
               ISNULL(g.HeldItems, 0)    AS HeldItems,
               ISNULL(g.LeftLines, 0)    AS LeftLines,
               ISNULL(g.ReversedLines, 0) AS ReversedLines,
               ISNULL(g.LateLines, 0)    AS LateLines,
               ISNULL(g.AmendedLines, 0) AS AmendedLines,
               CASE WHEN d.[Day] >= @Today THEN 'later'
                    WHEN ISNULL(g.AreasCounted, 0) = 0 THEN 'none'
                    WHEN g.AreasPosted = 0 THEN 'open'
                    WHEN g.AreasDone = g.AreasCounted THEN 'done'
                    ELSE 'partial' END AS DayStatus
        FROM #Site s
        CROSS JOIN d
        LEFT JOIN agg g ON g.BranchId = s.BranchId AND g.[Day] = d.[Day]
    )
    SELECT BranchId, BranchName, [Day], DayStatus,
           AreasCounted, AreasPosted, AreasDone, HeldItems, LeftLines, ReversedLines, LateLines, AmendedLines,
           CONCAT_WS(N' · ',
               CASE DayStatus
                   WHEN 'later'   THEN N'Not over yet'
                   WHEN 'none'    THEN N'No counts'
                   WHEN 'open'    THEN CONCAT(N'Not reconciled: ', AreasCounted, N' area(s) counted, none posted')
                   WHEN 'done'    THEN CONCAT(N'Reconciled: ', AreasCounted, N' of ', AreasCounted, N' area(s)')
                   ELSE CONCAT(N'Partly reconciled: ', AreasDone, N' of ', AreasCounted, N' area(s) done') END,
               CASE WHEN DayStatus IN ('partial', 'done') AND AreasPosted < AreasCounted
                    THEN CONCAT(AreasCounted - AreasPosted, N' area(s) not posted') END,
               CASE WHEN HeldItems > 0 THEN CONCAT(HeldItems, N' item(s) held back') END,
               CASE WHEN LeftLines > 0 THEN CONCAT(LeftLines, N' amendment(s) not written') END,
               CASE WHEN ReversedLines > 0 THEN CONCAT(ReversedLines, N' amendment(s) reversed') END,
               CASE WHEN LateLines > 0 THEN CONCAT(LateLines, N' line(s) counted after the run') END,
               CASE WHEN AmendedLines > 0 THEN CONCAT(AmendedLines, N' shift(s) amended') END
           ) AS Summary
    FROM cell
    ORDER BY SortOrder, BranchName, [Day];
END;
