/* Setup → The business → Regions, brands and formats (T021). Read-only.

   Every region, brand and format in one set (Kind says which), with how many
   branches carry it and the concurrency token its save must hand back.
   The three are small and arrive whole, so the screen sorts and filters them
   in the browser (feature-rules §B: the whole answer is in the response).

   @Kind narrows to one of them; NULL is all three.
   Read by Modules/Masters/Services/BranchMasterService. Deployed by v1__02p. */
CREATE OR ALTER PROCEDURE agora.usp_Masters_GetBranchGroupings
    @Kind NVARCHAR(10) = NULL,
    @UserId INT = NULL
AS
BEGIN
    SET NOCOUNT ON;

    SELECT g.Kind, g.Id, g.Name, g.LegacyId, g.IsActive, g.SortOrder, g.Version, g.UpdatedAt,
           (SELECT COUNT(*) FROM agora.Branch b WHERE b.DeletedAt IS NULL AND
                ((g.Kind = N'Region' AND b.RegionId = g.Id) OR (g.Kind = N'Brand' AND b.BrandId = g.Id)
                 OR (g.Kind = N'Format' AND b.FormatId = g.Id))) AS BranchCount,
           (SELECT STRING_AGG(CONVERT(nvarchar(max), b.Name), N', ') WITHIN GROUP (ORDER BY b.Name)
            FROM agora.Branch b WHERE b.DeletedAt IS NULL AND
                ((g.Kind = N'Region' AND b.RegionId = g.Id) OR (g.Kind = N'Brand' AND b.BrandId = g.Id)
                 OR (g.Kind = N'Format' AND b.FormatId = g.Id))) AS Branches
    FROM (
        SELECT N'Region' AS Kind, Id, Name, LegacyId, IsActive, SortOrder, UpdatedAt,
               CONVERT(VARCHAR(18), CONVERT(BINARY(8), RowVer), 1) AS Version
        FROM agora.Region WHERE DeletedAt IS NULL
        UNION ALL
        SELECT N'Brand', Id, Name, LegacyId, IsActive, SortOrder, UpdatedAt,
               CONVERT(VARCHAR(18), CONVERT(BINARY(8), RowVer), 1)
        FROM agora.Brand WHERE DeletedAt IS NULL
        UNION ALL
        SELECT N'Format', Id, Name, LegacyId, IsActive, SortOrder, UpdatedAt,
               CONVERT(VARCHAR(18), CONVERT(BINARY(8), RowVer), 1)
        FROM agora.[Format] WHERE DeletedAt IS NULL
    ) g
    WHERE @Kind IS NULL OR g.Kind = @Kind
    ORDER BY CASE g.Kind WHEN N'Region' THEN 1 WHEN N'Brand' THEN 2 ELSE 3 END, g.SortOrder, g.Name;
END
