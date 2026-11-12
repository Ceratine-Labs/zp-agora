/* Rename one shift at one site, or switch it on or off, in PumpIT (T022).
   A NAMED WRITE.

   CLAUDE.md rule 1; Ryan's standing rule of 12 Nov 2026 (PM decision
   01a1cecf-8e08). WRITES exactly [PumpIT].dbo.BRN_Shifts.ShiftDescription and
   .IsActive on ONE row, by (SSBranchId, ShiftNo), in one transaction, and one
   agora.MasterWrite row per column changed, so
   agora.usp_Masters_ReverseWrite can put it back.

   PER BRANCH, and ALWAYS BY (SSBranchId, ShiftNo): a bare ShiftNo join fans
   out across every site. The number is never changed — every cashup, Z-read,
   stock count and staff short names it. Never an INSERT or a DELETE.

   WHAT THE ACTIVE FLAG DOES IN PUMPIT: dbo.sp_GetBranchShifts lists only
   active shifts, so a shift switched off is no longer offered on PumpIT's
   capture screens at that site; switched on, it is.

   Refuses (AGORA:{Code}):
     WRITES_OFF, DENIED (masters.shifts.edit), REASON_REQUIRED, NOT_FOUND
     (also a site outside @AllowedBranchIds)
     DESCRIPTION  blank, or longer than PumpIT's 20 characters
     DUPLICATE    another shift at the site already has that name
     IN_USE       switching off a shift a counting area at the site still runs
                  (STK_Area.DayShift for 1, AfternoonShift for 2, NightShift
                  for 3 — the mapping PumpIT's sp_GetBranchAreaShift uses)
     TRIGGER_ON   a PumpIT trigger on BRN_Shifts is switched on
                  (trg_Sync_BRN_Shifts; disabled 12 Nov 2026)
     STALE        the row changed since the form was drawn
   Nothing changed is not a refusal: Code NOTHING_CHANGED.

   Returns one row (Ok, Code, Message, WriteId).
   Called by Modules/Masters/Services/MasterWriteService. Deployed by v1__02pd. */
CREATE OR ALTER PROCEDURE agora.usp_Masters_SaveShift
    @BranchId INT,
    @Key NVARCHAR(200),
    @ShiftDescription NVARCHAR(200),
    @IsActive BIT,
    @ExpectedShiftDescription NVARCHAR(400),
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
    SET @ShiftDescription = LTRIM(RTRIM(@ShiftDescription));
    DECLARE @No INT = TRY_CONVERT(INT, @Key);

    IF ISNULL(@WriteMode, 'off') <> 'live'
        THROW 51000, 'AGORA:WRITES_OFF:Changing reference masters is switched off (masters.write_mode). Nothing was written.', 1;
    IF ISNULL(@Authorized, 0) = 0
        THROW 51000, 'AGORA:DENIED:You may not change shifts.', 1;
    IF LEN(ISNULL(@Reason, N'')) < ISNULL(@ReasonMin, 10)
        THROW 51000, 'AGORA:REASON_REQUIRED:Say why the shift is being changed — it is kept with the change.', 1;
    IF @No IS NULL OR @IsActive IS NULL
       OR (NULLIF(LTRIM(RTRIM(@AllowedBranchIds)), '') IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM STRING_SPLIT(@AllowedBranchIds, ',') WHERE TRY_CONVERT(INT, value) = @BranchId))
        THROW 51000, 'AGORA:NOT_FOUND:There is no such shift at a site you may see.', 1;
    IF ISNULL(@ShiftDescription, N'') = N'' OR LEN(@ShiftDescription) > 20
        THROW 51000, 'AGORA:DESCRIPTION:A shift needs a name, at most 20 characters (PumpIT''s limit).', 1;
    IF EXISTS (SELECT 1 FROM [PumpIT].sys.triggers t
               WHERE t.parent_id = OBJECT_ID(N'[PumpIT].dbo.BRN_Shifts') AND t.is_disabled = 0)
        THROW 51000, 'AGORA:TRIGGER_ON:PumpIT has a trigger switched on for BRN_Shifts, which would write beyond it. Nothing was written.', 1;

    DECLARE @WriteId UNIQUEIDENTIFIER = NEWID();
    DECLARE @Changed INT = 0;

    BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @Found BIT, @CurDesc NVARCHAR(20), @Cur BIT;
    SELECT @Found = 1, @CurDesc = s.ShiftDescription, @Cur = s.IsActive
    FROM [PumpIT].dbo.BRN_Shifts s WITH (UPDLOCK, HOLDLOCK)
    WHERE s.SSBranchId = @BranchId AND s.ShiftNo = @No;

    IF @Found IS NULL
        THROW 51000, 'AGORA:NOT_FOUND:There is no such shift at a site you may see.', 1;
    IF ISNULL(LTRIM(RTRIM(@CurDesc)), N'') <> ISNULL(LTRIM(RTRIM(@ExpectedShiftDescription)), N'')
       OR @Cur <> ISNULL(@ExpectedIsActive, 0)
        THROW 51000, 'AGORA:STALE:This shift was changed in PumpIT or by someone else since you opened the form. Open it again and redo your change.', 1;

    DECLARE @Renamed BIT = IIF(ISNULL(LTRIM(RTRIM(@CurDesc)), N'') <> @ShiftDescription, 1, 0);
    IF @Renamed = 1 AND EXISTS (
            SELECT 1 FROM [PumpIT].dbo.BRN_Shifts o
            WHERE o.SSBranchId = @BranchId AND o.ShiftNo <> @No AND LTRIM(RTRIM(o.ShiftDescription)) = @ShiftDescription)
        THROW 51000, 'AGORA:DUPLICATE:Another shift at this site already has that name.', 1;
    IF @IsActive = 0 AND @Cur = 1 AND EXISTS (
            SELECT 1 FROM [PumpIT].dbo.STK_Area a
            WHERE a.SSBranchId = @BranchId
              AND ((@No = 1 AND a.DayShift = 1) OR (@No = 2 AND a.AfternoonShift = 1) OR (@No = 3 AND a.NightShift = 1)))
        THROW 51000, 'AGORA:IN_USE:A counting area at this site still runs this shift (STK_Area). Take the shift off the area first.', 1;

    INSERT INTO agora.MasterWrite (BranchId, WriteId, Entity, PumpItTable, RowKey, RowLabel, KeyJson, Field,
                                   PriorValue, NewValue, Reason, WriteMode, State, CreatedAt, CreatedBy)
    SELECT @BranchId, @WriteId, N'Shift', N'BRN_Shifts', CONVERT(NVARCHAR(200), @No),
           CONCAT(N'Shift ', @No, N' · ', LTRIM(RTRIM(@CurDesc))),
           (SELECT @BranchId AS SSBranchId, @No AS ShiftNo FOR JSON PATH, WITHOUT_ARRAY_WRAPPER),
           c.Field, c.PriorValue, c.NewValue, @Reason, @WriteMode, 'active', SYSUTCDATETIME(), @UserId
    FROM (VALUES
            (N'ShiftDescription', CONVERT(NVARCHAR(400), @CurDesc), CONVERT(NVARCHAR(400), @ShiftDescription), @Renamed),
            (N'IsActive', CONVERT(NVARCHAR(10), CONVERT(INT, @Cur)), CONVERT(NVARCHAR(10), CONVERT(INT, @IsActive)), IIF(@Cur <> @IsActive, 1, 0))
         ) c (Field, PriorValue, NewValue, IsChanged)
    WHERE c.IsChanged = 1;
    SET @Changed = @@ROWCOUNT;

    IF @Changed > 0
        UPDATE s
           SET ShiftDescription = CASE WHEN @Renamed = 1 THEN @ShiftDescription ELSE s.ShiftDescription END,
               IsActive         = @IsActive
          FROM [PumpIT].dbo.BRN_Shifts s
         WHERE s.SSBranchId = @BranchId AND s.ShiftNo = @No;

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
                ELSE CONCAT(@Changed, N' change(s) written to PumpIT''s BRN_Shifts, each kept with its prior value.') END AS Message,
           CASE WHEN @Changed = 0 THEN NULL ELSE @WriteId END AS WriteId;
END
