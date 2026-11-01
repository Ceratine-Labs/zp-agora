/*
   Area selector for Original vs amended variance. Read-only.
   Include historical/inactive areas: this report can read any past period.
   Area descriptions come from the selected AND granted sites, matching the
   grid's exact AreaDescription filter, without reading count lines.
*/
CREATE OR ALTER PROCEDURE [agora].[usp_StockRecon_VarianceAreas]
    @BranchIds NVARCHAR(MAX) = NULL,
    @AllowedBranchIds NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    SELECT DISTINCT LTRIM(RTRIM(a.AreaDescription)) AS AreaDescription
    FROM agora.vw_StockArea a
    WHERE NULLIF(LTRIM(RTRIM(a.AreaDescription)), '') IS NOT NULL
      AND (NULLIF(@BranchIds, '') IS NULL OR a.BranchId IN (
          SELECT TRY_CONVERT(int, value) FROM STRING_SPLIT(@BranchIds, ',')
          WHERE LTRIM(RTRIM(value)) <> ''))
      AND (NULLIF(@AllowedBranchIds, '') IS NULL OR a.BranchId IN (
          SELECT TRY_CONVERT(int, value) FROM STRING_SPLIT(@AllowedBranchIds, ',')
          WHERE LTRIM(RTRIM(value)) <> ''))
    ORDER BY AreaDescription;
END
