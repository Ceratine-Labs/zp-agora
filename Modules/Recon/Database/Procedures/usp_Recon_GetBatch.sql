/* One displayed batch in one area/site. Only Agora's recorded batches have
   the source ledger needed for a reversible preview. PumpIT-only is refused. */
CREATE OR ALTER PROCEDURE agora.usp_Recon_GetBatch
    @BranchId int, @ReconArea nvarchar(20), @BatchNo int
AS
BEGIN
    SET NOCOUNT ON;
    IF (SELECT COUNT(*) FROM agora.ReconBatch WHERE BranchId = @BranchId AND ReconArea = @ReconArea AND BatchNo = @BatchNo) > 1
        THROW 51000, 'AGORA:BATCH_AMBIGUOUS:More than one recorded batch has this number. Review the runs individually.', 1;
    DECLARE @Id bigint = (SELECT Id FROM agora.ReconBatch WHERE BranchId = @BranchId AND ReconArea = @ReconArea AND BatchNo = @BatchNo);
    IF @Id IS NULL
        THROW 51000, 'AGORA:BATCH_NOT_FOUND:No Agora-recorded batch has this number at this site in this area. PumpIT-only batches cannot be reversed here.', 1;
    SELECT b.*, r.StampMode FROM agora.ReconBatch b JOIN agora.ReconRun r ON r.Id = b.RunId WHERE b.Id = @Id;
    SELECT m.Side, m.SourceId, m.SourceTable, m.SourceDate, m.Amount, m.Adjustment, m.PriorAmount,
           m.PriorAdjusted, m.PriorAdjustedBy, JSON_VALUE(m.SourceKeyJson, '$.key') AS SourceKey
    FROM agora.ReconMatch m WHERE m.BranchId = @BranchId AND m.BatchId = @Id ORDER BY m.Side, m.Id;
    SELECT a.CashierCode, a.CashierName, a.Share, a.StaffShortsNo, a.ShortStatus
    FROM agora.ReconAllocation a WHERE a.BranchId = @BranchId AND a.BatchId = @Id ORDER BY a.Id;
END;
