CREATE OR ALTER PROCEDURE agora.usp_StockRecon_WeeklyRecipeEvidence
    @BranchId int,
    @FromDate date,
    @ToDate date
AS
BEGIN
    SET NOCOUNT ON;

    IF @BranchId IS NULL OR @BranchId <= 0 OR @FromDate IS NULL OR @ToDate IS NULL
       OR @ToDate < @FromDate OR @ToDate = '99991231'
        THROW 51000, 'AGORA:INVALID_SCOPE:Choose a branch and a valid date range for recipe evidence.', 1;

    IF OBJECT_ID(N'agora.vw_WeeklyRecipeWinbranch', N'V') IS NULL
       OR OBJECT_ID(N'agora.vw_WeeklyRecipePilotPrep', N'V') IS NULL
       OR OBJECT_ID(N'agora.vw_WeeklyRecipePilotMaster', N'V') IS NULL
       OR OBJECT_ID(N'agora.vw_WeeklyRecipePilotCategory', N'V') IS NULL
       OR OBJECT_ID(N'agora.vw_WeeklyRecipePilotWaste', N'V') IS NULL
        THROW 51000, 'AGORA:WEEKLY_SOURCE_UNAVAILABLE:The weekly recipe source views are not installed.', 1;

    /* Report 1237: the raw-material explosion of sales, TYPE u / DRC D.
       Keep signed source quantities, original units and source keys. Its COST
       column has no proven unit/extended basis, so Value deliberately stays
       NULL. Do not multiply it by QTY or infer recipe yield / StockMaster.Ratio.

       Report 1317: Quantity is THEORETICAL consumption, the recipe quantity
       the master book compares with recon POS. Actual consumption and variance
       remain separate facts in DetailJson. No Aura recipe source is supplied by
       the pack; absence is absence, not a manufactured zero-consumption row.

       Both readers use the exact requested inclusive dates. The weekly caller
       can request an earlier FromDate for prior-week comparisons. */
    SELECT CONVERT(nvarchar(20), N'WINBRANCH') AS SourceSystem,
           CONVERT(nvarchar(80), w.POSCode) AS POSCode,
           CONVERT(nvarchar(30), N'RawConsumption') AS EvidenceType,
           CONVERT(date, w.SourceDate) AS EvidenceDate,
           CONVERT(decimal(19,4), w.SourceQuantity) AS Quantity,
           CONVERT(decimal(19,4), NULL) AS Value,
           CONVERT(nvarchar(160), CONCAT(N'WINBRANCH:', RTRIM(w.SourceReference), N':', RTRIM(w.EodCounter))) AS Reference,
           (SELECT 1237 AS SourceReportId,
                   N'WINBRANCH_INVHISTF' AS SourceTable,
                   w.SourceQuantity AS TheoreticalConsumptionQuantity,
                   CONVERT(decimal(19,4), NULL) AS ActualConsumptionQuantity,
                   NULLIF(LTRIM(RTRIM(w.SourceUnit)), '') AS Unit,
                   N'WINBRANCH_INVHISTF.QTY; original source units, no conversion' AS QuantityBasis,
                   N'Unknown COST basis; raw cost retained, normalized Value unavailable' AS ValueBasis,
                   w.SourceCost AS SourceCost, w.SourcePrice AS SourceUnitPrice,
                   w.SourceSalesExcl AS SourceSalesExcl, w.SourceSalesIncl AS SourceSalesIncl,
                   RTRIM(w.SourceDescription) AS Description, w.ExportRegisterId,
                   JSON_QUERY((SELECT w.BranchId, w.Direction, w.SourceDate, w.TransactionType,
                                      w.SourceReference, w.SourceCode, w.SourceDescription, w.SourceVAT,
                                      w.SourceVATCode, w.SourcePrice, w.EodCounter, w.SourceTime,
                                      w.RepCode, w.PromoCode, w.Tender, w.AltCode
                               FOR JSON PATH, INCLUDE_NULL_VALUES, WITHOUT_ARRAY_WRAPPER)) AS SourceKey
            FOR JSON PATH, INCLUDE_NULL_VALUES, WITHOUT_ARRAY_WRAPPER) AS DetailJson
    FROM agora.vw_WeeklyRecipeWinbranch w
    WHERE w.BranchId = @BranchId
      AND w.BranchId NOT IN (11, 12) -- report 1237's published scope
      AND w.SourceDate >= @FromDate AND w.SourceDate < DATEADD(day, 1, @ToDate)
      AND w.Direction = 'D' AND w.TransactionType = 'u'

    UNION ALL

    SELECT CONVERT(nvarchar(20), N'PILOT'),
           CONVERT(nvarchar(80), p.POSCode),
           CONVERT(nvarchar(30), N'RecipeComparison'),
           CONVERT(date, p.SourceDate),
           CONVERT(decimal(19,4), p.TheoreticalQuantity),
           CONVERT(decimal(19,4), ROUND(p.TheoreticalQuantity * p.UnitCost, 2)),
           CONVERT(nvarchar(160), CONCAT(N'PILOT:', p.SourceId)),
           (SELECT 1317 AS SourceReportId,
                   N'PILOT_PrepsheetHistory' AS SourceTable,
                   p.SourceId, p.StoreId, p.Department, p.SourceDate, p.IsHourlyPrep,
                   COALESCE(NULLIF(RTRIM(m.Description), ''), RTRIM(p.SourceDescription)) AS Description,
                   NULLIF(LTRIM(RTRIM(m.Unit)), '') AS Unit,
                   m.MasterId AS UnitMasterId, m.Updated AS UnitMasterUpdated,
                   N'Latest PILOT_MasterFile at requested ToDate; missing unit remains unknown' AS UnitBasis,
                   COALESCE(NULLIF(RTRIM(c.CategoryName), ''), NULLIF(RTRIM(m.Category), '')) AS Category,
                   c.CostCentre,
                   p.OpeningQuantity, p.ReceivedMadeQuantity, p.TransferQuantity,
                   p.IssuedQuantity, p.ClosingQuantity,
                   a.ActualQuantity AS ActualConsumptionQuantity,
                   p.TheoreticalQuantity AS TheoreticalConsumptionQuantity,
                   a.ActualQuantity - p.TheoreticalQuantity AS ConsumptionVarianceQuantity,
                   p.RecordedWasteQuantity, waste.LossValue AS RecordedWasteValue,
                   waste.SourceRows AS WasteSourceRows,
                   p.UnitCost,
                   ROUND(a.ActualQuantity * p.UnitCost, 2) AS ActualConsumptionValue,
                   ROUND(p.TheoreticalQuantity * p.UnitCost, 2) AS TheoreticalConsumptionValue,
                   ROUND((a.ActualQuantity - p.TheoreticalQuantity) * p.UnitCost, 2) AS ConsumptionVarianceValue,
                   CASE WHEN p.TheoreticalQuantity = 0 THEN NULL
                        ELSE ROUND(100.0 * (a.ActualQuantity - p.TheoreticalQuantity) / p.TheoreticalQuantity, 2)
                   END AS VariancePercentOfTheoretical,
                   N'PILOT SALES theoretical consumption; no Ratio or inferred yield conversion' AS QuantityBasis,
                   N'Report 1317: ROUND(quantity * PILOT_PrepsheetHistory.COST, 2); source cost basis' AS ValueBasis,
                   p.CountCapturedAt
            FOR JSON PATH, INCLUDE_NULL_VALUES, WITHOUT_ARRAY_WRAPPER)
    FROM agora.vw_WeeklyRecipePilotPrep p
    OUTER APPLY (
        SELECT TOP (1) m.MasterId, m.Updated, m.Description, m.Unit, m.Category
        FROM agora.vw_WeeklyRecipePilotMaster m
        WHERE m.BranchId = p.BranchId AND m.POSCode = p.POSCode
          AND m.Updated < DATEADD(day, 1, @ToDate)
        ORDER BY m.Updated DESC, m.MasterId DESC
    ) m
    OUTER APPLY (
        SELECT MIN(c.CategoryName) AS CategoryName, MIN(c.CostCentre) AS CostCentre
        FROM agora.vw_WeeklyRecipePilotCategory c
        WHERE c.BranchId = p.BranchId AND c.Category = m.Category
    ) c
    OUTER APPLY (
        SELECT SUM(w.LossValue) AS LossValue, COUNT(*) AS SourceRows
        FROM agora.vw_WeeklyRecipePilotWaste w
        WHERE w.BranchId = p.BranchId AND w.POSCode = p.POSCode
          AND w.SourceDate >= CONVERT(date, p.SourceDate)
          AND w.SourceDate < DATEADD(day, 1, CONVERT(date, p.SourceDate))
    ) waste
    CROSS APPLY (
        SELECT p.OpeningQuantity + p.ReceivedMadeQuantity + p.TransferQuantity
               - p.IssuedQuantity - p.ClosingQuantity AS ActualQuantity
    ) a
    WHERE p.BranchId = @BranchId
      AND p.SourceDate >= @FromDate AND p.SourceDate < DATEADD(day, 1, @ToDate)
      AND (p.TheoreticalQuantity <> 0 OR p.ReceivedMadeQuantity <> 0
           OR p.IssuedQuantity <> 0 OR p.TransferQuantity <> 0
           OR p.RecordedWasteQuantity <> 0 OR p.ClosingQuantity <> p.OpeningQuantity)
    ORDER BY EvidenceDate, SourceSystem, POSCode, Reference;
END;
