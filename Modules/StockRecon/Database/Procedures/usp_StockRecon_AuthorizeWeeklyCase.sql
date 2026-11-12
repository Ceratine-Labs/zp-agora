/* Group patterns require current access to every immutable contributing site.
   Group storage does not imply a grant to the unrelated group entity. */
CREATE OR ALTER PROCEDURE agora.usp_StockRecon_AuthorizeWeeklyCase
    @BranchId INT,@CaseId BIGINT,@UserId INT,@Authorized BIT=0,@Workspace NVARCHAR(10)=N'branch',@BranchPin INT=NULL
AS
BEGIN
    SET NOCOUNT ON;
    IF EXISTS(SELECT 1 FROM agora.StockWeeklyCase WHERE BranchId=@BranchId AND Id=@CaseId AND RegisterName='Cross-Site Patterns')
    BEGIN
        EXEC agora.usp_StockRecon_AuthorizeWeekly NULL,@UserId,@Authorized,@Workspace,@BranchPin;
        IF @Workspace<>'ho' OR EXISTS(
            SELECT 1 FROM agora.StockWeeklyCase c CROSS APPLY OPENJSON(c.EvidenceJson,'$.RelatedCases') WITH(BranchId int) r
            WHERE c.BranchId=@BranchId AND c.Id=@CaseId AND EXISTS(SELECT 1 FROM agora.UserBranch WHERE UserId=@UserId)
              AND NOT EXISTS(SELECT 1 FROM agora.UserBranch WHERE UserId=@UserId AND BranchId=r.BranchId))
            THROW 51000,'AGORA:WEEKLY_FORBIDDEN:This pattern requires head-office access to every contributing site.',1;
    END
    ELSE EXEC agora.usp_StockRecon_AuthorizeWeekly @BranchId,@UserId,@Authorized,@Workspace,@BranchPin;
END
