/* ============================================================================
   agora.usp_Reports_GridWaste

   WHAT IT IS FOR
   Waste captured at a branch, by area, shift and stock item, with what it cost.

   ⚠ WHAT IT CANNOT DO, AND WHY THE MENU ENTRY SAYS "WASTE TO APPROVE"
   PumpIT HOLDS NO APPROVAL STATE FOR WASTE. STK_StockWasteLine has eight
   columns — branch, date, shift, area, item, good qty, bad qty, created-at —
   and not one of them records approval, decline, an approver or an approval
   date. Staff shorts have Approved / ApprovedByUserId / ApprovedDate /
   IsDeclined; purchase requests have the same four; waste has none of it.

   So this report is CAPTURED waste, not waste awaiting approval, and it says
   so in the Status column rather than returning an empty grid that would read
   as "nothing outstanding". An approval trail for waste is a thing Agora would
   have to own in its own database — it does not exist to be reported on.

   Raised for Ryan as a decision rather than invented here.

   ALSO WORTH KNOWING BEFORE READING THE NUMBERS
   STK_StockWasteLine holds 91 rows in total and the most recent is 1 Mar 2026,
   measured 4 Sep 2026. Waste capture is either barely used or has stopped. The
   report is correct; the estate is nearly empty, and a blank screen here is
   probably the data rather than a bug.

   HOW THE VALUE IS PUT ON IT
   Waste lines carry a quantity and no price, so the value comes from the stock
   master's SellingPrice. That is what it would have sold for, not what it cost
   — the distinction matters and the column is named for it.

   READ-ONLY.

     EXEC agora.usp_Reports_GridWaste @DateFrom = '2026-01-01', @DateTo = '2026-03-31';
   ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Reports_GridWaste]
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

    SELECT
        w.BranchId,
        b.Name                                          AS BranchName,
        w.TransactionDate,
        w.ShiftNo,
        f.ShiftDescription,
        w.AreaNo,
        a.AreaDescription,
        a.AreaGroup,
        w.StockItemNo,
        m.StockItemDescription,
        m.UOMCode,
        w.QtyGoodWaste,
        w.QtyBadWaste,
        ISNULL(w.QtyGoodWaste, 0) + ISNULL(w.QtyBadWaste, 0)                  AS QtyTotalWaste,
        m.SellingPrice,
        CONVERT(money, ISNULL(w.QtyGoodWaste, 0) * ISNULL(m.SellingPrice, 0)) AS GoodWasteAtSellingPrice,
        CONVERT(money, ISNULL(w.QtyBadWaste, 0)  * ISNULL(m.SellingPrice, 0)) AS BadWasteAtSellingPrice,
        CONVERT(money, (ISNULL(w.QtyGoodWaste, 0) + ISNULL(w.QtyBadWaste, 0))
                       * ISNULL(m.SellingPrice, 0))                           AS TotalAtSellingPrice,
        w.CreateDateTime                                AS CapturedAt,

        /* Not an approval state. PumpIT has none for waste — see the header. */
        'Captured (PumpIT records no approval for waste)' AS Status
    INTO #Rows
    FROM agora.vw_StockWasteLine w
    JOIN agora.vw_Branch b ON b.BranchId = w.BranchId
    LEFT JOIN agora.vw_StockArea a
           ON a.BranchId = w.BranchId AND a.AreaNo = w.AreaNo
    LEFT JOIN agora.vw_Shift f
           ON f.BranchId = w.BranchId AND f.ShiftNo = w.ShiftNo
    /* The stock master is keyed on (branch, item); the waste line's AreaNo is
       the counting area the waste happened IN, not the item's home area, so it
       is deliberately not part of this join. Joining it too is what makes an
       item captured outside its home area silently disappear from the report. */
    LEFT JOIN agora.vw_StockMaster m
           ON m.BranchId = w.BranchId AND m.StockItemNo = w.StockItemNo
    WHERE w.TransactionDate >= @DateFrom AND w.TransactionDate < DATEADD(day, 1, @DateTo)
      AND (@AllBranches = 1 OR w.BranchId IN (SELECT BranchId FROM @Branch))
      AND (@Search IS NULL
           OR b.Name                 LIKE '%' + @Search + '%'
           OR m.StockItemDescription LIKE '%' + @Search + '%'
           OR w.StockItemNo          LIKE '%' + @Search + '%'
           OR a.AreaDescription      LIKE '%' + @Search + '%');

    DECLARE @Total bigint = (SELECT COUNT_BIG(*) FROM #Rows);

    SELECT r.BranchId, r.BranchName, r.TransactionDate, r.ShiftNo, r.ShiftDescription,
           r.AreaNo, r.AreaDescription, r.AreaGroup, r.StockItemNo, r.StockItemDescription,
           r.UOMCode, r.QtyGoodWaste, r.QtyBadWaste, r.QtyTotalWaste, r.SellingPrice,
           r.GoodWasteAtSellingPrice, r.BadWasteAtSellingPrice, r.TotalAtSellingPrice,
           r.CapturedAt, r.Status
    FROM #Rows r
    ORDER BY
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'BranchName'           THEN r.BranchName
                             WHEN 'StockItemDescription' THEN r.StockItemDescription
                             WHEN 'AreaDescription'      THEN r.AreaDescription
                             WHEN 'StockItemNo'          THEN r.StockItemNo END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'BranchName'           THEN r.BranchName
                             WHEN 'StockItemDescription' THEN r.StockItemDescription
                             WHEN 'AreaDescription'      THEN r.AreaDescription
                             WHEN 'StockItemNo'          THEN r.StockItemNo END END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'QtyTotalWaste'       THEN CONVERT(decimal(38,6), r.QtyTotalWaste)
                             WHEN 'QtyBadWaste'         THEN CONVERT(decimal(38,6), r.QtyBadWaste)
                             WHEN 'TotalAtSellingPrice' THEN CONVERT(decimal(38,6), r.TotalAtSellingPrice) END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'QtyTotalWaste'       THEN CONVERT(decimal(38,6), r.QtyTotalWaste)
                             WHEN 'QtyBadWaste'         THEN CONVERT(decimal(38,6), r.QtyBadWaste)
                             WHEN 'TotalAtSellingPrice' THEN CONVERT(decimal(38,6), r.TotalAtSellingPrice) END END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'TransactionDate' THEN CONVERT(datetime2, r.TransactionDate) END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'TransactionDate' THEN CONVERT(datetime2, r.TransactionDate) END END DESC,
        r.TransactionDate DESC, r.BranchName, r.AreaNo, r.StockItemNo
    OFFSET (@Page - 1) * @PageSize ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT @Total AS TotalRows;
END
