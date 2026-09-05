/* ============================================================================
   agora.usp_Reports_GridDayClose

   WHAT IT IS FOR
   Which sites have finished the day, which are still open, and what is holding
   each one up. One row per branch per trading day.

   WHO READS IT
   Head office, every morning, to decide who to phone.

   WHERE THE NUMBERS COME FROM
   RCN_ReconImports, through agora.vw_ReconImports: one row per branch per day
   carrying a bit per step of the close. HoldingUp is the FIRST unticked step in
   the order the branch actually works them, so the answer is one thing to chase
   rather than a row of ticks to interpret.

   WHICH BITS ARE REAL. Measured over 4,201 branch-days in 2026 on 4 Sep 2026:

     DayBalance          3 428   ShortsConfirmed  2 113   CBRecon      1 834
     DirectDepositsRecon 1 828   SmartATMRecon    1 821   DepositaRecon 1 817
     ABSARecon           1 726   FNBRecon            90
     HOImport               87   SiteClosedOff        0   ReadyToImport   0
     PastelImport            0

   So DayBalance is the close, NOT SiteClosedOff — which is set on 13 rows in
   the table's entire history, all of them in 2023. A report built on the
   obviously-named column would have said every site is open, every day, for
   three years. FNBRecon is included in the sequence but marked, because its 90
   is the same population as the six FNB branches that cannot reconcile at all
   (recon findings, question 3.2) — chasing it is chasing a known open question,
   not a branch that has forgotten something.

   The three dead bits are returned rather than dropped, so the answer to "why
   does nothing say closed off" is in the grid and not in a migration comment.

   ⚠ THE LEDGER DOES NOT COVER EVERY BRANCH, WHICH IS WHY THIS REPORT SCAFFOLDS
   Over 1-3 Sep 2026, sixteen branches captured cashups and only SIX had a row
   in RCN_ReconImports at all. Reading the ledger directly — the obvious way to
   write this — reports on those six and says nothing whatsoever about the other
   ten. A site that never started its close would be INVISIBLE on the report
   whose whole job is to find it.

   So the grid is built from active branches x days and the ledger is LEFT
   JOINed to it. A branch with no ledger row is 'Not started' with HoldingUp
   'No day-close record', which is the row somebody needs to see.

   HoldingUp is NULL once the day has balanced. The recon steps are head
   office's, and they run behind the branch by design — DayBalance is set on
   3,428 of 4,201 branch-days and ABSARecon on 1,726 — so naming an outstanding
   recon as what is 'holding up' a day the branch has finished would send people
   to phone the wrong person. ReconsOutstanding carries that count instead.

   READ-ONLY.

     EXEC agora.usp_Reports_GridDayClose @DateFrom = '2026-09-01', @DateTo = '2026-09-03';
   ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Reports_GridDayClose]
    @BranchIds   NVARCHAR(MAX) = NULL,
    @DateFrom    DATE          = NULL,
    @DateTo      DATE          = NULL,
    @Search      NVARCHAR(200) = NULL,
    @SortColumn  NVARCHAR(80)  = NULL,
    @SortAsc     BIT           = 1,
    @Page        INT           = 1,
    @PageSize    INT           = 50
AS
BEGIN
    SET NOCOUNT ON;

    SET @DateTo   = ISNULL(@DateTo, CONVERT(date, GETDATE()));
    SET @DateFrom = ISNULL(@DateFrom, DATEADD(day, -6, @DateTo));
    SET @Page     = CASE WHEN ISNULL(@Page, 1) < 1 THEN 1 ELSE @Page END;
    SET @PageSize = CASE WHEN ISNULL(@PageSize, 50) BETWEEN 1 AND 100000 THEN @PageSize ELSE 50 END;
    SET @Search   = NULLIF(LTRIM(RTRIM(ISNULL(@Search, ''))), '');

    DECLARE @Branch TABLE (BranchId int PRIMARY KEY);

    /* The empty-string trap. STRING_SPLIT('', ',') returns ONE row holding an
       empty string, and TRY_CONVERT(int, '') is 0 — not NULL. Without the
       first predicate below, a caller who passes no branches gets a @Branch
       table containing branch 0, @AllBranches is 0, and every report in this
       module silently returns nothing at all. Caught on the first run. */
    INSERT INTO @Branch (BranchId)
    SELECT DISTINCT TRY_CONVERT(int, LTRIM(RTRIM(s.value)))
    FROM STRING_SPLIT(ISNULL(@BranchIds, ''), ',') s
    WHERE LTRIM(RTRIM(s.value)) <> ''
      AND TRY_CONVERT(int, LTRIM(RTRIM(s.value))) IS NOT NULL;

    DECLARE @AllBranches bit = CASE WHEN EXISTS (SELECT 1 FROM @Branch) THEN 0 ELSE 1 END;

    /* Refuse a range this shape cannot usefully answer: it is branches x days. */
    IF DATEDIFF(day, @DateFrom, @DateTo) > 92
        THROW 51000, 'AGORA:RANGE_TOO_WIDE:Day close status covers at most 92 days at a time — it is a grid of branches by days.', 1;

    ;WITH Days AS (
        SELECT @DateFrom AS ReconDate
        UNION ALL
        SELECT DATEADD(day, 1, ReconDate) FROM Days WHERE ReconDate < @DateTo
    )
    SELECT
        b.BranchId,
        b.BranchName,
        d.ReconDate,
        CASE WHEN r.BranchId IS NULL       THEN 'Not started'
             WHEN ISNULL(r.DayBalance, 0) = 1 THEN 'Closed'
             ELSE 'Open' END                        AS Status,

        /* The first unticked step, in the order a branch works them, and NULL
           once the day has balanced — see the header. */
        CASE WHEN ISNULL(r.DayBalance, 0) = 1 THEN NULL
             WHEN r.BranchId IS NULL          THEN 'No day-close record'
             WHEN r.ShortsConfirmed     = 0 THEN 'Shorts not confirmed'
             ELSE 'Day not balanced' END            AS HoldingUp,

        /* How many of the six head-office reconciliations are still open. Kept
           apart from HoldingUp because they are a different person's work. */
        CONVERT(int, 6)
      - (CONVERT(int, ISNULL(r.ABSARecon, 0)) + CONVERT(int, ISNULL(r.CBRecon, 0))
       + CONVERT(int, ISNULL(r.DepositaRecon, 0)) + CONVERT(int, ISNULL(r.SmartATMRecon, 0))
       + CONVERT(int, ISNULL(r.DirectDepositsRecon, 0)) + CONVERT(int, ISNULL(r.FNBRecon, 0))) AS ReconsOutstanding,

        CONVERT(int, ISNULL(r.ABSARecon, 0)) + CONVERT(int, ISNULL(r.CBRecon, 0))
      + CONVERT(int, ISNULL(r.DepositaRecon, 0)) + CONVERT(int, ISNULL(r.SmartATMRecon, 0))
      + CONVERT(int, ISNULL(r.DirectDepositsRecon, 0)) + CONVERT(int, ISNULL(r.FNBRecon, 0))
      + CONVERT(int, ISNULL(r.ShortsConfirmed, 0)) + CONVERT(int, ISNULL(r.DayBalance, 0))  AS StepsDone,
        CONVERT(int, 8)                             AS StepsTotal,

        ISNULL(r.DayBalance, 0)          AS DayBalance,
        ISNULL(r.ShortsConfirmed, 0)     AS ShortsConfirmed,
        ISNULL(r.ABSARecon, 0)           AS ABSARecon,
        ISNULL(r.CBRecon, 0)             AS CBRecon,
        ISNULL(r.DepositaRecon, 0)       AS DepositaRecon,
        ISNULL(r.SmartATMRecon, 0)       AS SmartATMRecon,
        ISNULL(r.DirectDepositsRecon, 0) AS DirectDepositsRecon,
        ISNULL(r.FNBRecon, 0)            AS FNBRecon,

        r.CashierShort, r.PumpShort, r.ZReading, r.TotalAmount, r.EODNo,

        /* What the branch actually captured that day, so 'Not started' can be
           read against 'nothing was ever loaded'. */
        ISNULL(c.Cashups, 0)                        AS Cashups,
        ISNULL(e.DayEnds, 0)                        AS DayEnds,

        DATEDIFF(day, d.ReconDate, @DateTo)         AS AgeDays,

        /* The three that have not been set in years. Returned, and named as
           what they are, so nobody filters on one. */
        ISNULL(r.SiteClosedOff, 0)                  AS DeadSiteClosedOff,
        ISNULL(r.ReadyToImport, 0)                  AS DeadReadyToImport,
        ISNULL(r.PastelImport, 0)                   AS DeadPastelImport
    INTO #Rows
    FROM agora.vw_BranchImportStatus b
    CROSS JOIN Days d
    LEFT JOIN agora.vw_ReconImports r
           ON r.BranchId = b.BranchId AND r.ReconDate = d.ReconDate
    OUTER APPLY (
        SELECT COUNT(*) AS Cashups
        FROM agora.vw_DailyBanking k
        WHERE k.BranchId = b.BranchId
          AND k.TransactionDate >= d.ReconDate
          AND k.TransactionDate <  DATEADD(day, 1, d.ReconDate)
    ) c
    OUTER APPLY (
        SELECT COUNT(*) AS DayEnds
        FROM agora.vw_DayEnd y
        WHERE y.BranchId = b.BranchId
          AND y.DayEndDate >= d.ReconDate
          AND y.DayEndDate <  DATEADD(day, 1, d.ReconDate)
    ) e
    WHERE b.IsActive = 1
      AND (@AllBranches = 1 OR b.BranchId IN (SELECT BranchId FROM @Branch))
      AND (@Search IS NULL OR b.BranchName LIKE '%' + @Search + '%' OR r.EODNo LIKE '%' + @Search + '%')
    OPTION (MAXRECURSION 100);

    DECLARE @Total bigint = (SELECT COUNT_BIG(*) FROM #Rows);

    SELECT r.BranchId, r.BranchName, r.ReconDate, r.Status, r.HoldingUp,
           r.ReconsOutstanding, r.StepsDone, r.StepsTotal, r.AgeDays,
           r.DayBalance, r.ShortsConfirmed, r.ABSARecon, r.CBRecon, r.DepositaRecon,
           r.SmartATMRecon, r.DirectDepositsRecon, r.FNBRecon,
           r.CashierShort, r.PumpShort, r.ZReading, r.TotalAmount, r.EODNo,
           r.Cashups, r.DayEnds,
           r.DeadSiteClosedOff, r.DeadReadyToImport, r.DeadPastelImport
    FROM #Rows r
    ORDER BY
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'BranchName' THEN r.BranchName
                             WHEN 'Status'     THEN r.Status
                             WHEN 'HoldingUp'  THEN r.HoldingUp
                             WHEN 'EODNo'      THEN r.EODNo END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'BranchName' THEN r.BranchName
                             WHEN 'Status'     THEN r.Status
                             WHEN 'HoldingUp'  THEN r.HoldingUp
                             WHEN 'EODNo'      THEN r.EODNo END END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'StepsDone'    THEN CONVERT(decimal(38,6), r.StepsDone)
                             WHEN 'ReconsOutstanding' THEN CONVERT(decimal(38,6), r.ReconsOutstanding)
                             WHEN 'AgeDays'      THEN CONVERT(decimal(38,6), r.AgeDays)
                             WHEN 'CashierShort' THEN CONVERT(decimal(38,6), r.CashierShort)
                             WHEN 'PumpShort'    THEN CONVERT(decimal(38,6), r.PumpShort)
                             WHEN 'TotalAmount'  THEN CONVERT(decimal(38,6), r.TotalAmount)
                             WHEN 'Cashups'      THEN CONVERT(decimal(38,6), r.Cashups) END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'StepsDone'    THEN CONVERT(decimal(38,6), r.StepsDone)
                             WHEN 'ReconsOutstanding' THEN CONVERT(decimal(38,6), r.ReconsOutstanding)
                             WHEN 'AgeDays'      THEN CONVERT(decimal(38,6), r.AgeDays)
                             WHEN 'CashierShort' THEN CONVERT(decimal(38,6), r.CashierShort)
                             WHEN 'PumpShort'    THEN CONVERT(decimal(38,6), r.PumpShort)
                             WHEN 'TotalAmount'  THEN CONVERT(decimal(38,6), r.TotalAmount)
                             WHEN 'Cashups'      THEN CONVERT(decimal(38,6), r.Cashups) END END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'ReconDate' THEN CONVERT(datetime2, r.ReconDate) END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'ReconDate' THEN CONVERT(datetime2, r.ReconDate) END END DESC,
        /* Not started first, then open, then closed. */
        r.ReconDate DESC, r.Status, r.BranchName
    OFFSET (@Page - 1) * @PageSize ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT @Total AS TotalRows;
END
