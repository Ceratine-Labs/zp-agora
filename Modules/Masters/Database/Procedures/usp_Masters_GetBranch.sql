/* One branch for its view and edit screens (T021). Read-only.

   Result sets, in order:
     1. the branch, with its groupings' names and a concurrency token
        (RowVer as 0x… text) the save must hand back
     2. its profit centres
     3. its change history from agora.MasterChange, newest first
     4. the groupings a branch may be given (Kind, Id, Name, IsActive) — the
        active ones, plus whichever this branch already carries
     5. every profit centre (agora.vw_ProfitCentre)

   An empty first set means no such branch, or one outside the caller's
   granted sites. Read by Modules/Masters/Services/BranchMasterService.
   Deployed by v1__02p. */
CREATE OR ALTER PROCEDURE agora.usp_Masters_GetBranch
    @BranchId INT,
    @UserId INT = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @Visible BIT = CASE
        WHEN NOT EXISTS (SELECT 1 FROM agora.UserBranch WHERE UserId = @UserId) THEN 1
        WHEN EXISTS (SELECT 1 FROM agora.UserBranch WHERE UserId = @UserId AND BranchId = @BranchId) THEN 1
        ELSE 0 END;

    SELECT b.BranchId, b.Name, b.LegacyName,
           b.RegionId, r.Name AS RegionName,
           b.BrandId, br.Name AS BrandName,
           b.FormatId, f.Name AS FormatName,
           b.ClassId, b.Status, b.POSType, b.IsAdminEntity, b.IsActive, b.IsTrading,
           b.MigratedAt, b.UpdatedAt, u.UserName AS UpdatedByName,
           CONVERT(VARCHAR(18), CONVERT(BINARY(8), b.RowVer), 1) AS Version
    FROM agora.Branch b
    LEFT JOIN agora.Region r   ON r.Id = b.RegionId
    LEFT JOIN agora.Brand br   ON br.Id = b.BrandId
    LEFT JOIN agora.[Format] f ON f.Id = b.FormatId
    LEFT JOIN agora.[User] u   ON u.Id = b.UpdatedBy
    WHERE b.BranchId = @BranchId AND b.DeletedAt IS NULL AND @Visible = 1;

    SELECT x.ProfitCentreId, pc.Name, x.Source
    FROM agora.BranchProfitCentre x
    LEFT JOIN agora.vw_ProfitCentre pc ON pc.ProfitCentreId = x.ProfitCentreId
    WHERE x.BranchId = @BranchId AND @Visible = 1
    ORDER BY pc.Name;

    SELECT TOP (500) c.Id, c.Field, c.PriorValue, c.NewValue, c.Reason, c.Source,
           c.CreatedAt, c.CreatedBy, u.UserName AS ChangedByName
    FROM agora.MasterChange c
    LEFT JOIN agora.[User] u ON u.Id = c.CreatedBy
    WHERE c.BranchId = @BranchId AND c.Entity = N'Branch' AND c.EntityId = @BranchId AND @Visible = 1
    ORDER BY c.Id DESC;

    SELECT N'Region' AS Kind, g.Id, g.Name, g.IsActive, g.SortOrder FROM agora.Region g
    WHERE g.DeletedAt IS NULL AND (g.IsActive = 1 OR g.Id = (SELECT RegionId FROM agora.Branch WHERE BranchId = @BranchId))
    UNION ALL
    SELECT N'Brand', g.Id, g.Name, g.IsActive, g.SortOrder FROM agora.Brand g
    WHERE g.DeletedAt IS NULL AND (g.IsActive = 1 OR g.Id = (SELECT BrandId FROM agora.Branch WHERE BranchId = @BranchId))
    UNION ALL
    SELECT N'Format', g.Id, g.Name, g.IsActive, g.SortOrder FROM agora.[Format] g
    WHERE g.DeletedAt IS NULL AND (g.IsActive = 1 OR g.Id = (SELECT FormatId FROM agora.Branch WHERE BranchId = @BranchId))
    ORDER BY Kind, SortOrder, Name;

    SELECT pc.ProfitCentreId, pc.Name, pc.IsActive
    FROM agora.vw_ProfitCentre pc
    ORDER BY pc.Name;
END
