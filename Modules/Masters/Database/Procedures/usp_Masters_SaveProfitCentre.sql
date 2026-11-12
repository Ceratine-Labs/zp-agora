/* Switch one profit centre on or off in PumpIT (T022). A NAMED WRITE.

   CLAUDE.md rule 1; Ryan's standing rule of 12 Nov 2026 (PM decision
   01a1cecf-8e08). WRITES exactly [PumpIT].dbo.SS_ProfitCentre.IsActive on
   ONE row, by ProfitCentreId, in one transaction, and one agora.MasterWrite
   row with the prior value, so agora.usp_Masters_ReverseWrite can put it back.

   THE NAME IS NOT CHANGED, ever, from Agora. PumpIT refers to a profit centre
   by its TEXT: SDK_CategoryGLCode.ProfitCentre, and the copy every POS load
   stamps onto MIST_Trans_Sales, MIST_Trans_MOPS, MIST_Trans_Customer and the
   _MIST_Transaction_* tables. A rename could not follow it into years of
   transactions in one transaction, and a half-followed rename splits every
   report by centre — so it is refused rather than attempted.

   Refuses (AGORA:{Code}):
     WRITES_OFF, DENIED (masters.profitcentres.edit), REASON_REQUIRED,
     NOT_FOUND
     IN_USE      switching off a centre ACTIVE POS categories still map to
                 (SDK_CategoryGLCode) — move or switch those off first
     TRIGGER_ON  a PumpIT trigger on SS_ProfitCentre is switched on
     STALE       the row changed since the form was drawn
   Nothing changed is not a refusal: Code NOTHING_CHANGED.

   @BranchId is the group entity (2): SS_ProfitCentre is group-wide.
   Returns one row (Ok, Code, Message, WriteId).
   Called by Modules/Masters/Services/MasterWriteService. Deployed by v1__02pb. */
CREATE OR ALTER PROCEDURE agora.usp_Masters_SaveProfitCentre
    @BranchId INT,
    @Key NVARCHAR(200),
    @IsActive BIT,
    @ExpectedIsActive BIT,
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
    DECLARE @Id INT = TRY_CONVERT(INT, @Key);

    IF ISNULL(@WriteMode, 'off') <> 'live'
        THROW 51000, 'AGORA:WRITES_OFF:Changing reference masters is switched off (masters.write_mode). Nothing was written.', 1;
    IF ISNULL(@Authorized, 0) = 0
        THROW 51000, 'AGORA:DENIED:You may not change profit centres.', 1;
    IF LEN(ISNULL(@Reason, N'')) < ISNULL(@ReasonMin, 10)
        THROW 51000, 'AGORA:REASON_REQUIRED:Say why the profit centre is being changed — it is kept with the change.', 1;
    IF EXISTS (SELECT 1 FROM [PumpIT].sys.triggers t
               WHERE t.parent_id = OBJECT_ID(N'[PumpIT].dbo.SS_ProfitCentre') AND t.is_disabled = 0)
        THROW 51000, 'AGORA:TRIGGER_ON:PumpIT has a trigger switched on for SS_ProfitCentre, which would write beyond it. Nothing was written.', 1;

    DECLARE @WriteId UNIQUEIDENTIFIER = NEWID();
    DECLARE @Changed INT = 0;

    BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @Name VARCHAR(40), @Cur BIT;
    SELECT @Name = p.ProfitCentre, @Cur = p.IsActive
    FROM [PumpIT].dbo.SS_ProfitCentre p WITH (UPDLOCK, HOLDLOCK)
    WHERE p.ProfitCentreId = @Id;

    IF @Name IS NULL
        THROW 51000, 'AGORA:NOT_FOUND:There is no such profit centre.', 1;
    IF @Cur <> @ExpectedIsActive
        THROW 51000, 'AGORA:STALE:This profit centre was changed in PumpIT or by someone else since you opened the form. Open it again and redo your change.', 1;
    IF @IsActive = 0 AND @Cur = 1 AND EXISTS (
            SELECT 1 FROM [PumpIT].dbo.SDK_CategoryGLCode g
            WHERE g.IsActive = 1 AND LTRIM(RTRIM(g.ProfitCentre)) = LTRIM(RTRIM(@Name)))
        THROW 51000, 'AGORA:IN_USE:Active POS categories still map to this profit centre. Move them or switch them off first.', 1;

    IF @Cur <> @IsActive
    BEGIN
        INSERT INTO agora.MasterWrite (BranchId, WriteId, Entity, PumpItTable, RowKey, RowLabel, KeyJson, Field,
                                       PriorValue, NewValue, Reason, WriteMode, State, CreatedAt, CreatedBy)
        VALUES (@BranchId, @WriteId, N'ProfitCentre', N'SS_ProfitCentre', CONVERT(NVARCHAR(200), @Id), LTRIM(RTRIM(@Name)),
                (SELECT @Id AS ProfitCentreId FOR JSON PATH, WITHOUT_ARRAY_WRAPPER), N'IsActive',
                CONVERT(NVARCHAR(10), CONVERT(INT, @Cur)), CONVERT(NVARCHAR(10), CONVERT(INT, @IsActive)),
                @Reason, @WriteMode, 'active', SYSUTCDATETIME(), @UserId);
        SET @Changed = 1;

        UPDATE [PumpIT].dbo.SS_ProfitCentre SET IsActive = @IsActive WHERE ProfitCentreId = @Id;
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
                ELSE N'Written to PumpIT''s SS_ProfitCentre, kept with its prior value.' END AS Message,
           CASE WHEN @Changed = 0 THEN NULL ELSE @WriteId END AS WriteId;
END
