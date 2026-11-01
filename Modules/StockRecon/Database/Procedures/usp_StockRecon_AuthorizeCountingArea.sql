/* Counting-area access shared by the listing, detail and writer.
   Authorized is resolved by PermissionService in PHP, NEVER from request input:
   managed roles and direct wildcards retain their single authoritative resolver.
   SQL independently requires an active HO account and checks its stored sites.
   Read-only. Deployed by v1__14px. */
CREATE OR ALTER PROCEDURE agora.usp_StockRecon_AuthorizeCountingArea
    @BranchId INT = NULL,
    @UserId INT = NULL,
    @Authorized BIT = 0,
    @Workspace NVARCHAR(10) = N'branch'
AS
BEGIN
    SET NOCOUNT ON;
    IF ISNULL(@Authorized, 0) <> 1 OR ISNULL(@Workspace, N'') <> N'ho'
        THROW 51000, 'AGORA:AREA_FORBIDDEN:Counting areas require head-office access and the matching permission.', 1;
    IF NOT EXISTS (SELECT 1 FROM agora.[User] WHERE Id = @UserId AND IsActive = 1 AND IsLocked = 0
                   AND DeletedAt IS NULL AND ISNULL(Workspace, N'ho') = N'ho' AND HomeBranchId IS NULL)
        THROW 51000, 'AGORA:AREA_FORBIDDEN:Counting areas require an active head-office account.', 1;
    IF @BranchId IS NOT NULL AND (@BranchId <= 0 OR
        (EXISTS (SELECT 1 FROM agora.UserBranch WHERE UserId = @UserId)
         AND NOT EXISTS (SELECT 1 FROM agora.UserBranch WHERE UserId = @UserId AND BranchId = @BranchId)))
        THROW 51000, 'AGORA:AREA_FORBIDDEN:That site is outside your access.', 1;
END
