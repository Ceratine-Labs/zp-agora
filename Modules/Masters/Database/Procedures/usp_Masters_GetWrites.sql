/* What Agora has written to PumpIT for one reference-master record (T022).

   Reads agora.MasterWrite only: every column a press changed, before and
   after, who, when, why, and whether it has been put back — newest first.
   Shown on the record's page as "Written to PumpIT". Read-only.

   @Entity and @RowKey name the record as the listing does (CategoryGl,
   '5:BREAD'); @BranchId is its site, or the group entity for a group-wide
   master. A site outside @AllowedBranchIds answers nothing.

   Called by Modules/Masters/Services/MasterWriteService. Deployed by v1__02pb. */
CREATE OR ALTER PROCEDURE agora.usp_Masters_GetWrites
    @BranchId INT,
    @Entity NVARCHAR(40),
    @RowKey NVARCHAR(200),
    @AllowedBranchIds NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    SELECT w.WriteId, w.Field, w.PriorValue, w.NewValue, w.PumpItTable, w.Reason, w.State,
           w.CreatedAt, w.CreatedBy, u.UserName AS ChangedByName,
           w.ReversedAt, r.UserName AS ReversedByName, w.ReversalReason
    FROM agora.MasterWrite w
    LEFT JOIN agora.[User] u ON u.Id = w.CreatedBy
    LEFT JOIN agora.[User] r ON r.Id = w.ReversedBy
    WHERE w.BranchId = @BranchId AND w.Entity = @Entity AND w.RowKey = @RowKey
      AND (NULLIF(LTRIM(RTRIM(@AllowedBranchIds)), '') IS NULL
           OR EXISTS (SELECT 1 FROM STRING_SPLIT(@AllowedBranchIds, ',') WHERE TRY_CONVERT(INT, value) = @BranchId))
    ORDER BY w.CreatedAt DESC, w.Id DESC;
END
