/* Rename one till at one site in PumpIT (T022). A NAMED WRITE.

   CLAUDE.md rule 1; Ryan's standing rule of 12 Nov 2026 (PM decision
   01a1cecf-8e08). WRITES exactly [PumpIT].dbo.BRN_Tills.TillDescription on
   ONE row, by (SSBranchId, TillNo), in one transaction, and one
   agora.MasterWrite row, so agora.usp_Masters_ReverseWrite can put it back.

   THE NAME ONLY. TillNo is what every Z-read, cashup and staff short names,
   and Location is the POS system the till's Z-reads arrive from
   (sp_SelectBranchTill_POSSystem) — changing either would move live POS data,
   so neither is offered. PumpIT joins a till by its number, never by its
   name, so the new name shows on PumpIT's screens and nothing else moves.
   Never an INSERT or a DELETE.

   PER BRANCH: TillNo 11 is a different till at every site.

   Refuses (AGORA:{Code}):
     WRITES_OFF, DENIED (masters.tills.edit), REASON_REQUIRED, NOT_FOUND
     (also a site outside @AllowedBranchIds)
     DESCRIPTION  blank, or longer than PumpIT's 30 characters
     DUPLICATE    another till at the site already has that name
     TRIGGER_ON   a PumpIT trigger on BRN_Tills is switched on
                  (trg_Sync_BRN_Tills; disabled 12 Nov 2026)
     STALE        the row changed since the form was drawn
   Nothing changed is not a refusal: Code NOTHING_CHANGED.

   Returns one row (Ok, Code, Message, WriteId).
   Called by Modules/Masters/Services/MasterWriteService. Deployed by v1__02pd. */
CREATE OR ALTER PROCEDURE agora.usp_Masters_SaveTill
    @BranchId INT,
    @Key NVARCHAR(200),
    @TillDescription NVARCHAR(200),
    @ExpectedTillDescription NVARCHAR(400),
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
    SET @TillDescription = LTRIM(RTRIM(@TillDescription));
    DECLARE @No INT = TRY_CONVERT(INT, @Key);

    IF ISNULL(@WriteMode, 'off') <> 'live'
        THROW 51000, 'AGORA:WRITES_OFF:Changing reference masters is switched off (masters.write_mode). Nothing was written.', 1;
    IF ISNULL(@Authorized, 0) = 0
        THROW 51000, 'AGORA:DENIED:You may not rename tills.', 1;
    IF LEN(ISNULL(@Reason, N'')) < ISNULL(@ReasonMin, 10)
        THROW 51000, 'AGORA:REASON_REQUIRED:Say why the till is being renamed — it is kept with the change.', 1;
    IF @No IS NULL
       OR (NULLIF(LTRIM(RTRIM(@AllowedBranchIds)), '') IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM STRING_SPLIT(@AllowedBranchIds, ',') WHERE TRY_CONVERT(INT, value) = @BranchId))
        THROW 51000, 'AGORA:NOT_FOUND:There is no such till at a site you may see.', 1;
    IF ISNULL(@TillDescription, N'') = N'' OR LEN(@TillDescription) > 30
        THROW 51000, 'AGORA:DESCRIPTION:A till needs a name, at most 30 characters (PumpIT''s limit).', 1;
    IF EXISTS (SELECT 1 FROM [PumpIT].sys.triggers t
               WHERE t.parent_id = OBJECT_ID(N'[PumpIT].dbo.BRN_Tills') AND t.is_disabled = 0)
        THROW 51000, 'AGORA:TRIGGER_ON:PumpIT has a trigger switched on for BRN_Tills, which would write beyond it. Nothing was written.', 1;

    DECLARE @WriteId UNIQUEIDENTIFIER = NEWID();
    DECLARE @Changed INT = 0;

    BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @Found BIT, @Cur NVARCHAR(30);
    SELECT @Found = 1, @Cur = t.TillDescription
    FROM [PumpIT].dbo.BRN_Tills t WITH (UPDLOCK, HOLDLOCK)
    WHERE t.SSBranchId = @BranchId AND t.TillNo = @No;

    IF @Found IS NULL
        THROW 51000, 'AGORA:NOT_FOUND:There is no such till at a site you may see.', 1;
    IF ISNULL(LTRIM(RTRIM(@Cur)), N'') <> ISNULL(LTRIM(RTRIM(@ExpectedTillDescription)), N'')
        THROW 51000, 'AGORA:STALE:This till was changed in PumpIT or by someone else since you opened the form. Open it again and redo your change.', 1;

    IF ISNULL(LTRIM(RTRIM(@Cur)), N'') <> @TillDescription
    BEGIN
        IF EXISTS (SELECT 1 FROM [PumpIT].dbo.BRN_Tills o
                   WHERE o.SSBranchId = @BranchId AND o.TillNo <> @No AND LTRIM(RTRIM(o.TillDescription)) = @TillDescription)
            THROW 51000, 'AGORA:DUPLICATE:Another till at this site already has that name.', 1;

        INSERT INTO agora.MasterWrite (BranchId, WriteId, Entity, PumpItTable, RowKey, RowLabel, KeyJson, Field,
                                       PriorValue, NewValue, Reason, WriteMode, State, CreatedAt, CreatedBy)
        VALUES (@BranchId, @WriteId, N'Till', N'BRN_Tills', CONVERT(NVARCHAR(200), @No),
                CONCAT(N'Till ', @No, N' · ', LTRIM(RTRIM(@Cur))),
                (SELECT @BranchId AS SSBranchId, @No AS TillNo FOR JSON PATH, WITHOUT_ARRAY_WRAPPER),
                N'TillDescription', CONVERT(NVARCHAR(400), @Cur), @TillDescription, @Reason, @WriteMode, 'active', SYSUTCDATETIME(), @UserId);
        SET @Changed = 1;

        UPDATE [PumpIT].dbo.BRN_Tills SET TillDescription = @TillDescription
         WHERE SSBranchId = @BranchId AND TillNo = @No;
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
                ELSE N'The new name was written to PumpIT''s BRN_Tills, the old one kept with it.' END AS Message,
           CASE WHEN @Changed = 0 THEN NULL ELSE @WriteId END AS WriteId;
END
