/* Read-only source for the weekly pack. Original and live quantities remain
   separate. A nonunique master match is a source failure, never a fan-out. */
CREATE OR ALTER PROCEDURE agora.usp_StockRecon_WeeklyRecon
    @BranchId INT, @FromDate DATE, @ToDate DATE
AS
BEGIN
    SET NOCOUNT ON;
    IF @BranchId <= 0 OR @FromDate IS NULL OR @ToDate IS NULL OR DATEDIFF(day, @FromDate, @ToDate) NOT BETWEEN 0 AND 92
        THROW 51000, 'AGORA:WEEKLY_PERIOD:Choose a source period of at most 93 days.', 1;
    IF EXISTS (SELECT 1 FROM agora.vw_StockReconLine l
        CROSS APPLY (SELECT COUNT_BIG(*) AS Matches FROM agora.vw_StockMaster m
            WHERE m.BranchId = l.BranchId AND m.StockItemNo = l.StockItemNo AND m.AreaNo = l.AreaNo) m
        WHERE l.BranchId = @BranchId AND l.TransactionDate >= DATEADD(day,-7,@FromDate)
          AND l.TransactionDate < DATEADD(day,1,@ToDate) AND m.Matches <> 1)
        THROW 51000, 'AGORA:WEEKLY_MASTER:Resolve missing or duplicate stock-master mappings before building the week.', 1;
    SELECT l.AreaNo, l.StockItemNo, a.AreaDescription, m.StockItemDescription AS Description,
        UPPER(LTRIM(RTRIM(m.StockLocation))) AS SourceSystem, LTRIM(RTRIM(m.POSCode)) AS POSCode,
        CONVERT(decimal(19,4),COALESCE(im.IssueMultiple,si.IssueMultiple,0)) AS IssueMultiple,
        CONVERT(bit,CASE WHEN im.IsWeighed=1 OR si.UOMCode IN ('KG','KGS','L','LT','LTR') THEN 1 ELSE 0 END) AS IsWeighed,
        CONVERT(date,l.TransactionDate) AS TransactionDate, l.ShiftNo,
        CONVERT(decimal(19,4),l.SellPrice) AS UnitSellingPrice,
        CONVERT(decimal(19,4),l.QtyOpen) AS QtyOpen,
        CONVERT(decimal(19,4),l.QtyIssued) AS QtyIssued,
        CONVERT(decimal(19,4),l.QtyClose) AS QtyClose,
        CONVERT(decimal(19,4),l.QtyComputer) AS QtyPOS,
        CONVERT(decimal(19,4),l.QtyOpen_Original) AS OriginalOpen,
        CONVERT(decimal(19,4),l.QtyIssued_Original) AS OriginalIssued,
        CONVERT(decimal(19,4),l.QtyClose_Original) AS OriginalClose,
        CONVERT(decimal(19,4),l.QtyComputer_Original) AS OriginalPOS
    FROM agora.vw_StockReconLine l
    JOIN agora.vw_StockArea a ON a.BranchId = l.BranchId AND a.AreaNo = l.AreaNo
    JOIN agora.vw_StockMaster m ON m.BranchId = l.BranchId AND m.StockItemNo = l.StockItemNo AND m.AreaNo = l.AreaNo
    LEFT JOIN agora.StockIssueMultiple im ON im.BranchId=l.BranchId AND im.AreaNo=l.AreaNo AND im.StockItemNo=l.StockItemNo
    OUTER APPLY(SELECT TOP(1) x.IssueMultiple,x.UOMCode FROM agora.vw_StockItem x
        WHERE x.BranchId=l.BranchId AND x.AreaNo=l.AreaNo AND x.StockItemNo=l.StockItemNo ORDER BY x.PosSystem) si
    WHERE l.BranchId = @BranchId AND l.TransactionDate >= DATEADD(day,-7,@FromDate)
      AND l.TransactionDate < DATEADD(day,1,@ToDate)
      AND ISNULL(a.AreaGroup,'') NOT LIKE '%FUEL%' AND a.AreaDescription NOT LIKE 'OK %'
    ORDER BY l.AreaNo,l.StockItemNo,l.TransactionDate,l.ShiftNo;
END
