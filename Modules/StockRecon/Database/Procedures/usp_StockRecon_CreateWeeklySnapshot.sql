/* The weekly.py/weeklines.py rules, applied to immutable source facts.
   SourceJson is composed server-side by the fixed readers, never form input.
   Scenario labels express measured differences; only a written investigation
   can establish a cause. No charge, balancing stamp or source correction. */
CREATE OR ALTER PROCEDURE agora.usp_StockRecon_CreateWeeklySnapshot
    @BranchId INT, @WeekStart DATE, @SnapshotRef UNIQUEIDENTIFIER,
    @SourceJson NVARCHAR(MAX), @CoverageJson NVARCHAR(MAX), @SourceReadAt DATETIME2,
    @UserId INT, @Authorized BIT = 0, @Workspace NVARCHAR(10) = N'branch', @BranchPin INT = NULL,
    @PeriodFrom DATE=NULL,@PeriodTo DATE=NULL,@ReportingAsOf DATE=NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    EXEC agora.usp_StockRecon_AuthorizeWeekly @BranchId,@UserId,@Authorized,@Workspace,@BranchPin;
    IF @WeekStart IS NULL OR DATEDIFF(day,CONVERT(date,'19000101'),@WeekStart)%7<>0
       OR DATEADD(day,6,@WeekStart)>=CONVERT(date,DATEADD(hour,2,SYSUTCDATETIME()))
        THROW 51000, 'AGORA:WEEKLY_PERIOD:Choose a completed Monday to Sunday week.', 1;
    IF ISJSON(@SourceJson)<>1 OR ISJSON(@CoverageJson)<>1 OR @SourceReadAt IS NULL
        THROW 51000, 'AGORA:WEEKLY_SOURCE:Valid source facts and coverage are required.', 1;
    DECLARE @WeekEnd date=DATEADD(day,6,@WeekStart),@Now datetime2=SYSUTCDATETIME();
    DECLARE @BuildControls bit=CASE WHEN @PeriodFrom IS NOT NULL THEN 1 ELSE 0 END;
    SET @PeriodFrom=ISNULL(@PeriodFrom,@WeekStart);
    SET @PeriodTo=ISNULL(@PeriodTo,@WeekEnd);
    SET @ReportingAsOf=ISNULL(@ReportingAsOf,CONVERT(date,DATEADD(hour,2,@SourceReadAt)));
    IF @PeriodFrom>@WeekStart OR @PeriodTo<@WeekEnd OR DATEDIFF(day,@PeriodFrom,@PeriodTo)>92
        OR @PeriodTo>=CONVERT(date,DATEADD(hour,2,@Now)) OR @ReportingAsOf<@PeriodTo
        OR @ReportingAsOf>CONVERT(date,DATEADD(hour,2,@Now))
        THROW 51000, 'AGORA:WEEKLY_PERIOD:The control period must contain the week and span at most 93 completed days; reporting date must be on or after the period.', 1;
    IF EXISTS(SELECT 1 FROM agora.StockWeeklySnapshot WHERE BranchId=@BranchId AND SnapshotRef=@SnapshotRef)
    BEGIN
        SELECT CAST(1 AS bit) AS Ok,'EXISTS' AS Code,'This snapshot is already saved.' AS Message,Id,@SnapshotRef AS SnapshotRef
        FROM agora.StockWeeklySnapshot WHERE BranchId=@BranchId AND SnapshotRef=@SnapshotRef;
        RETURN;
    END
    SELECT *, CASE WHEN RIGHT(Code1,2)='.0' THEN LEFT(Code1,LEN(Code1)-2) ELSE Code1 END AS Code
    INTO #Recon FROM OPENJSON(@SourceJson,'$.recon') WITH (
        AreaNo int,StockItemNo nvarchar(10),AreaDescription nvarchar(160),Description nvarchar(240),
        SourceSystem nvarchar(20),POSCode nvarchar(80),TransactionDate date,ShiftNo int,UnitSellingPrice decimal(19,4),IssueMultiple decimal(19,4),IsWeighed bit,
        QtyOpen decimal(19,4),QtyIssued decimal(19,4),QtyClose decimal(19,4),QtyPOS decimal(19,4),
        OriginalOpen decimal(19,4),OriginalIssued decimal(19,4),OriginalClose decimal(19,4),OriginalPOS decimal(19,4)
    ) r CROSS APPLY(SELECT CASE WHEN UPPER(LTRIM(RTRIM(POSCode))) LIKE 'PROD%'
        THEN SUBSTRING(UPPER(LTRIM(RTRIM(POSCode))),5,80) ELSE UPPER(LTRIM(RTRIM(POSCode))) END AS Code1) n;
    IF NOT EXISTS(SELECT 1 FROM #Recon WHERE TransactionDate BETWEEN @WeekStart AND @WeekEnd)
        THROW 51000, 'AGORA:WEEKLY_EMPTY:No recon rows were found for that site and week.', 1;
    IF EXISTS(SELECT 1 FROM #Recon WHERE AreaNo IS NULL OR StockItemNo IS NULL OR NULLIF(Code,'') IS NULL
        OR TransactionDate IS NULL OR ShiftNo IS NULL OR QtyOpen IS NULL OR QtyIssued IS NULL OR QtyClose IS NULL OR QtyPOS IS NULL)
        THROW 51000, 'AGORA:WEEKLY_SOURCE:Recon rows have missing keys or quantities. Resolve the source before classifying.', 1;
    IF EXISTS(SELECT 1 FROM #Recon GROUP BY AreaNo,StockItemNo,TransactionDate,ShiftNo HAVING COUNT(*)>1)
        THROW 51000, 'AGORA:WEEKLY_SOURCE:Duplicate recon source keys would multiply the week. Resolve them first.', 1;
    CREATE INDEX IX_WeeklyRecon_ItemDate ON #Recon(SourceSystem,Code,TransactionDate) INCLUDE(AreaNo,StockItemNo,QtyPOS,QtyIssued,QtyOpen,QtyClose,OriginalPOS,OriginalOpen,OriginalIssued,OriginalClose);
    CREATE INDEX IX_WeeklyRecon_AreaDate ON #Recon(AreaNo,StockItemNo,TransactionDate,ShiftNo);
    UPDATE #Recon SET SourceSystem='WINBRANCH' WHERE SourceSystem IN ('ARCH','ARCH LIQUOR');

    /* Pack cutoff: normal shift line count and nonzero closing density, then
       two complete shifts and normal sales density per day. Missing days are
       held too. Keep all source rows in the immutable evidence. */
    SELECT TransactionDate,ShiftNo,COUNT(*) AS N,
        AVG(CASE WHEN QtyClose<>0 THEN 1.0 ELSE 0.0 END) AS ClosingShare,
        AVG(CASE WHEN QtyPOS<>0 THEN 1.0 ELSE 0.0 END) AS SalesShare
    INTO #ShiftStats FROM #Recon GROUP BY TransactionDate,ShiftNo;
    DECLARE @MedianN float,@MedianClosing float,@MedianSales float;
    SELECT TOP(1) @MedianN=PERCENTILE_CONT(0.5) WITHIN GROUP(ORDER BY N) OVER(),
        @MedianClosing=PERCENTILE_CONT(0.5) WITHIN GROUP(ORDER BY ClosingShare) OVER(),
        @MedianSales=PERCENTILE_CONT(0.5) WITHIN GROUP(ORDER BY SalesShare) OVER() FROM #ShiftStats;
    DECLARE @CompleteThrough date=@WeekEnd,@FirstBad date;
    SELECT @FirstBad=MIN(d.DayDate) FROM (VALUES(0),(1),(2),(3),(4),(5),(6)) v(n)
    CROSS APPLY(SELECT DATEADD(day,v.n,@WeekStart) AS DayDate) d
    OUTER APPLY(SELECT COUNT(*) AS Shifts,MIN(CASE WHEN N>=0.95*@MedianN AND ClosingShare>=0.5*@MedianClosing THEN 1 ELSE 0 END) AS Complete,
        AVG(SalesShare) AS SalesShare FROM #ShiftStats s WHERE s.TransactionDate=d.DayDate) s
    WHERE s.Shifts<2 OR ISNULL(s.Complete,0)=0 OR s.SalesShare<0.5*@MedianSales;
    IF @FirstBad IS NOT NULL SET @CompleteThrough=DATEADD(day,-1,@FirstBad);
    IF @CompleteThrough<@WeekStart
        THROW 51000, 'AGORA:WEEKLY_INCOMPLETE:The first day has incomplete recon coverage. Complete its shifts before building a weekly investigation.', 1;

    SELECT *,CASE WHEN RIGHT(Code1,2)='.0' THEN LEFT(Code1,LEN(Code1)-2) ELSE Code1 END AS Code
    INTO #Counts FROM OPENJSON(@SourceJson,'$.counts') WITH (SourceSystem nvarchar(20),POSCode nvarchar(80),CountDate date,
        VarianceQty decimal(19,4),CountedQty decimal(19,4),ExpectedQty decimal(19,4),UnitCost decimal(19,4),Reference nvarchar(160),DetailJson nvarchar(max)) c
    CROSS APPLY(SELECT CASE WHEN UPPER(LTRIM(RTRIM(POSCode))) LIKE 'PROD%' THEN SUBSTRING(UPPER(LTRIM(RTRIM(POSCode))),5,80)
        ELSE UPPER(LTRIM(RTRIM(POSCode))) END AS Code1) n;
    SELECT *,CASE WHEN RIGHT(Code1,2)='.0' THEN LEFT(Code1,LEN(Code1)-2) ELSE Code1 END AS Code
    INTO #Evidence FROM OPENJSON(@SourceJson,'$.evidence') WITH (SourceSystem nvarchar(20),POSCode nvarchar(80),EvidenceType nvarchar(30),EvidenceDate date,
        Quantity decimal(19,4),Value decimal(19,4),Reference nvarchar(160),DetailJson nvarchar(max)) e
    CROSS APPLY(SELECT CASE WHEN UPPER(LTRIM(RTRIM(POSCode))) LIKE 'PROD%' THEN SUBSTRING(UPPER(LTRIM(RTRIM(POSCode))),5,80)
        ELSE UPPER(LTRIM(RTRIM(POSCode))) END AS Code1) n;
    CREATE INDEX IX_WeeklyCounts_ProductDate ON #Counts(SourceSystem,Code,CountDate);
    CREATE INDEX IX_WeeklyEvidence_ProductDate ON #Evidence(SourceSystem,Code,EvidenceDate,EvidenceType);
    ;WITH ordered AS (
        SELECT *,ROW_NUMBER() OVER(PARTITION BY AreaNo,StockItemNo ORDER BY TransactionDate DESC,ShiftNo DESC) AS Latest
        FROM #Recon WHERE TransactionDate BETWEEN @WeekStart AND @CompleteThrough
    )
    SELECT AreaNo,StockItemNo,MAX(AreaDescription) AS AreaDescription,MAX(Description) AS Description,
        MAX(SourceSystem) AS SourceSystem,MAX(Code) AS POSCode,MAX(CASE WHEN Latest=1 THEN UnitSellingPrice END) AS UnitSellingPrice,
        SUM(QtyPOS-(QtyOpen+QtyIssued-QtyClose)) AS ReconQty,
        CASE WHEN COUNT(OriginalPOS+OriginalOpen+OriginalIssued+OriginalClose)=COUNT(*)
            THEN SUM(OriginalPOS-(OriginalOpen+OriginalIssued-OriginalClose)) END AS OriginalReconQty,
        SUM(QtyIssued) AS IssuedQty,SUM(QtyPOS) AS SalesQty,MAX(IssueMultiple) AS IssueMultiple,MAX(CONVERT(int,IsWeighed)) AS IsWeighed
    INTO #Cases FROM ordered GROUP BY AreaNo,StockItemNo;
    ALTER TABLE #Cases ADD CountQty decimal(19,4),PreviousCountQty decimal(19,4),CountRows int,LastCountDate date,
        StoreroomQty decimal(19,4),SharedCountCases int,Scenario nvarchar(8),Z decimal(19,4),
        IorDays int,IorMatched int,IorReceived decimal(19,4),RecipeQty decimal(19,4),ReceiptQty decimal(19,4),
        ChainBreaks int,IssuesAdded int,StaffVar decimal(19,4),EvidenceJson nvarchar(max),ValueAtRiskSelling decimal(19,2),
        RecipePOSQty decimal(19,4),RecipeComparedDays int,RecipeMismatchDays int,RecipeMissingDays int,RecipeDailyJson nvarchar(max),ProductSalesQty decimal(19,4);
    SELECT SourceSystem,UPPER(LTRIM(RTRIM(POSCode))) AS POSCode,MAX(LastCountDate) AS LastCountDate
    INTO #LastCounts FROM OPENJSON(@SourceJson,'$.lastCounts') WITH(SourceSystem nvarchar(20),POSCode nvarchar(80),LastCountDate date)
    WHERE LastCountDate<=@CompleteThrough GROUP BY SourceSystem,UPPER(LTRIM(RTRIM(POSCode)));
    CREATE INDEX IX_LastCounts_Product ON #LastCounts(SourceSystem,POSCode);
    CREATE INDEX IX_WeeklyCases_Product ON #Cases(SourceSystem,POSCode);
    UPDATE c SET CountQty=k.CurrentQty,PreviousCountQty=k.PreviousQty,CountRows=k.CurrentRows,
        LastCountDate=CASE WHEN k.LastCountDate IS NULL OR l.LastCountDate>k.LastCountDate THEN l.LastCountDate ELSE k.LastCountDate END,SharedCountCases=n.SharedCases,
        Z=CASE WHEN IsWeighed=1 OR Description LIKE '%1000G%' OR Description LIKE '%P/KG%' OR Description LIKE '% KG%'
            OR Description LIKE '%Litre%' OR Description LIKE '%(K)%' OR Description LIKE '%(L)%' THEN 0.2 ELSE 0.5 END
    FROM #Cases c
    OUTER APPLY(SELECT CASE WHEN COUNT(CASE WHEN CountDate BETWEEN @WeekStart AND @CompleteThrough THEN 1 END)
              =COUNT(CASE WHEN CountDate BETWEEN @WeekStart AND @CompleteThrough THEN VarianceQty END)
            THEN SUM(CASE WHEN CountDate BETWEEN @WeekStart AND @CompleteThrough THEN VarianceQty END) END AS CurrentQty,
        CASE WHEN COUNT(CASE WHEN CountDate>=DATEADD(day,-7,@WeekStart) AND CountDate<@WeekStart THEN 1 END)=COUNT(CASE WHEN CountDate>=DATEADD(day,-7,@WeekStart) AND CountDate<@WeekStart THEN VarianceQty END)
            THEN SUM(CASE WHEN CountDate>=DATEADD(day,-7,@WeekStart) AND CountDate<@WeekStart THEN VarianceQty END) END AS PreviousQty,
        COUNT(CASE WHEN CountDate BETWEEN @WeekStart AND @CompleteThrough THEN 1 END) AS CurrentRows,
        MAX(CASE WHEN CountDate<=@CompleteThrough THEN CountDate END) AS LastCountDate
        FROM #Counts k WHERE k.SourceSystem=c.SourceSystem AND k.Code=c.POSCode) k
    OUTER APPLY(SELECT MAX(LastCountDate) AS LastCountDate FROM #LastCounts l WHERE l.SourceSystem=c.SourceSystem AND l.POSCode=c.POSCode) l
    CROSS APPLY(SELECT COUNT(*) AS SharedCases FROM #Cases x WHERE x.SourceSystem=c.SourceSystem AND x.POSCode=c.POSCode) n;
    UPDATE #Cases SET StoreroomQty=CountQty-ReconQty;

    ;WITH chain AS (
        SELECT *,LAG(OriginalClose) OVER(PARTITION BY AreaNo,StockItemNo ORDER BY TransactionDate,ShiftNo) AS PreviousOriginalClose FROM #Recon
    )
    UPDATE c SET ChainBreaks=s.ChainBreaks,IssuesAdded=s.IssuesAdded,StaffVar=s.StaffVar,ProductSalesQty=s.ProductSalesQty
    FROM #Cases c OUTER APPLY(SELECT SUM(CASE WHEN OriginalOpen<PreviousOriginalClose-0.0001 THEN 1 ELSE 0 END) AS ChainBreaks,
        SUM(CASE WHEN QtyIssued>OriginalIssued+0.0001 THEN 1 ELSE 0 END) AS IssuesAdded,
        SUM(QtyPOS) AS ProductSalesQty,CASE WHEN COUNT(OriginalPOS+OriginalOpen+OriginalIssued+OriginalClose)=COUNT(*)
            THEN SUM(OriginalPOS-(OriginalOpen+OriginalIssued-OriginalClose)+ISNULL(OriginalOpen-PreviousOriginalClose,0)) END AS StaffVar
        FROM chain s WHERE s.SourceSystem=c.SourceSystem AND s.Code=c.POSCode AND s.TransactionDate BETWEEN @WeekStart AND @CompleteThrough) s;
    UPDATE c SET ReceiptQty=e.ReceiptQty FROM #Cases c
    OUTER APPLY(SELECT SUM(CASE WHEN EvidenceType='Receipt' THEN Quantity END) AS ReceiptQty
        FROM #Evidence e WHERE e.SourceSystem=c.SourceSystem AND e.Code=c.POSCode AND e.EvidenceDate BETWEEN @WeekStart AND @CompleteThrough) e;

    /* Recipe quantities describe the whole product/day, not each area. Keep
       daily differences visible even when positive and negative gaps cancel. */
    SELECT SourceSystem,Code,TransactionDate AS DayDate,SUM(QtyPOS) AS POSQty INTO #DailyPOS FROM #Recon
    WHERE TransactionDate BETWEEN @WeekStart AND @CompleteThrough GROUP BY SourceSystem,Code,TransactionDate;
    SELECT SourceSystem,Code,EvidenceDate AS DayDate,CASE WHEN COUNT(Quantity)=COUNT(*) THEN SUM(Quantity) END AS RecipeQty INTO #DailyRecipe
    FROM #Evidence WHERE EvidenceType IN ('RawConsumption','RecipeComparison') AND EvidenceDate BETWEEN @WeekStart AND @CompleteThrough
    GROUP BY SourceSystem,Code,EvidenceDate;
    CREATE UNIQUE CLUSTERED INDEX IX_DailyPOS ON #DailyPOS(SourceSystem,Code,DayDate);
    CREATE UNIQUE CLUSTERED INDEX IX_DailyRecipe ON #DailyRecipe(SourceSystem,Code,DayDate);
    SELECT COALESCE(p.SourceSystem,r.SourceSystem) AS SourceSystem,COALESCE(p.Code,r.Code) AS Code,COALESCE(p.DayDate,r.DayDate) AS DayDate,
        p.POSQty,r.RecipeQty,r.RecipeQty-p.POSQty AS Difference,
        CASE WHEN p.POSQty IS NULL OR r.RecipeQty IS NULL THEN 'Missing daily evidence'
             WHEN ABS(r.RecipeQty-p.POSQty)<=CASE WHEN 0.1*CASE WHEN ABS(r.RecipeQty)>ABS(p.POSQty) THEN ABS(r.RecipeQty) ELSE ABS(p.POSQty) END>0.5
                 THEN 0.1*CASE WHEN ABS(r.RecipeQty)>ABS(p.POSQty) THEN ABS(r.RecipeQty) ELSE ABS(p.POSQty) END ELSE 0.5 END THEN 'Match'
             WHEN ABS(p.POSQty)<0.0001 AND r.RecipeQty>0 THEN 'Recipe used; recon POS nil' ELSE 'Difference over tolerance' END AS Comparison
    INTO #RecipeDays FROM #DailyPOS p FULL HASH JOIN #DailyRecipe r ON r.SourceSystem=p.SourceSystem AND r.Code=p.Code AND r.DayDate=p.DayDate
    WHERE EXISTS(SELECT 1 FROM #DailyRecipe e WHERE e.SourceSystem=COALESCE(p.SourceSystem,r.SourceSystem) AND e.Code=COALESCE(p.Code,r.Code)) OPTION(RECOMPILE);
    CREATE INDEX IX_RecipeDays_Product ON #RecipeDays(SourceSystem,Code,DayDate);
    UPDATE c SET RecipeQty=r.RecipeQty,RecipePOSQty=r.POSQty,RecipeComparedDays=r.ComparedDays,RecipeMismatchDays=r.MismatchDays,
        RecipeMissingDays=r.MissingDays,RecipeDailyJson=(SELECT d.DayDate,d.POSQty,d.RecipeQty,d.Difference,d.Comparison FROM #RecipeDays d
            WHERE d.SourceSystem=c.SourceSystem AND d.Code=c.POSCode ORDER BY d.DayDate FOR JSON PATH,INCLUDE_NULL_VALUES)
    FROM #Cases c OUTER APPLY(SELECT SUM(CASE WHEN POSQty IS NOT NULL AND RecipeQty IS NOT NULL THEN RecipeQty END) AS RecipeQty,
        SUM(CASE WHEN POSQty IS NOT NULL AND RecipeQty IS NOT NULL THEN POSQty END) AS POSQty,
        COUNT(CASE WHEN POSQty IS NOT NULL AND RecipeQty IS NOT NULL THEN 1 END) AS ComparedDays,
        COUNT(CASE WHEN Comparison IN ('Recipe used; recon POS nil','Difference over tolerance') THEN 1 END) AS MismatchDays,
        COUNT(CASE WHEN Comparison='Missing daily evidence' THEN 1 END) AS MissingDays
        FROM #RecipeDays d WHERE d.SourceSystem=c.SourceSystem AND d.Code=c.POSCode) r;
    UPDATE c SET IorDays=i.Days,IorMatched=i.Matched,IorReceived=i.Received FROM #Cases c
    OUTER APPLY(SELECT COUNT(*) AS Days,SUM(CASE WHEN ABS(ISNULL(x.Issued,0)-g.Received)<=CASE WHEN ABS(g.Received)*0.01>0.5 THEN ABS(g.Received)*0.01 ELSE 0.5 END THEN 1 ELSE 0 END) AS Matched,SUM(g.Received) AS Received
        FROM (SELECT EvidenceDate,SUM(Quantity) AS Received FROM #Evidence e
            WHERE e.SourceSystem=c.SourceSystem AND e.Code=c.POSCode AND EvidenceType='Receipt'
              AND EvidenceDate BETWEEN @WeekStart AND @CompleteThrough GROUP BY EvidenceDate) g
        OUTER APPLY(SELECT SUM(QtyIssued) AS Issued FROM #Recon r
            WHERE r.AreaNo=c.AreaNo AND r.StockItemNo=c.StockItemNo AND r.TransactionDate=g.EvidenceDate) sameDay
        OUTER APPLY(SELECT SUM(QtyIssued) AS Issued FROM #Recon r
            WHERE r.AreaNo=c.AreaNo AND r.StockItemNo=c.StockItemNo AND r.TransactionDate=DATEADD(day,1,g.EvidenceDate)
              AND r.TransactionDate<=@CompleteThrough) nextDay
        CROSS APPLY(SELECT COALESCE(NULLIF(sameDay.Issued,0),nextDay.Issued) AS Issued) x) i
    WHERE c.AreaDescription='Steers BOH' AND c.POSCode IN ('V7','B1','SBBB');

    UPDATE c SET Scenario=CASE
        WHEN AreaDescription LIKE '%Lotto%' OR AreaDescription LIKE '%Electricity%' OR AreaDescription LIKE '%Airtime%'
          OR AreaDescription LIKE '%Water Sales%' OR Description LIKE '%AIRTIME%' OR Description LIKE '%ELECTRIC%'
          OR Description LIKE '%LOTTO%' OR Description LIKE '%VOUCHER%' OR Description LIKE '%REFILL WATER%'
          OR EXISTS(SELECT 1 FROM agora.StockReconMeter m WHERE m.BranchId=@BranchId AND m.AreaNo=c.AreaNo AND m.StockItemNo=c.StockItemNo AND m.IsActive=1) THEN 'V'
        WHEN CountQty IS NULL THEN 'NC'
        WHEN ABS(CountQty)>50*CASE WHEN ABS(ReconQty)>1 THEN ABS(ReconQty) ELSE 1 END
          AND ABS(CountQty)*CASE WHEN UnitSellingPrice>1 THEN UnitSellingPrice ELSE 1 END>5000
          AND NOT (PreviousCountQty IS NOT NULL AND ABS(CountQty+PreviousCountQty)<=
              CASE WHEN 0.15*CASE WHEN ABS(CountQty)>ABS(PreviousCountQty) THEN ABS(CountQty) ELSE ABS(PreviousCountQty) END>Z
                  THEN 0.15*CASE WHEN ABS(CountQty)>ABS(PreviousCountQty) THEN ABS(CountQty) ELSE ABS(PreviousCountQty) END ELSE Z END) THEN 'E2'
        WHEN ABS(ReconQty)<=Z AND ABS(CountQty)<=Z THEN 'OK'
        WHEN ReconQty< -Z AND CountQty< -Z THEN CASE WHEN ABS(ReconQty-CountQty)<=
            CASE WHEN 0.15*CASE WHEN ABS(ReconQty)>ABS(CountQty) THEN ABS(ReconQty) ELSE ABS(CountQty) END>Z
                THEN 0.15*CASE WHEN ABS(ReconQty)>ABS(CountQty) THEN ABS(ReconQty) ELSE ABS(CountQty) END ELSE Z END THEN 'A1'
            WHEN CountQty<ReconQty THEN 'A2' ELSE 'A3' END
        WHEN ABS(ReconQty)<=Z AND CountQty< -Z THEN 'B1'
        WHEN ReconQty>Z AND CountQty< -Z THEN 'B2'
        WHEN ReconQty< -Z AND ABS(CountQty)<=Z THEN 'C1'
        WHEN ReconQty>Z AND ABS(CountQty)<=Z THEN 'C2'
        WHEN ReconQty>Z AND CountQty>Z THEN CASE WHEN ABS(ReconQty-CountQty)<=
            CASE WHEN 0.15*CASE WHEN ABS(ReconQty)>ABS(CountQty) THEN ABS(ReconQty) ELSE ABS(CountQty) END>Z
                THEN 0.15*CASE WHEN ABS(ReconQty)>ABS(CountQty) THEN ABS(ReconQty) ELSE ABS(CountQty) END ELSE Z END THEN 'D1' ELSE 'D4' END
        WHEN ABS(ReconQty)<=Z AND CountQty>Z THEN 'D2'
        WHEN ReconQty< -Z AND CountQty>Z THEN 'D3' ELSE 'NC' END FROM #Cases c;
    UPDATE #Cases SET Scenario='RV' WHERE Scenario NOT IN ('V','NC','E2') AND ABS(ReconQty)<=Z AND CountQty*PreviousCountQty<0
        AND ABS(CountQty)>Z AND ABS(PreviousCountQty)>Z AND ABS(CountQty+PreviousCountQty)<=
        CASE WHEN 0.15*CASE WHEN ABS(CountQty)>ABS(PreviousCountQty) THEN ABS(CountQty) ELSE ABS(PreviousCountQty) END>Z
            THEN 0.15*CASE WHEN ABS(CountQty)>ABS(PreviousCountQty) THEN ABS(CountQty) ELSE ABS(PreviousCountQty) END ELSE Z END;
    UPDATE #Cases SET Scenario='E1' WHERE Scenario<>'V' AND ReconQty< -Z AND (CountQty IS NULL OR CountQty< -Z)
        AND ProductSalesQty<=0.1*ABS(StaffVar) AND StaffVar< -CASE WHEN IssueMultiple>3 THEN IssueMultiple ELSE 3 END;
    UPDATE #Cases SET Scenario='IR' WHERE Scenario NOT IN ('V','E1') AND IorDays>0 AND IorMatched<IorDays AND CountQty< -Z;
    UPDATE #Cases SET Scenario='E3' WHERE Scenario NOT IN ('V','NC','E2','E1','IR')
        AND ABS(ReconQty)>5*CASE WHEN ABS(CountQty)>Z THEN ABS(CountQty) ELSE Z END AND ABS(ReconQty*UnitSellingPrice)>=5000;
    UPDATE c SET ValueAtRiskSelling=CASE WHEN Scenario='V' THEN 0 WHEN UnitSellingPrice IS NULL THEN NULL
        WHEN Scenario='E2' AND ABS(CountQty)*CASE WHEN UnitSellingPrice>1 THEN UnitSellingPrice ELSE 1 END>100000 THEN ABS(ReconQty*UnitSellingPrice)
        ELSE v.AtRisk END,EvidenceJson=(SELECT c.ChainBreaks,c.IssuesAdded,c.StaffVar,c.ReceiptQty,c.RecipeQty,
            c.IorDays,c.IorMatched,c.IorReceived,c.SharedCountCases,c.IssueMultiple,
            c.RecipeQty-c.RecipePOSQty AS RecipeLessReconPOS,c.RecipeComparedDays,c.RecipeMismatchDays,c.RecipeMissingDays,
            JSON_QUERY(c.RecipeDailyJson) AS RecipeDaily,
            @CompleteThrough AS CompleteThrough FOR JSON PATH,WITHOUT_ARRAY_WRAPPER)
    FROM #Cases c CROSS APPLY(SELECT MAX(q.Amount) AS AtRisk FROM (VALUES(ABS(c.ReconQty*c.UnitSellingPrice)),
        (ABS(c.CountQty*c.UnitSellingPrice)),(ABS(c.StoreroomQty*c.UnitSellingPrice))) q(Amount)) v;

    DECLARE @PeriodThrough date=@PeriodTo;
    ;WITH days AS (SELECT @PeriodFrom AS DayDate UNION ALL SELECT DATEADD(day,1,DayDate) FROM days WHERE DayDate<@PeriodTo)
    SELECT @FirstBad=MIN(d.DayDate) FROM days d
    OUTER APPLY(SELECT COUNT(*) AS Shifts,MIN(CASE WHEN N>=0.95*@MedianN AND ClosingShare>=0.5*@MedianClosing THEN 1 ELSE 0 END) AS Complete,
        AVG(SalesShare) AS SalesShare FROM #ShiftStats s WHERE s.TransactionDate=d.DayDate) s
    WHERE s.Shifts<2 OR ISNULL(s.Complete,0)=0 OR s.SalesShare<0.5*@MedianSales OPTION(MAXRECURSION 100);
    IF @FirstBad IS NOT NULL SET @PeriodThrough=DATEADD(day,-1,@FirstBad);
    IF @PeriodThrough<@PeriodFrom SET @PeriodThrough=NULL;
    BEGIN TRANSACTION;
    INSERT agora.StockWeeklySnapshot(BranchId,SnapshotRef,WeekStart,WeekEnd,CompleteThrough,SourceReadAt,RuleVersion,SourceJson,CoverageJson,CreatedAt,CreatedBy,PeriodFrom,PeriodTo,PeriodThrough,ReportingAsOf)
    VALUES(@BranchId,@SnapshotRef,@WeekStart,@WeekEnd,@CompleteThrough,@SourceReadAt,'weekly-pack-2026-09-v2',@SourceJson,@CoverageJson,@Now,@UserId,@PeriodFrom,@PeriodTo,@PeriodThrough,@ReportingAsOf);
    DECLARE @Id bigint=SCOPE_IDENTITY();
    INSERT agora.StockWeeklyCase(BranchId,SnapshotRef,AreaNo,StockItemNo,AreaDescription,Description,SourceSystem,POSCode,
        ReconQty,OriginalReconQty,CountQty,PreviousCountQty,StoreroomQty,UnitSellingPrice,IssuedQty,SalesQty,CountRows,LastCountDate,SharedCountCases,
        Scenario,ScenarioName,NextAction,Priority,ValueAtRiskSelling,EvidenceJson,Status,CreatedAt,CreatedBy)
    SELECT @BranchId,@SnapshotRef,AreaNo,StockItemNo,AreaDescription,Description,SourceSystem,POSCode,
        ReconQty,OriginalReconQty,CountQty,PreviousCountQty,StoreroomQty,UnitSellingPrice,IssuedQty,SalesQty,CountRows,LastCountDate,SharedCountCases,
        Scenario,CASE Scenario WHEN 'OK' THEN 'Recon and count balanced' WHEN 'A1' THEN 'Matching recon and site shorts'
        WHEN 'A2' THEN 'Site short exceeds recon short' WHEN 'A3' THEN 'Recon short exceeds site short'
        WHEN 'B1' THEN 'Recon balanced; site short' WHEN 'B2' THEN 'Recon over; site short'
        WHEN 'C1' THEN 'Recon short; site balanced' WHEN 'C2' THEN 'Recon over; site balanced'
        WHEN 'D1' THEN 'Matching recon and site overs' WHEN 'D2' THEN 'Recon balanced; site over'
        WHEN 'D3' THEN 'Recon short; site over' WHEN 'D4' THEN 'Recon and site overs differ'
        WHEN 'RV' THEN 'Stocktake reverses previous week' WHEN 'NC' THEN 'Count not proven'
        WHEN 'E1' THEN 'Low linked POS usage: review recipe / PLU' WHEN 'E2' THEN 'Count quantity disproportionate'
        WHEN 'E3' THEN 'Recon quantity disproportionate' WHEN 'IR' THEN 'Receipt and issue quantities differ'
        WHEN 'V' THEN 'Virtual / cumulative meter; not physical stock' END,
        CASE WHEN Scenario='V' THEN 'Review in the appropriate utility or virtual-sales control; exclude from physical loss.'
        WHEN Scenario='OK' THEN 'No quantity difference; retain the evidence.' WHEN Scenario='NC' THEN 'Obtain a dated physical count or missing source evidence; do not assume zero.'
        WHEN Scenario IN ('E1','E2','E3','RV') THEN 'Review the dated counts, units, recipe mapping and prior-week evidence before deciding the cause.'
        WHEN Scenario='IR' THEN 'Match each receipt to the same-day issue or next-day issue when no same-day issue exists; check supplier documents.'
        ELSE 'Compare count, issues, receipts and shift evidence; record the verified finding before closing.' END,
        CASE WHEN Scenario IN ('V','OK') THEN 'Low' WHEN ChainBreaks>0 OR ValueAtRiskSelling>=500 OR (Scenario IN ('RV','E2','E3') AND ValueAtRiskSelling>=100) THEN 'High'
            WHEN ValueAtRiskSelling>=100 THEN 'Medium' ELSE 'Low' END,
        ValueAtRiskSelling,EvidenceJson,'Open',@Now,@UserId FROM #Cases;
    IF @BuildControls=1 EXEC agora.usp_StockRecon_BuildPeriodRegisters @BranchId,@SnapshotRef,@PeriodFrom,@PeriodThrough,@ReportingAsOf,@UserId;
    COMMIT TRANSACTION;
    SELECT CAST(1 AS bit) AS Ok,'CREATED' AS Code,'Weekly source snapshot and investigation cases saved.' AS Message,@Id AS Id,@SnapshotRef AS SnapshotRef;
END
