/*
 * Run-wide exception counts for the type pills above the exceptions grid.
 * Reads only Agora's saved run lines; writes nothing. Counts are item/shift
 * rows, not chains. A row with both codes belongs to both type counts but
 * occurs only once in All types. Grid searches and filters do not change this
 * inventory, so another type remains discoverable while one is selected.
 */
CREATE OR ALTER PROCEDURE [agora].[usp_StockRecon_ExceptionTypes]
    @BranchId INT,
    @RunId INT
AS
BEGIN
    SET NOCOUNT ON;

    SELECT codes.Kind, codes.Code, COUNT_BIG(*) AS TotalRows
    FROM agora.StockReconRunLine rl
    CROSS APPLY (VALUES
        ('all', CAST(NULL AS nvarchar(20))),
        ('ExceptionCode', rl.ExceptionCode),
        ('AuditCode', rl.AuditCode)
    ) codes(Kind, Code)
    WHERE rl.BranchId = @BranchId AND rl.RunId = @RunId
      AND (rl.ExceptionCode IS NOT NULL OR rl.AuditCode IS NOT NULL)
      AND (codes.Kind = 'all' OR codes.Code IS NOT NULL)
    GROUP BY codes.Kind, codes.Code;
END
