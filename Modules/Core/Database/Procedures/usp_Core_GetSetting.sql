/* One setting for its view and edit screens (T026). Read-only.

   Result sets, in order:
     1. the definition and the group value — one row, or none when there is
        no such key. A key PumpIT kept per branch only (pumpit.BRN_Config.*)
        may have no group row; then the definition is its first site row's
        and HasGroupRow is 0.
     2. the sites with their own value, by branch — only the sites the caller
        is granted (agora.UserBranch; no grant means every branch), each with
        a concurrency token (RowVer as 0x… text) a change must hand back
     3. its change history from agora.MasterChange, newest first, over every
        row of the key including removed ones — again only granted sites, and
        the group's
     4. the branches that may be given their own value: active, not an
        administrative entity, granted, and without one already

   Read by Modules/Core/Services/SettingService. Deployed by v1__01pj. */
CREATE OR ALTER PROCEDURE agora.usp_Core_GetSetting
    @SettingKey NVARCHAR(120),
    @UserId INT = NULL,
    @GroupBranchId INT = 2
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @Granted TABLE (BranchId INT PRIMARY KEY);
    INSERT INTO @Granted SELECT DISTINCT BranchId FROM agora.UserBranch WHERE UserId = @UserId;
    DECLARE @All BIT = CASE WHEN EXISTS (SELECT 1 FROM @Granted) THEN 0 ELSE 1 END;

    SELECT TOP (1)
           s.SettingKey, s.Type, s.Description, s.Choices, s.MinValue, s.MaxValue, s.IsNullable, s.AllowsBranch,
           s.UsedBy, s.Source, s.LegacySource,
           CONVERT(BIT, CASE WHEN s.BranchId = @GroupBranchId THEN 1 ELSE 0 END) AS HasGroupRow,
           @GroupBranchId AS GroupBranchId,
           CASE WHEN s.BranchId = @GroupBranchId THEN s.Value END AS GroupValue,
           CASE WHEN s.BranchId = @GroupBranchId THEN CONVERT(VARCHAR(18), CONVERT(BINARY(8), s.RowVer), 1) END AS Version,
           s.UpdatedAt, u.UserName AS UpdatedByName,
           CONVERT(BIT, CASE WHEN s.SettingKey LIKE N'pumpit.%' THEN 1 ELSE 0 END) AS IsReadOnly
    FROM agora.Setting s
    LEFT JOIN agora.[User] u ON u.Id = s.UpdatedBy
    WHERE s.SettingKey = @SettingKey AND s.DeletedAt IS NULL
    ORDER BY CASE WHEN s.BranchId = @GroupBranchId THEN 0 ELSE 1 END, s.BranchId;

    SELECT s.BranchId, b.Name AS BranchName, s.Value, s.Source, s.UpdatedAt, s.CreatedAt,
           ISNULL(u.UserName, c.UserName) AS UpdatedByName,
           CONVERT(VARCHAR(18), CONVERT(BINARY(8), s.RowVer), 1) AS Version
    FROM agora.Setting s
    LEFT JOIN agora.Branch b ON b.BranchId = s.BranchId
    LEFT JOIN agora.[User] u ON u.Id = s.UpdatedBy
    LEFT JOIN agora.[User] c ON c.Id = s.CreatedBy
    WHERE s.SettingKey = @SettingKey AND s.DeletedAt IS NULL AND s.BranchId <> @GroupBranchId
      AND (@All = 1 OR s.BranchId IN (SELECT BranchId FROM @Granted))
    ORDER BY b.Name, s.BranchId;

    SELECT TOP (500) c.Id, c.BranchId, b.Name AS BranchName, c.Field, c.PriorValue, c.NewValue, c.Reason, c.Source,
           c.CreatedAt, c.CreatedBy, u.UserName AS ChangedByName
    FROM agora.MasterChange c
    JOIN agora.Setting s ON s.Id = c.EntityId AND s.BranchId = c.BranchId
    LEFT JOIN agora.Branch b ON b.BranchId = c.BranchId
    LEFT JOIN agora.[User] u ON u.Id = c.CreatedBy
    WHERE c.Entity = N'Setting' AND s.SettingKey = @SettingKey
      AND (@All = 1 OR c.BranchId = @GroupBranchId OR c.BranchId IN (SELECT BranchId FROM @Granted))
    ORDER BY c.Id DESC;

    SELECT b.BranchId, b.Name
    FROM agora.Branch b
    WHERE b.DeletedAt IS NULL AND b.IsActive = 1 AND b.IsAdminEntity = 0 AND b.BranchId <> @GroupBranchId
      AND (@All = 1 OR b.BranchId IN (SELECT BranchId FROM @Granted))
      AND NOT EXISTS (SELECT 1 FROM agora.Setting s WHERE s.SettingKey = @SettingKey AND s.BranchId = b.BranchId AND s.DeletedAt IS NULL)
    ORDER BY b.Name;
END
