/* Only administrative findings in Agora; source quantities never change. */
CREATE OR ALTER PROCEDURE agora.usp_StockRecon_SaveWeeklyCase
    @BranchId INT,@CaseId BIGINT,@ExpectedVersion VARCHAR(18),@Status NVARCHAR(20),@OwnerId INT=NULL,@DueDate DATE=NULL,
    @Finding NVARCHAR(2000)=NULL,@EvidenceReference NVARCHAR(1000)=NULL,@Reason NVARCHAR(400)=NULL,
    @UserId INT=NULL,@Authorized BIT=0,@Workspace NVARCHAR(10)=N'branch',@BranchPin INT=NULL,
    @Operation NVARCHAR(10)=N'save',@ClosureOutcome NVARCHAR(60)=NULL,@RecoveryAmount DECIMAL(19,2)=NULL,
    @RecoveryReference NVARCHAR(1000)=NULL,@CorrectionStatus NVARCHAR(1)=NULL,@CorrectionReference NVARCHAR(1000)=NULL,
    @ActionTaken NVARCHAR(2000)=NULL,@ActionDate DATE=NULL,@SignoffNote NVARCHAR(1000)=NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    EXEC agora.usp_StockRecon_AuthorizeWeekly @BranchId,@UserId,@Authorized,@Workspace,@BranchPin;
    IF @Status NOT IN ('Open','Investigating','Waiting','Escalated','Closed') OR @Status IS NULL OR LEN(LTRIM(RTRIM(ISNULL(@Reason,''))))<10
        THROW 51000, 'AGORA:WEEKLY_ACTION:Choose a valid status and give a change reason of at least ten characters.', 1;
    IF @Operation IS NULL OR @Operation NOT IN ('save','approve','reopen')
        OR (@Status='Closed' AND @Operation<>'approve') OR (@Operation='approve' AND (@Status<>'Closed' OR @Workspace<>'ho'))
        THROW 51000, 'AGORA:WEEKLY_APPROVAL:Closure requires the head-office approval action and permission.', 1;
    IF @Operation='reopen' AND @Status<>'Investigating'
        THROW 51000, 'AGORA:WEEKLY_ACTION:Reopening returns a signed case to investigation.', 1;
    IF @Status<>'Open' AND @Operation<>'reopen' AND (@OwnerId IS NULL OR @DueDate IS NULL)
        THROW 51000, 'AGORA:WEEKLY_OWNER:An investigation needs an owner and due date.', 1;
    IF @Status='Closed' AND (LEN(LTRIM(RTRIM(ISNULL(@Finding,''))))<20 OR LEN(LTRIM(RTRIM(ISNULL(@EvidenceReference,''))))<5)
        THROW 51000, 'AGORA:WEEKLY_FINDING:Closing requires a written finding and a specific evidence reference.', 1;
    IF @Status='Closed' AND (@ClosureOutcome IS NULL OR @ClosureOutcome NOT IN
        ('Real loss - staff charged','Real loss - key holder accountable','Paperwork corrected','Invoice / GRV corrected','Recount corrected','System / master data fixed','No action')
        OR @RecoveryAmount IS NULL OR @RecoveryAmount<0
        OR (@RecoveryAmount>0 AND LEN(LTRIM(RTRIM(ISNULL(@RecoveryReference,''))))<5)
        OR @CorrectionStatus IS NULL OR @CorrectionStatus NOT IN ('Y','N')
        OR LEN(LTRIM(RTRIM(ISNULL(@CorrectionReference,''))))<5
        OR LEN(LTRIM(RTRIM(ISNULL(@ActionTaken,''))))<20 OR @ActionDate IS NULL
        OR @ActionDate>CONVERT(date,DATEADD(hour,2,SYSUTCDATETIME()))
        OR LEN(LTRIM(RTRIM(ISNULL(@SignoffNote,''))))<10)
        THROW 51000, 'AGORA:WEEKLY_CLOSURE:Record the outcome, supported recovery (zero if none), correction or reason not needed, dated action and head-office sign-off.', 1;
    IF @RecoveryAmount<0
        THROW 51000, 'AGORA:WEEKLY_CLOSURE:Recovery cannot be negative.', 1;
    IF @OwnerId IS NOT NULL AND NOT EXISTS(SELECT 1 FROM agora.[User] u WHERE u.Id=@OwnerId AND IsActive=1 AND IsLocked=0 AND DeletedAt IS NULL
        AND (HomeBranchId IS NULL OR HomeBranchId=@BranchId)
        AND (NOT EXISTS(SELECT 1 FROM agora.UserBranch WHERE UserId=u.Id) OR EXISTS(SELECT 1 FROM agora.UserBranch WHERE UserId=u.Id AND BranchId=@BranchId)))
        THROW 51000, 'AGORA:WEEKLY_OWNER:Choose an active owner who can access this site.', 1;
    BEGIN TRANSACTION;
    DECLARE @Prior nvarchar(max),@Version varchar(18),@Now datetime2=SYSUTCDATETIME();
    SELECT @Version=CONVERT(varchar(18),CONVERT(binary(8),RowVer),1),
        @Prior=(SELECT Status,OwnerId,DueDate,Finding,EvidenceReference,ClosureOutcome,RecoveryAmount,RecoveryReference,CorrectionStatus,CorrectionReference,ActionTaken,ActionDate,SignoffNote,SignedOffBy,SignedOffAt,ClosedDate FOR JSON PATH,INCLUDE_NULL_VALUES,WITHOUT_ARRAY_WRAPPER)
    FROM agora.StockWeeklyCase WITH(UPDLOCK,HOLDLOCK) WHERE BranchId=@BranchId AND Id=@CaseId;
    IF @Version IS NULL THROW 51000, 'AGORA:WEEKLY_MISSING:This investigation case was not found.', 1;
    IF @ExpectedVersion IS NULL OR @ExpectedVersion<>@Version
        THROW 51000, 'AGORA:WEEKLY_STALE:This case changed after you opened it. Reload before saving.', 1;
    IF @Operation='approve' AND EXISTS(SELECT 1 FROM agora.StockWeeklyCase c WHERE c.BranchId=@BranchId AND c.Id=@CaseId
        AND c.RegisterName IN ('Opening vs Closing','Balancing Breaches') AND @CorrectionStatus<>'Y')
        THROW 51000, 'AGORA:WEEKLY_CORRECTION:Closing this control requires the recorded PumpIT correction and its evidence.', 1;
    IF @Operation='approve' AND EXISTS(SELECT 1 FROM agora.StockWeeklyCase c
        CROSS APPLY OPENJSON(c.EvidenceJson,'$.RelatedCases') WITH(CaseId bigint) related
        JOIN agora.StockWeeklyCase child ON child.BranchId=c.BranchId AND child.SnapshotRef=c.SnapshotRef AND child.Id=related.CaseId
        WHERE c.BranchId=@BranchId AND c.Id=@CaseId AND c.RegisterName='Control Actions' AND (child.Status<>'Closed' OR child.SignedOffBy IS NULL))
        THROW 51000, 'AGORA:WEEKLY_RELATED:Complete and approve the related register cases before signing off this management action.', 1;
    IF JSON_VALUE(@Prior,'$.Status')='Closed' AND @Operation<>'reopen'
        THROW 51000, 'AGORA:WEEKLY_CLOSED:Reopen the signed case before changing its findings or actions.', 1;
    IF @Operation='reopen' AND JSON_VALUE(@Prior,'$.Status')<>'Closed'
        THROW 51000, 'AGORA:WEEKLY_ACTION:Only a signed closed case can be reopened.', 1;
    IF @Operation='reopen'
    BEGIN
        UPDATE agora.StockWeeklyCase SET Status='Investigating',SignedOffBy=NULL,SignedOffAt=NULL,ClosedDate=NULL,SignoffNote=NULL,
            UpdatedAt=@Now,UpdatedBy=@UserId WHERE BranchId=@BranchId AND Id=@CaseId;
        /* A changed supporting finding invalidates the management approval too.
           Preserve both approvals in their audit trails, in this transaction. */
        DECLARE @Parents TABLE(Id bigint,PriorJson nvarchar(max));
        INSERT @Parents SELECT c.Id,
            (SELECT c.Status,c.OwnerId,c.DueDate,c.Finding,c.EvidenceReference,c.ClosureOutcome,c.RecoveryAmount,c.RecoveryReference,
                c.CorrectionStatus,c.CorrectionReference,c.ActionTaken,c.ActionDate,c.SignoffNote,c.SignedOffBy,c.SignedOffAt,c.ClosedDate
                FOR JSON PATH,INCLUDE_NULL_VALUES,WITHOUT_ARRAY_WRAPPER)
        FROM agora.StockWeeklyCase c WITH(UPDLOCK,HOLDLOCK)
        WHERE c.BranchId=@BranchId AND c.RegisterName='Control Actions' AND c.Status='Closed'
            AND EXISTS(SELECT 1 FROM OPENJSON(c.EvidenceJson,'$.RelatedCases') WITH(CaseId bigint) r WHERE r.CaseId=@CaseId);
        UPDATE c SET Status='Investigating',SignedOffBy=NULL,SignedOffAt=NULL,ClosedDate=NULL,SignoffNote=NULL,UpdatedAt=@Now,UpdatedBy=@UserId
        FROM agora.StockWeeklyCase c JOIN @Parents p ON p.Id=c.Id WHERE c.BranchId=@BranchId;
        INSERT agora.StockWeeklyCaseChange(BranchId,CaseId,PriorJson,NewJson,Reason,CreatedAt,CreatedBy)
        SELECT @BranchId,c.Id,p.PriorJson,
            (SELECT c.Status,c.OwnerId,c.DueDate,c.Finding,c.EvidenceReference,c.ClosureOutcome,c.RecoveryAmount,c.RecoveryReference,
                c.CorrectionStatus,c.CorrectionReference,c.ActionTaken,c.ActionDate,c.SignoffNote,c.SignedOffBy,c.SignedOffAt,c.ClosedDate
                FOR JSON PATH,INCLUDE_NULL_VALUES,WITHOUT_ARRAY_WRAPPER),
            LEFT(CONCAT('Supporting case ',@CaseId,' reopened: ',@Reason),400),@Now,@UserId
        FROM agora.StockWeeklyCase c JOIN @Parents p ON p.Id=c.Id;

    END
    ELSE
    UPDATE agora.StockWeeklyCase SET Status=@Status,OwnerId=@OwnerId,DueDate=@DueDate,
        Finding=NULLIF(LTRIM(RTRIM(@Finding)),''),EvidenceReference=NULLIF(LTRIM(RTRIM(@EvidenceReference)),''),
        ClosureOutcome=@ClosureOutcome,RecoveryAmount=@RecoveryAmount,RecoveryReference=@RecoveryReference,
        CorrectionStatus=@CorrectionStatus,CorrectionReference=@CorrectionReference,ActionTaken=@ActionTaken,ActionDate=@ActionDate,
        SignoffNote=CASE WHEN @Operation='approve' THEN @SignoffNote END,
        SignedOffBy=CASE WHEN @Operation='approve' THEN @UserId END,SignedOffAt=CASE WHEN @Operation='approve' THEN @Now END,
        ClosedDate=CASE WHEN @Operation='approve' THEN CONVERT(date,DATEADD(hour,2,@Now)) END,UpdatedAt=@Now,UpdatedBy=@UserId
    WHERE BranchId=@BranchId AND Id=@CaseId;
    INSERT agora.StockWeeklyCaseChange(BranchId,CaseId,PriorJson,NewJson,Reason,CreatedAt,CreatedBy)
    SELECT @BranchId,@CaseId,@Prior,(SELECT Status,OwnerId,DueDate,Finding,EvidenceReference,ClosureOutcome,RecoveryAmount,RecoveryReference,CorrectionStatus,CorrectionReference,ActionTaken,ActionDate,SignoffNote,SignedOffBy,SignedOffAt,ClosedDate FOR JSON PATH,INCLUDE_NULL_VALUES,WITHOUT_ARRAY_WRAPPER),@Reason,@Now,@UserId
    FROM agora.StockWeeklyCase WHERE BranchId=@BranchId AND Id=@CaseId;
    COMMIT TRANSACTION;
    SELECT CAST(1 AS bit) AS Ok,'SAVED' AS Code,'Investigation action saved with its history.' AS Message,@CaseId AS Id;
END
