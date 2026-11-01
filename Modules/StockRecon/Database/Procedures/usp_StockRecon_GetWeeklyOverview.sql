/* Site scorecard uses latest snapshots and one site-count per product, never
   a sum of the same whole-site variance attached to several counting areas. */
CREATE OR ALTER PROCEDURE agora.usp_StockRecon_GetWeeklyOverview
    @BranchIds NVARCHAR(MAX)=NULL,@DateFrom DATE=NULL,@DateTo DATE=NULL,@SnapshotRef UNIQUEIDENTIFIER=NULL,
    @UserId INT=NULL,@Authorized BIT=0,@Workspace NVARCHAR(10)=N'branch',@BranchPin INT=NULL
AS
BEGIN
    SET NOCOUNT ON;
    EXEC agora.usp_StockRecon_AuthorizeWeekly NULL,@UserId,@Authorized,@Workspace,@BranchPin;
    SELECT s.*,b.Name AS BranchName INTO #Snapshots FROM agora.StockWeeklySnapshot s LEFT JOIN agora.Branch b ON b.BranchId=s.BranchId
    WHERE (@Workspace='ho' OR s.BranchId=@BranchPin)
      AND (NOT EXISTS(SELECT 1 FROM agora.UserBranch WHERE UserId=@UserId) OR EXISTS(SELECT 1 FROM agora.UserBranch WHERE UserId=@UserId AND BranchId=s.BranchId))
      AND (NULLIF(@BranchIds,'') IS NULL OR s.BranchId IN(SELECT TRY_CONVERT(int,value) FROM STRING_SPLIT(@BranchIds,',')))
      AND (@SnapshotRef IS NOT NULL OR ((@DateFrom IS NULL OR s.WeekStart>=@DateFrom) AND (@DateTo IS NULL OR s.WeekStart<=@DateTo)))
      AND ((@SnapshotRef IS NOT NULL AND s.SnapshotRef=@SnapshotRef) OR (@SnapshotRef IS NULL AND NOT EXISTS(
          SELECT 1 FROM agora.StockWeeklySnapshot newer WHERE newer.BranchId=s.BranchId AND newer.WeekStart=s.WeekStart AND ISNULL(newer.PeriodFrom,newer.WeekStart)=ISNULL(s.PeriodFrom,s.WeekStart) AND ISNULL(newer.PeriodTo,newer.WeekEnd)=ISNULL(s.PeriodTo,s.WeekEnd) AND newer.Id>s.Id)));
    SELECT s.BranchId,s.BranchName,s.SnapshotRef,s.WeekStart,s.WeekEnd,s.PeriodFrom,s.PeriodTo,s.PeriodThrough,s.ReportingAsOf,s.CompleteThrough,s.SourceReadAt,s.CoverageJson,
        SUM(CASE WHEN c.Status='Closed' AND c.SignedOffBy IS NOT NULL THEN c.RecoveryAmount END) AS ApprovedRecoveryAmount,
        COUNT(*) AS Cases,SUM(CASE WHEN c.Scenario<>'OK' THEN 1 ELSE 0 END) AS LinesToInvestigate,
        SUM(CASE WHEN c.Scenario NOT IN ('OK','V') AND c.SharedCountCases=1 THEN c.ValueAtRiskSelling END) AS ValueAtRiskSelling,
        SUM(CASE WHEN c.Scenario IN ('A2','B1','B2','IR') AND c.SharedCountCases=1 THEN c.ValueAtRiskSelling END) AS StoreroomExposureSelling,
        SUM(CASE WHEN c.Scenario='A1' AND c.SharedCountCases=1 THEN c.ValueAtRiskSelling END) AS ReconPointExposureSelling,
        SUM(CASE WHEN c.Status='Closed' THEN 1 ELSE 0 END) AS ClosedCases,
        SUM(CASE WHEN c.Scenario NOT IN ('OK','V') AND c.Status<>'Closed' AND c.OwnerId IS NULL THEN 1 ELSE 0 END) AS UnassignedCases,
        SUM(CASE WHEN c.Scenario NOT IN ('OK','V') AND c.Status<>'Closed' AND c.DueDate<CONVERT(date,DATEADD(hour,2,SYSUTCDATETIME())) THEN 1 ELSE 0 END) AS OverdueCases,
        SUM(CASE WHEN c.Scenario NOT IN ('OK','V') AND c.Status<>'Closed' AND c.OwnerId IS NULL AND DATEDIFF(hour,c.CreatedAt,SYSUTCDATETIME())>=24 THEN 1 ELSE 0 END) AS Unanswered24h,
        SUM(CASE WHEN c.Scenario NOT IN ('OK','V') AND c.Status<>'Closed' AND NULLIF(c.Finding,'') IS NULL AND DATEDIFF(hour,c.CreatedAt,SYSUTCDATETIME())>=48 THEN 1 ELSE 0 END) AS NoFinding48h,
        SUM(CASE WHEN c.Scenario='V' THEN 1 ELSE 0 END) AS VirtualCases,
        SUM(CASE WHEN c.SharedCountCases>1 THEN 1 ELSE 0 END) AS SharedCountCases
    FROM #Snapshots s JOIN agora.StockWeeklyCase c ON c.BranchId=s.BranchId AND c.SnapshotRef=s.SnapshotRef AND c.RegisterName='Item Register'
    GROUP BY s.BranchId,s.BranchName,s.SnapshotRef,s.WeekStart,s.WeekEnd,s.PeriodFrom,s.PeriodTo,s.PeriodThrough,s.ReportingAsOf,s.CompleteThrough,s.SourceReadAt,s.CoverageJson ORDER BY s.WeekStart DESC,s.BranchName;
    SELECT p.BranchId,p.SnapshotRef,COUNT(*) AS PhysicalProducts,SUM(CASE WHEN CountQty IS NOT NULL THEN 1 ELSE 0 END) AS CountProven,
        SUM(CASE WHEN CountQty IS NULL THEN 1 ELSE 0 END) AS CountUnproven,
        SUM(CASE WHEN Shared=1 AND SamePrice=1 THEN ReconQty*UnitSellingPrice END) AS ReconValueSelling,
        SUM(CASE WHEN Shared=1 AND SamePrice=1 THEN CountQty*UnitSellingPrice END) AS CountValueSelling,
        SUM(CASE WHEN Shared=1 AND SamePrice=1 THEN (CountQty-ReconQty)*UnitSellingPrice END) AS StoreroomValueSelling,
        SUM(CASE WHEN Shared>1 OR SamePrice=0 THEN 1 ELSE 0 END) AS ExcludedFromValueTotals
    FROM (SELECT c.BranchId,c.SnapshotRef,c.SourceSystem,c.POSCode,MAX(c.CountQty) AS CountQty,SUM(c.ReconQty) AS ReconQty,
        MAX(c.UnitSellingPrice) AS UnitSellingPrice,MAX(c.SharedCountCases) AS Shared,
        CASE WHEN MIN(c.UnitSellingPrice)=MAX(c.UnitSellingPrice) THEN 1 ELSE 0 END AS SamePrice
        FROM agora.StockWeeklyCase c JOIN #Snapshots s ON s.BranchId=c.BranchId AND s.SnapshotRef=c.SnapshotRef
        WHERE c.RegisterName='Item Register' AND c.Scenario<>'V' GROUP BY c.BranchId,c.SnapshotRef,c.SourceSystem,c.POSCode) p GROUP BY p.BranchId,p.SnapshotRef;
    SELECT c.BranchId,c.SnapshotRef,c.RegisterName,COUNT(*) AS Cases,
        SUM(CASE WHEN c.Status='Closed' THEN 1 ELSE 0 END) AS ClosedCases,
        SUM(CASE WHEN c.Status='Closed' AND c.SignedOffBy IS NOT NULL THEN c.RecoveryAmount END) AS ApprovedRecovery,
        SUM(c.ReconValueCost) AS ReconValueCost,SUM(c.CountValueCost) AS CountValueCost,SUM(c.StoreroomValueCost) AS StoreroomValueCost,
        SUM(CASE WHEN c.CostBasis LIKE 'Estimated:%' THEN 1 ELSE 0 END) AS EstimatedCostCases,
        SUM(CASE WHEN TRY_CONVERT(int,JSON_VALUE(c.EvidenceJson,'$.PartialWindows'))>0 THEN 1 ELSE 0 END) AS PartialCases
    FROM agora.StockWeeklyCase c JOIN #Snapshots s ON s.BranchId=c.BranchId AND s.SnapshotRef=c.SnapshotRef
    GROUP BY c.BranchId,c.SnapshotRef,c.RegisterName;
    ;WITH revisions AS (
        SELECT s.*,ROW_NUMBER() OVER(PARTITION BY BranchId,PeriodFrom,PeriodTo ORDER BY Id DESC) AS Latest FROM #Snapshots s
    ), products AS (
        SELECT s.BranchId,s.BranchName,s.SnapshotRef,s.PeriodFrom,s.PeriodTo,c.POSCode,MAX(c.Description) AS Description,MIN(c.Id) AS CaseId,
            MAX(TRY_CONVERT(decimal(19,4),JSON_VALUE(c.EvidenceJson,'$.PeriodPOS'))) AS POS,
            MAX(TRY_CONVERT(decimal(19,4),JSON_VALUE(c.EvidenceJson,'$.PeriodStaffRecon'))) AS StaffRecon
        FROM revisions s JOIN agora.StockWeeklyCase c ON c.BranchId=s.BranchId AND c.SnapshotRef=s.SnapshotRef
        WHERE s.Latest=1 AND s.PeriodThrough=s.PeriodTo AND c.RegisterName='Item Register' AND c.Scenario<>'V'
        GROUP BY s.BranchId,s.BranchName,s.SnapshotRef,s.PeriodFrom,s.PeriodTo,c.POSCode
    ), ratios AS (
        SELECT *,StaffRecon/POS AS Pct FROM products WHERE POS>0 AND StaffRecon IS NOT NULL
    ), medians AS (
        SELECT *,PERCENTILE_CONT(0.5) WITHIN GROUP(ORDER BY Pct) OVER(PARTITION BY PeriodFrom,PeriodTo,POSCode) AS MedianPct FROM ratios
    )
    SELECT PeriodFrom,PeriodTo,POSCode,MAX(Description) AS Description,COUNT(*) AS Sites,
        SUM(CASE WHEN Pct< -0.03 THEN 1 ELSE 0 END) AS ShortSites,SUM(CASE WHEN Pct>0.03 THEN 1 ELSE 0 END) AS OverSites,
        MAX(MedianPct) AS MedianPct,STRING_AGG(CONVERT(nvarchar(max),BranchName),', ') AS SiteNames,
        'Review recipe, pack size and PLU mapping across these sites before attributing a loss.' AS NextAction,
        (SELECT r.BranchId,r.BranchName,r.CaseId,r.SnapshotRef FROM ratios r
            WHERE r.PeriodFrom=m.PeriodFrom AND r.PeriodTo=m.PeriodTo AND r.POSCode=m.POSCode
            ORDER BY r.BranchId FOR JSON PATH) AS CasesJson
    FROM medians m GROUP BY PeriodFrom,PeriodTo,POSCode
    HAVING COUNT(*)>=3 AND (SUM(CASE WHEN Pct< -0.03 THEN 1 ELSE 0 END)>=0.75*COUNT(*) AND SUM(CASE WHEN Pct< -0.03 THEN 1 ELSE 0 END)>=3
        OR SUM(CASE WHEN Pct>0.03 THEN 1 ELSE 0 END)>=0.75*COUNT(*) AND SUM(CASE WHEN Pct>0.03 THEN 1 ELSE 0 END)>=3)
    ORDER BY PeriodFrom,POSCode;
END
