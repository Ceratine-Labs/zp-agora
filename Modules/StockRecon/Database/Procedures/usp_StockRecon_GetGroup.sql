CREATE OR ALTER PROCEDURE [agora].[usp_StockRecon_GetGroup]
    @GroupRef uniqueidentifier,
    @UserId int
AS
BEGIN
    SET NOCOUNT ON;
    SELECT s.BranchId, ISNULL(b.Name, 'Site '+CONVERT(varchar(12), s.BranchId)) AS BranchName, r.Id AS RunId,
           ISNULL(r.Status, 'pending') AS Status, r.FailureMessage,
           r.TotalRows, r.ChainCount, r.AmendedRows, r.BlockedChains
    FROM agora.StockReconRunGroup g
    CROSS APPLY OPENJSON(g.BranchIdsJson) WITH (BranchId int '$') s
    LEFT JOIN agora.Branch b ON b.BranchId = s.BranchId
    OUTER APPLY (
        SELECT TOP (1) x.Id, x.Status, x.FailureMessage, x.TotalRows,
               x.ChainCount, x.AmendedRows, x.BlockedChains
        FROM agora.StockReconRun x
        WHERE x.BranchId = s.BranchId AND x.GroupRef = g.GroupRef
        ORDER BY x.Id DESC
    ) r
    WHERE g.GroupRef = @GroupRef AND g.CreatedBy = @UserId
    ORDER BY b.Name, s.BranchId;
END;
