/* Rename one tank at one site in PumpIT (T022). A NAMED WRITE.

   CLAUDE.md rule 1; Ryan's standing rule of 12 Nov 2026 (PM decision
   01a1cecf-8e08). WRITES exactly [PumpIT].dbo.BRN_Tank.TankDescription on
   ONE row, by (SSBranchId, TankNo), in one transaction, and one
   agora.MasterWrite row, so agora.usp_Masters_ReverseWrite can put it back.

   THE NAME ONLY. TankNo is what every pump and dip names; PumpIT joins a
   tank by its number, never by its name. BRN_Tank has no active flag.

   PER BRANCH: tank 1 is a different tank at every site.
   Never an INSERT or a DELETE.

   Refuses (AGORA:{Code}):
     WRITES_OFF, DENIED (masters.tanks.edit), REASON_REQUIRED, NOT_FOUND
     (also a site outside @AllowedBranchIds)
     DESCRIPTION  blank, or longer than PumpIT's 50 characters
     DUPLICATE    another tank at the site already has that name
     TRIGGER_ON   a PumpIT trigger on BRN_Tank is switched on
                  (trg_BRN_Tank_Replicate; disabled 12 Nov 2026)
     STALE        the row changed since the form was drawn
   Nothing changed is not a refusal: Code NOTHING_CHANGED.

   Returns one row (Ok, Code, Message, WriteId).
   Called by Modules/Masters/Services/MasterWriteService. Deployed by v1__02pd. */
CREATE OR ALTER PROCEDURE agora.usp_Masters_SaveTank
    @BranchId INT,
    @Key NVARCHAR(200),
    @TankDescription NVARCHAR(200),
    @ExpectedTankDescription NVARCHAR(400),
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
    SET @TankDescription = LTRIM(RTRIM(@TankDescription));
    DECLARE @No NVARCHAR(4) = NULLIF(LTRIM(RTRIM(@Key)), N'');

    IF ISNULL(@WriteMode, 'off') <> 'live'
        THROW 51000, 'AGORA:WRITES_OFF:Changing reference masters is switched off (masters.write_mode). Nothing was written.', 1;
    IF ISNULL(@Authorized, 0) = 0
        THROW 51000, 'AGORA:DENIED:You may not change tanks.', 1;
    IF LEN(ISNULL(@Reason, N'')) < ISNULL(@ReasonMin, 10)
        THROW 51000, 'AGORA:REASON_REQUIRED:Say why the tank is being changed — it is kept with the change.', 1;
    IF @No IS NULL OR LEN(LTRIM(RTRIM(@Key))) > 4
       OR (NULLIF(LTRIM(RTRIM(@AllowedBranchIds)), '') IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM STRING_SPLIT(@AllowedBranchIds, ',') WHERE TRY_CONVERT(INT, value) = @BranchId))
        THROW 51000, 'AGORA:NOT_FOUND:There is no such tank at a site you may see.', 1;
    IF ISNULL(@TankDescription, N'') = N'' OR LEN(@TankDescription) > 50
        THROW 51000, 'AGORA:DESCRIPTION:A tank needs a name, at most 50 characters (PumpIT''s limit).', 1;
    IF EXISTS (SELECT 1 FROM [PumpIT].sys.triggers t
               WHERE t.parent_id = OBJECT_ID(N'[PumpIT].dbo.BRN_Tank') AND t.is_disabled = 0)
        THROW 51000, 'AGORA:TRIGGER_ON:PumpIT has a trigger switched on for BRN_Tank, which would write beyond it. Nothing was written.', 1;

    DECLARE @WriteId UNIQUEIDENTIFIER = NEWID();
    DECLARE @Changed INT = 0;

    BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @Found BIT, @Cur NVARCHAR(50);
    SELECT @Found = 1, @Cur = r.TankDescription
    FROM [PumpIT].dbo.BRN_Tank r WITH (UPDLOCK, HOLDLOCK)
    WHERE r.SSBranchId = @BranchId AND r.TankNo = @No;

    IF @Found IS NULL
        THROW 51000, 'AGORA:NOT_FOUND:There is no such tank at a site you may see.', 1;
    IF ISNULL(LTRIM(RTRIM(@Cur)), N'') <> ISNULL(LTRIM(RTRIM(@ExpectedTankDescription)), N'')
        THROW 51000, 'AGORA:STALE:This tank was changed in PumpIT or by someone else since you opened the form. Open it again and redo your change.', 1;

    IF ISNULL(LTRIM(RTRIM(@Cur)), N'') <> @TankDescription
    BEGIN
        IF EXISTS (SELECT 1 FROM [PumpIT].dbo.BRN_Tank o
                   WHERE o.SSBranchId = @BranchId AND o.TankNo <> @No AND LTRIM(RTRIM(o.TankDescription)) = @TankDescription)
            THROW 51000, 'AGORA:DUPLICATE:Another tank at this site already has that name.', 1;

        INSERT INTO agora.MasterWrite (BranchId, WriteId, Entity, PumpItTable, RowKey, RowLabel, KeyJson, Field,
                                       PriorValue, NewValue, Reason, WriteMode, State, CreatedAt, CreatedBy)
        VALUES (@BranchId, @WriteId, N'Tank', N'BRN_Tank', @No,
                CONCAT(N'Tank ', @No, N' · ', LTRIM(RTRIM(@Cur))),
                (SELECT @BranchId AS SSBranchId, @No AS TankNo FOR JSON PATH, WITHOUT_ARRAY_WRAPPER),
                N'TankDescription', CONVERT(NVARCHAR(400), @Cur), @TankDescription, @Reason, @WriteMode, 'active', SYSUTCDATETIME(), @UserId);
        SET @Changed = 1;

        UPDATE r SET TankDescription = @TankDescription
          FROM [PumpIT].dbo.BRN_Tank r
         WHERE r.SSBranchId = @BranchId AND r.TankNo = @No;
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
                ELSE N'The new name was written to PumpIT''s BRN_Tank, the old one kept with it.' END AS Message,
           CASE WHEN @Changed = 0 THEN NULL ELSE @WriteId END AS WriteId;
END
