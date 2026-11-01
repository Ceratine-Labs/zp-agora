/* Read-only picker for explicitly mapped cumulative meters. NULL/empty scope
   means unrestricted; any nonempty invalid scope fails closed. */
CREATE OR ALTER PROCEDURE [agora].[usp_StockRecon_WaterMeterSites]
    @AllowedBranchIds NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SELECT b.BranchId, b.Name
    FROM agora.Branch b
    WHERE EXISTS (SELECT 1 FROM agora.StockReconMeter m WHERE m.BranchId = b.BranchId AND m.IsActive = 1)
      AND (NULLIF(LTRIM(RTRIM(@AllowedBranchIds)), '') IS NULL
           OR b.BranchId IN (SELECT TRY_CONVERT(int, NULLIF(LTRIM(RTRIM(value)), ''))
                            FROM STRING_SPLIT(@AllowedBranchIds, ',')))
    ORDER BY b.Name;
END
