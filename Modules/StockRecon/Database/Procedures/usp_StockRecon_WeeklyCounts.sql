/* Read-only adapters for the actual export SQL: SS_Report1307,1308,1321,539.
   Missing source, missing prior count and null source operands remain unknown.
   Returns count facts, then source coverage. No customer procedure is executed. */
CREATE OR ALTER PROCEDURE agora.usp_StockRecon_WeeklyCounts
    @BranchId INT, @FromDate DATE, @ToDate DATE
AS
BEGIN
    SET NOCOUNT ON;
    IF @BranchId <= 0 OR @FromDate IS NULL OR @ToDate IS NULL OR DATEDIFF(day,@FromDate,@ToDate) NOT BETWEEN 0 AND 92
        THROW 51000, 'AGORA:WEEKLY_PERIOD:Choose a source period of at most 93 days.', 1;
    DECLARE @PriorFrom date = DATEADD(day,-7,@FromDate), @Until date = DATEADD(day,1,@ToDate);
    CREATE TABLE #Counts (SourceSystem nvarchar(20) COLLATE DATABASE_DEFAULT, POSCode nvarchar(80) COLLATE DATABASE_DEFAULT, CountDate date,
        VarianceQty decimal(19,4) NULL, CountedQty decimal(19,4) NULL, ExpectedQty decimal(19,4) NULL,
        UnitCost decimal(19,4) NULL, Reference nvarchar(160) COLLATE DATABASE_DEFAULT, DetailJson nvarchar(max) COLLATE DATABASE_DEFAULT);
    CREATE TABLE #Coverage (SourceSystem nvarchar(20) COLLATE DATABASE_DEFAULT, SourceName nvarchar(100) COLLATE DATABASE_DEFAULT,
        State nvarchar(30) COLLATE DATABASE_DEFAULT, Note nvarchar(400) COLLATE DATABASE_DEFAULT);
    IF OBJECT_ID('agora.vw_WeeklyWinbranchCount','V') IS NOT NULL
    BEGIN
        INSERT #Counts
        SELECT 'WINBRANCH', LTRIM(RTRIM(POSCode)), CONVERT(date,CountDate), QTY-QTYREC,QTY,QTYREC,NULL,
               CONCAT('S/TAKE ',DETAILS), (SELECT 'SS_Report 1307' AS SourceReport,'quantity' AS ValueBasis FOR JSON PATH,WITHOUT_ARRAY_WRAPPER)
        FROM agora.vw_WeeklyWinbranchCount
        WHERE BranchId=@BranchId AND CountDate>=@PriorFrom AND CountDate<@Until AND TYPE='S' AND LTRIM(RTRIM(REF))='S/TAKE';
        INSERT #Coverage VALUES ('WINBRANCH','WINBRANCH_INVHISTF','Available','S/TAKE facts include nil variances; no row is not a zero.');
    END
    ELSE INSERT #Coverage VALUES ('WINBRANCH','WINBRANCH_INVHISTF','Unavailable','Stocktake source is not installed.');

    IF OBJECT_ID('agora.vw_WeeklyWinbranchLastCount','V') IS NOT NULL
    BEGIN
        /* Historical latest-date proof is usable only when it falls IN this
           week; a newer latest date cannot prove a missing earlier count. */
        IF OBJECT_ID('agora.vw_WeeklyWinbranchCount','V') IS NOT NULL
        INSERT #Counts
        SELECT 'WINBRANCH', LTRIM(RTRIM(l.POSCode)), MAX(CONVERT(date,l.LastCountDate)),0,NULL,NULL,NULL,
            'Last stocktake date; no variance row', N'{"SourceReport":"SS_Report 539","Proof":"Latest stocktake date in selected week; no variance row"}'
        FROM agora.vw_WeeklyWinbranchLastCount l
        WHERE l.BranchId=@BranchId AND UPPER(LTRIM(RTRIM(l.LOCATION)))='WINBRANCH'
          AND l.LastCountDate>=@FromDate AND l.LastCountDate<@Until
          AND NOT EXISTS (SELECT 1 FROM #Counts c WHERE c.SourceSystem='WINBRANCH'
              AND c.POSCode=LTRIM(RTRIM(l.POSCode)) AND c.CountDate>=@FromDate)
        GROUP BY l.POSCode;
        INSERT #Coverage VALUES ('WINBRANCH','DBF_STDB.L_STKTAKE','Available','Latest count date is evidence only, not a history of earlier nil counts.');
    END
    ELSE INSERT #Coverage VALUES ('WINBRANCH','DBF_STDB.L_STKTAKE','Unavailable','Nil-count proof and older count recency unavailable.');

    IF OBJECT_ID('agora.vw_WeeklyWinbranchCost','V') IS NOT NULL
    BEGIN
        UPDATE c SET UnitCost=p.UnitCost,DetailJson=JSON_MODIFY(c.DetailJson,'$.ValueBasis','SS_Report 1307 latest master unit cost at source read')
        FROM #Counts c OUTER APPLY(SELECT TOP(1) UnitCost FROM agora.vw_WeeklyWinbranchCost m
            WHERE m.BranchId=@BranchId AND LTRIM(RTRIM(m.POSCode))=c.POSCode ORDER BY UnitCost DESC,Description) p
        WHERE c.SourceSystem='WINBRANCH';
        INSERT #Coverage VALUES ('WINBRANCH','WINBRANCH_STDB latest cost','Available','Report 1307 chooses latest master cost at read time, not an immutable historical purchase cost.');
    END
    ELSE INSERT #Coverage VALUES ('WINBRANCH','WINBRANCH_STDB latest cost','Unavailable','Count cost missing; use DET fallback or explicitly labelled pack estimate.');

    IF OBJECT_ID('agora.vw_WeeklyPilotCount','V') IS NOT NULL
    BEGIN
        INSERT #Counts
        SELECT 'PILOT',LTRIM(RTRIM(POSCode)),CONVERT(date,CountDate),
            CSTK-(OSTK+TRANS+MAKE-ISSUE-SALES),CSTK,OSTK+TRANS+MAKE-ISSUE-SALES,COST,
            CONCAT('Prep sheet ',PrepsheetHistoryId,' / store ',StoreId),
            (SELECT 'SS_Report 1321' AS SourceReport,CapturedAt,'unit cost' AS ValueBasis FOR JSON PATH,WITHOUT_ARRAY_WRAPPER)
        FROM agora.vw_WeeklyPilotCount
        WHERE BranchId=@BranchId AND CountDate>=@PriorFrom AND CountDate<@Until AND LTRIM(RTRIM(POSCode))<>'-1';
        INSERT #Coverage VALUES ('PILOT','PILOT_PrepsheetHistory','Available','Daily prep-count variance; product -1 is excluded.');
    END
    ELSE INSERT #Coverage VALUES ('PILOT','PILOT_PrepsheetHistory','Unavailable','Daily count source is not installed.');

    IF OBJECT_ID('agora.vw_WeeklyAuraCount','V') IS NOT NULL AND OBJECT_ID('agora.vw_WeeklyAuraPurchase','V') IS NOT NULL
       AND OBJECT_ID('agora.vw_WeeklyAuraSale','V') IS NOT NULL AND OBJECT_ID('agora.vw_WeeklyAuraPrice','V') IS NOT NULL
       AND OBJECT_ID('agora.vw_WeeklyAuraRecipe','V') IS NOT NULL
    BEGIN
        ;WITH cnt AS (
            SELECT LocationId,POSCode,CONVERT(date,CountDate) AS CountDate,
                SUM(Prepped+Unprepped) AS CountQty, MAX(BulkUnitPrice) AS UnitCost
            FROM agora.vw_WeeklyAuraCount
            WHERE BranchId=@BranchId AND Entered=1 AND CountDate>=DATEADD(day,-60,@PriorFrom) AND CountDate<@Until
            GROUP BY LocationId,POSCode,CONVERT(date,CountDate)
        ), seq AS (
            SELECT *,LAG(CountDate) OVER(PARTITION BY LocationId,POSCode ORDER BY CountDate) AS PrevCountDate,
                LAG(CountQty) OVER(PARTITION BY LocationId,POSCode ORDER BY CountDate) AS PrevCountQty FROM cnt
        ), purchases AS (
            SELECT LocationId,POSCode,CONVERT(date,MovementDate) AS MovementDate,
                SUM(CASE WHEN BULKPCH<>0 THEN BULKPCH WHEN QUANTITY<>0 THEN QUANTITY
                    WHEN ISNULL(UNIT_PRICE,0)<>0 THEN PRICEPCH/UNIT_PRICE ELSE 0 END) AS Qty
            FROM agora.vw_WeeklyAuraPurchase WHERE BranchId=@BranchId AND MovementDate>=DATEADD(day,-60,@PriorFrom) AND MovementDate<@Until
            GROUP BY LocationId,POSCode,CONVERT(date,MovementDate)
        ), costpaid AS (
            SELECT POSCode,SUM(PRICEPCH)/NULLIF(SUM(CASE WHEN BULKPCH<>0 THEN BULKPCH WHEN QUANTITY<>0 THEN QUANTITY
                WHEN ISNULL(UNIT_PRICE,0)<>0 THEN PRICEPCH/UNIT_PRICE ELSE 0 END),0) AS UnitCost
            FROM agora.vw_WeeklyAuraPurchase WHERE BranchId=@BranchId AND MovementDate>=DATEADD(day,-180,@ToDate) AND MovementDate<@Until
            GROUP BY POSCode
        ), consumption AS (
            SELECT p.LocationId,r.POSCode,CONVERT(date,l.MovementDate) AS MovementDate,SUM(l.QUANTITY*r.QUANTITY) AS Qty
            FROM agora.vw_WeeklyAuraSale l
            JOIN agora.vw_WeeklyAuraPrice p ON p.BranchId=l.BranchId AND p.CODE=l.CODE
            JOIN agora.vw_WeeklyAuraRecipe r ON r.BranchId=p.BranchId AND r.RECIPE=p.RECIPE
            WHERE l.BranchId=@BranchId AND ISNULL(l.PREVOID,0)=0 AND l.MovementDate>=DATEADD(day,-60,@PriorFrom) AND l.MovementDate<@Until
            GROUP BY p.LocationId,r.POSCode,CONVERT(date,l.MovementDate)
        )
        INSERT #Counts
        SELECT 'AURA',LTRIM(RTRIM(s.POSCode)),s.CountDate,s.CountQty-e.ExpectedQty,s.CountQty,e.ExpectedQty,paid.UnitCost,
            CONCAT('Aura count / location ',s.LocationId),
            (SELECT 'SS_Report 1308' AS SourceReport,s.LocationId,s.PrevCountDate,s.PrevCountQty,
                    ISNULL(p.ReceivedQty,0) AS ReceivedQty,ISNULL(c.ConsumptionQty,0) AS RecipeConsumptionQty,
                    s.UnitCost AS SnapshotUnitCost,'SS_Report 1308 actual unit cost paid over 180 days; missing paid cost stays unknown' AS ValueBasis FOR JSON PATH,WITHOUT_ARRAY_WRAPPER)
        FROM seq s LEFT JOIN costpaid paid ON paid.POSCode=s.POSCode
        OUTER APPLY(SELECT SUM(Qty) AS ReceivedQty FROM purchases p WHERE p.LocationId=s.LocationId AND p.POSCode=s.POSCode
            AND p.MovementDate>s.PrevCountDate AND p.MovementDate<=s.CountDate) p
        OUTER APPLY(SELECT SUM(Qty) AS ConsumptionQty FROM consumption c WHERE c.LocationId=s.LocationId AND c.POSCode=s.POSCode
            AND c.MovementDate>s.PrevCountDate AND c.MovementDate<=s.CountDate) c
        CROSS APPLY(SELECT s.PrevCountQty+ISNULL(p.ReceivedQty,0)-ISNULL(c.ConsumptionQty,0) AS ExpectedQty) e
        WHERE s.CountDate>=@PriorFrom;
        INSERT #Coverage VALUES ('AURA','STOCK1 / STOCKPCH / LINEITEM / PRICES / RECIPE','Available','Entered counts only; previous count plus receipts minus recipe consumption. Missing previous count stays unknown.');
    END
    ELSE INSERT #Coverage VALUES ('AURA','STOCK1 / STOCKPCH / LINEITEM / PRICES / RECIPE','Unavailable','One or more required Aura count, receipt or recipe sources are not installed.');

    SELECT * FROM #Counts ORDER BY SourceSystem,POSCode,CountDate,Reference;
    SELECT * FROM #Coverage ORDER BY SourceSystem,SourceName;
    IF OBJECT_ID('agora.vw_WeeklyWinbranchLastCount','V') IS NOT NULL
        SELECT 'WINBRANCH' AS SourceSystem,LTRIM(RTRIM(POSCode)) AS POSCode,MAX(CONVERT(date,LastCountDate)) AS LastCountDate
        FROM agora.vw_WeeklyWinbranchLastCount WHERE BranchId=@BranchId AND UPPER(LTRIM(RTRIM(LOCATION)))='WINBRANCH'
        GROUP BY POSCode;
    ELSE SELECT CAST(NULL AS nvarchar(20)) AS SourceSystem,CAST(NULL AS nvarchar(80)) AS POSCode,CAST(NULL AS date) AS LastCountDate WHERE 1=0;
END
