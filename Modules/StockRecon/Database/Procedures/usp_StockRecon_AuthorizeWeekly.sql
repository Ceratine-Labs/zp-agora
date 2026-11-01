/* Canonical permissions are resolved by PermissionService, never form input.
   Independent SQL checks protect every snapshot, case and export by site. */
CREATE OR ALTER PROCEDURE agora.usp_StockRecon_AuthorizeWeekly
    @BranchId INT = NULL,
    @UserId INT = NULL,
    @Authorized BIT = 0,
    @Workspace NVARCHAR(10) = N'branch',
    @BranchPin INT = NULL
AS
BEGIN
    SET NOCOUNT ON;
    IF ISNULL(@Authorized, 0) <> 1 OR ISNULL(@Workspace, '') NOT IN ('ho', 'branch')
        THROW 51000, 'AGORA:WEEKLY_FORBIDDEN:Weekly investigations require the matching permission.', 1;
    IF NOT EXISTS (SELECT 1 FROM agora.[User] WHERE Id = @UserId AND IsActive = 1 AND IsLocked = 0 AND DeletedAt IS NULL)
        THROW 51000, 'AGORA:WEEKLY_FORBIDDEN:An active account is required.', 1;
    IF @Workspace = 'ho' AND EXISTS (SELECT 1 FROM agora.[User] WHERE Id = @UserId AND (HomeBranchId IS NOT NULL OR Workspace = 'branch'))
        THROW 51000, 'AGORA:WEEKLY_FORBIDDEN:That workspace is outside your access.', 1;
    IF @Workspace = 'branch' AND (ISNULL(@BranchPin, 0) <= 0 OR (@BranchId IS NOT NULL AND @BranchId <> @BranchPin)
        OR EXISTS (SELECT 1 FROM agora.[User] WHERE Id = @UserId AND HomeBranchId IS NOT NULL AND HomeBranchId <> @BranchPin))
        THROW 51000, 'AGORA:WEEKLY_FORBIDDEN:Select your permitted site.', 1;
    IF @BranchId IS NOT NULL AND (@BranchId <= 0 OR (EXISTS (SELECT 1 FROM agora.UserBranch WHERE UserId = @UserId)
        AND NOT EXISTS (SELECT 1 FROM agora.UserBranch WHERE UserId = @UserId AND BranchId = @BranchId)))
        THROW 51000, 'AGORA:WEEKLY_FORBIDDEN:That site is outside your access.', 1;
END
