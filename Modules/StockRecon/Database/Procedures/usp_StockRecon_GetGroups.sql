CREATE OR ALTER PROCEDURE [agora].[usp_StockRecon_GetGroups]
    @UserId int,
    @AllowedBranchIds nvarchar(max)
AS
BEGIN
    SET NOCOUNT ON;
    SELECT TOP (10) g.GroupRef, g.FromDate, g.ToDate, g.Note, g.CreatedAt,
           (SELECT COUNT(*) FROM OPENJSON(g.BranchIdsJson)) AS SiteCount
    FROM agora.StockReconRunGroup g
    WHERE g.CreatedBy = @UserId
      AND (@AllowedBranchIds IS NULL OR NOT EXISTS (
          SELECT 1 FROM OPENJSON(g.BranchIdsJson) s
          WHERE NOT EXISTS (SELECT 1 FROM STRING_SPLIT(@AllowedBranchIds, ',') a
                            WHERE TRY_CONVERT(int, a.value) = TRY_CONVERT(int, s.value))
      ))
    ORDER BY g.CreatedAt DESC, g.Id DESC;
END;
