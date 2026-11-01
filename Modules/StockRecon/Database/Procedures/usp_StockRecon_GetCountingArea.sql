/* Counting-area detail and immutable confirmation history. Reads only;
   opening this screen must never turn a proposal into a confirmation.
   Deployed by v1__14px. */
CREATE OR ALTER PROCEDURE agora.usp_StockRecon_GetCountingArea
    @BranchId INT,
    @AreaNo INT,
    @UserId INT = NULL,
    @Authorized BIT = 0,
    @Workspace NVARCHAR(10) = N'branch'
AS
BEGIN
    SET NOCOUNT ON;
    EXEC agora.usp_StockRecon_AuthorizeCountingArea @BranchId, @UserId, @Authorized, @Workspace;
    SELECT f.*, COALESCE(b.Name, CONVERT(nvarchar(20), f.BranchId)) AS BranchName, u.UserName AS ConfirmedByName,
           COALESCE(CONVERT(varchar(18), CONVERT(binary(8), s.RowVer), 1), 'new') AS VersionToken
    FROM agora.vw_StockAreaFood f
    LEFT JOIN agora.Branch b ON b.BranchId = f.BranchId
    LEFT JOIN agora.StockAreaSetting s ON s.BranchId = f.BranchId AND s.AreaNo = f.AreaNo
    LEFT JOIN agora.[User] u ON u.Id = f.ConfirmedBy
    WHERE f.BranchId = @BranchId AND f.AreaNo = @AreaNo;

    SELECT c.PriorIsPreparedFood, c.NewIsPreparedFood, c.PriorConfirmedAt, c.Reason,
           c.CreatedAt, c.CreatedBy, u.UserName AS ChangedByName
    FROM agora.StockAreaSettingChange c
    LEFT JOIN agora.[User] u ON u.Id = c.CreatedBy
    WHERE c.BranchId = @BranchId AND c.AreaNo = @AreaNo
    ORDER BY c.Id DESC;
END
