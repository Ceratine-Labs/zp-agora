/* Snapshot case register: scope, typed filters, count and CSV share one set. */
CREATE OR ALTER PROCEDURE agora.usp_StockRecon_GridWeeklyCases
    @BranchIds NVARCHAR(MAX)=NULL,@DateFrom DATE=NULL,@DateTo DATE=NULL,@Search NVARCHAR(200)=NULL,
    @SortColumn NVARCHAR(80)=NULL,@SortAsc BIT=0,@Page INT=1,@PageSize INT=50,@FiltersJson NVARCHAR(MAX)=NULL,
    @SnapshotRef UNIQUEIDENTIFIER=NULL,@UserId INT=NULL,@Authorized BIT=0,@Workspace NVARCHAR(10)=N'branch',@BranchPin INT=NULL
AS
BEGIN
    SET NOCOUNT ON;
    EXEC agora.usp_StockRecon_AuthorizeWeekly NULL,@UserId,@Authorized,@Workspace,@BranchPin;
    SET @Page=CASE WHEN ISNULL(@Page,0)<1 THEN 1 ELSE @Page END;
    SET @PageSize=CASE WHEN ISNULL(@PageSize,0) BETWEEN 1 AND 100000 THEN @PageSize ELSE 50 END;
    DECLARE @Offset bigint=CONVERT(bigint,@Page-1)*@PageSize;
    DECLARE @Filters TABLE([Column] nvarchar(80),Op nvarchar(20),Value nvarchar(400),SetJson nvarchar(max));
    INSERT @Filters SELECT JSON_VALUE(value,'$.column'),JSON_VALUE(value,'$.op'),JSON_VALUE(value,'$.value'),JSON_QUERY(value,'$.in') FROM OPENJSON(@FiltersJson);
    SELECT c.*,s.PeriodFrom,s.PeriodTo,s.WeekStart,s.WeekEnd,s.CompleteThrough,s.SourceReadAt,b.Name AS BranchName,u.UserName AS OwnerName,approver.UserName AS SignedOffName
    INTO #Cases FROM agora.StockWeeklyCase c
    JOIN agora.StockWeeklySnapshot s ON s.BranchId=c.BranchId AND s.SnapshotRef=c.SnapshotRef
    LEFT JOIN agora.[User] approver ON approver.Id=c.SignedOffBy
    LEFT JOIN agora.Branch b ON b.BranchId=c.BranchId LEFT JOIN agora.[User] u ON u.Id=c.OwnerId
    WHERE (@Workspace='ho' OR c.BranchId=@BranchPin)
      AND (NOT EXISTS(SELECT 1 FROM agora.UserBranch WHERE UserId=@UserId) OR EXISTS(SELECT 1 FROM agora.UserBranch WHERE UserId=@UserId AND BranchId=c.BranchId))
      AND (NULLIF(@BranchIds,'') IS NULL OR c.BranchId IN(SELECT TRY_CONVERT(int,value) FROM STRING_SPLIT(@BranchIds,',')))
      AND (@SnapshotRef IS NOT NULL OR ((@DateFrom IS NULL OR s.WeekStart>=@DateFrom) AND (@DateTo IS NULL OR s.WeekStart<=@DateTo)))
      AND ((@SnapshotRef IS NOT NULL AND c.SnapshotRef=@SnapshotRef) OR (@SnapshotRef IS NULL AND NOT EXISTS(
          SELECT 1 FROM agora.StockWeeklySnapshot newer WHERE newer.BranchId=s.BranchId AND newer.WeekStart=s.WeekStart AND ISNULL(newer.PeriodFrom,newer.WeekStart)=ISNULL(s.PeriodFrom,s.WeekStart) AND ISNULL(newer.PeriodTo,newer.WeekEnd)=ISNULL(s.PeriodTo,s.WeekEnd) AND newer.Id>s.Id)))
      AND (@Search IS NULL OR c.Description LIKE '%'+@Search+'%' OR c.POSCode LIKE '%'+@Search+'%' OR b.Name LIKE '%'+@Search+'%');
    SELECT a.* INTO #Filtered FROM #Cases a WHERE NOT EXISTS(
        SELECT 1 FROM @Filters f CROSS APPLY(SELECT
            CASE f.[Column] WHEN 'BranchName' THEN a.BranchName WHEN 'Description' THEN a.Description WHEN 'AreaDescription' THEN a.AreaDescription WHEN 'POSCode' THEN a.POSCode WHEN 'Finding' THEN a.Finding WHEN 'EvidenceReference' THEN a.EvidenceReference WHEN 'NextAction' THEN a.NextAction WHEN 'ClosureOutcome' THEN a.ClosureOutcome WHEN 'ActionTaken' THEN a.ActionTaken WHEN 'SignedOffName' THEN a.SignedOffName WHEN 'RecoveryReference' THEN a.RecoveryReference WHEN 'CorrectionReference' THEN a.CorrectionReference WHEN 'SignoffNote' THEN a.SignoffNote WHEN 'RegisterName' THEN a.RegisterName WHEN 'CostBasis' THEN a.CostBasis WHEN 'ResponsibleRole' THEN a.ResponsibleRole WHEN 'OwnerName' THEN a.OwnerName WHEN 'SourceSystem' THEN a.SourceSystem WHEN 'Scenario' THEN a.Scenario WHEN 'Status' THEN a.Status WHEN 'CorrectionStatus' THEN a.CorrectionStatus WHEN 'Priority' THEN a.Priority END AS TextValue,
            CASE f.[Column] WHEN 'ReconQty' THEN a.ReconQty WHEN 'CountQty' THEN a.CountQty WHEN 'StoreroomQty' THEN a.StoreroomQty WHEN 'ValueAtRiskSelling' THEN a.ValueAtRiskSelling WHEN 'RecoveryAmount' THEN a.RecoveryAmount WHEN 'UnitCost' THEN a.UnitCost WHEN 'ReconValueCost' THEN a.ReconValueCost WHEN 'CountValueCost' THEN a.CountValueCost WHEN 'StoreroomValueCost' THEN a.StoreroomValueCost WHEN 'SharedCountCases' THEN a.SharedCountCases END AS NumberValue,
            CASE f.[Column] WHEN 'WeekStart' THEN a.WeekStart WHEN 'SignedOffAt' THEN CONVERT(date,DATEADD(hour,2,a.SignedOffAt)) WHEN 'ActionDate' THEN a.ActionDate WHEN 'ClosedDate' THEN a.ClosedDate WHEN 'PeriodFrom' THEN a.PeriodFrom WHEN 'PeriodTo' THEN a.PeriodTo WHEN 'EvidenceDate' THEN a.EvidenceDate WHEN 'DueDate' THEN a.DueDate WHEN 'LastCountDate' THEN a.LastCountDate END AS DateValue) v
        WHERE (f.[Column] IN('BranchName','Description','AreaDescription','POSCode','Finding','EvidenceReference','NextAction','ClosureOutcome','ActionTaken','SignedOffName','RecoveryReference','CorrectionReference','SignoffNote','CostBasis','ResponsibleRole','OwnerName') AND CASE
            WHEN f.Op='contains' AND v.TextValue LIKE '%'+f.Value+'%' THEN 1
            WHEN f.Op='eq' AND v.TextValue = f.Value THEN 1
WHEN f.Op='ne' AND v.TextValue <> f.Value THEN 1
WHEN f.Op='gt' AND v.TextValue > f.Value THEN 1
WHEN f.Op='gte' AND v.TextValue >= f.Value THEN 1
WHEN f.Op='lt' AND v.TextValue < f.Value THEN 1
WHEN f.Op='lte' AND v.TextValue <= f.Value THEN 1 ELSE 0 END=0)
        OR (f.[Column] IN('SourceSystem','Scenario','Status','CorrectionStatus','RegisterName','Priority') AND NOT EXISTS(SELECT 1 FROM OPENJSON(f.SetJson) x WHERE x.value=v.TextValue))
        OR (f.[Column] IN('ReconQty','CountQty','StoreroomQty','ValueAtRiskSelling','RecoveryAmount','UnitCost','ReconValueCost','CountValueCost','StoreroomValueCost','SharedCountCases') AND CASE WHEN f.Op='eq' AND v.NumberValue = TRY_CONVERT(decimal(19,4),f.Value) THEN 1
WHEN f.Op='ne' AND v.NumberValue <> TRY_CONVERT(decimal(19,4),f.Value) THEN 1
WHEN f.Op='gt' AND v.NumberValue > TRY_CONVERT(decimal(19,4),f.Value) THEN 1
WHEN f.Op='gte' AND v.NumberValue >= TRY_CONVERT(decimal(19,4),f.Value) THEN 1
WHEN f.Op='lt' AND v.NumberValue < TRY_CONVERT(decimal(19,4),f.Value) THEN 1
WHEN f.Op='lte' AND v.NumberValue <= TRY_CONVERT(decimal(19,4),f.Value) THEN 1 ELSE 0 END=0)
        OR (f.[Column] IN('WeekStart','SignedOffAt','ActionDate','ClosedDate','PeriodFrom','PeriodTo','EvidenceDate','DueDate','LastCountDate') AND CASE WHEN f.Op='eq' AND v.DateValue = TRY_CONVERT(date,f.Value) THEN 1
WHEN f.Op='ne' AND v.DateValue <> TRY_CONVERT(date,f.Value) THEN 1
WHEN f.Op='gt' AND v.DateValue > TRY_CONVERT(date,f.Value) THEN 1
WHEN f.Op='gte' AND v.DateValue >= TRY_CONVERT(date,f.Value) THEN 1
WHEN f.Op='lt' AND v.DateValue < TRY_CONVERT(date,f.Value) THEN 1
WHEN f.Op='lte' AND v.DateValue <= TRY_CONVERT(date,f.Value) THEN 1 ELSE 0 END=0));
    SELECT * FROM #Filtered a ORDER BY
        CASE WHEN @SortAsc=1 THEN CASE @SortColumn WHEN 'BranchName' THEN a.BranchName WHEN 'Description' THEN a.Description WHEN 'AreaDescription' THEN a.AreaDescription WHEN 'POSCode' THEN a.POSCode WHEN 'Finding' THEN a.Finding WHEN 'EvidenceReference' THEN a.EvidenceReference WHEN 'NextAction' THEN a.NextAction WHEN 'ClosureOutcome' THEN a.ClosureOutcome WHEN 'ActionTaken' THEN a.ActionTaken WHEN 'SignedOffName' THEN a.SignedOffName WHEN 'RecoveryReference' THEN a.RecoveryReference WHEN 'CorrectionReference' THEN a.CorrectionReference WHEN 'SignoffNote' THEN a.SignoffNote WHEN 'RegisterName' THEN a.RegisterName WHEN 'CostBasis' THEN a.CostBasis WHEN 'ResponsibleRole' THEN a.ResponsibleRole WHEN 'OwnerName' THEN a.OwnerName WHEN 'SourceSystem' THEN a.SourceSystem WHEN 'Scenario' THEN a.Scenario WHEN 'Status' THEN a.Status WHEN 'CorrectionStatus' THEN a.CorrectionStatus WHEN 'Priority' THEN a.Priority END END ASC,
        CASE WHEN @SortAsc=0 THEN CASE @SortColumn WHEN 'BranchName' THEN a.BranchName WHEN 'Description' THEN a.Description WHEN 'AreaDescription' THEN a.AreaDescription WHEN 'POSCode' THEN a.POSCode WHEN 'Finding' THEN a.Finding WHEN 'EvidenceReference' THEN a.EvidenceReference WHEN 'NextAction' THEN a.NextAction WHEN 'ClosureOutcome' THEN a.ClosureOutcome WHEN 'ActionTaken' THEN a.ActionTaken WHEN 'SignedOffName' THEN a.SignedOffName WHEN 'RecoveryReference' THEN a.RecoveryReference WHEN 'CorrectionReference' THEN a.CorrectionReference WHEN 'SignoffNote' THEN a.SignoffNote WHEN 'RegisterName' THEN a.RegisterName WHEN 'CostBasis' THEN a.CostBasis WHEN 'ResponsibleRole' THEN a.ResponsibleRole WHEN 'OwnerName' THEN a.OwnerName WHEN 'SourceSystem' THEN a.SourceSystem WHEN 'Scenario' THEN a.Scenario WHEN 'Status' THEN a.Status WHEN 'CorrectionStatus' THEN a.CorrectionStatus WHEN 'Priority' THEN a.Priority END END DESC,
        CASE WHEN @SortAsc=1 THEN CASE @SortColumn WHEN 'ReconQty' THEN a.ReconQty WHEN 'CountQty' THEN a.CountQty WHEN 'StoreroomQty' THEN a.StoreroomQty WHEN 'ValueAtRiskSelling' THEN a.ValueAtRiskSelling WHEN 'RecoveryAmount' THEN a.RecoveryAmount WHEN 'UnitCost' THEN a.UnitCost WHEN 'ReconValueCost' THEN a.ReconValueCost WHEN 'CountValueCost' THEN a.CountValueCost WHEN 'StoreroomValueCost' THEN a.StoreroomValueCost WHEN 'SharedCountCases' THEN a.SharedCountCases END END ASC,
        CASE WHEN @SortAsc=0 THEN CASE @SortColumn WHEN 'ReconQty' THEN a.ReconQty WHEN 'CountQty' THEN a.CountQty WHEN 'StoreroomQty' THEN a.StoreroomQty WHEN 'ValueAtRiskSelling' THEN a.ValueAtRiskSelling WHEN 'RecoveryAmount' THEN a.RecoveryAmount WHEN 'UnitCost' THEN a.UnitCost WHEN 'ReconValueCost' THEN a.ReconValueCost WHEN 'CountValueCost' THEN a.CountValueCost WHEN 'StoreroomValueCost' THEN a.StoreroomValueCost WHEN 'SharedCountCases' THEN a.SharedCountCases END END DESC,
        CASE WHEN @SortAsc=1 THEN CASE ISNULL(@SortColumn,'WeekStart') WHEN 'WeekStart' THEN a.WeekStart WHEN 'SignedOffAt' THEN a.SignedOffAt WHEN 'ActionDate' THEN a.ActionDate WHEN 'ClosedDate' THEN a.ClosedDate WHEN 'PeriodFrom' THEN a.PeriodFrom WHEN 'PeriodTo' THEN a.PeriodTo WHEN 'EvidenceDate' THEN a.EvidenceDate WHEN 'DueDate' THEN a.DueDate WHEN 'LastCountDate' THEN a.LastCountDate END END ASC,
        CASE WHEN @SortAsc=0 THEN CASE ISNULL(@SortColumn,'WeekStart') WHEN 'WeekStart' THEN a.WeekStart WHEN 'SignedOffAt' THEN a.SignedOffAt WHEN 'ActionDate' THEN a.ActionDate WHEN 'ClosedDate' THEN a.ClosedDate WHEN 'PeriodFrom' THEN a.PeriodFrom WHEN 'PeriodTo' THEN a.PeriodTo WHEN 'EvidenceDate' THEN a.EvidenceDate WHEN 'DueDate' THEN a.DueDate WHEN 'LastCountDate' THEN a.LastCountDate END END DESC,
        BranchId,AreaNo,StockItemNo,Id
    OFFSET @Offset ROWS FETCH NEXT @PageSize ROWS ONLY;
    SELECT COUNT_BIG(*) AS TotalRows FROM #Filtered;
END
