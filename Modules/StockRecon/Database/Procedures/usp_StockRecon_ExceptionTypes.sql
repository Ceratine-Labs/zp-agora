/*
 * Run-wide exception counts for the type pills above the exceptions grid.
 * Reads only Agora's saved run lines; writes nothing. Counts are item/shift
 * rows, not chains. A row with both codes belongs to both type counts but
 * occurs only once in All types. Grid searches and filters do not change this
 * inventory, so another type remains discoverable while one is selected.
 */
CREATE OR ALTER PROCEDURE [agora].[usp_StockRecon_ExceptionTypes]
    @BranchId INT,
    @RunId INT,
    @RowGroup NVARCHAR(20) = 'all'
AS
BEGIN
    SET NOCOUNT ON;

    SELECT codes.Kind, codes.Code, COUNT_BIG(*) AS TotalRows
    FROM agora.StockReconRunLine rl
    INNER JOIN agora.vw_StockReconExceptionGroups rg ON rg.BranchId = rl.BranchId AND rg.Id = rl.Id
    CROSS APPLY (VALUES
        ('all', CAST(NULL AS nvarchar(20))),
        ('ExceptionCode', COALESCE(rl.ExceptionCode, CASE WHEN rl.CountDriftReason IS NOT NULL THEN 'C1' END)),
        ('AuditCode', rl.AuditCode)
    ) codes(Kind, Code)
    WHERE rl.BranchId = @BranchId AND rl.RunId = @RunId
      AND (@RowGroup NOT IN ('exceptions', 'review') OR rg.RowGroup = @RowGroup)
      AND (codes.Kind = 'all' OR codes.Code IS NOT NULL)
    GROUP BY codes.Kind, codes.Code
    UNION ALL
    SELECT 'group', rg.RowGroup, COUNT_BIG(*)
    FROM agora.vw_StockReconExceptionGroups rg
    WHERE rg.BranchId = @BranchId AND rg.RunId = @RunId
    GROUP BY rg.RowGroup;
END
