/* Rename one fuel type at one site in PumpIT (T022). A NAMED WRITE.

   CLAUDE.md rule 1; Ryan's standing rule of 12 Nov 2026 (PM decision
   01a1cecf-8e08). WRITES exactly [PumpIT].dbo.BRN_FuelType.FuelTypeDescription on
   ONE row, by (SSBranchId, FuelTypeNo), in one transaction, and one
   agora.MasterWrite row, so agora.usp_Masters_ReverseWrite can put it back.

   THE NAME ONLY. FuelTypeNo is what every pump, tank, price and fuel
   input names. POSType and POSIdentifier are how the forecourt's grades reach
   PumpIT (sp_Load_BranchFuelPrice joins POSIdentifier to the grade id), and
   IsFleetCard and HandlingFee are re-read into the WHOLE price history each time
   PumpIT reloads BRN_FuelPrice — none of them is a one-row change, so none is
   offered. CostPrice and SellingPrice are dead: prices live in BRN_FuelPrice.

   PumpIT copies the name as text into BRN_FuelPrice, which it rebuilds from this
   table, and into each month's BRN_MonthlyDOEFuelPricingMatrix rows when they
   are made; both are joined by FuelTypeNo, so a renamed type keeps its history,
   and the old name stays on months already made.

   PER BRANCH, and the name is NOT unique: FuelTypeNo 2 is a different fuel at
   different sites, and a site can carry the same name twice (a fleet-card
   grade beside the forecourt one). So there is no duplicate check, and the row
   is always (SSBranchId, FuelTypeNo).
   Never an INSERT or a DELETE.

   Refuses (AGORA:{Code}):
     WRITES_OFF, DENIED (masters.fueltypes.edit), REASON_REQUIRED, NOT_FOUND
     (also a site outside @AllowedBranchIds)
     DESCRIPTION  blank, or longer than PumpIT's 35 characters
     TRIGGER_ON   a PumpIT trigger on BRN_FuelType is switched on
                  (trg_BRN_FuelType_Replicate; disabled 12 Nov 2026)
     STALE        the row changed since the form was drawn
   Nothing changed is not a refusal: Code NOTHING_CHANGED.

   Returns one row (Ok, Code, Message, WriteId).
   Called by Modules/Masters/Services/MasterWriteService. Deployed by v1__02pd. */
CREATE OR ALTER PROCEDURE agora.usp_Masters_SaveFuelType
    @BranchId INT,
    @Key NVARCHAR(200),
    @FuelTypeDescription NVARCHAR(200),
    @ExpectedFuelTypeDescription NVARCHAR(400),
    @Reason NVARCHAR(400),
    @UserId INT,
    @Authorized BIT = 0,
    @WriteMode VARCHAR(10) = 'off',
    @AllowedBranchIds NVARCHAR(MAX) = NULL,
    @ReasonMin INT = 10
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    SET @Reason = NULLIF(LTRIM(RTRIM(@Reason)), N'');
    SET @FuelTypeDescription = LTRIM(RTRIM(@FuelTypeDescription));
    DECLARE @No INT = TRY_CONVERT(INT, @Key);

    IF ISNULL(@WriteMode, 'off') <> 'live'
        THROW 51000, 'AGORA:WRITES_OFF:Changing reference masters is switched off (masters.write_mode). Nothing was written.', 1;
    IF ISNULL(@Authorized, 0) = 0
        THROW 51000, 'AGORA:DENIED:You may not change fuel types.', 1;
    IF LEN(ISNULL(@Reason, N'')) < ISNULL(@ReasonMin, 10)
        THROW 51000, 'AGORA:REASON_REQUIRED:Say why the fuel type is being changed — it is kept with the change.', 1;
    IF @No IS NULL
       OR (NULLIF(LTRIM(RTRIM(@AllowedBranchIds)), '') IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM STRING_SPLIT(@AllowedBranchIds, ',') WHERE TRY_CONVERT(INT, value) = @BranchId))
        THROW 51000, 'AGORA:NOT_FOUND:There is no such fuel type at a site you may see.', 1;
    IF ISNULL(@FuelTypeDescription, N'') = N'' OR LEN(@FuelTypeDescription) > 35
        THROW 51000, 'AGORA:DESCRIPTION:A fuel type needs a name, at most 35 characters (PumpIT''s limit).', 1;
    IF EXISTS (SELECT 1 FROM [PumpIT].sys.triggers t
               WHERE t.parent_id = OBJECT_ID(N'[PumpIT].dbo.BRN_FuelType') AND t.is_disabled = 0)
        THROW 51000, 'AGORA:TRIGGER_ON:PumpIT has a trigger switched on for BRN_FuelType, which would write beyond it. Nothing was written.', 1;

    DECLARE @WriteId UNIQUEIDENTIFIER = NEWID();
    DECLARE @Changed INT = 0;

    BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @Found BIT, @Cur NVARCHAR(35);
    SELECT @Found = 1, @Cur = r.FuelTypeDescription
    FROM [PumpIT].dbo.BRN_FuelType r WITH (UPDLOCK, HOLDLOCK)
    WHERE r.SSBranchId = @BranchId AND r.FuelTypeNo = @No;

    IF @Found IS NULL
        THROW 51000, 'AGORA:NOT_FOUND:There is no such fuel type at a site you may see.', 1;
    IF ISNULL(LTRIM(RTRIM(@Cur)), N'') <> ISNULL(LTRIM(RTRIM(@ExpectedFuelTypeDescription)), N'')
        THROW 51000, 'AGORA:STALE:This fuel type was changed in PumpIT or by someone else since you opened the form. Open it again and redo your change.', 1;

    IF ISNULL(LTRIM(RTRIM(@Cur)), N'') <> @FuelTypeDescription
    BEGIN
        INSERT INTO agora.MasterWrite (BranchId, WriteId, Entity, PumpItTable, RowKey, RowLabel, KeyJson, Field,
                                       PriorValue, NewValue, Reason, WriteMode, State, CreatedAt, CreatedBy)
        VALUES (@BranchId, @WriteId, N'FuelType', N'BRN_FuelType', CONVERT(NVARCHAR(200), @No),
                CONCAT(N'Fuel type ', @No, N' · ', LTRIM(RTRIM(@Cur))),
                (SELECT @BranchId AS SSBranchId, @No AS FuelTypeNo FOR JSON PATH, WITHOUT_ARRAY_WRAPPER),
                N'FuelTypeDescription', CONVERT(NVARCHAR(400), @Cur), @FuelTypeDescription, @Reason, @WriteMode, 'active', SYSUTCDATETIME(), @UserId);
        SET @Changed = 1;

        UPDATE r SET FuelTypeDescription = @FuelTypeDescription
          FROM [PumpIT].dbo.BRN_FuelType r
         WHERE r.SSBranchId = @BranchId AND r.FuelTypeNo = @No;
    END

    COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        DECLARE @Err NVARCHAR(2048) = ERROR_MESSAGE();
        THROW 51000, @Err, 1;
    END CATCH

    SELECT CONVERT(BIT, 1) AS Ok,
           CASE WHEN @Changed = 0 THEN 'NOTHING_CHANGED' ELSE 'SAVED' END AS Code,
           CASE WHEN @Changed = 0 THEN N'Nothing changed — the form matched PumpIT.'
                ELSE N'The new name was written to PumpIT''s BRN_FuelType, the old one kept with it.' END AS Message,
           CASE WHEN @Changed = 0 THEN NULL ELSE @WriteId END AS WriteId;
END
