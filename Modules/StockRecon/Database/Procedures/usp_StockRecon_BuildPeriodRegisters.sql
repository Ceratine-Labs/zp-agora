/* Internal snapshot builder. Called inside CreateWeeklySnapshot's transaction;
   #Recon, #Counts and #Evidence contain only the authorized site's immutable
   source read. No customer writes. Registers share the case action/approval trail.
   Count windows follow the customer's engine.py: (prior count, count date],
   whole product across areas; period totals use the last window's unit cost. */
CREATE OR ALTER PROCEDURE agora.usp_StockRecon_BuildPeriodRegisters
    @BranchId INT,@SnapshotRef UNIQUEIDENTIFIER,@PeriodFrom DATE,@PeriodThrough DATE,@AsOf DATE,@UserId INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    IF @@TRANCOUNT=0 OR OBJECT_ID('tempdb..#Recon') IS NULL OR OBJECT_ID('tempdb..#Counts') IS NULL
        THROW 51000, 'AGORA:WEEKLY_SOURCE:Control registers must be built with the authorized immutable snapshot.', 1;
    DECLARE @Now datetime2=SYSUTCDATETIME(),@Source nvarchar(max);
    SELECT @Source=SourceJson FROM agora.StockWeeklySnapshot WHERE BranchId=@BranchId AND SnapshotRef=@SnapshotRef;
    SELECT SourceSystem,UPPER(LTRIM(RTRIM(POSCode))) AS Code,UnitCost,CostBasis INTO #FallbackCosts
    FROM OPENJSON(@Source,'$.costs') WITH(SourceSystem nvarchar(20),POSCode nvarchar(80),UnitCost decimal(19,6),CostBasis nvarchar(100));
    SELECT r.*,LAG(OriginalClose) OVER(PARTITION BY AreaNo,StockItemNo ORDER BY TransactionDate,ShiftNo) AS PreviousClose,
        LAG(QtyClose) OVER(PARTITION BY AreaNo,StockItemNo ORDER BY TransactionDate,ShiftNo) AS PreviousAmendedClose,
        ROW_NUMBER() OVER(PARTITION BY AreaNo,StockItemNo ORDER BY TransactionDate DESC,ShiftNo DESC) AS LastShift
    INTO #PeriodShifts FROM #Recon r WHERE TransactionDate<=@PeriodThrough
        AND AreaDescription NOT LIKE '%virtual%' AND Description NOT LIKE '%REFILL WATER%'
        AND NOT EXISTS(SELECT 1 FROM agora.StockWeeklyCase v WHERE v.BranchId=@BranchId AND v.SnapshotRef=@SnapshotRef
            AND v.RegisterName='Item Register' AND v.AreaNo=r.AreaNo AND v.StockItemNo=r.StockItemNo AND v.Scenario='V');
    CREATE INDEX IX_PeriodShifts_ProductDate ON #PeriodShifts(SourceSystem,Code,TransactionDate);
    CREATE INDEX IX_PeriodShifts_AreaDate ON #PeriodShifts(AreaNo,StockItemNo,TransactionDate);
    ALTER TABLE #PeriodShifts ADD R decimal(19,4),Ro decimal(19,4),ChainBreak decimal(19,4);
    UPDATE #PeriodShifts SET R=QtyPOS-(QtyOpen+QtyIssued-QtyClose),ChainBreak=OriginalOpen-PreviousClose,
        Ro=OriginalPOS-(OriginalOpen+OriginalIssued-OriginalClose)
            +CASE WHEN OriginalIssued>5*CASE WHEN QtyIssued>1 THEN QtyIssued ELSE 1 END AND OriginalIssued>20 THEN OriginalIssued-QtyIssued ELSE 0 END
            +COALESCE(OriginalOpen-PreviousClose,OriginalOpen-QtyOpen);
    SELECT SourceSystem,Code,MIN(AreaNo) AS AreaNo,MIN(StockItemNo) AS StockItemNo,MAX(Description) AS Description,
        STRING_AGG(CONVERT(nvarchar(max),AreaDescription),', ') AS Areas,
        MAX(CONVERT(int,IsWeighed)) AS Weighed,MAX(IssueMultiple) AS Multiple
    INTO #Product FROM (SELECT DISTINCT SourceSystem,Code,AreaNo,StockItemNo,Description,AreaDescription,IsWeighed,IssueMultiple FROM #PeriodShifts
        WHERE TransactionDate BETWEEN @PeriodFrom AND @PeriodThrough) r GROUP BY SourceSystem,Code;

    SELECT Code,SUM(QtyPOS) AS POS,CASE WHEN COUNT(*)=COUNT(Ro) THEN SUM(Ro) END AS StaffRecon
    INTO #CrossSiteBasis FROM #PeriodShifts WHERE TransactionDate BETWEEN @PeriodFrom AND @PeriodThrough GROUP BY Code;
    UPDATE c SET EvidenceJson=JSON_MODIFY(JSON_MODIFY(c.EvidenceJson,'$.PeriodPOS',p.POS),'$.PeriodStaffRecon',p.StaffRecon)
    FROM agora.StockWeeklyCase c JOIN #CrossSiteBasis p ON p.Code=c.POSCode
    WHERE c.BranchId=@BranchId AND c.SnapshotRef=@SnapshotRef AND c.RegisterName='Item Register';

    /* One whole-site count per product/day. A missing operand in any location
       keeps that day's variance unknown instead of summing only known locations. */
    SELECT c.SourceSystem,c.Code,c.CountDate,
        CASE WHEN COUNT(*)=COUNT(c.VarianceQty) THEN SUM(c.VarianceQty) END AS S,
        CASE WHEN COUNT(*)=COUNT(c.CountedQty) THEN SUM(c.CountedQty) END AS Counted,
        CASE WHEN COUNT(*)=COUNT(c.ExpectedQty) THEN SUM(c.ExpectedQty) END AS Expected,
        CASE WHEN MIN(c.UnitCost)=MAX(c.UnitCost) THEN MAX(c.UnitCost) END AS Cost,COUNT(*) AS CountLines,
        MAX(TRY_CONVERT(date,JSON_VALUE(c.DetailJson,'$.PrevCountDate'))) AS SourcePreviousDate,
        MAX(TRY_CONVERT(int,JSON_VALUE(c.DetailJson,'$.DaysSincePreviousCount'))) AS SourceGap
    INTO #CountDays FROM #Counts c WHERE c.CountDate<=@PeriodThrough
        AND EXISTS(SELECT 1 FROM #Product p WHERE p.SourceSystem=c.SourceSystem AND p.Code=c.Code)
        AND ISNULL(JSON_VALUE(c.DetailJson,'$.Proof'),'') NOT LIKE 'Latest stocktake%'
    GROUP BY c.SourceSystem,c.Code,c.CountDate;
    ;WITH seq AS (SELECT *,LAG(CountDate) OVER(PARTITION BY SourceSystem,Code ORDER BY CountDate) AS PreviousDate FROM #CountDays)
    SELECT s.*,COALESCE(PreviousDate,SourcePreviousDate,DATEADD(day,-CASE WHEN SourceGap>0 THEN SourceGap WHEN SourceSystem='PILOT' THEN 1 ELSE 7 END,CountDate)) AS WindowAfter
    INTO #Windows FROM seq s WHERE CountDate BETWEEN @PeriodFrom AND @PeriodThrough;
    CREATE INDEX IX_CountWindows_ProductDate ON #Windows(SourceSystem,Code,CountDate);
    ALTER TABLE #Windows ADD R decimal(19,4),Ro decimal(19,4),POS decimal(19,4),Issued decimal(19,4),UnitSellingPrice decimal(19,4),
        UnitCost decimal(19,6),CostBasis nvarchar(100),PartialRecon bit,Class nvarchar(8),ClassOrig nvarchar(8),Z decimal(19,4),M decimal(19,4),Mo decimal(19,4);
    UPDATE w SET R=r.R,Ro=r.Ro,POS=r.POS,Issued=r.Issued,UnitSellingPrice=r.Price,
        PartialRecon=CASE WHEN DATEADD(day,1,WindowAfter)<@PeriodFrom OR r.R IS NULL THEN 1 ELSE 0 END,
        UnitCost=CASE WHEN Cost>0 THEN Cost WHEN fallback.UnitCost>0 THEN fallback.UnitCost ELSE r.Price/1.15*0.7 END,
        CostBasis=CASE WHEN Cost>0 THEN 'Count source unit cost' WHEN fallback.UnitCost>0 THEN 'DET sales cost fallback' WHEN r.Price IS NOT NULL THEN 'Estimated: selling / 1.15 x 0.7 (pack fallback)' ELSE 'Unavailable: no count cost or selling price' END,
        Z=CASE WHEN 0.02*ABS(r.POS)>CASE WHEN p.Weighed=1 THEN 0.2 ELSE 1 END THEN 0.02*ABS(r.POS) ELSE CASE WHEN p.Weighed=1 THEN 0.2 ELSE 1 END END
    FROM #Windows w JOIN #Product p ON p.SourceSystem=w.SourceSystem AND p.Code=w.Code
    OUTER APPLY(SELECT CASE WHEN MIN(UnitCost)=MAX(UnitCost) THEN MAX(UnitCost) END AS UnitCost FROM #FallbackCosts f
        WHERE f.SourceSystem=w.SourceSystem AND f.Code=w.Code) fallback
    OUTER APPLY(SELECT SUM(R) AS R,CASE WHEN COUNT(*)=COUNT(Ro) THEN SUM(Ro) END AS Ro,SUM(QtyPOS) AS POS,SUM(QtyIssued) AS Issued,MAX(UnitSellingPrice) AS Price
        FROM #PeriodShifts r WHERE r.SourceSystem=w.SourceSystem AND r.Code=w.Code AND r.TransactionDate>w.WindowAfter
            AND r.TransactionDate>=@PeriodFrom AND r.TransactionDate<=w.CountDate) r;
    UPDATE #Windows SET M=CASE WHEN 0.15*CASE WHEN ABS(R)>ABS(S) THEN ABS(R) ELSE ABS(S) END>Z THEN 0.15*CASE WHEN ABS(R)>ABS(S) THEN ABS(R) ELSE ABS(S) END ELSE Z END,
        Mo=CASE WHEN 0.15*CASE WHEN ABS(Ro)>ABS(S) THEN ABS(Ro) ELSE ABS(S) END>Z THEN 0.15*CASE WHEN ABS(Ro)>ABS(S) THEN ABS(Ro) ELSE ABS(S) END ELSE Z END;
    /* The same quantity matrix is used for each window and its original basis. */
    SELECT w.SourceSystem,w.Code,w.CountDate,b.Basis,
        CASE WHEN b.R IS NULL OR S IS NULL THEN 'NC' WHEN ABS(b.R)<=Z AND ABS(S)<=Z THEN 'OK'
        WHEN b.R< -Z AND S< -Z THEN CASE WHEN ABS(b.R-S)<=b.M THEN 'A1' WHEN S<b.R THEN 'A2' ELSE 'A3' END
        WHEN ABS(b.R)<=Z AND S< -Z THEN 'B1' WHEN b.R>Z AND S< -Z THEN 'B2'
        WHEN b.R< -Z AND ABS(S)<=Z THEN 'C1' WHEN b.R>Z AND ABS(S)<=Z THEN 'C2'
        WHEN b.R>Z AND S>Z THEN CASE WHEN ABS(b.R-S)<=b.M THEN 'D1' ELSE 'D4' END
        WHEN ABS(b.R)<=Z AND S>Z THEN 'D2' WHEN b.R< -Z AND S>Z THEN 'D3' ELSE 'NC' END AS Class
    INTO #WindowClasses FROM #Windows w CROSS APPLY(VALUES('balanced',R,M),('original',Ro,Mo)) b(Basis,R,M);
    UPDATE w SET Class=b.Class,ClassOrig=o.Class FROM #Windows w JOIN #WindowClasses b ON b.SourceSystem=w.SourceSystem AND b.Code=w.Code AND b.CountDate=w.CountDate AND b.Basis='balanced'
        JOIN #WindowClasses o ON o.SourceSystem=w.SourceSystem AND o.Code=w.Code AND o.CountDate=w.CountDate AND o.Basis='original';
    UPDATE #Windows SET Class='A4' WHERE ClassOrig='A1' AND Class<>'A1';
    UPDATE #Windows SET Class='C3' WHERE ClassOrig='C2' AND Class='OK';
    UPDATE w SET Class='E1' FROM #Windows w JOIN #Product p ON p.SourceSystem=w.SourceSystem AND p.Code=w.Code
        WHERE POS<=0.2*ABS(Ro) AND Ro< -CASE WHEN Multiple>3 THEN Multiple ELSE 3 END AND DATEDIFF(day,WindowAfter,CountDate)>=2;
    UPDATE w SET Class='E2' FROM #Windows w JOIN #Product p ON p.SourceSystem=w.SourceSystem AND p.Code=w.Code
        CROSS APPLY(SELECT MAX(q) AS Throughput FROM (VALUES(ABS(POS)),(ABS(Issued)),(Multiple),(1)) t(q)) x WHERE ABS(S)>10*x.Throughput AND ABS(S)>20;

    SELECT w.SourceSystem,w.Code,COUNT(*) AS Counts,CASE WHEN COUNT(*)=COUNT(R) THEN SUM(R) END AS R,
        CASE WHEN COUNT(*)=COUNT(Ro) THEN SUM(Ro) END AS Ro,CASE WHEN COUNT(*)=COUNT(S) THEN SUM(S) END AS S,
        SUM(POS) AS POS,SUM(Issued) AS Issued,SUM(CASE WHEN Class='E1' THEN S ELSE 0 END) AS S_E1,
        SUM(CONVERT(int,PartialRecon)) AS PartialWindows,SUM(CASE WHEN S IS NULL THEN 1 ELSE 0 END) AS UnknownCountWindows,MAX(CountDate) AS LastCountDate
    INTO #PeriodItems FROM #Windows w GROUP BY w.SourceSystem,w.Code;
    ALTER TABLE #PeriodItems ADD UnitCost decimal(19,6),CostBasis nvarchar(100),Z decimal(19,4),M decimal(19,4),Mo decimal(19,4),Class nvarchar(8),ClassOrig nvarchar(8);
    UPDATE i SET UnitCost=w.UnitCost,CostBasis=w.CostBasis,
        Z=CASE WHEN 0.02*ABS(i.POS)>CASE WHEN p.Weighed=1 THEN 0.2 ELSE 1 END THEN 0.02*ABS(i.POS) ELSE CASE WHEN p.Weighed=1 THEN 0.2 ELSE 1 END END
    FROM #PeriodItems i JOIN #Windows w ON w.SourceSystem=i.SourceSystem AND w.Code=i.Code AND w.CountDate=i.LastCountDate
    JOIN #Product p ON p.SourceSystem=i.SourceSystem AND p.Code=i.Code;
    UPDATE #PeriodItems SET M=CASE WHEN 0.15*CASE WHEN ABS(R)>ABS(S) THEN ABS(R) ELSE ABS(S) END>Z THEN 0.15*CASE WHEN ABS(R)>ABS(S) THEN ABS(R) ELSE ABS(S) END ELSE Z END,
        Mo=CASE WHEN 0.15*CASE WHEN ABS(Ro)>ABS(S) THEN ABS(Ro) ELSE ABS(S) END>Z THEN 0.15*CASE WHEN ABS(Ro)>ABS(S) THEN ABS(Ro) ELSE ABS(S) END ELSE Z END;
    SELECT i.SourceSystem,i.Code,b.Basis,
        CASE WHEN b.R IS NULL OR S IS NULL THEN 'NC' WHEN ABS(b.R)<=Z AND ABS(S)<=Z THEN 'OK'
        WHEN b.R< -Z AND S< -Z THEN CASE WHEN ABS(b.R-S)<=b.M THEN 'A1' WHEN S<b.R THEN 'A2' ELSE 'A3' END
        WHEN ABS(b.R)<=Z AND S< -Z THEN 'B1' WHEN b.R>Z AND S< -Z THEN 'B2'
        WHEN b.R< -Z AND ABS(S)<=Z THEN 'C1' WHEN b.R>Z AND ABS(S)<=Z THEN 'C2'
        WHEN b.R>Z AND S>Z THEN CASE WHEN ABS(b.R-S)<=b.M THEN 'D1' ELSE 'D4' END
        WHEN ABS(b.R)<=Z AND S>Z THEN 'D2' WHEN b.R< -Z AND S>Z THEN 'D3' ELSE 'NC' END AS Class
    INTO #PeriodClasses FROM #PeriodItems i CROSS APPLY(VALUES('balanced',R,M),('original',Ro,Mo)) b(Basis,R,M);
    UPDATE i SET Class=b.Class,ClassOrig=o.Class FROM #PeriodItems i JOIN #PeriodClasses b ON b.SourceSystem=i.SourceSystem AND b.Code=i.Code AND b.Basis='balanced'
        JOIN #PeriodClasses o ON o.SourceSystem=i.SourceSystem AND o.Code=i.Code AND o.Basis='original';
    UPDATE #PeriodItems SET Class='A4' WHERE ClassOrig='A1' AND Class<>'A1';
    UPDATE #PeriodItems SET Class='C3' WHERE ClassOrig='C2' AND Class='OK';
    UPDATE #PeriodItems SET Class='E1' WHERE (POS<=0.1*ABS(Ro) AND Ro< -3) OR (S<0 AND S_E1<=0.5*S);
    UPDATE i SET Class='E2' FROM #PeriodItems i JOIN #Product p ON p.SourceSystem=i.SourceSystem AND p.Code=i.Code
        CROSS APPLY(SELECT MAX(q) AS Throughput FROM (VALUES(ABS(POS)),(ABS(Issued)),(Multiple),(1)) t(q)) x WHERE ABS(S)>10*x.Throughput AND ABS(S)>20;
    INSERT agora.StockWeeklyCase(BranchId,SnapshotRef,RegisterName,RegisterKey,AreaNo,StockItemNo,AreaDescription,Description,SourceSystem,POSCode,
        ReconQty,OriginalReconQty,CountQty,StoreroomQty,IssuedQty,SalesQty,CountRows,LastCountDate,SharedCountCases,Scenario,ScenarioName,NextAction,Priority,
        UnitCost,CostBasis,ReconValueCost,CountValueCost,StoreroomValueCost,EvidenceJson,Status,ResponsibleRole,CreatedAt,CreatedBy)
    SELECT @BranchId,@SnapshotRef,'Period Items','period',p.AreaNo,p.StockItemNo,LEFT(p.Areas,160),p.Description,i.SourceSystem,i.Code,
        i.R,i.Ro,i.S,i.S-i.R,i.Issued,i.POS,i.Counts,i.LastCountDate,1,i.Class,'Count-to-count period diagnosis',
        'Review each count window, original and balanced quantities, cost basis and partial coverage. Record the evidenced action.',
        CASE WHEN i.PartialWindows>0 OR i.UnknownCountWindows>0 THEN 'High' ELSE 'Medium' END,
        i.UnitCost,i.CostBasis,i.R*i.UnitCost,i.S*i.UnitCost,
        CASE WHEN i.Class='A2' THEN i.S-i.R WHEN i.Class='A4' AND i.S<i.Ro THEN i.S-i.Ro WHEN i.Class IN ('B1','B2','D2','D3') THEN i.S ELSE 0 END*i.UnitCost,
        (SELECT @PeriodFrom AS PeriodFrom,@PeriodThrough AS PeriodThrough,i.PartialWindows,i.UnknownCountWindows,i.Ro*i.UnitCost AS OriginalReconValueCost,
            JSON_QUERY((SELECT DATEADD(day,1,w.WindowAfter) AS WindowStart,w.CountDate,w.R,w.Ro,w.S,w.POS,w.Issued,w.UnitCost,w.CostBasis,w.PartialRecon,w.Class,
                w.Expected,w.Counted,w.CountLines FROM #Windows w WHERE w.SourceSystem=i.SourceSystem AND w.Code=i.Code ORDER BY w.CountDate FOR JSON PATH,INCLUDE_NULL_VALUES)) AS CountWindows
            FOR JSON PATH,INCLUDE_NULL_VALUES,WITHOUT_ARRAY_WRAPPER),
        'Open','Stock controller / site manager',@Now,@UserId
    FROM #PeriodItems i JOIN #Product p ON p.SourceSystem=i.SourceSystem AND p.Code=i.Code;

    CREATE TABLE #Controls(RegisterName nvarchar(40),RegisterKey nvarchar(100),AreaNo int,StockItemNo nvarchar(10),AreaDescription nvarchar(160),
        Description nvarchar(240),SourceSystem nvarchar(20),POSCode nvarchar(80),EvidenceDate date,Scenario nvarchar(8),ScenarioName nvarchar(160),
        NextAction nvarchar(500),ResponsibleRole nvarchar(100),Quantity decimal(19,4),ValueSelling decimal(19,2),EvidenceJson nvarchar(max));
    INSERT #Controls
    SELECT 'Opening vs Closing',CONCAT('opening:',CONVERT(char(10),TransactionDate,23),':',ShiftNo),AreaNo,StockItemNo,AreaDescription,Description,SourceSystem,Code,TransactionDate,
        CASE WHEN ChainBreak<0 THEN 'S10a' ELSE 'S10b' END,'Opening differs from previous staff closing',
        'Amend the OPENING to the previous closing in PumpIT, with the reason. Changing a closing to match is not accepted.','Site manager',ChainBreak,ChainBreak*UnitSellingPrice,
        (SELECT TransactionDate,ShiftNo,PreviousClose,OriginalOpen,ChainBreak,PreviousAmendedClose,QtyOpen,
            CASE WHEN ABS(QtyOpen-PreviousAmendedClose)>0.000001 THEN 'Still open after balancing'
            WHEN ABS(PreviousAmendedClose-PreviousClose)>0.000001 AND ABS(QtyOpen-OriginalOpen)<=0.000001 THEN 'WRONG - previous closing changed'
            WHEN ABS(QtyOpen-OriginalOpen)>0.000001 AND ABS(PreviousAmendedClose-PreviousClose)<=0.000001 THEN 'Correct - opening amended' ELSE 'Both changed' END AS Repair
            FOR JSON PATH,INCLUDE_NULL_VALUES,WITHOUT_ARRAY_WRAPPER)
    FROM #PeriodShifts WHERE TransactionDate BETWEEN @PeriodFrom AND @PeriodThrough AND ABS(ChainBreak)>0.000001;
    INSERT #Controls
    SELECT 'Balancing Breaches',CONCAT(b.Code,':',CONVERT(char(10),TransactionDate,23),':',ShiftNo),AreaNo,StockItemNo,AreaDescription,Description,SourceSystem,r.Code,TransactionDate,
        b.Code,b.Name,'Reverse the breach and explain the correction, preserving the latest staff closing.','Site manager',R,R*UnitSellingPrice,
        (SELECT TransactionDate,ShiftNo,Ro AS StaffVariance,R AS BalancedVariance,OriginalClose,QtyClose FOR JSON PATH,INCLUDE_NULL_VALUES,WITHOUT_ARRAY_WRAPPER)
    FROM #PeriodShifts r CROSS APPLY(VALUES
        ('S7','Balancing turned an over into a short',CASE WHEN Ro>0.5 AND R< -0.5 THEN 1 ELSE 0 END),
        ('S8','Balancing increased a short',CASE WHEN Ro<0 AND R<Ro-0.5 THEN 1 ELSE 0 END),
        ('S11','Latest staff closing changed',CASE WHEN LastShift=1 AND ABS(OriginalClose-QtyClose)>0.000001 THEN 1 ELSE 0 END)) b(Code,Name,Failed)
    WHERE TransactionDate BETWEEN @PeriodFrom AND @PeriodThrough AND b.Failed=1;
    INSERT #Controls
    SELECT 'Issue Sheet Failures',CONCAT(b.Test,':',CONVERT(char(10),TransactionDate,23),':',ShiftNo),AreaNo,StockItemNo,AreaDescription,Description,SourceSystem,r.Code,TransactionDate,
        b.Test,b.Name,'Produce the issue sheet, correct the recorded issue or identify the accountable staff member with evidence.','Site manager / key holder',QtyIssued-OriginalIssued,
        CASE WHEN b.Test='S4' THEN 0 WHEN b.Test IN ('S4a','S4b') THEN (QtyIssued-OriginalIssued)*UnitSellingPrice ELSE R*UnitSellingPrice END,
        (SELECT TransactionDate,ShiftNo,OriginalIssued,QtyIssued,IssueMultiple,Ro,R FOR JSON PATH,INCLUDE_NULL_VALUES,WITHOUT_ARRAY_WRAPPER)
    FROM #PeriodShifts r CROSS APPLY(VALUES
        ('S1','Missed issue sheet',CASE WHEN IsWeighed=0 AND IssueMultiple>=2 AND R>0 AND ROUND(R/NULLIF(IssueMultiple,0),0)>=1 AND ABS(R-ROUND(R/NULLIF(IssueMultiple,0),0)*IssueMultiple)<=0.1*IssueMultiple THEN 1 ELSE 0 END),
        ('S3','Full pack short',CASE WHEN IsWeighed=0 AND IssueMultiple>=2 AND R<0 AND ROUND(-R/NULLIF(IssueMultiple,0),0)>=1 AND ABS(-R-ROUND(-R/NULLIF(IssueMultiple,0),0)*IssueMultiple)<=0.1*IssueMultiple THEN 1 ELSE 0 END),
        ('S4','Issue mis-keyed',CASE WHEN OriginalIssued>5*CASE WHEN QtyIssued>1 THEN QtyIssued ELSE 1 END AND OriginalIssued>20 AND OriginalIssued<>QtyIssued THEN 1 ELSE 0 END),
        ('S4a','Missed issue captured at balancing',CASE WHEN QtyIssued>OriginalIssued THEN 1 ELSE 0 END),
        ('S4b','Issue reduced at balancing',CASE WHEN QtyIssued<OriginalIssued AND NOT(OriginalIssued>5*CASE WHEN QtyIssued>1 THEN QtyIssued ELSE 1 END AND OriginalIssued>20) THEN 1 ELSE 0 END)) b(Test,Name,Failed)
    WHERE TransactionDate BETWEEN @PeriodFrom AND @PeriodThrough AND b.Failed=1;
    INSERT #Controls
    SELECT 'Count Compliance','count-compliance',AreaNo,StockItemNo,AreaDescription,Description,SourceSystem,POSCode,NULL,'NC','Count not proven in the selected week',
        'Obtain a dated physical count and retain the count sheet. A missing source is not a zero variance.','Stock controller',NULL,NULL,EvidenceJson
    FROM agora.StockWeeklyCase WHERE BranchId=@BranchId AND SnapshotRef=@SnapshotRef AND RegisterName='Item Register' AND Scenario='NC';
    SELECT SourceSystem,UPPER(LTRIM(RTRIM(POSCode))) AS Code,MAX(LastCountDate) AS LastCountDate INTO #Recency
    FROM (SELECT SourceSystem,POSCode,LastCountDate FROM OPENJSON(@Source,'$.lastCounts')
        WITH(SourceSystem nvarchar(20),POSCode nvarchar(80),LastCountDate date)
        UNION ALL SELECT SourceSystem,Code,CountDate FROM #Counts) dates
    WHERE LastCountDate<=@AsOf GROUP BY SourceSystem,UPPER(LTRIM(RTRIM(POSCode)));
    CREATE INDEX IX_Recency ON #Recency(SourceSystem,Code);
    INSERT #Controls
    SELECT 'Count Recency','count-recency',c.AreaNo,c.StockItemNo,c.AreaDescription,c.Description,c.SourceSystem,c.POSCode,d.LastCountDate,'NC','Last count older than 16 days or not proven',
        'Count items now if over 30 days old; schedule remaining overdue items and retain dated count evidence.','Stock controller',NULL,NULL,
        (SELECT @AsOf AS ReportingAsOf,d.LastCountDate,DATEDIFF(day,d.LastCountDate,@AsOf) AS DaysSinceCount FOR JSON PATH,INCLUDE_NULL_VALUES,WITHOUT_ARRAY_WRAPPER)
    FROM agora.StockWeeklyCase c LEFT JOIN #Recency d ON d.SourceSystem=c.SourceSystem AND d.Code=c.POSCode
    WHERE c.BranchId=@BranchId AND c.SnapshotRef=@SnapshotRef AND c.RegisterName='Item Register' AND c.SourceSystem='WINBRANCH' AND c.Scenario<>'V'
        AND (d.LastCountDate IS NULL OR DATEDIFF(day,d.LastCountDate,@AsOf)>16);
    SELECT SourceSystem,Code,TransactionDate AS DayDate,SUM(QtyPOS) AS POS INTO #PeriodPOS FROM #PeriodShifts
        WHERE TransactionDate BETWEEN @PeriodFrom AND @PeriodThrough GROUP BY SourceSystem,Code,TransactionDate;
    SELECT SourceSystem,Code,EvidenceDate AS DayDate,CASE WHEN COUNT(*)=COUNT(Quantity) THEN SUM(Quantity) END AS Recipe INTO #PeriodConsumption
        FROM #Evidence WHERE EvidenceType IN ('RawConsumption','RecipeComparison') AND EvidenceDate BETWEEN @PeriodFrom AND @PeriodThrough
        GROUP BY SourceSystem,Code,EvidenceDate;
    CREATE UNIQUE CLUSTERED INDEX IX_PeriodPOS ON #PeriodPOS(SourceSystem,Code,DayDate);
    CREATE UNIQUE CLUSTERED INDEX IX_PeriodConsumption ON #PeriodConsumption(SourceSystem,Code,DayDate);
    SELECT COALESCE(p.SourceSystem,e.SourceSystem) AS SourceSystem,COALESCE(p.Code,e.Code) AS Code,COALESCE(p.DayDate,e.DayDate) AS DayDate,p.POS,e.Recipe,
        p.POS-e.Recipe AS Gap,CASE WHEN p.POS IS NULL OR e.Recipe IS NULL THEN 'Unavailable'
        WHEN ABS(p.POS)<=0.000001 AND e.Recipe>0 THEN 'Recipe used; recon POS nil'
        WHEN ABS(p.POS-e.Recipe)>CASE WHEN 0.1*CASE WHEN ABS(p.POS)>ABS(e.Recipe) THEN ABS(p.POS) ELSE ABS(e.Recipe) END>0.5 THEN 0.1*CASE WHEN ABS(p.POS)>ABS(e.Recipe) THEN ABS(p.POS) ELSE ABS(e.Recipe) END ELSE 0.5 END THEN 'Difference over 10%' ELSE 'Match' END AS Comparison
    INTO #PeriodRecipe FROM #PeriodPOS p FULL HASH JOIN #PeriodConsumption e ON p.SourceSystem=e.SourceSystem AND p.Code=e.Code AND p.DayDate=e.DayDate
    WHERE EXISTS(SELECT 1 FROM #PeriodConsumption x WHERE x.SourceSystem=COALESCE(p.SourceSystem,e.SourceSystem) AND x.Code=COALESCE(p.Code,e.Code)) OPTION(RECOMPILE);
    CREATE INDEX IX_PeriodRecipe_Product ON #PeriodRecipe(SourceSystem,Code,DayDate);
    INSERT #Controls
    SELECT 'Recipe vs Recon','recipe',p.AreaNo,p.StockItemNo,LEFT(p.Areas,160),p.Description,p.SourceSystem,p.Code,@PeriodThrough,'RECIPE',
        CASE WHEN d.MissingDays>0 THEN 'Daily recipe evidence incomplete' WHEN ABS(d.Gap)<=CASE WHEN ABS(d.Recipe)*0.05>2 THEN ABS(d.Recipe)*0.05 ELSE 2 END AND d.NoPOS=0 THEN 'Recipe and recon agree' ELSE 'Recipe / recon link differs' END,
        'Review daily differences. Fix the link or recipe where proven; retain the correction record. Recipe yield is already included.','IT / master data',d.Gap,NULL,
        (SELECT @PeriodFrom AS PeriodFrom,@PeriodThrough AS PeriodThrough,d.Recipe,d.POS,d.Gap,d.NoPOS,d.DifferDays,d.MissingDays,
            JSON_QUERY((SELECT r.DayDate,r.POS AS POSQty,r.Recipe AS RecipeQty,r.Gap AS Difference,r.Comparison FROM #PeriodRecipe r WHERE r.SourceSystem=p.SourceSystem AND r.Code=p.Code ORDER BY r.DayDate FOR JSON PATH,INCLUDE_NULL_VALUES)) AS RecipeDaily
            FOR JSON PATH,INCLUDE_NULL_VALUES,WITHOUT_ARRAY_WRAPPER)
    FROM #Product p CROSS APPLY(SELECT SUM(Recipe) AS Recipe,SUM(POS) AS POS,SUM(Gap) AS Gap,
        SUM(CASE WHEN Comparison='Recipe used; recon POS nil' THEN 1 ELSE 0 END) AS NoPOS,
        SUM(CASE WHEN Comparison='Difference over 10%' THEN 1 ELSE 0 END) AS DifferDays,
        SUM(CASE WHEN Comparison='Unavailable' THEN 1 ELSE 0 END) AS MissingDays FROM #PeriodRecipe r WHERE r.SourceSystem=p.SourceSystem AND r.Code=p.Code) d
    WHERE EXISTS(SELECT 1 FROM #PeriodRecipe r WHERE r.SourceSystem=p.SourceSystem AND r.Code=p.Code);

    /* Compare period totals independently from same-day / next-day delivery matches.
       Issues without a delivery today or yesterday remain explicit evidence. */
    SELECT c.AreaNo,c.StockItemNo,c.AreaDescription,c.Description,c.SourceSystem,c.POSCode,
        s.TransactionDate AS EvidenceDate,SUM(s.QtyIssued) AS Issued,CAST(0 AS decimal(19,4)) AS Received
    INTO #IorDays FROM agora.StockWeeklyCase c JOIN #PeriodShifts s ON s.AreaNo=c.AreaNo AND s.StockItemNo=c.StockItemNo
    WHERE c.BranchId=@BranchId AND c.SnapshotRef=@SnapshotRef AND c.RegisterName='Item Register'
        AND c.AreaDescription='Steers BOH' AND c.POSCode IN ('V7','B1','SBBB')
        AND s.TransactionDate BETWEEN @PeriodFrom AND @PeriodThrough
    GROUP BY c.AreaNo,c.StockItemNo,c.AreaDescription,c.Description,c.SourceSystem,c.POSCode,s.TransactionDate;
    INSERT #IorDays
    SELECT DISTINCT c.AreaNo,c.StockItemNo,c.AreaDescription,c.Description,c.SourceSystem,c.POSCode,e.EvidenceDate,0,0
    FROM agora.StockWeeklyCase c JOIN #Evidence e ON e.SourceSystem=c.SourceSystem AND e.Code=c.POSCode AND e.EvidenceType='Receipt'
    WHERE c.BranchId=@BranchId AND c.SnapshotRef=@SnapshotRef AND c.RegisterName='Item Register'
        AND c.AreaDescription='Steers BOH' AND c.POSCode IN ('V7','B1','SBBB') AND e.EvidenceDate BETWEEN @PeriodFrom AND @PeriodThrough
        AND NOT EXISTS(SELECT 1 FROM #IorDays d WHERE d.AreaNo=c.AreaNo AND d.StockItemNo=c.StockItemNo AND d.EvidenceDate=e.EvidenceDate);
    UPDATE d SET Received=ISNULL(e.Received,0) FROM #IorDays d OUTER APPLY(SELECT SUM(Quantity) AS Received FROM #Evidence e
        WHERE e.SourceSystem=d.SourceSystem AND e.Code=d.POSCode AND e.EvidenceType='Receipt' AND e.EvidenceDate=d.EvidenceDate) e;
    SELECT d.*,CASE WHEN d.Issued<>0 THEN d.Issued ELSE n.Issued END AS MatchedIssued,
        n.Issued AS NextDayIssued,CASE WHEN d.Received=0 AND ISNULL(p.Received,0)=0 AND d.Issued<>0 THEN 1 ELSE 0 END AS IssueWithoutDelivery
    INTO #PeriodDeliveries FROM #IorDays d LEFT JOIN #IorDays n ON n.AreaNo=d.AreaNo AND n.StockItemNo=d.StockItemNo AND n.EvidenceDate=DATEADD(day,1,d.EvidenceDate)
        OUTER APPLY(SELECT SUM(e.Quantity) AS Received FROM #Evidence e WHERE e.SourceSystem=d.SourceSystem AND e.Code=d.POSCode AND e.EvidenceType='Receipt' AND e.EvidenceDate=DATEADD(day,-1,d.EvidenceDate)) p;
    INSERT #Controls
    SELECT 'Potatoes & Buns','receipt-control',c.AreaNo,c.StockItemNo,c.AreaDescription,c.Description,c.SourceSystem,c.POSCode,@PeriodThrough,'IR',
        'Delivery and issue comparison over the control period','Account for every delivery and its issue, including any next-day fallback or unexplained shortfall.','Site manager / key holder',
        SUM(c.Received)-SUM(c.Issued),NULL,
        (SELECT @PeriodFrom AS PeriodFrom,@PeriodThrough AS PeriodThrough,SUM(c.Received) AS Received,SUM(c.Issued) AS Issued,
            JSON_QUERY((SELECT EvidenceDate,Received,Issued AS SameDayIssued,NextDayIssued,MatchedIssued,IssueWithoutDelivery,
                CASE WHEN ABS(ISNULL(MatchedIssued,0)-Received)<=CASE WHEN ABS(Received)*0.01>0.5 THEN ABS(Received)*0.01 ELSE 0.5 END THEN 1 ELSE 0 END AS Matched
                FROM #PeriodDeliveries d WHERE d.AreaNo=c.AreaNo AND d.StockItemNo=c.StockItemNo AND (d.Received<>0 OR d.IssueWithoutDelivery=1)
                ORDER BY EvidenceDate FOR JSON PATH,INCLUDE_NULL_VALUES)) AS Deliveries
            FOR JSON PATH,WITHOUT_ARRAY_WRAPPER)
    FROM #PeriodDeliveries c GROUP BY c.AreaNo,c.StockItemNo,c.AreaDescription,c.Description,c.SourceSystem,c.POSCode;

    INSERT agora.StockWeeklyCase(BranchId,SnapshotRef,RegisterName,RegisterKey,AreaNo,StockItemNo,AreaDescription,Description,SourceSystem,POSCode,EvidenceDate,
        Scenario,ScenarioName,NextAction,ResponsibleRole,ReconQty,ValueAtRiskSelling,CountRows,SharedCountCases,Priority,EvidenceJson,Status,CreatedAt,CreatedBy)
    SELECT @BranchId,@SnapshotRef,RegisterName,RegisterKey,AreaNo,StockItemNo,AreaDescription,Description,SourceSystem,POSCode,EvidenceDate,
        Scenario,ScenarioName,NextAction,ResponsibleRole,Quantity,ValueSelling,0,1,'Medium',EvidenceJson,'Open',@Now,@UserId FROM #Controls;
    /* Management actions are separate cases with their own owner and closure.
       Their evidence lists the exact register cases being followed up. */
    INSERT agora.StockWeeklyCase(BranchId,SnapshotRef,RegisterName,RegisterKey,AreaNo,StockItemNo,AreaDescription,Description,SourceSystem,POSCode,
        Scenario,ScenarioName,NextAction,ResponsibleRole,CountRows,SharedCountCases,Priority,EvidenceJson,Status,CreatedAt,CreatedBy)
    SELECT @BranchId,@SnapshotRef,'Control Actions',CONCAT('action:',c.RegisterName),0,'CONTROL','Management',c.RegisterName,'CONTROL',c.RegisterName,
        'ACTION','Complete the supporting register actions',
        CONCAT('Review ',COUNT(*),' ',c.RegisterName,' cases, assign responsible people and record the completed action and date.'),
        MAX(c.ResponsibleRole),0,1,'High',
        (SELECT JSON_QUERY((SELECT x.Id AS CaseId,x.AreaDescription,x.POSCode,x.Scenario FROM agora.StockWeeklyCase x
            WHERE x.BranchId=@BranchId AND x.SnapshotRef=@SnapshotRef AND x.RegisterName=c.RegisterName FOR JSON PATH)) AS RelatedCases FOR JSON PATH,WITHOUT_ARRAY_WRAPPER),
        'Open',@Now,@UserId
    FROM agora.StockWeeklyCase c WHERE c.BranchId=@BranchId AND c.SnapshotRef=@SnapshotRef AND c.RegisterName NOT IN ('Item Register','Period Items','Control Actions')
    GROUP BY c.RegisterName;
    /* The book also assigns preventive system controls. They need accountable
       action records even when they cannot be inferred from a variance amount. */
    INSERT agora.StockWeeklyCase(BranchId,SnapshotRef,RegisterName,RegisterKey,AreaNo,StockItemNo,AreaDescription,Description,SourceSystem,POSCode,
        Scenario,ScenarioName,NextAction,ResponsibleRole,CountRows,SharedCountCases,Priority,EvidenceJson,Status,CreatedAt,CreatedBy)
    SELECT @BranchId,@SnapshotRef,'Control Actions',CONCAT('policy:',p.Code),0,'CONTROL',p.Area,p.Title,'CONTROL',p.Code,
        'ACTION','Preventive control from the customer master book',p.Action,p.Role,0,1,'Medium',
        (SELECT p.BookAction AS MasterBookAction,p.Target AS RequiredTarget,
            @PeriodFrom AS PeriodFrom,@PeriodThrough AS PeriodThrough,
            'Assign an owner and date. Record the implemented control or a documented, approved reason it is not required at this site. No ERP or customer source change is performed by this case.' AS EvidenceRequirement
            FOR JSON PATH,WITHOUT_ARRAY_WRAPPER),'Open',@Now,@UserId
    FROM (VALUES
        ('namos-count',35,'Counting','NAMOS stock count link','Link a NAMOS stock take to the shop recon.','IT','30 days','NAMOS'),
        ('nil-count-export',36,'Data','Zero-variance count evidence','Include zero-variance count lines in the WinBranch stock take export.','IT / data','30 days','WINBRANCH'),
        ('virtual-lines',37,'Recon master','Remove virtual recon lines','Remove virtual lines (airtime, electricity, lotto, refill water) from the recon item lists.','IT / master data','30 days','ALL'),
        ('pack-validation',38,'Recon master','Enforce issue multiples','Load the issue multiples; reject issues that are not whole packs.','IT / master data','30 days','ALL'),
        ('opening-lock',39,'ERP','Protect the opening and closing handover','Lock the opening to the previous closing; handover difference signed by both staff; balancing may never change a closing to match an opening.','IT / ERP','ERP build','ALL'),
        ('staff-identity',40,'ERP','Identify accountable shift staff','Put the cashier / staff ID on every recon line.','IT / ERP','ERP build','ALL'),
        ('count-reasons',41,'ERP','Explain stock-take overs and reversals','A stock take over or a reversal of last week cannot be posted without a reason code.','IT / ERP','ERP build','ALL')
    ) p(Code,BookAction,Area,Title,Action,Role,Target,System)
    WHERE p.System='ALL' OR EXISTS(SELECT 1 FROM #Recon r WHERE r.SourceSystem=p.System);

END
