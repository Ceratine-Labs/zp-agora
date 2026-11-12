/* Fuel matrix and literal POS price observations. READ ONLY.
   Source grains were verified 8 November 2026. No row is selected as the
   authoritative price; dates, nulls and different prices remain independent.
   Date range means DOE period intersection, or POS trading Date respectively.
   S_Date is separately exposed as SourceDate, never silently substituted.
   Deployed by v1__11p_fuel_procs.php; re-sent by v1__11pa (which adds the
   zero-price note: Diesel 50 ppm carries 0 cost, 0 price and 0 margin on the
   DOE matrix at most sites, and an average across grades is diluted unless
   those rows are seen). First set: page; second: TotalRows.
   EXEC agora.usp_Fuel_GridPrices @BranchIds='18', @DateFrom='20260801', @DateTo='20260831'; */
CREATE OR ALTER PROCEDURE [agora].[usp_Fuel_GridPrices]
    @BranchIds nvarchar(max) = NULL,
    @DateFrom date = NULL,
    @DateTo date = NULL,
    @Search nvarchar(200) = NULL,
    @SortColumn nvarchar(80) = NULL,
    @SortAsc bit = 1,
    @Page int = 1,
    @PageSize int = 50,
    @FiltersJson nvarchar(max) = NULL,
    @AllowedBranchIds nvarchar(max) = NULL,
    @Dataset nvarchar(20) = 'periods',
    @RowKey varchar(64) = NULL,
    @IncludeChart bit = 0
AS
BEGIN
    SET NOCOUNT ON;
    SET @DateTo=ISNULL(@DateTo,CONVERT(date,GETDATE()));
    SET @DateFrom=ISNULL(@DateFrom,DATEADD(day,-365,@DateTo));
    IF @DateFrom>@DateTo OR DATEDIFF(day,@DateFrom,@DateTo)>3660
        THROW 51000,'AGORA:DATE_RANGE:Choose an ordered date range of ten years or less.',1;
    IF @Dataset IS NULL OR @Dataset NOT IN ('periods','observations')
        THROW 51000,'AGORA:FUEL_SOURCE:Choose DOE periods or POS observations.',1;
    SET @Page=CASE WHEN @Page>0 THEN @Page ELSE 1 END;
    SET @PageSize=CASE WHEN @PageSize BETWEEN 1 AND 100000 THEN @PageSize ELSE 50 END;
    SET @Search=NULLIF(LTRIM(RTRIM(@Search)),'');
    IF @FiltersJson IS NOT NULL AND ISJSON(@FiltersJson)<>1
        THROW 51000,'AGORA:FILTERS:The column filters are not valid JSON.',1;

    CREATE TABLE #FuelRows (
        RowKey varchar(64) NOT NULL, BranchId int NOT NULL, BranchName nvarchar(200) NOT NULL,
        FuelTypeNo int NOT NULL, FuelTypeDescription nvarchar(35) NULL,
        StartDate datetime NULL, EndDate datetime NULL, TradingDate datetime NULL, SourceDate datetime NULL,
        CostPrice decimal(18,6) NULL, SellingPrice decimal(18,6) NULL, Margin decimal(18,6) NULL,
        FleetMargin decimal(18,6) NULL, IsFleetCard bit NULL, POSType nvarchar(50) NULL,
        POSIdentifier nvarchar(50) NULL, EodCounter char(8) NULL, HandlingFee money NULL,
        SellingPriceMatrix decimal(18,6) NULL, SalesVariance decimal(18,6) NULL
    );
    IF @Dataset='periods'
    BEGIN
        INSERT INTO #FuelRows
        SELECT CONVERT(varchar(64),HASHBYTES('SHA2_256',CONCAT(p.BranchId,'|',p.FuelTypeNo,'|',CONVERT(varchar(23),p.StartDate,121),'|',CONVERT(varchar(23),p.EndDate,121))),2),
               p.BranchId,COALESCE(b.Name,CONCAT('Site ',p.BranchId)),p.FuelTypeNo,p.FuelTypeDescription,
               p.StartDate,p.EndDate,NULL,NULL,p.CostPrice,p.SellingPrice,p.Margin,p.FleetMargin,
               NULL,NULL,NULL,NULL,NULL,NULL,NULL
        FROM agora.vw_FuelDoePeriod p
        LEFT JOIN agora.Branch b ON b.BranchId=p.BranchId
        WHERE CONVERT(date,p.StartDate)<=@DateTo AND p.EndDate>=@DateFrom
          AND (@BranchIds IS NULL OR p.BranchId IN (SELECT TRY_CONVERT(int,value) FROM STRING_SPLIT(@BranchIds,',')))
          AND (@AllowedBranchIds IS NULL OR p.BranchId IN (SELECT TRY_CONVERT(int,value) FROM STRING_SPLIT(@AllowedBranchIds,',')));
    END
    ELSE
    BEGIN
        INSERT INTO #FuelRows
        SELECT CONVERT(varchar(64),HASHBYTES('SHA2_256',CONCAT(p.BranchId,'|',p.FuelTypeNo,'|',CONVERT(varchar(23),p.TradingDate,121),'|',CONVERT(varchar(23),p.SourceDate,121),'|',p.EodCounter,'|',p.SellingPrice)),2),
               p.BranchId,COALESCE(b.Name,CONCAT('Site ',p.BranchId)),p.FuelTypeNo,p.FuelTypeDescription,
               NULL,NULL,p.TradingDate,p.SourceDate,p.CostPrice,p.SellingPrice,p.Margin,NULL,
               p.IsFleetCard,p.POSType,p.POSIdentifier,p.EodCounter,p.HandlingFee,p.SellingPriceMatrix,p.SalesVariance
        FROM agora.vw_FuelObservation p
        LEFT JOIN agora.Branch b ON b.BranchId=p.BranchId
        WHERE p.TradingDate>=@DateFrom AND CONVERT(date,p.TradingDate)<=@DateTo
          AND (@BranchIds IS NULL OR p.BranchId IN (SELECT TRY_CONVERT(int,value) FROM STRING_SPLIT(@BranchIds,',')))
          AND (@AllowedBranchIds IS NULL OR p.BranchId IN (SELECT TRY_CONVERT(int,value) FROM STRING_SPLIT(@AllowedBranchIds,',')));
    END;

    ALTER TABLE #FuelRows ADD SourceNote nvarchar(250) NULL;
    UPDATE r SET SourceNote=CONCAT(
        CASE WHEN @Dataset='periods' AND EXISTS (
            SELECT 1 FROM agora.vw_FuelDoePeriod p
            WHERE p.BranchId=r.BranchId AND p.FuelTypeNo=r.FuelTypeNo
              AND p.StartDate<=r.EndDate AND p.EndDate>=r.StartDate
              AND (p.StartDate<>r.StartDate OR p.EndDate<>r.EndDate)
        ) THEN 'Overlapping DOE period. ' ELSE '' END,
        CASE WHEN @Dataset='observations' AND EXISTS (
            SELECT 1 FROM #FuelRows o WHERE o.BranchId=r.BranchId AND o.FuelTypeNo=r.FuelTypeNo
              AND o.IsFleetCard=r.IsFleetCard AND CONVERT(date,o.SourceDate)=CONVERT(date,r.SourceDate)
              AND o.SellingPrice<>r.SellingPrice
        ) THEN 'Multiple observed prices on source date. ' ELSE '' END,
        CASE WHEN CONVERT(date,r.TradingDate)<>CONVERT(date,r.SourceDate) THEN 'Trading and source dates differ. ' ELSE '' END,
        CASE WHEN r.SellingPrice = 0 THEN 'Zero price recorded. ' ELSE '' END,
        CASE WHEN r.CostPrice IS NULL THEN 'Cost missing. ' ELSE '' END,
        CASE WHEN r.Margin IS NULL THEN 'Margin missing.' ELSE '' END
    ) FROM #FuelRows r;

    SELECT r.* INTO #Filtered
    FROM #FuelRows r
    WHERE (@RowKey IS NULL OR r.RowKey=@RowKey)
      AND (@Search IS NULL OR CONCAT(r.BranchName,' ',r.FuelTypeNo,' ',r.FuelTypeDescription,' ',r.POSType,' ',r.POSIdentifier) LIKE '%'+@Search+'%')
      AND NOT EXISTS (
        SELECT 1 FROM OPENJSON(ISNULL(@FiltersJson,'[]')) j
        CROSS APPLY (SELECT JSON_VALUE(j.value,'$.column') AS Col,
                            JSON_VALUE(j.value,'$.type') AS Kind,
                            ISNULL(JSON_VALUE(j.value,'$.op'),'eq') AS Op,
                            JSON_VALUE(j.value,'$.value') AS Val) f
        OUTER APPLY (SELECT v.TextValue,v.NumberValue,v.DateValue
                     FROM (VALUES
                         ('BranchName',CONVERT(nvarchar(400),r.BranchName),NULL,NULL),
                         ('FuelTypeNo',NULL,CONVERT(decimal(19,6),r.FuelTypeNo),NULL),
                         ('FuelTypeDescription',CONVERT(nvarchar(400),r.FuelTypeDescription),NULL,NULL),
                         ('StartDate',NULL,NULL,CONVERT(date,r.StartDate)),
                         ('EndDate',NULL,NULL,CONVERT(date,r.EndDate)),
                         ('TradingDate',NULL,NULL,CONVERT(date,r.TradingDate)),
                         ('SourceDate',NULL,NULL,CONVERT(date,r.SourceDate)),
                         ('CostPrice',NULL,CONVERT(decimal(19,6),r.CostPrice),NULL),
                         ('SellingPrice',NULL,CONVERT(decimal(19,6),r.SellingPrice),NULL),
                         ('Margin',NULL,CONVERT(decimal(19,6),r.Margin),NULL),
                         ('FleetMargin',NULL,CONVERT(decimal(19,6),r.FleetMargin),NULL),
                         ('IsFleetCard',NULL,CONVERT(decimal(19,6),r.IsFleetCard),NULL),
                         ('POSType',CONVERT(nvarchar(400),r.POSType),NULL,NULL),
                         ('POSIdentifier',CONVERT(nvarchar(400),r.POSIdentifier),NULL,NULL),
                         ('EodCounter',CONVERT(nvarchar(400),r.EodCounter),NULL,NULL),
                         ('HandlingFee',NULL,CONVERT(decimal(19,6),r.HandlingFee),NULL),
                         ('SellingPriceMatrix',NULL,CONVERT(decimal(19,6),r.SellingPriceMatrix),NULL),
                         ('SalesVariance',NULL,CONVERT(decimal(19,6),r.SalesVariance),NULL)
                     ) v(Col,TextValue,NumberValue,DateValue) WHERE v.Col=f.Col) x
        WHERE CASE
            WHEN f.Kind='set' AND EXISTS (SELECT 1 FROM OPENJSON(j.value,'$.in') s
                WHERE s.value=x.TextValue OR TRY_CONVERT(decimal(19,6),s.value)=x.NumberValue
                   OR TRY_CONVERT(date,s.value)=x.DateValue) THEN 1
            WHEN f.Kind='text' AND f.Op='eq' AND x.TextValue=f.Val THEN 1
            WHEN f.Kind='text' AND f.Op='ne' AND x.TextValue<>f.Val THEN 1
            WHEN f.Kind='text' AND f.Op='gt' AND x.TextValue>f.Val THEN 1
            WHEN f.Kind='text' AND f.Op='gte' AND x.TextValue>=f.Val THEN 1
            WHEN f.Kind='text' AND f.Op='lt' AND x.TextValue<f.Val THEN 1
            WHEN f.Kind='text' AND f.Op='lte' AND x.TextValue<=f.Val THEN 1
            WHEN f.Kind='number' AND f.Op='eq' AND x.NumberValue=TRY_CONVERT(decimal(19,6),f.Val) THEN 1
            WHEN f.Kind='number' AND f.Op='ne' AND x.NumberValue<>TRY_CONVERT(decimal(19,6),f.Val) THEN 1
            WHEN f.Kind='number' AND f.Op='gt' AND x.NumberValue>TRY_CONVERT(decimal(19,6),f.Val) THEN 1
            WHEN f.Kind='number' AND f.Op='gte' AND x.NumberValue>=TRY_CONVERT(decimal(19,6),f.Val) THEN 1
            WHEN f.Kind='number' AND f.Op='lt' AND x.NumberValue<TRY_CONVERT(decimal(19,6),f.Val) THEN 1
            WHEN f.Kind='number' AND f.Op='lte' AND x.NumberValue<=TRY_CONVERT(decimal(19,6),f.Val) THEN 1
            WHEN f.Kind='date' AND f.Op='eq' AND x.DateValue=TRY_CONVERT(date,f.Val) THEN 1
            WHEN f.Kind='date' AND f.Op='ne' AND x.DateValue<>TRY_CONVERT(date,f.Val) THEN 1
            WHEN f.Kind='date' AND f.Op='gt' AND x.DateValue>TRY_CONVERT(date,f.Val) THEN 1
            WHEN f.Kind='date' AND f.Op='gte' AND x.DateValue>=TRY_CONVERT(date,f.Val) THEN 1
            WHEN f.Kind='date' AND f.Op='lt' AND x.DateValue<TRY_CONVERT(date,f.Val) THEN 1
            WHEN f.Kind='date' AND f.Op='lte' AND x.DateValue<=TRY_CONVERT(date,f.Val) THEN 1
            WHEN f.Kind='text' AND f.Op='contains' AND CHARINDEX(f.Val,x.TextValue)>0 THEN 1
            ELSE 0 END=0
      );
    SELECT * FROM #Filtered
    ORDER BY
        CASE WHEN @SortColumn='BranchName' AND @SortAsc=1 THEN BranchName END ASC,
        CASE WHEN @SortColumn='BranchName' AND @SortAsc=0 THEN BranchName END DESC,
        CASE WHEN @SortColumn='FuelTypeNo' AND @SortAsc=1 THEN FuelTypeNo END ASC,
        CASE WHEN @SortColumn='FuelTypeNo' AND @SortAsc=0 THEN FuelTypeNo END DESC,
        CASE WHEN @SortColumn='FuelTypeDescription' AND @SortAsc=1 THEN FuelTypeDescription END ASC,
        CASE WHEN @SortColumn='FuelTypeDescription' AND @SortAsc=0 THEN FuelTypeDescription END DESC,
        CASE WHEN @SortColumn='StartDate' AND @SortAsc=1 THEN StartDate END ASC,
        CASE WHEN @SortColumn='StartDate' AND @SortAsc=0 THEN StartDate END DESC,
        CASE WHEN @SortColumn='EndDate' AND @SortAsc=1 THEN EndDate END ASC,
        CASE WHEN @SortColumn='EndDate' AND @SortAsc=0 THEN EndDate END DESC,
        CASE WHEN @SortColumn='TradingDate' AND @SortAsc=1 THEN TradingDate END ASC,
        CASE WHEN @SortColumn='TradingDate' AND @SortAsc=0 THEN TradingDate END DESC,
        CASE WHEN @SortColumn='SourceDate' AND @SortAsc=1 THEN SourceDate END ASC,
        CASE WHEN @SortColumn='SourceDate' AND @SortAsc=0 THEN SourceDate END DESC,
        CASE WHEN @SortColumn='CostPrice' AND @SortAsc=1 THEN CostPrice END ASC,
        CASE WHEN @SortColumn='CostPrice' AND @SortAsc=0 THEN CostPrice END DESC,
        CASE WHEN @SortColumn='SellingPrice' AND @SortAsc=1 THEN SellingPrice END ASC,
        CASE WHEN @SortColumn='SellingPrice' AND @SortAsc=0 THEN SellingPrice END DESC,
        CASE WHEN @SortColumn='Margin' AND @SortAsc=1 THEN Margin END ASC,
        CASE WHEN @SortColumn='Margin' AND @SortAsc=0 THEN Margin END DESC,
        CASE WHEN @SortColumn='FleetMargin' AND @SortAsc=1 THEN FleetMargin END ASC,
        CASE WHEN @SortColumn='FleetMargin' AND @SortAsc=0 THEN FleetMargin END DESC,
        CASE WHEN @SortColumn='IsFleetCard' AND @SortAsc=1 THEN IsFleetCard END ASC,
        CASE WHEN @SortColumn='IsFleetCard' AND @SortAsc=0 THEN IsFleetCard END DESC,
        CASE WHEN @SortColumn='POSType' AND @SortAsc=1 THEN POSType END ASC,
        CASE WHEN @SortColumn='POSType' AND @SortAsc=0 THEN POSType END DESC,
        CASE WHEN @SortColumn='POSIdentifier' AND @SortAsc=1 THEN POSIdentifier END ASC,
        CASE WHEN @SortColumn='POSIdentifier' AND @SortAsc=0 THEN POSIdentifier END DESC,
        CASE WHEN @SortColumn='EodCounter' AND @SortAsc=1 THEN EodCounter END ASC,
        CASE WHEN @SortColumn='EodCounter' AND @SortAsc=0 THEN EodCounter END DESC,
        CASE WHEN @SortColumn='HandlingFee' AND @SortAsc=1 THEN HandlingFee END ASC,
        CASE WHEN @SortColumn='HandlingFee' AND @SortAsc=0 THEN HandlingFee END DESC,
        CASE WHEN @SortColumn='SellingPriceMatrix' AND @SortAsc=1 THEN SellingPriceMatrix END ASC,
        CASE WHEN @SortColumn='SellingPriceMatrix' AND @SortAsc=0 THEN SellingPriceMatrix END DESC,
        CASE WHEN @SortColumn='SalesVariance' AND @SortAsc=1 THEN SalesVariance END ASC,
        CASE WHEN @SortColumn='SalesVariance' AND @SortAsc=0 THEN SalesVariance END DESC,
        BranchName,FuelTypeNo,StartDate,TradingDate,SourceDate,RowKey
    OFFSET (CONVERT(bigint,@Page)-1)*@PageSize ROWS FETCH NEXT @PageSize ROWS ONLY;
    SELECT COUNT_BIG(*) AS TotalRows FROM #Filtered;

    /* Chart and change log use the same filtered population, never just the page.
       Grouping is an explicit range, not a winning observation. NULLs are not
       filled from DOE or another day. SourceDate is labelled as such in the UI. */
    IF @IncludeChart=1
    BEGIN
        ;WITH daily AS (
            SELECT BranchId,MAX(BranchName) AS BranchName,FuelTypeNo,
                   CASE WHEN COUNT(DISTINCT FuelTypeDescription)>1 THEN CONCAT('Grade ',FuelTypeNo,' (multiple labels)')
                        ELSE MAX(FuelTypeDescription) END AS FuelTypeDescription,IsFleetCard,
                   CONVERT(date,CASE WHEN @Dataset='periods' THEN StartDate ELSE SourceDate END) AS ChartDate,
                   MIN(SellingPrice) AS SellLow,MAX(SellingPrice) AS SellHigh,
                   MIN(CostPrice) AS CostLow,MAX(CostPrice) AS CostHigh,
                   MIN(Margin) AS MarginLow,MAX(Margin) AS MarginHigh,
                   COUNT_BIG(*) AS Observations,
                   SUM(CASE WHEN CostPrice IS NULL THEN 1 ELSE 0 END) AS MissingCost,
                   SUM(CASE WHEN Margin IS NULL THEN 1 ELSE 0 END) AS MissingMargin,
                   MAX(CASE WHEN SourceNote LIKE '%Overlapping%' THEN 1 ELSE 0 END) AS Overlap
            FROM #Filtered
            GROUP BY BranchId,FuelTypeNo,IsFleetCard,
                     CONVERT(date,CASE WHEN @Dataset='periods' THEN StartDate ELSE SourceDate END)
        )
        SELECT *,
               LAG(SellLow) OVER(PARTITION BY BranchId,FuelTypeNo,IsFleetCard ORDER BY ChartDate) AS PreviousSellLow,
               LAG(SellHigh) OVER(PARTITION BY BranchId,FuelTypeNo,IsFleetCard ORDER BY ChartDate) AS PreviousSellHigh
        FROM daily ORDER BY BranchId,FuelTypeNo,IsFleetCard,ChartDate;
    END;
END;
