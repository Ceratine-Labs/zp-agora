/* Capture a displayed pattern as an immutable group-owned investigation.
   Never accepts source quantities or approval fields from the browser.
   WeeklyPatterns supplies the same evidence shown by GetWeeklyOverview. */
CREATE OR ALTER PROCEDURE agora.usp_StockRecon_CreateWeeklyPattern
    @GroupBranchId INT,@BranchIds NVARCHAR(MAX),@PeriodFrom DATE,@PeriodTo DATE,@POSCode NVARCHAR(80),
    @UserId INT=NULL,@Authorized BIT=0,@Workspace NVARCHAR(10)=N'branch',@BranchPin INT=NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    EXEC agora.usp_StockRecon_AuthorizeWeekly NULL,@UserId,@Authorized,@Workspace,@BranchPin;
    IF @Workspace<>'ho' OR @GroupBranchId IS NULL OR NOT EXISTS(SELECT 1 FROM agora.Branch WHERE BranchId=@GroupBranchId)
        THROW 51000,'AGORA:WEEKLY_FORBIDDEN:Cross-site investigations require head office and a configured group entity.',1;
    IF NULLIF(@BranchIds,'') IS NULL OR EXISTS(SELECT 1 FROM STRING_SPLIT(@BranchIds,',') r
        WHERE TRY_CONVERT(int,r.value) IS NULL OR (EXISTS(SELECT 1 FROM agora.UserBranch WHERE UserId=@UserId)
            AND NOT EXISTS(SELECT 1 FROM agora.UserBranch WHERE UserId=@UserId AND BranchId=TRY_CONVERT(int,r.value))))
        THROW 51000,'AGORA:WEEKLY_FORBIDDEN:Every requested site must be within your current access.',1;
    DECLARE @Patterns TABLE(PeriodFrom date,PeriodTo date,POSCode nvarchar(80),Description nvarchar(240),Sites int,ShortSites int,OverSites int,
        MedianPct float,SiteNames nvarchar(max),NextAction varchar(150),CasesJson nvarchar(max));
    INSERT @Patterns EXEC agora.usp_StockRecon_WeeklyPatterns @BranchIds,NULL,NULL,NULL,@UserId,@Authorized,@Workspace,@BranchPin;
    DECLARE @Cases nvarchar(max),@Evidence nvarchar(max),@Description nvarchar(240),@NextAction nvarchar(500),
        @Ref uniqueidentifier,@CaseId bigint,@Week date,@Now datetime2=SYSUTCDATETIME();
    SELECT @Description=Description,@NextAction=NextAction,@Cases=CasesJson FROM @Patterns
    WHERE PeriodFrom=@PeriodFrom AND PeriodTo=@PeriodTo AND POSCode=@POSCode;
    IF @Cases IS NULL THROW 51000,'AGORA:WEEKLY_PATTERN:That complete cross-site pattern is no longer available. Refresh the overview.',1;
    /* Include every supporting area case, not just the overview's one link per site. */
    SELECT @Cases=(SELECT c.BranchId,b.Name AS BranchName,c.Id AS CaseId,c.SnapshotRef,c.AreaDescription,c.POSCode,c.Scenario,
            JSON_QUERY(c.EvidenceJson) AS SourceEvidence
        FROM OPENJSON(@Cases) WITH(BranchId int,SnapshotRef uniqueidentifier) r
        JOIN agora.StockWeeklyCase c ON c.BranchId=r.BranchId AND c.SnapshotRef=r.SnapshotRef
        LEFT JOIN agora.Branch b ON b.BranchId=c.BranchId
        WHERE c.RegisterName='Item Register' AND c.Scenario<>'V' AND c.POSCode=@POSCode
        ORDER BY c.BranchId,c.Id FOR JSON PATH);
    SELECT @Week=MAX(s.WeekStart) FROM OPENJSON(@Cases) WITH(BranchId int,SnapshotRef uniqueidentifier) r
        JOIN agora.StockWeeklySnapshot s ON s.BranchId=r.BranchId AND s.SnapshotRef=r.SnapshotRef;
    SELECT @Evidence=(SELECT p.PeriodFrom,p.PeriodTo,p.POSCode,p.Sites,p.ShortSites,p.OverSites,p.MedianPct,p.SiteNames,
        JSON_QUERY(@Cases) AS RelatedCases FROM @Patterns p
        WHERE p.PeriodFrom=@PeriodFrom AND p.PeriodTo=@PeriodTo AND p.POSCode=@POSCode FOR JSON PATH,WITHOUT_ARRAY_WRAPPER);
    SET @Ref=CONVERT(uniqueidentifier,SUBSTRING(HASHBYTES('SHA2_256',@Evidence),1,16));
    BEGIN TRANSACTION;
    SELECT @CaseId=Id FROM agora.StockWeeklyCase WITH(UPDLOCK,HOLDLOCK)
        WHERE BranchId=@GroupBranchId AND SnapshotRef=@Ref AND RegisterName='Cross-Site Patterns';
    IF @CaseId IS NULL
    BEGIN
        INSERT agora.StockWeeklySnapshot(BranchId,SnapshotRef,WeekStart,WeekEnd,CompleteThrough,SourceReadAt,RuleVersion,SourceJson,CoverageJson,
            PeriodFrom,PeriodTo,PeriodThrough,ReportingAsOf,CreatedAt,CreatedBy)
        VALUES(@GroupBranchId,@Ref,@Week,DATEADD(day,6,@Week),@PeriodTo,@Now,'weekly-pattern-1',@Evidence,'[]',
            @PeriodFrom,@PeriodTo,@PeriodTo,CONVERT(date,DATEADD(hour,2,@Now)),@Now,@UserId);
        INSERT agora.StockWeeklyCase(BranchId,SnapshotRef,RegisterName,RegisterKey,AreaNo,StockItemNo,AreaDescription,Description,SourceSystem,POSCode,
            CountRows,SharedCountCases,Scenario,ScenarioName,NextAction,Priority,EvidenceJson,ResponsibleRole,CreatedAt,CreatedBy)
        VALUES(@GroupBranchId,@Ref,'Cross-Site Patterns','pattern',0,'PATTERN','Cross-site',@Description,'PATTERN',@POSCode,
            0,1,'PATTERN','Cross-site recipe, pack or link investigation',@NextAction,'High',@Evidence,'IT / master data',@Now,@UserId);
        SET @CaseId=SCOPE_IDENTITY();
    END
    COMMIT TRANSACTION;
    SELECT CAST(1 AS bit) AS Ok,'SAVED' AS Code,'Cross-site investigation ready. Supporting source evidence is preserved.' AS Message,
        @CaseId AS Id,@GroupBranchId AS BranchId,@Ref AS SnapshotRef;
END
