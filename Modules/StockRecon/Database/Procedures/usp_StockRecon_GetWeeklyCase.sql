CREATE OR ALTER PROCEDURE agora.usp_StockRecon_GetWeeklyCase
    @BranchId INT,@CaseId BIGINT,@UserId INT,@Authorized BIT=0,@Workspace NVARCHAR(10)=N'branch',@BranchPin INT=NULL
AS
BEGIN
    SET NOCOUNT ON;
    EXEC agora.usp_StockRecon_AuthorizeWeekly @BranchId,@UserId,@Authorized,@Workspace,@BranchPin;
    DECLARE @SourceJson nvarchar(max),@POSCode nvarchar(80),@SourceSystem nvarchar(20),@AreaNo int,@StockItemNo nvarchar(10);
    SELECT @SourceJson=s.SourceJson,@POSCode=c.POSCode,@SourceSystem=c.SourceSystem,@AreaNo=c.AreaNo,@StockItemNo=c.StockItemNo
    FROM agora.StockWeeklyCase c JOIN agora.StockWeeklySnapshot s ON s.BranchId=c.BranchId AND s.SnapshotRef=c.SnapshotRef
    WHERE c.BranchId=@BranchId AND c.Id=@CaseId;
    SELECT c.*,CONVERT(varchar(18),CONVERT(binary(8),c.RowVer),1) AS VersionToken,
        s.PeriodFrom,s.PeriodTo,s.PeriodThrough,s.ReportingAsOf,s.WeekStart,s.WeekEnd,s.CompleteThrough,s.SourceReadAt,s.RuleVersion,s.CoverageJson,b.Name AS BranchName,u.UserName AS OwnerName,approver.UserName AS SignedOffName
    FROM agora.StockWeeklyCase c JOIN agora.StockWeeklySnapshot s ON s.BranchId=c.BranchId AND s.SnapshotRef=c.SnapshotRef
    LEFT JOIN agora.[User] approver ON approver.Id=c.SignedOffBy
    LEFT JOIN agora.Branch b ON b.BranchId=c.BranchId LEFT JOIN agora.[User] u ON u.Id=c.OwnerId
    WHERE c.BranchId=@BranchId AND c.Id=@CaseId;
    SELECT * FROM OPENJSON(@SourceJson,'$.recon') WITH (AreaNo int,StockItemNo nvarchar(10),TransactionDate date,ShiftNo int,
        QtyOpen decimal(19,4),QtyIssued decimal(19,4),QtyClose decimal(19,4),QtyPOS decimal(19,4),
        OriginalOpen decimal(19,4),OriginalIssued decimal(19,4),OriginalClose decimal(19,4),OriginalPOS decimal(19,4)) r
    WHERE AreaNo=@AreaNo AND StockItemNo=@StockItemNo ORDER BY TransactionDate,ShiftNo;
    SELECT c.* FROM OPENJSON(@SourceJson,'$.counts') WITH(SourceSystem nvarchar(20),POSCode nvarchar(80),CountDate date,
        VarianceQty decimal(19,4),CountedQty decimal(19,4),ExpectedQty decimal(19,4),UnitCost decimal(19,4),Reference nvarchar(160),DetailJson nvarchar(max)) c
    CROSS APPLY(SELECT CASE WHEN UPPER(LTRIM(RTRIM(POSCode))) LIKE 'PROD%' THEN SUBSTRING(UPPER(LTRIM(RTRIM(POSCode))),5,80) ELSE UPPER(LTRIM(RTRIM(POSCode))) END AS Code1) n
    WHERE SourceSystem=@SourceSystem AND CASE WHEN RIGHT(Code1,2)='.0' THEN LEFT(Code1,LEN(Code1)-2) ELSE Code1 END=@POSCode ORDER BY CountDate,Reference;
    SELECT e.* FROM OPENJSON(@SourceJson,'$.evidence') WITH(SourceSystem nvarchar(20),POSCode nvarchar(80),EvidenceType nvarchar(30),EvidenceDate date,
        Quantity decimal(19,4),Value decimal(19,4),Reference nvarchar(160),DetailJson nvarchar(max)) e
    CROSS APPLY(SELECT CASE WHEN UPPER(LTRIM(RTRIM(POSCode))) LIKE 'PROD%' THEN SUBSTRING(UPPER(LTRIM(RTRIM(POSCode))),5,80) ELSE UPPER(LTRIM(RTRIM(POSCode))) END AS Code1) n
    WHERE SourceSystem=@SourceSystem AND CASE WHEN RIGHT(Code1,2)='.0' THEN LEFT(Code1,LEN(Code1)-2) ELSE Code1 END=@POSCode ORDER BY EvidenceDate,EvidenceType,Reference;
    SELECT h.*,u.UserName AS ActorName FROM agora.StockWeeklyCaseChange h LEFT JOIN agora.[User] u ON u.Id=h.CreatedBy
    WHERE h.BranchId=@BranchId AND h.CaseId=@CaseId ORDER BY h.Id DESC;
    SELECT u.Id,u.UserName FROM agora.[User] u WHERE u.IsActive=1 AND u.IsLocked=0 AND u.DeletedAt IS NULL
      AND (u.HomeBranchId IS NULL OR u.HomeBranchId=@BranchId)
      AND (NOT EXISTS(SELECT 1 FROM agora.UserBranch WHERE UserId=u.Id) OR EXISTS(SELECT 1 FROM agora.UserBranch WHERE UserId=u.Id AND BranchId=@BranchId))
    ORDER BY u.UserName;
END
