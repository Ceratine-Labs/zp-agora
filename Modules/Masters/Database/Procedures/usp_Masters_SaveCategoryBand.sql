/* Change one POS category's GP and days-cover band in PumpIT (T022). A NAMED WRITE.

   CLAUDE.md rule 1; Ryan's standing rule of 12 Nov 2026, "agora is replacing
   pumpit, always write back to pumpit with confirmation" (PM decision
   01a1cecf-8e08). PumpIT's own margin reports and the exception rules read
   these bands, so the edit goes into PumpIT's row, not an Agora copy.

   WRITES exactly [PumpIT].dbo.SDK_CategoryGLCode.MINGP, .MAXGP,
   .MINDaysCover, .MAXDaysCover and .IsActive on ONE row, found by its key
   (SSBranchId, POSSystemId, Category — trimmed, as the listing shows it; the
   trimmed key is unique on the estate), in one transaction — and one
   agora.MasterWrite row per column it changed, the prior value as text and
   exactly as stored (ExactJson), so agora.usp_Masters_ReverseWrite can put it
   back. The category's name, profit centre, standard category and GL codes
   are not touched.

   @Key is the listing's RowKey, 'POSSystemId:Category'.

   Refuses (AGORA:{Code}):
     WRITES_OFF      @WriteMode is not 'live' (config masters.write_mode)
     DENIED          the caller lacks masters.categorygl.edit (@Authorized)
     REASON_REQUIRED fewer than @ReasonMin characters of reason
     NOT_FOUND       no such category, or a site outside @AllowedBranchIds
     BAND            a GP band outside 0–100, a cover band outside 0–366
                     days, or a minimum above its maximum
     TRIGGER_ON      PumpIT has a trigger switched on for this table
                     (trg_SDK_CategoryGLCode_Replicate writes Alteryx, which
                     is read-only to Agora). Disabled on 12 Nov 2026.
     STALE           the row changed since the form was drawn (@Expected*)
   Nothing changed is not a refusal: Code NOTHING_CHANGED, nothing written.

   Returns one row (Ok, Code, Message, WriteId).
   Called by Modules/Masters/Services/MasterWriteService. Deployed by v1__02pb. */
CREATE OR ALTER PROCEDURE agora.usp_Masters_SaveCategoryBand
    @BranchId INT,
    @Key NVARCHAR(200),
    @MinGP DECIMAL(9, 4),
    @MaxGP DECIMAL(9, 4),
    @MinDaysCover DECIMAL(9, 2),
    @MaxDaysCover DECIMAL(9, 2),
    @IsActive BIT,
    @ExpectedMinGP DECIMAL(9, 4),
    @ExpectedMaxGP DECIMAL(9, 4),
    @ExpectedMinDaysCover DECIMAL(9, 2),
    @ExpectedMaxDaysCover DECIMAL(9, 2),
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
    DECLARE @Colon INT = CHARINDEX(N':', ISNULL(@Key, N''));
    DECLARE @System INT = TRY_CONVERT(INT, LEFT(@Key, NULLIF(@Colon, 0) - 1));
    DECLARE @Category NVARCHAR(80) = LTRIM(RTRIM(SUBSTRING(@Key, @Colon + 1, 200)));

    IF ISNULL(@WriteMode, 'off') <> 'live'
        THROW 51000, 'AGORA:WRITES_OFF:Changing reference masters is switched off (masters.write_mode). Nothing was written.', 1;
    IF ISNULL(@Authorized, 0) = 0
        THROW 51000, 'AGORA:DENIED:You may not change GP and cover bands.', 1;
    IF LEN(ISNULL(@Reason, N'')) < ISNULL(@ReasonMin, 10)
        THROW 51000, 'AGORA:REASON_REQUIRED:Say why the band is being changed — it is kept with the change.', 1;
    IF @System IS NULL OR @Category = N''
       OR (NULLIF(LTRIM(RTRIM(@AllowedBranchIds)), '') IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM STRING_SPLIT(@AllowedBranchIds, ',') WHERE TRY_CONVERT(INT, value) = @BranchId))
        THROW 51000, 'AGORA:NOT_FOUND:There is no such category at a site you may see.', 1;
    IF @MinGP IS NULL OR @MaxGP IS NULL OR @MinGP < 0 OR @MaxGP > 100 OR @MinGP > @MaxGP
        THROW 51000, 'AGORA:BAND:A GP band runs from 0 to 100 per cent, and its minimum is not above its maximum.', 1;
    IF @MinDaysCover IS NULL OR @MaxDaysCover IS NULL OR @MinDaysCover < 0 OR @MaxDaysCover > 366 OR @MinDaysCover > @MaxDaysCover
        THROW 51000, 'AGORA:BAND:Days cover runs from 0 to 366, and its minimum is not above its maximum.', 1;
    IF EXISTS (SELECT 1 FROM [PumpIT].sys.triggers t
               WHERE t.parent_id = OBJECT_ID(N'[PumpIT].dbo.SDK_CategoryGLCode') AND t.is_disabled = 0)
        THROW 51000, 'AGORA:TRIGGER_ON:PumpIT has a trigger switched on for SDK_CategoryGLCode, which would write beyond it. Nothing was written.', 1;

    DECLARE @WriteId UNIQUEIDENTIFIER = NEWID();
    DECLARE @Changed INT = 0;

    BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @Raw VARCHAR(40), @CurMin FLOAT, @CurMax FLOAT, @CurMinC FLOAT, @CurMaxC FLOAT, @CurActive BIT;

    SELECT @Raw = g.Category, @CurMin = g.MINGP, @CurMax = g.MAXGP, @CurMinC = g.MINDaysCover,
           @CurMaxC = g.MAXDaysCover, @CurActive = g.IsActive
    FROM [PumpIT].dbo.SDK_CategoryGLCode g WITH (UPDLOCK, HOLDLOCK)
    WHERE g.SSBranchId = @BranchId AND g.POSSystemId = @System AND LTRIM(RTRIM(g.Category)) = @Category;

    IF @Raw IS NULL
        THROW 51000, 'AGORA:NOT_FOUND:There is no such category at a site you may see.', 1;
    IF CONVERT(DECIMAL(9, 4), @CurMin) <> @ExpectedMinGP OR CONVERT(DECIMAL(9, 4), @CurMax) <> @ExpectedMaxGP
       OR CONVERT(DECIMAL(9, 2), @CurMinC) <> @ExpectedMinDaysCover OR CONVERT(DECIMAL(9, 2), @CurMaxC) <> @ExpectedMaxDaysCover
       OR @CurActive <> @ExpectedIsActive
        THROW 51000, 'AGORA:STALE:This category was changed in PumpIT or by someone else since you opened the form. Open it again and redo your change.', 1;

    INSERT INTO agora.MasterWrite (BranchId, WriteId, Entity, PumpItTable, RowKey, RowLabel, KeyJson, Field,
                                   PriorValue, NewValue, ExactJson, Reason, WriteMode, State, CreatedAt, CreatedBy)
    SELECT @BranchId, @WriteId, N'CategoryGl', N'SDK_CategoryGLCode', CONCAT(@System, N':', @Category),
           CONCAT(N'POS system ', @System, N' · ', @Category),
           (SELECT @BranchId AS SSBranchId, @System AS POSSystemId, @Raw AS Category FOR JSON PATH, WITHOUT_ARRAY_WRAPPER),
           c.Field, c.PriorValue, c.NewValue, c.ExactJson, @Reason, @WriteMode, 'active', SYSUTCDATETIME(), @UserId
    FROM (VALUES
            (N'MINGP', FORMAT(CONVERT(DECIMAL(18, 4), @CurMin), '0.####'), FORMAT(@MinGP, '0.####'),
             CONCAT(N'{"exact":"', CONVERT(VARCHAR(30), @CurMin, 3), N'"}'), IIF(CONVERT(DECIMAL(9, 4), @CurMin) <> @MinGP, 1, 0)),
            (N'MAXGP', FORMAT(CONVERT(DECIMAL(18, 4), @CurMax), '0.####'), FORMAT(@MaxGP, '0.####'),
             CONCAT(N'{"exact":"', CONVERT(VARCHAR(30), @CurMax, 3), N'"}'), IIF(CONVERT(DECIMAL(9, 4), @CurMax) <> @MaxGP, 1, 0)),
            (N'MINDaysCover', FORMAT(CONVERT(DECIMAL(18, 2), @CurMinC), '0.##'), FORMAT(@MinDaysCover, '0.##'),
             CONCAT(N'{"exact":"', CONVERT(VARCHAR(30), @CurMinC, 3), N'"}'), IIF(CONVERT(DECIMAL(9, 2), @CurMinC) <> @MinDaysCover, 1, 0)),
            (N'MAXDaysCover', FORMAT(CONVERT(DECIMAL(18, 2), @CurMaxC), '0.##'), FORMAT(@MaxDaysCover, '0.##'),
             CONCAT(N'{"exact":"', CONVERT(VARCHAR(30), @CurMaxC, 3), N'"}'), IIF(CONVERT(DECIMAL(9, 2), @CurMaxC) <> @MaxDaysCover, 1, 0)),
            (N'IsActive', CONVERT(NVARCHAR(10), CONVERT(INT, @CurActive)), CONVERT(NVARCHAR(10), CONVERT(INT, @IsActive)),
             NULL, IIF(@CurActive <> @IsActive, 1, 0))
         ) c (Field, PriorValue, NewValue, ExactJson, IsChanged)
    WHERE c.IsChanged = 1;

    SET @Changed = @@ROWCOUNT;

    IF @Changed > 0
        UPDATE g
           SET MINGP        = CASE WHEN CONVERT(DECIMAL(9, 4), g.MINGP) <> @MinGP THEN CONVERT(FLOAT, @MinGP) ELSE g.MINGP END,
               MAXGP        = CASE WHEN CONVERT(DECIMAL(9, 4), g.MAXGP) <> @MaxGP THEN CONVERT(FLOAT, @MaxGP) ELSE g.MAXGP END,
               MINDaysCover = CASE WHEN CONVERT(DECIMAL(9, 2), g.MINDaysCover) <> @MinDaysCover THEN CONVERT(FLOAT, @MinDaysCover) ELSE g.MINDaysCover END,
               MAXDaysCover = CASE WHEN CONVERT(DECIMAL(9, 2), g.MAXDaysCover) <> @MaxDaysCover THEN CONVERT(FLOAT, @MaxDaysCover) ELSE g.MAXDaysCover END,
               IsActive     = @IsActive
          FROM [PumpIT].dbo.SDK_CategoryGLCode g
         WHERE g.SSBranchId = @BranchId AND g.POSSystemId = @System AND g.Category = @Raw;

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
                ELSE CONCAT(@Changed, N' change(s) written to PumpIT''s SDK_CategoryGLCode, each kept with its prior value.') END AS Message,
           CASE WHEN @Changed = 0 THEN NULL ELSE @WriteId END AS WriteId;
END
