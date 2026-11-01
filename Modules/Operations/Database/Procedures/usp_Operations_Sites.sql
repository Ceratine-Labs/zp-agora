/*
   The wall's site selector. A branch workspace passes @BranchId as well as
   its grants. Brand/Class/Region are identifiers, not inferred divisions.
   READ-ONLY.
*/
CREATE OR ALTER PROCEDURE [agora].[usp_Operations_Sites]
    @AllowedBranchIds NVARCHAR(MAX) = NULL,
    @BranchId INT = NULL
AS
BEGIN
    SET NOCOUNT ON;

    SELECT b.BranchId, b.Name, b.BrandId, b.RegionId, b.ClassId
    FROM agora.Branch b
    WHERE b.IsActive = 1
      AND b.IsTrading = 1
      AND b.DeletedAt IS NULL
      AND (@BranchId IS NULL OR b.BranchId = @BranchId)
      AND (NULLIF(LTRIM(RTRIM(@AllowedBranchIds)), '') IS NULL OR b.BranchId IN (
          SELECT TRY_CONVERT(int, value)
          FROM STRING_SPLIT(@AllowedBranchIds, ',')
          WHERE LTRIM(RTRIM(value)) <> ''
      ))
    ORDER BY b.Name;
END
