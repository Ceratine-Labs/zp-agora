/* ============================================================================
   agora.usp_Reports_GridStockCount

   WHAT IT IS FOR
   The count itself, line by line: what was there, what came in, what the
   computer thinks was sold, what was counted, and the variance between them.

   WHO READS IT
   The branch after a count, and head office looking for a pattern.

   THE ARITHMETIC, from dbo.sp_SelectStockRecon:

       QtyVar   = QtyComputer - (QtyOpen + QtyIssued - QtyClose)
       ValueVar = QtyVar * SellPrice

   WHICH COLUMNS, AND WHY IT MATTERS
   STK_StockReconLine carries each quantity TWICE: QtyOpen and QtyOpen_Original,
   and so on. The plain ones are what balancing has amended since; the
   `_Original` ones are what was actually counted. The customer's own capture
   procedure loads the originals, and this report uses the originals — a
   variance computed from a mixture of the two belongs to neither number. Both
   are returned so an amendment is visible as the difference.

   SIZE. 7.1 million lines, so the date range is what bounds this report and a
   wide range with no branch is a slow question honestly answered — TotalRows in
   the second result set is what tells the grid to offer the export centre
   rather than a page.

   Counts are transactional. Read only.

     EXEC agora.usp_Reports_GridStockCount @BranchIds = '5', @DateFrom = '2026-09-01', @DateTo = '2026-09-01';
   ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Reports_GridStockCount]
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

    /* One day by default. This is line grain over 7.1 million rows and a month
       across the group is not a screen, it is an export. */
    SET @DateTo   = ISNULL(@DateTo, CONVERT(date, GETDATE()));
    SET @DateFrom = ISNULL(@DateFrom, @DateTo);
    SET @Page     = CASE WHEN ISNULL(@Page, 1) < 1 THEN 1 ELSE @Page END;
    SET @PageSize = CASE WHEN ISNULL(@PageSize, 50) BETWEEN 1 AND 100000 THEN @PageSize ELSE 50 END;
    SET @Search   = NULLIF(LTRIM(RTRIM(ISNULL(@Search, ''))), '');

    IF DATEDIFF(day, @DateFrom, @DateTo) > 92
        THROW 51000, 'AGORA:RANGE_TOO_WIDE:Stock count is line grain over 7.1 million rows — 92 days at a time, or use the export centre.', 1;

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
        l.BranchId,
        b.Name                                      AS BranchName,
        l.TransactionDate,
        l.ShiftNo,
        f.ShiftDescription,
        l.AreaNo,
        a.AreaDescription,
        a.AreaGroup,
        l.StockItemNo,
        m.StockItemDescription,
        m.UOMCode,
        m.StockLocation,

        /* What was counted. */
        l.QtyOpen_Original                          AS QtyOpen,
        l.QtyIssued_Original                        AS QtyIssued,
        l.QtyClose_Original                         AS QtyClose,
        l.QtyComputer_Original                      AS QtyComputer,
        CONVERT(float, ISNULL(l.QtyComputer_Original, 0)
              - (ISNULL(l.QtyOpen_Original, 0) + ISNULL(l.QtyIssued_Original, 0)
                 - ISNULL(l.QtyClose_Original, 0)))  AS QtyVar,
        l.SellPrice,
        CONVERT(money, (ISNULL(l.QtyComputer_Original, 0)
              - (ISNULL(l.QtyOpen_Original, 0) + ISNULL(l.QtyIssued_Original, 0)
                 - ISNULL(l.QtyClose_Original, 0))) * ISNULL(l.SellPrice, 0)) AS ValueVar,

        /* What balancing has made of it since. Equal to the above until someone
           amends the count. */
        l.QtyOpen                                   AS QtyOpenAmended,
        l.QtyIssued                                 AS QtyIssuedAmended,
        l.QtyClose                                  AS QtyCloseAmended,
        l.QtyComputer                               AS QtyComputerAmended,
        CASE WHEN ISNULL(l.QtyOpen, 0)     <> ISNULL(l.QtyOpen_Original, 0)
               OR ISNULL(l.QtyIssued, 0)   <> ISNULL(l.QtyIssued_Original, 0)
               OR ISNULL(l.QtyClose, 0)    <> ISNULL(l.QtyClose_Original, 0)
               OR ISNULL(l.QtyComputer, 0) <> ISNULL(l.QtyComputer_Original, 0)
             THEN CONVERT(bit, 1) ELSE CONVERT(bit, 0) END AS WasAmended,

        m.QtyVarAllowance,
        /* An allowance of 0 means NOT SET, not zero tolerance. Only 434 of the
           7,404 items in the stock master carry one, measured 4 Sep 2026, so
           testing `> QtyVarAllowance` marks every non-zero variance in the
           company as over its allowance and the status column stops meaning
           anything. The allowance is applied where there is one, and where
           there is not the row says so. */
        CASE WHEN ISNULL(l.QtyComputer_Original, 0)
                - (ISNULL(l.QtyOpen_Original, 0) + ISNULL(l.QtyIssued_Original, 0)
                   - ISNULL(l.QtyClose_Original, 0)) = 0 THEN 'Balanced'
             WHEN ISNULL(m.QtyVarAllowance, 0) > 0
              AND ABS(ISNULL(l.QtyComputer_Original, 0)
                    - (ISNULL(l.QtyOpen_Original, 0) + ISNULL(l.QtyIssued_Original, 0)
                       - ISNULL(l.QtyClose_Original, 0))) > m.QtyVarAllowance
             THEN 'Over the item''s allowance'
             WHEN ISNULL(m.QtyVarAllowance, 0) > 0 THEN 'Within the item''s allowance'
             ELSE 'Variance, no allowance set' END   AS Status,
        m.IsMonitoredItem
    INTO #Rows
    FROM agora.vw_StockReconLine l
    JOIN agora.vw_Branch b ON b.BranchId = l.BranchId
    LEFT JOIN agora.vw_StockArea a
           ON a.BranchId = l.BranchId AND a.AreaNo = l.AreaNo
    LEFT JOIN agora.vw_Shift f
           ON f.BranchId = l.BranchId AND f.ShiftNo = l.ShiftNo
    /* (branch, item) only. STK_StockMaster also carries an AreaNo, and joining
       on it as well drops every line counted outside the item's home area —
       the customer's own procedure has that join commented out for exactly this
       reason and the comment has survived since 2024. */
    LEFT JOIN agora.vw_StockMaster m
           ON m.BranchId = l.BranchId AND m.StockItemNo = l.StockItemNo
    WHERE l.TransactionDate >= @DateFrom AND l.TransactionDate < DATEADD(day, 1, @DateTo)
      AND (@AllBranches = 1 OR l.BranchId IN (SELECT BranchId FROM @Branch))
      AND (@Search IS NULL
           OR b.Name                 LIKE '%' + @Search + '%'
           OR m.StockItemDescription LIKE '%' + @Search + '%'
           OR l.StockItemNo          LIKE '%' + @Search + '%'
           OR a.AreaDescription      LIKE '%' + @Search + '%');

    DECLARE @Total bigint = (SELECT COUNT_BIG(*) FROM #Rows);

    SELECT r.BranchId, r.BranchName, r.TransactionDate, r.ShiftNo, r.ShiftDescription,
           r.AreaNo, r.AreaDescription, r.AreaGroup, r.StockItemNo, r.StockItemDescription,
           r.UOMCode, r.StockLocation,
           r.QtyOpen, r.QtyIssued, r.QtyClose, r.QtyComputer, r.QtyVar, r.SellPrice, r.ValueVar,
           r.QtyOpenAmended, r.QtyIssuedAmended, r.QtyCloseAmended, r.QtyComputerAmended,
           r.WasAmended, r.QtyVarAllowance, r.Status, r.IsMonitoredItem
    FROM #Rows r
    ORDER BY
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'BranchName'           THEN r.BranchName
                             WHEN 'StockItemDescription' THEN r.StockItemDescription
                             WHEN 'StockItemNo'          THEN r.StockItemNo
                             WHEN 'AreaDescription'      THEN r.AreaDescription
                             WHEN 'Status'               THEN r.Status END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'BranchName'           THEN r.BranchName
                             WHEN 'StockItemDescription' THEN r.StockItemDescription
                             WHEN 'StockItemNo'          THEN r.StockItemNo
                             WHEN 'AreaDescription'      THEN r.AreaDescription
                             WHEN 'Status'               THEN r.Status END END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'QtyVar'      THEN CONVERT(decimal(38,6), r.QtyVar)
                             WHEN 'ValueVar'    THEN CONVERT(decimal(38,6), r.ValueVar)
                             WHEN 'QtyClose'    THEN CONVERT(decimal(38,6), r.QtyClose)
                             WHEN 'QtyComputer' THEN CONVERT(decimal(38,6), r.QtyComputer) END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'QtyVar'      THEN CONVERT(decimal(38,6), r.QtyVar)
                             WHEN 'ValueVar'    THEN CONVERT(decimal(38,6), r.ValueVar)
                             WHEN 'QtyClose'    THEN CONVERT(decimal(38,6), r.QtyClose)
                             WHEN 'QtyComputer' THEN CONVERT(decimal(38,6), r.QtyComputer) END END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'TransactionDate' THEN CONVERT(datetime2, r.TransactionDate) END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'TransactionDate' THEN CONVERT(datetime2, r.TransactionDate) END END DESC,
        r.TransactionDate DESC, r.BranchName, r.AreaNo, r.StockItemNo
    OFFSET (@Page - 1) * @PageSize ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT @Total AS TotalRows;
END
