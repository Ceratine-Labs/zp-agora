/* ============================================================================
   agora.usp_Reports_GridDropSafe

   WHAT IT IS FOR
   Drop-safe collections: what the security company took, against what the tills
   actually dropped. And, in the same grid, the bags that were dropped and never
   collected at all — which is the row that matters.

   WHO READS IT
   Head office and the branch manager. Measured 4 Sep 2026: 11 bags dropped and
   never collected, out of 33,705.

   TWO GRAINS IN ONE GRID, AND WHY THAT IS RIGHT
   A collection is a bag of bags. The report has one row per collection, with
   the bags aggregated into it, PLUS one row per uncollected bag. Those are
   different grains, and mixing grains is usually a mistake — here it is the
   question: "what happened to the drop safe" is answered by the collections
   and by what is still sitting in it, and splitting them into two screens means
   nobody looks at the second one.

   RowType says which is which, so a filter or a sum can separate them again.

   THE AGGREGATION IS DONE BEFORE THE JOIN
   BRN_DropSafe_Collection carries its own TotalAmount and TotalNoOfBags, typed
   in by the manager. The bags carry the truth. Joining bag to collection and
   summing afterwards would multiply the declared total by the bag count; the
   bags are collapsed in an APPLY first, and the two totals are shown side by
   side with their difference, because a declared total that does not match the
   bags in it is the whole reason to look.

   THE R2,000 RULE
   A drop over R2,000 needs a reason. ReasonIfAmountMoreThanR2000 is where it
   goes, and a bag over R2,000 without one is flagged rather than filtered out.

   READ-ONLY.

     EXEC agora.usp_Reports_GridDropSafe @BranchIds = '25', @DateFrom = '2026-08-01';
   ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Reports_GridDropSafe]
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
    SET @DateFrom = ISNULL(@DateFrom, DATEADD(day, -30, @DateTo));
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

    SELECT * INTO #Rows FROM (

        /* One row per collection. */
        SELECT
            c.BranchId,
            b.Name                                      AS BranchName,
            'Collection'                                AS RowType,
            c.CollectionDate                            AS EventDate,
            c.CollectionTime                            AS EventTime,
            c.DBagNo                                    AS Reference,
            CONVERT(bigint, c.CollectionId)             AS CollectionId,
            m.EmployeeName                              AS ManagerName,
            c.SecurityName,
            CONVERT(nvarchar(70), NULL)                 AS CashierName,
            c.TotalAmount                               AS DeclaredAmount,
            CONVERT(int, c.TotalNoOfBags)               AS DeclaredBags,
            ISNULL(g.BagAmount, 0)                      AS BagAmount,
            ISNULL(g.BagCount, 0)                       AS BagCount,
            ISNULL(g.BagAmount, 0) - c.TotalAmount      AS AmountDifference,
            ISNULL(g.BagCount, 0) - CONVERT(int, ISNULL(c.TotalNoOfBags, 0)) AS BagDifference,
            CONVERT(nvarchar(200), NULL)                AS OverR2000Reason,
            CONVERT(nvarchar(200), NULL)                AS ManualBagReason,
            CONVERT(bit, 0)                             AS NeedsReason,
            CASE WHEN ISNULL(g.BagCount, 0) = 0 THEN 'Collection with no bags'
                 WHEN ISNULL(g.BagAmount, 0) <> c.TotalAmount THEN 'Bags do not agree with the total'
                 WHEN ISNULL(g.BagCount, 0) <> CONVERT(int, ISNULL(c.TotalNoOfBags, 0)) THEN 'Bag count does not agree'
                 ELSE 'Agrees' END                      AS Status,
            DATEDIFF(day, c.CollectionDate, @DateTo)    AS AgeDays
        FROM agora.vw_DropSafeCollectionHeader c
        JOIN agora.vw_Branch b ON b.BranchId = c.BranchId
        LEFT JOIN agora.vw_Employee m
               ON m.BranchId = c.BranchId AND m.EmployeeCode = c.ManagerCode
        OUTER APPLY (
            SELECT COUNT(*) AS BagCount, SUM(x.Amount) AS BagAmount
            FROM agora.vw_DropSafeBag x
            WHERE x.BranchId = c.BranchId AND x.CollectionId = c.CollectionId
        ) g
        WHERE c.CollectionDate >= @DateFrom AND c.CollectionDate < DATEADD(day, 1, @DateTo)

        UNION ALL

        /* One row per bag that was never collected. */
        SELECT
            d.BranchId,
            b.Name,
            'Uncollected bag',
            d.DropDate,
            d.DropTime,
            d.BagNo,
            NULL,
            m.EmployeeName,
            NULL,
            k.EmployeeName,
            d.Amount,
            NULL,
            d.Amount,
            1,
            NULL,
            NULL,
            LEFT(d.ReasonIfAmountMoreThanR2000, 200),
            LEFT(n.ReasonForManualBag, 200),
            CASE WHEN d.Amount > 2000
                  AND ISNULL(LTRIM(RTRIM(d.ReasonIfAmountMoreThanR2000)), '') = ''
                 THEN CONVERT(bit, 1) ELSE CONVERT(bit, 0) END,
            'Never collected',
            DATEDIFF(day, d.DropDate, @DateTo)
        FROM agora.vw_DropSafeBag d
        JOIN agora.vw_Branch b ON b.BranchId = d.BranchId
        LEFT JOIN agora.vw_Employee k
               ON k.BranchId = d.BranchId AND k.EmployeeCode = d.CashierCode
        LEFT JOIN agora.vw_Employee m
               ON m.BranchId = d.BranchId AND m.EmployeeCode = d.ManagerCode
        LEFT JOIN agora.vw_DropSafeReason n
               ON n.ReasonForManualBagId = d.ReasonForManualBagId
        WHERE d.DropDate >= @DateFrom AND d.DropDate < DATEADD(day, 1, @DateTo)
          AND ISNULL(d.CollectionId, 0) = 0
    ) u
    WHERE (@AllBranches = 1 OR u.BranchId IN (SELECT BranchId FROM @Branch))
      AND (@Search IS NULL
           OR u.BranchName   LIKE '%' + @Search + '%'
           OR u.Reference    LIKE '%' + @Search + '%'
           OR u.SecurityName LIKE '%' + @Search + '%'
           OR u.ManagerName  LIKE '%' + @Search + '%'
           OR u.CashierName  LIKE '%' + @Search + '%');

    DECLARE @Total bigint = (SELECT COUNT_BIG(*) FROM #Rows);

    SELECT r.BranchId, r.BranchName, r.RowType, r.EventDate, r.EventTime, r.Reference,
           r.CollectionId, r.ManagerName, r.SecurityName, r.CashierName,
           r.DeclaredAmount, r.DeclaredBags, r.BagAmount, r.BagCount,
           r.AmountDifference, r.BagDifference,
           r.OverR2000Reason, r.ManualBagReason, r.NeedsReason, r.Status, r.AgeDays
    FROM #Rows r
    ORDER BY
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'BranchName'   THEN r.BranchName
                             WHEN 'Reference'    THEN r.Reference
                             WHEN 'RowType'      THEN r.RowType
                             WHEN 'Status'       THEN r.Status
                             WHEN 'SecurityName' THEN r.SecurityName
                             WHEN 'ManagerName'  THEN r.ManagerName END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'BranchName'   THEN r.BranchName
                             WHEN 'Reference'    THEN r.Reference
                             WHEN 'RowType'      THEN r.RowType
                             WHEN 'Status'       THEN r.Status
                             WHEN 'SecurityName' THEN r.SecurityName
                             WHEN 'ManagerName'  THEN r.ManagerName END END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'DeclaredAmount'   THEN CONVERT(decimal(38,6), r.DeclaredAmount)
                             WHEN 'BagAmount'        THEN CONVERT(decimal(38,6), r.BagAmount)
                             WHEN 'AmountDifference' THEN CONVERT(decimal(38,6), r.AmountDifference)
                             WHEN 'BagCount'         THEN CONVERT(decimal(38,6), r.BagCount)
                             WHEN 'AgeDays'          THEN CONVERT(decimal(38,6), r.AgeDays) END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'DeclaredAmount'   THEN CONVERT(decimal(38,6), r.DeclaredAmount)
                             WHEN 'BagAmount'        THEN CONVERT(decimal(38,6), r.BagAmount)
                             WHEN 'AmountDifference' THEN CONVERT(decimal(38,6), r.AmountDifference)
                             WHEN 'BagCount'         THEN CONVERT(decimal(38,6), r.BagCount)
                             WHEN 'AgeDays'          THEN CONVERT(decimal(38,6), r.AgeDays) END END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'EventDate' THEN CONVERT(datetime2, r.EventDate) END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'EventDate' THEN CONVERT(datetime2, r.EventDate) END END DESC,
        /* Uncollected bags first — they are the ones that need a person. */
        r.RowType DESC, r.EventDate DESC, r.BranchName, r.Reference
    OFFSET (@Page - 1) * @PageSize ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT @Total AS TotalRows;
END
