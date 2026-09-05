/* ============================================================================
   agora.usp_Reports_GridPurchaseApprovals

   WHAT IT IS FOR
   Purchase requests raised at a branch and still waiting on somebody. One row
   per request, with its line total.

   WHO READS IT
   Whoever approves. The approver is a property of the EXPENSE CODE on each
   line, not of the request, so a request with lines against two expense codes
   is waiting on two people — Approvers below is a list, and RequiresApprovers
   is how many.

   THE RULE
   Taken from dbo.sp_SelectUnApprovedPurchaseRequestByApproverUser:

       Approved <> 1  AND  IsDeclined = 0  AND  Posted <> 1

   The legacy procedure also requires IsApprovedEmailSent <> 1 on the LINE, and
   filters to one user's approval band via BRN_ApproverUserlevel. Neither is
   here, and both omissions are deliberate:

     * The email flag is about whether a notification went out, not about
       whether the request was approved. A queue that hides a request because
       an email fired is a queue that loses work.
     * The band filter belongs to the screen, not to the report. This is the
       head-office view of everything outstanding; the branches selector scopes
       the site and the approver column says whose it is.

   THE FAN-OUT, WHICH IS THE REAL POINT OF THIS PROCEDURE
   The legacy procedure joins header to line to expense to supplier and then
   applies SELECT DISTINCT. DISTINCT does not undo a fan-out — it only hides
   rows that happen to be identical, and the moment a request has two lines with
   different expense codes it returns that request twice. Here the lines are
   aggregated in their own APPLY before anything joins to the header, so the
   count is the number of requests and the total is the total.

   Measured 4 Sep 2026: 119 requests outstanding of 53,598.

   Purchase requests are transactional: they are declined or cancelled, never
   deleted. This procedure reads.

     EXEC agora.usp_Reports_GridPurchaseApprovals;
   ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Reports_GridPurchaseApprovals]
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

    SELECT
        p.BranchId,
        b.Name                                          AS BranchName,
        p.TransactionNo,
        p.PurchaseRequestDate,
        p.TypeCode,
        y.TypeDescription,
        p.SupplierCode,
        s.SupplierName,
        CASE WHEN s.IsCash = 1 THEN 'Cash' ELSE 'Credit' END AS SupplierTerms,
        p.QuoteNo,
        p.ShiftNo,
        f.ShiftDescription,
        p.EmailAddress,
        l.Lines,
        l.TotalAmount,
        l.FirstDescription                              AS Description,
        l.Approvers,
        l.RequiresApprovers,
        DATEDIFF(day, p.PurchaseRequestDate, @DateTo)   AS AgeDays,
        CASE WHEN l.RequiresApprovers > 1 THEN 'Several approvers'
             WHEN l.Approvers IS NULL     THEN 'No approver on the expense code'
             ELSE l.Approvers END                       AS WaitingOn
    INTO #Rows
    FROM agora.vw_PurchaseRequest p
    JOIN agora.vw_Branch b ON b.BranchId = p.BranchId
    LEFT JOIN agora.vw_Supplier s
           ON s.BranchId = p.BranchId AND s.SupplierCode = p.SupplierCode
    LEFT JOIN agora.vw_TransactionType y
           ON y.BranchId = p.BranchId AND y.TypeCode = p.TypeCode
    LEFT JOIN agora.vw_Shift f
           ON f.BranchId = p.BranchId AND f.ShiftNo = p.ShiftNo
    /* The lines, collapsed to one row BEFORE anything joins to them. This is
       the difference between counting requests and counting lines. */
    OUTER APPLY (
        SELECT COUNT(*)                     AS Lines,
               SUM(x.PurchaseAmount)        AS TotalAmount,
               MIN(LEFT(x.Description, 200)) AS FirstDescription,
               COUNT(DISTINCT e.ApproverUserId) AS RequiresApprovers,
               /* Distinct approver names, comma separated. STRING_AGG over a
                  subquery rather than over the join, so a user named on two
                  expense codes is listed once. */
               (SELECT STRING_AGG(u.UserName, ', ') WITHIN GROUP (ORDER BY u.UserName)
                FROM (
                    SELECT DISTINCT e2.ApproverUserId
                    FROM agora.vw_PurchaseRequestLine x2
                    JOIN agora.vw_Expense e2
                      ON e2.BranchId = x2.BranchId AND e2.ExpenseNo = x2.ExpenseNo
                    WHERE x2.BranchId = p.BranchId AND x2.TransactionNo = p.TransactionNo
                      AND ISNULL(e2.ApproverUserId, 0) <> 0
                ) d
                JOIN agora.vw_ErpUser u ON u.ErpUserId = d.ApproverUserId) AS Approvers
        FROM agora.vw_PurchaseRequestLine x
        LEFT JOIN agora.vw_Expense e
               ON e.BranchId = x.BranchId AND e.ExpenseNo = x.ExpenseNo
        WHERE x.BranchId = p.BranchId AND x.TransactionNo = p.TransactionNo
    ) l
    WHERE p.PurchaseRequestDate >= @DateFrom AND p.PurchaseRequestDate < DATEADD(day, 1, @DateTo)
      AND p.Approved = 0 AND p.IsDeclined = 0 AND p.Posted = 0
      AND (@AllBranches = 1 OR p.BranchId IN (SELECT BranchId FROM @Branch))
      AND (@Search IS NULL
           OR b.Name           LIKE '%' + @Search + '%'
           OR p.TransactionNo  LIKE '%' + @Search + '%'
           OR s.SupplierName   LIKE '%' + @Search + '%'
           OR p.QuoteNo        LIKE '%' + @Search + '%'
           OR l.FirstDescription LIKE '%' + @Search + '%');

    DECLARE @Total bigint = (SELECT COUNT_BIG(*) FROM #Rows);

    SELECT r.BranchId, r.BranchName, r.TransactionNo, r.PurchaseRequestDate,
           r.TypeCode, r.TypeDescription, r.SupplierCode, r.SupplierName, r.SupplierTerms,
           r.QuoteNo, r.ShiftNo, r.ShiftDescription, r.EmailAddress,
           r.Lines, r.TotalAmount, r.Description, r.Approvers, r.RequiresApprovers,
           r.WaitingOn, r.AgeDays
    FROM #Rows r
    ORDER BY
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'BranchName'    THEN r.BranchName
                             WHEN 'TransactionNo' THEN r.TransactionNo
                             WHEN 'SupplierName'  THEN r.SupplierName
                             WHEN 'WaitingOn'     THEN r.WaitingOn
                             WHEN 'Description'   THEN r.Description END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'BranchName'    THEN r.BranchName
                             WHEN 'TransactionNo' THEN r.TransactionNo
                             WHEN 'SupplierName'  THEN r.SupplierName
                             WHEN 'WaitingOn'     THEN r.WaitingOn
                             WHEN 'Description'   THEN r.Description END END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'TotalAmount' THEN CONVERT(decimal(38,6), r.TotalAmount)
                             WHEN 'Lines'       THEN CONVERT(decimal(38,6), r.Lines)
                             WHEN 'AgeDays'     THEN CONVERT(decimal(38,6), r.AgeDays) END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'TotalAmount' THEN CONVERT(decimal(38,6), r.TotalAmount)
                             WHEN 'Lines'       THEN CONVERT(decimal(38,6), r.Lines)
                             WHEN 'AgeDays'     THEN CONVERT(decimal(38,6), r.AgeDays) END END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'PurchaseRequestDate' THEN CONVERT(datetime2, r.PurchaseRequestDate) END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'PurchaseRequestDate' THEN CONVERT(datetime2, r.PurchaseRequestDate) END END DESC,
        r.PurchaseRequestDate, r.BranchName, r.TransactionNo
    OFFSET (@Page - 1) * @PageSize ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT @Total AS TotalRows;
END
