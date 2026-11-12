/* What Agora has written to PumpIT for one supplier or customer (T024), from
   agora.PartnersWrite, newest press first. Read-only.

   @Kind 'Supplier': every press on the code, at any of the caller's sites —
   a name change is one press across every site carrying the code.
   @Kind 'Customer': the presses on the one row (@BranchId, @Code).

   One row per ledger row: WriteId, Verb, PumpItTable, BranchId, BranchName,
   PartnerCode, Field, PriorValue, NewValue, Reason, State, CreatedAt,
   ChangedByName, ReversedAt, ReversedByName, ReversalReason.
   A press touching a site outside @AllowedBranchIds is left out whole.
   Read by Modules/Partners/Services/PartnerWriteService. Deployed by v1__04pa. */
CREATE OR ALTER PROCEDURE agora.usp_Partners_GetChanges
    @Kind NVARCHAR(10),
    @BranchId INT,
    @Code NVARCHAR(10),
    @AllowedBranchIds NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET @Code = LTRIM(RTRIM(@Code));

    DECLARE @Allowed TABLE (BranchId INT PRIMARY KEY);
    INSERT INTO @Allowed
    SELECT DISTINCT TRY_CONVERT(INT, value) FROM STRING_SPLIT(@AllowedBranchIds, ',')
    WHERE TRY_CONVERT(INT, value) IS NOT NULL;
    DECLARE @AllBranches BIT = CASE WHEN NULLIF(LTRIM(RTRIM(@AllowedBranchIds)), '') IS NULL THEN 1 ELSE 0 END;

    DECLARE @Presses TABLE (WriteId UNIQUEIDENTIFIER PRIMARY KEY);
    INSERT INTO @Presses
    SELECT DISTINCT w.WriteId
    FROM agora.PartnersWrite w
    WHERE w.PartnerCode = @Code
      AND ((@Kind = N'Supplier' AND w.Verb LIKE 'supplier%')
           OR (@Kind = N'Customer' AND w.Verb LIKE 'customer%' AND w.BranchId = @BranchId));

    DELETE p FROM @Presses p
    WHERE @AllBranches = 0 AND EXISTS (SELECT 1 FROM agora.PartnersWrite w WHERE w.WriteId = p.WriteId
                                       AND w.BranchId NOT IN (SELECT BranchId FROM @Allowed));

    SELECT w.WriteId, w.Verb, w.PumpItTable, w.BranchId, b.Name AS BranchName, w.PartnerCode, w.Field,
           w.PriorValue, w.NewValue, w.Reason, w.State, w.CreatedAt, cu.UserName AS ChangedByName,
           w.ReversedAt, ru.UserName AS ReversedByName, w.ReversalReason,
           MAX(w.CreatedAt) OVER (PARTITION BY w.WriteId) AS PressedAt
    FROM agora.PartnersWrite w
    JOIN @Presses p ON p.WriteId = w.WriteId
    LEFT JOIN agora.Branch b ON b.BranchId = w.BranchId
    LEFT JOIN agora.[User] cu ON cu.Id = w.CreatedBy
    LEFT JOIN agora.[User] ru ON ru.Id = w.ReversedBy
    ORDER BY PressedAt DESC, w.WriteId, w.Field, b.Name;
END
