/* Read-only cross-site evidence shared by overview and signed pattern capture.
   Complete permitted snapshots only; no source or investigation writes. */
CREATE OR ALTER PROCEDURE agora.usp_StockRecon_WeeklyPatterns
    @BranchIds NVARCHAR(MAX)=NULL,@DateFrom DATE=NULL,@DateTo DATE=NULL,@SnapshotRef UNIQUEIDENTIFIER=NULL,
    @UserId INT=NULL,@Authorized BIT=0,@Workspace NVARCHAR(10)=N'branch',@BranchPin INT=NULL
AS
BEGIN
    SET NOCOUNT ON;
    EXEC agora.usp_StockRecon_AuthorizeWeekly NULL,@UserId,@Authorized,@Workspace,@BranchPin;
    SELECT s.*,b.Name AS BranchName INTO #PatternSnapshots FROM agora.StockWeeklySnapshot s LEFT JOIN agora.Branch b ON b.BranchId=s.BranchId
    WHERE (@Workspace='ho' OR s.BranchId=@BranchPin)
      AND (NOT EXISTS(SELECT 1 FROM agora.UserBranch WHERE UserId=@UserId) OR EXISTS(SELECT 1 FROM agora.UserBranch WHERE UserId=@UserId AND BranchId=s.BranchId))
      AND (NULLIF(@BranchIds,'') IS NULL OR s.BranchId IN(SELECT TRY_CONVERT(int,value) FROM STRING_SPLIT(@BranchIds,',')))
      AND (@SnapshotRef IS NOT NULL OR ((@DateFrom IS NULL OR s.WeekStart>=@DateFrom) AND (@DateTo IS NULL OR s.WeekStart<=@DateTo)))
      AND ((@SnapshotRef IS NOT NULL AND s.SnapshotRef=@SnapshotRef) OR (@SnapshotRef IS NULL AND NOT EXISTS(
          SELECT 1 FROM agora.StockWeeklySnapshot newer WHERE newer.BranchId=s.BranchId AND newer.WeekStart=s.WeekStart AND ISNULL(newer.PeriodFrom,newer.WeekStart)=ISNULL(s.PeriodFrom,s.WeekStart) AND ISNULL(newer.PeriodTo,newer.WeekEnd)=ISNULL(s.PeriodTo,s.WeekEnd) AND newer.Id>s.Id)));
    ;WITH revisions AS (
        SELECT s.*,ROW_NUMBER() OVER(PARTITION BY BranchId,PeriodFrom,PeriodTo ORDER BY Id DESC) AS Latest FROM #PatternSnapshots s
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
        MAX(MedianPct) AS MedianPct,STRING_AGG(CONVERT(nvarchar(max),BranchName),', ') WITHIN GROUP(ORDER BY BranchId) AS SiteNames,
        'Review recipe, pack size and PLU mapping across these sites before attributing a loss.' AS NextAction,
        (SELECT r.BranchId,r.BranchName,r.CaseId,r.SnapshotRef FROM ratios r
            WHERE r.PeriodFrom=m.PeriodFrom AND r.PeriodTo=m.PeriodTo AND r.POSCode=m.POSCode
            ORDER BY r.BranchId FOR JSON PATH) AS CasesJson
    FROM medians m GROUP BY PeriodFrom,PeriodTo,POSCode
    HAVING COUNT(*)>=3 AND (SUM(CASE WHEN Pct< -0.03 THEN 1 ELSE 0 END)>=0.75*COUNT(*) AND SUM(CASE WHEN Pct< -0.03 THEN 1 ELSE 0 END)>=3
        OR SUM(CASE WHEN Pct>0.03 THEN 1 ELSE 0 END)>=0.75*COUNT(*) AND SUM(CASE WHEN Pct>0.03 THEN 1 ELSE 0 END)>=3)
    ORDER BY PeriodFrom,POSCode;
END
