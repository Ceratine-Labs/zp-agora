/* The changes Agora has written to PumpIT for one employee (T023). Read-only.

   One row per agora.PeopleWrite row naming the employee — a column of
   BRN_Employee or one of their counting areas — newest first, with who made
   it and, where it was put back, who did and why. Rows of one press share a
   WriteId; that is what the Put back press on the screen hands to
   agora.usp_People_ReverseWrite.

   Read by Modules/People/Services/EmployeeWriteService. Deployed by v1__03pa. */
CREATE OR ALTER PROCEDURE agora.usp_People_GetEmployeeChanges
    @BranchId INT,
    @EmployeeCode NVARCHAR(11),
    @AllowedBranchIds NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET @EmployeeCode = LTRIM(RTRIM(@EmployeeCode));

    DECLARE @Visible BIT = CASE
        WHEN NULLIF(LTRIM(RTRIM(@AllowedBranchIds)), '') IS NULL THEN 1
        WHEN EXISTS (SELECT 1 FROM STRING_SPLIT(@AllowedBranchIds, ',') WHERE TRY_CONVERT(INT, value) = @BranchId) THEN 1
        ELSE 0 END;

    SELECT TOP (500) w.Id, CONVERT(VARCHAR(36), w.WriteId) AS WriteId, w.Verb, w.PumpItTable, w.Field, w.AreaNo,
           s.AreaDescription, w.PriorValue, w.NewValue, w.Reason, w.State,
           w.CreatedAt, w.CreatedBy, cu.UserName AS ChangedByName,
           w.ReversedAt, ru.UserName AS ReversedByName, w.ReversalReason
    FROM agora.PeopleWrite w
    LEFT JOIN agora.[User] cu ON cu.Id = w.CreatedBy
    LEFT JOIN agora.[User] ru ON ru.Id = w.ReversedBy
    OUTER APPLY (SELECT TOP 1 x.AreaDescription FROM [PumpIT].dbo.STK_Area x
                 WHERE x.SSBranchId = w.BranchId AND x.AreaNo = w.AreaNo) s
    WHERE w.BranchId = @BranchId AND w.EmployeeCode = @EmployeeCode AND @Visible = 1
    ORDER BY w.Id DESC;
END
