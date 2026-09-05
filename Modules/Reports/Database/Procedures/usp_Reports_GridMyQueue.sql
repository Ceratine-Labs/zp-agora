/* ============================================================================
   agora.usp_Reports_GridMyQueue

   WHAT IT IS FOR
   The first thing on the Today menu, and the only one that is not a list of
   rows: one line per open queue per branch, so a person arriving in the morning
   can see where the work is before deciding which screen to open. Everything it
   counts is counted again, in full, by the report named in RouteKey — this is a
   directory, not a second opinion, and the two must always agree.

   WHO READS IT
   Head office first thing; a branch manager sees only their own site because
   the branch workspace passes a single @BranchIds.

   WHERE THE NUMBERS COME FROM
   Five queues, each the same rule its own report uses:

     day-close            a trading day whose DayBalance bit is still 0
     unallocated-zreads   a till reading with no till, no shift and no employee
     open-cashups         a cashup on a day that has not balanced
     purchase-approvals   Approved = 0, IsDeclined = 0, Posted = 0
     staff-shorts         Approved = 0, IsDeclined = 0
     drop-safe            a bag with CollectionId = 0 — dropped, never collected

   Waste is deliberately absent. PumpIT holds no approval state for waste (see
   usp_Reports_GridWaste), so "waste to approve" is not a number this database
   can produce and a zero here would read as "none outstanding".

   THE SHAPE EVERY GRID PROCEDURE IN THIS MODULE SHARES
   Two result sets: the page of rows, then one row (TotalRows BIGINT) so the
   grid can say "showing 50 of 12,480" and decide about the 100,000-row export
   ceiling. Sorting is done with three typed sort keys computed alongside the
   columns — no dynamic SQL, so the procedure can be granted EXECUTE without
   granting anything underneath it.

   READ-ONLY. Nothing in this module writes to any database. The temp table is
   in tempdb; the customer's estate is not touched.

     EXEC agora.usp_Reports_GridMyQueue;
     EXEC agora.usp_Reports_GridMyQueue @BranchIds = '5,7,25', @DateFrom = '2026-06-01';
   ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Reports_GridMyQueue]
    @BranchIds   NVARCHAR(MAX) = NULL,   -- CSV of branch ids; NULL = every branch in scope
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

    /* A queue is not a day's work — it is everything still outstanding — so the
       default window is a quarter rather than the month the capture reports
       use. Anything older than this is a data problem, not a queue. */
    SET @DateTo   = ISNULL(@DateTo, CONVERT(date, GETDATE()));
    SET @DateFrom = ISNULL(@DateFrom, DATEADD(day, -90, @DateTo));
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

    /* One row per branch per queue. Each block is the same rule its own report
       applies, written the same way, so a difference between this and that is a
       bug in one of them and not a matter of interpretation. */
    SELECT
        q.BranchId,
        q.QueueKey,
        q.Queue,
        q.RouteKey,
        q.SortOrder,
        b.Name                       AS BranchName,
        q.OpenItems,
        q.OldestDate,
        DATEDIFF(day, q.OldestDate, @DateTo) AS AgeDays,
        q.Value
    INTO #Rows
    FROM (
        /* Days that have not balanced. */
        SELECT r.BranchId,
               'day-close'                     AS QueueKey,
               'Days not closed'               AS Queue,
               'app.reports.show/day-close'    AS RouteKey,
               10                              AS SortOrder,
               COUNT(*)                        AS OpenItems,
               MIN(r.ReconDate)                AS OldestDate,
               CONVERT(money, NULL)            AS Value
        FROM agora.vw_ReconImports r
        WHERE r.ReconDate >= @DateFrom AND r.ReconDate <= @DateTo
          AND r.DayBalance = 0
        GROUP BY r.BranchId

        UNION ALL

        /* Till readings nobody has claimed. */
        SELECT z.BranchId, 'unallocated-zreads', 'Unallocated Z-reads',
               'app.reports.show/unallocated-zreads', 20,
               COUNT(*), MIN(z.ZReadDate), CONVERT(money, SUM(z.Sales))
        FROM agora.vw_ZRead z
        WHERE z.ZReadDate >= @DateFrom AND z.ZReadDate <= @DateTo
          AND ISNULL(z.TillNo, 0) = 0
          AND ISNULL(z.ShiftNo, 0) = 0
          AND ISNULL(z.EmployeeCode, '') = ''
        GROUP BY z.BranchId

        UNION ALL

        /* Cashups on a day that has not balanced. LEFT JOIN, not INNER: a day
           with no ledger row at all has certainly not been closed off, and an
           inner join would quietly drop exactly those. */
        SELECT d.BranchId, 'open-cashups', 'Open cashups',
               'app.reports.show/open-cashups', 30,
               COUNT(*), MIN(d.TransactionDate), CONVERT(money, SUM(d.ClosingBalance))
        FROM agora.vw_DailyBanking d
        LEFT JOIN agora.vw_ReconImports r
               ON r.BranchId = d.BranchId
              AND r.ReconDate = CONVERT(date, d.TransactionDate)
        WHERE d.TransactionDate >= @DateFrom AND d.TransactionDate < DATEADD(day, 1, @DateTo)
          AND ISNULL(r.DayBalance, 0) = 0
        GROUP BY d.BranchId

        UNION ALL

        /* Purchase requests waiting on somebody. The value is summed from the
           lines in a derived table FIRST — joining header to lines and summing
           afterwards multiplies the header across its lines, which is the
           fan-out the legacy sp_SelectUnApprovedPurchaseRequest* procedures
           mask with SELECT DISTINCT. */
        SELECT p.BranchId, 'purchase-approvals', 'Purchase approvals',
               'app.reports.show/purchase-approvals', 40,
               COUNT(*), MIN(p.PurchaseRequestDate), CONVERT(money, SUM(ISNULL(l.LineTotal, 0)))
        FROM agora.vw_PurchaseRequest p
        OUTER APPLY (
            SELECT SUM(x.PurchaseAmount) AS LineTotal
            FROM agora.vw_PurchaseRequestLine x
            WHERE x.BranchId = p.BranchId AND x.TransactionNo = p.TransactionNo
        ) l
        WHERE p.PurchaseRequestDate >= @DateFrom AND p.PurchaseRequestDate < DATEADD(day, 1, @DateTo)
          AND p.Approved = 0 AND p.IsDeclined = 0 AND p.Posted = 0
        GROUP BY p.BranchId

        UNION ALL

        /* Staff shorts waiting on an approver. */
        SELECT s.BranchId, 'staff-shorts', 'Staff shorts',
               'app.reports.show/staff-shorts', 50,
               COUNT(*), MIN(s.TransactionDate), CONVERT(money, SUM(s.AmountShort))
        FROM agora.vw_StaffShort s
        WHERE s.TransactionDate >= @DateFrom AND s.TransactionDate < DATEADD(day, 1, @DateTo)
          AND s.Approved = 0 AND s.IsDeclined = 0
        GROUP BY s.BranchId

        UNION ALL

        /* Bags dropped and never collected. */
        SELECT g.BranchId, 'drop-safe', 'Bags not collected',
               'app.reports.show/drop-safe', 60,
               COUNT(*), MIN(g.DropDate), CONVERT(money, SUM(g.Amount))
        FROM agora.vw_DropSafeBag g
        WHERE g.DropDate >= @DateFrom AND g.DropDate < DATEADD(day, 1, @DateTo)
          AND ISNULL(g.CollectionId, 0) = 0
        GROUP BY g.BranchId
    ) q
    JOIN agora.vw_Branch b ON b.BranchId = q.BranchId
    WHERE (@AllBranches = 1 OR q.BranchId IN (SELECT BranchId FROM @Branch))
      AND (@Search IS NULL OR b.Name LIKE '%' + @Search + '%' OR q.Queue LIKE '%' + @Search + '%');

    DECLARE @Total bigint = (SELECT COUNT_BIG(*) FROM #Rows);

    /* The sort. Three typed keys rather than one, because a single CASE over
       columns of different types takes the highest-precedence type and silently
       converts the rest — sorting a branch name as a number is an error, and
       sorting a number as text puts 100 before 20. */
    SELECT r.BranchId, r.BranchName, r.QueueKey, r.Queue, r.OpenItems,
           r.OldestDate, r.AgeDays, r.Value, r.RouteKey
    FROM #Rows r
    ORDER BY
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'BranchName' THEN r.BranchName WHEN 'Queue' THEN r.Queue END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'BranchName' THEN r.BranchName WHEN 'Queue' THEN r.Queue END END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'OpenItems' THEN CONVERT(decimal(38,6), r.OpenItems)
                             WHEN 'AgeDays'   THEN CONVERT(decimal(38,6), r.AgeDays)
                             WHEN 'Value'     THEN CONVERT(decimal(38,6), r.Value) END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'OpenItems' THEN CONVERT(decimal(38,6), r.OpenItems)
                             WHEN 'AgeDays'   THEN CONVERT(decimal(38,6), r.AgeDays)
                             WHEN 'Value'     THEN CONVERT(decimal(38,6), r.Value) END END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'OldestDate' THEN CONVERT(datetime2, r.OldestDate) END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'OldestDate' THEN CONVERT(datetime2, r.OldestDate) END END DESC,
        r.BranchName, r.SortOrder
    OFFSET (@Page - 1) * @PageSize ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT @Total AS TotalRows;
END
