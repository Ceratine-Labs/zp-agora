/* Read-only physical-product DET sales fallback (report 1227): retain the
   last export per EOD, sales with rand, and cost-of-sales / sales quantity.
   A product with conflicting fallback categories remains unavailable instead
   of guessing how the customer's category-mapping report combines it. */
CREATE OR ALTER PROCEDURE agora.usp_StockRecon_WeeklyCosts
    @BranchId INT,@FromDate DATE,@ToDate DATE
AS
BEGIN
    SET NOCOUNT ON;
    IF @BranchId<=0 OR @BranchId IS NULL OR @FromDate IS NULL OR @ToDate IS NULL OR DATEDIFF(day,@FromDate,@ToDate) NOT BETWEEN 0 AND 92
        THROW 51000, 'AGORA:WEEKLY_PERIOD:Choose a site and a cost period of at most 93 days.', 1;
    IF OBJECT_ID('agora.vw_WeeklySalesCost','V') IS NULL OR OBJECT_ID('agora.vw_WeeklyWinbranchCost','V') IS NULL
        THROW 51000, 'AGORA:WEEKLY_SOURCE_UNAVAILABLE:DET sales cost fallback sources are not installed.', 1;
    ;WITH exports AS (
        SELECT *,MAX(ExportRegisterId) OVER(PARTITION BY BranchId,EOD_CNTR) AS KeepExport
        FROM agora.vw_WeeklySalesCost WHERE BranchId=@BranchId AND SalesDate>=@FromDate AND SalesDate<DATEADD(day,1,@ToDate)
            AND ((TYPE='I' AND DRCR='D' AND DRC='D') OR TYPE='S')
    ), mapped AS (
        SELECT e.*,LTRIM(RTRIM(COALESCE(s.CAT,m.CAT,p.CAT,''))) AS Category
        FROM exports e
        OUTER APPLY(SELECT TOP(1) CAT FROM agora.vw_WeeklyWinbranchCost WHERE BranchId=e.BranchId AND POSCode=e.POSCode ORDER BY UnitCost DESC,Description) s
        OUTER APPLY(SELECT TOP(1) CAT FROM agora.vw_WeeklyWinbranchCost WHERE BranchId=e.BranchId AND POSCode=e.MCODE ORDER BY UnitCost DESC,Description) m
        OUTER APPLY(SELECT TOP(1) CAT FROM agora.vw_WeeklyWinbranchCost WHERE BranchId=e.BranchId AND PACKCODE=e.POSCode ORDER BY UnitCost DESC,Description) p
        WHERE ExportRegisterId=KeepExport AND TYPE='I'
    )
    SELECT 'WINBRANCH' AS SourceSystem,LTRIM(RTRIM(POSCode)) AS POSCode,
        CASE WHEN COUNT(DISTINCT Category)=1 AND MAX(Category) NOT IN ('FUEL','VVONL','DSTV')
            AND SUM(CASE WHEN SALES=0 THEN 0 ELSE QTY END)>0
            THEN CONVERT(decimal(19,2),SUM(COST))/NULLIF(CONVERT(decimal(19,2),SUM(CASE WHEN SALES=0 THEN 0 ELSE QTY END)),0) END AS UnitCost,
        CASE WHEN COUNT(DISTINCT Category)>1 THEN 'Unavailable: conflicting product categories require the DET report'
            WHEN MAX(Category) IN ('FUEL','VVONL','DSTV') THEN 'Unavailable: excluded non-stock category' ELSE 'DET sales cost / positive sales quantity' END AS CostBasis
    FROM mapped GROUP BY POSCode;
END
