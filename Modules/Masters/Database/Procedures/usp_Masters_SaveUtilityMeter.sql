/* Change one utility meter's description in PumpIT (T022). A NAMED WRITE.

   CLAUDE.md rule 1; Ryan's standing rule of 12 Nov 2026 (PM decision
   01a1cecf-8e08). WRITES exactly [PumpIT].dbo.BRN_UtilityMeter.Description on
   ONE row, by UtilityMeterId within its site, in one transaction, and one
   agora.MasterWrite row, so agora.usp_Masters_ReverseWrite can put it back.

   THE DESCRIPTION ONLY, AND THE METER ROW ONLY. MeterNo is the key every
   reading carries (BRN_UtilityTransaction names its meter by MeterNo, as
   text), so a renumber would orphan the readings; UtilityType says which
   capture screen lists it. Neither is offered. Readings are
   Modules/Utilities' own named write (usp_Utilities_Save) and are not touched
   here.

   50 characters, not the column's 100: PumpIT's own capture
   (sp_CaptureUtilityTransaction) copies the description into an nvarchar(50)
   work table, and a longer one would stop that screen opening.
   Never an INSERT or a DELETE.

   Refuses (AGORA:{Code}):
     WRITES_OFF, DENIED (masters.meters.edit), REASON_REQUIRED, NOT_FOUND
     (also a site outside @AllowedBranchIds)
     DESCRIPTION  blank, or longer than PumpIT's 50 characters
     TRIGGER_ON   a PumpIT trigger on BRN_UtilityMeter is switched on
     STALE        the row changed since the form was drawn
   Nothing changed is not a refusal: Code NOTHING_CHANGED.

   Returns one row (Ok, Code, Message, WriteId).
   Called by Modules/Masters/Services/MasterWriteService. Deployed by v1__02pd. */
CREATE OR ALTER PROCEDURE agora.usp_Masters_SaveUtilityMeter
    @BranchId INT,
    @Key NVARCHAR(200),
    @MeterDescription NVARCHAR(200),
    @ExpectedMeterDescription NVARCHAR(400),
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
    SET @MeterDescription = LTRIM(RTRIM(@MeterDescription));
    DECLARE @Id INT = TRY_CONVERT(INT, @Key);

    IF ISNULL(@WriteMode, 'off') <> 'live'
        THROW 51000, 'AGORA:WRITES_OFF:Changing reference masters is switched off (masters.write_mode). Nothing was written.', 1;
    IF ISNULL(@Authorized, 0) = 0
        THROW 51000, 'AGORA:DENIED:You may not change utility meters.', 1;
    IF LEN(ISNULL(@Reason, N'')) < ISNULL(@ReasonMin, 10)
        THROW 51000, 'AGORA:REASON_REQUIRED:Say why the meter is being changed — it is kept with the change.', 1;
    IF @Id IS NULL
       OR (NULLIF(LTRIM(RTRIM(@AllowedBranchIds)), '') IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM STRING_SPLIT(@AllowedBranchIds, ',') WHERE TRY_CONVERT(INT, value) = @BranchId))
        THROW 51000, 'AGORA:NOT_FOUND:There is no such meter at a site you may see.', 1;
    IF ISNULL(@MeterDescription, N'') = N'' OR LEN(@MeterDescription) > 50
        THROW 51000, 'AGORA:DESCRIPTION:A meter needs a description, at most 50 characters (PumpIT''s limit).', 1;
    IF EXISTS (SELECT 1 FROM [PumpIT].sys.triggers t
               WHERE t.parent_id = OBJECT_ID(N'[PumpIT].dbo.BRN_UtilityMeter') AND t.is_disabled = 0)
        THROW 51000, 'AGORA:TRIGGER_ON:PumpIT has a trigger switched on for BRN_UtilityMeter, which would write beyond it. Nothing was written.', 1;

    DECLARE @WriteId UNIQUEIDENTIFIER = NEWID();
    DECLARE @Changed INT = 0;

    BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @Found BIT, @Cur NVARCHAR(50), @MeterNo NVARCHAR(50);
    SELECT @Found = 1, @Cur = r.Description, @MeterNo = r.MeterNo
    FROM [PumpIT].dbo.BRN_UtilityMeter r WITH (UPDLOCK, HOLDLOCK)
    WHERE r.UtilityMeterId = @Id AND r.SSBranchId = @BranchId;

    IF @Found IS NULL
        THROW 51000, 'AGORA:NOT_FOUND:There is no such meter at a site you may see.', 1;
    IF ISNULL(LTRIM(RTRIM(@Cur)), N'') <> ISNULL(LTRIM(RTRIM(@ExpectedMeterDescription)), N'')
        THROW 51000, 'AGORA:STALE:This meter was changed in PumpIT or by someone else since you opened the form. Open it again and redo your change.', 1;

    IF ISNULL(LTRIM(RTRIM(@Cur)), N'') <> @MeterDescription
    BEGIN
        INSERT INTO agora.MasterWrite (BranchId, WriteId, Entity, PumpItTable, RowKey, RowLabel, KeyJson, Field,
                                       PriorValue, NewValue, Reason, WriteMode, State, CreatedAt, CreatedBy)
        VALUES (@BranchId, @WriteId, N'UtilityMeter', N'BRN_UtilityMeter', CONVERT(NVARCHAR(200), @Id),
                CONCAT(N'Meter ', LTRIM(RTRIM(@MeterNo))),
                (SELECT @BranchId AS SSBranchId, @Id AS UtilityMeterId FOR JSON PATH, WITHOUT_ARRAY_WRAPPER),
                N'Description', CONVERT(NVARCHAR(400), @Cur), @MeterDescription, @Reason, @WriteMode, 'active', SYSUTCDATETIME(), @UserId);
        SET @Changed = 1;

        UPDATE r SET Description = @MeterDescription
          FROM [PumpIT].dbo.BRN_UtilityMeter r
         WHERE r.UtilityMeterId = @Id AND r.SSBranchId = @BranchId;
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
                ELSE N'The new description was written to PumpIT''s BRN_UtilityMeter, the old one kept with it.' END AS Message,
           CASE WHEN @Changed = 0 THEN NULL ELSE @WriteId END AS WriteId;
END
