/*
 * agora.usp_Product_RefreshCountStats — rebuild when each stock line was last
 * counted.
 *
 * Reads:  [PumpIT].dbo.STK_StockReconLine (6.2M rows, READ ONLY)
 * Writes: agora.StockItemCountStat, and nothing else.
 *
 * WHY A REBUILD AND NOT AN INCREMENT. Measured against the customer's instance
 * on 20 September 2026, the full GROUP BY over all 6.2 million count lines
 * produces its 7,094 (branch, item) pairs in about four seconds. An
 * incremental refresh would need a watermark, and a watermark that is wrong is
 * a "last counted" date that is quietly stale forever. Four seconds of honesty
 * beats a cache nobody can audit.
 *
 * SCOPED OR WHOLE. @BranchId refreshes one site — which is what a screen's
 * "refresh" button should do — and NULL refreshes the estate. Either way the
 * rows in scope are deleted and rewritten, so a line that has stopped being
 * counted loses its row rather than keeping yesterday's answer.
 *
 * A LINE WITH NO ROW HERE HAS NEVER BEEN COUNTED, and the grid renders that as
 * an em dash rather than a date. That is a real answer: branches 30 and 31
 * appear nowhere in the count lines at all, and 4,434 of 7,447 items had not
 * been counted anywhere since 1 August 2026. Nothing here invents a zero to
 * make the column look full.
 *
 * Refusals: AGORA:UNKNOWN_BRANCH:...
 */
CREATE OR ALTER PROCEDURE [agora].[usp_Product_RefreshCountStats]
    @BranchId int = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @BranchId IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM [agora].[Branch] WHERE BranchId = @BranchId)
        THROW 51000, 'AGORA:UNKNOWN_BRANCH:That branch is not in Agora''s branch list.', 1;

    DECLARE @Now datetime2(0) = SYSDATETIME();
    DECLARE @Since date = DATEADD(day, -90, CAST(GETDATE() AS date));

    /*
     * One pass over the count lines, then one swap. The delete and the insert
     * are in the same transaction so a screen never reads a half-empty table —
     * which on this table would render as "never counted" for every line it
     * had not reached yet, the single most misleading thing it could say.
     */
    BEGIN TRAN;

        DECLARE @stat TABLE (
            BranchId          int          NOT NULL,
            StockItemNo       nvarchar(5)  NOT NULL,
            LastCountedAt     datetime     NULL,
            CountLinesAllTime int          NOT NULL,
            CountLines90      int          NOT NULL
        );

        INSERT INTO @stat (BranchId, StockItemNo, LastCountedAt, CountLinesAllTime, CountLines90)
        SELECT l.SSBranchId,
               l.StockItemNo,
               MAX(l.TransactionDate),
               COUNT(*),
               SUM(CASE WHEN l.TransactionDate >= @Since THEN 1 ELSE 0 END)
        FROM [PumpIT].dbo.STK_StockReconLine l
        WHERE (@BranchId IS NULL OR l.SSBranchId = @BranchId)
        GROUP BY l.SSBranchId, l.StockItemNo;

        DELETE FROM [agora].[StockItemCountStat]
        WHERE (@BranchId IS NULL OR BranchId = @BranchId);

        INSERT INTO [agora].[StockItemCountStat]
            (BranchId, StockItemNo, LastCountedAt, CountLinesAllTime, CountLines90, RefreshedAt, CreatedAt)
        SELECT s.BranchId, s.StockItemNo, s.LastCountedAt, s.CountLinesAllTime, s.CountLines90, @Now, @Now
        FROM @stat s;

    COMMIT;

    /*
     * The writer status row every caller of ProcedureService::write() gets:
     * (Ok, Code, Message, Id), with this procedure's own numbers after it. Id
     * is the row count rather than a key, because a rebuild has no one row to
     * point at.
     */
    DECLARE @Written int = (
        SELECT COUNT(*) FROM [agora].[StockItemCountStat]
        WHERE (@BranchId IS NULL OR BranchId = @BranchId)
    );

    SELECT CONVERT(bit, 1) AS Ok,
           'REFRESHED'     AS Code,
           CONCAT('Last-counted rebuilt for ', @Written, ' stock lines.') AS Message,
           @Written        AS Id,
           @Written        AS RowsWritten,
           @Now            AS RefreshedAt,
           @BranchId       AS BranchId;
END
