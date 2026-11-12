/* Change one pump's capture order or switch it on or off in PumpIT (T022).
   A NAMED WRITE.

   CLAUDE.md rule 1; Ryan's standing rule of 12 Nov 2026 (PM decision
   01a1cecf-8e08). WRITES exactly [PumpIT].dbo.BRN_Pump.[Order] and .IsActive
   on ONE row, by (SSBranchId, PumpNo), in one transaction, and one
   agora.MasterWrite row per column changed, so
   agora.usp_Masters_ReverseWrite can put it back.

   NEVER THE MAPPING. FuelTypeNo and TankNo say which grade and tank a pump's
   litres belong to, and PumpIT's volume reports join every reading of every
   day to the pump's CURRENT row — a change there would rewrite history, not
   move one pump. POSType and POSIdentifier are how the forecourt controller's
   readings reach the pump (sp_CaptureMechReading matches FPID and GRADEID out
   of POSIdentifier). None of them is offered. Never an INSERT or a DELETE.

   WHAT THE TWO COLUMNS DO IN PUMPIT: sp_CaptureMechReading lists the site's
   ACTIVE pumps for the daily meter reading, in [Order]. Switched off, a pump
   is no longer asked for a reading; its readings and sales stay as they are.

   PER BRANCH: pump 1 is a different pump at every site.

   Refuses (AGORA:{Code}):
     WRITES_OFF, DENIED (masters.pumps.edit), REASON_REQUIRED, NOT_FOUND
     (also a site outside @AllowedBranchIds)
     ORDER        not a whole number from 0 to 999
     TRIGGER_ON   a PumpIT trigger on BRN_Pump is switched on
                  (trg_BRN_Pump_Replicate; disabled 12 Nov 2026)
     STALE        the row changed since the form was drawn
   Nothing changed is not a refusal: Code NOTHING_CHANGED.

   Returns one row (Ok, Code, Message, WriteId).
   Called by Modules/Masters/Services/MasterWriteService. Deployed by v1__02pd. */
CREATE OR ALTER PROCEDURE agora.usp_Masters_SavePump
    @BranchId INT,
    @Key NVARCHAR(200),
    @PumpOrder DECIMAL(9, 2),
    @IsActive BIT,
    @ExpectedPumpOrder DECIMAL(9, 2),
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
    DECLARE @No NVARCHAR(4) = NULLIF(LTRIM(RTRIM(@Key)), N'');

    IF ISNULL(@WriteMode, 'off') <> 'live'
        THROW 51000, 'AGORA:WRITES_OFF:Changing reference masters is switched off (masters.write_mode). Nothing was written.', 1;
    IF ISNULL(@Authorized, 0) = 0
        THROW 51000, 'AGORA:DENIED:You may not change pumps.', 1;
    IF LEN(ISNULL(@Reason, N'')) < ISNULL(@ReasonMin, 10)
        THROW 51000, 'AGORA:REASON_REQUIRED:Say why the pump is being changed — it is kept with the change.', 1;
    IF @No IS NULL OR LEN(LTRIM(RTRIM(@Key))) > 4 OR @IsActive IS NULL
       OR (NULLIF(LTRIM(RTRIM(@AllowedBranchIds)), '') IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM STRING_SPLIT(@AllowedBranchIds, ',') WHERE TRY_CONVERT(INT, value) = @BranchId))
        THROW 51000, 'AGORA:NOT_FOUND:There is no such pump at a site you may see.', 1;
    IF @PumpOrder IS NULL OR @PumpOrder < 0 OR @PumpOrder > 999 OR @PumpOrder <> FLOOR(@PumpOrder)
        THROW 51000, 'AGORA:ORDER:The capture order is a whole number from 0 to 999.', 1;
    IF EXISTS (SELECT 1 FROM [PumpIT].sys.triggers t
               WHERE t.parent_id = OBJECT_ID(N'[PumpIT].dbo.BRN_Pump') AND t.is_disabled = 0)
        THROW 51000, 'AGORA:TRIGGER_ON:PumpIT has a trigger switched on for BRN_Pump, which would write beyond it. Nothing was written.', 1;

    DECLARE @Order INT = CONVERT(INT, @PumpOrder);
    DECLARE @WriteId UNIQUEIDENTIFIER = NEWID();
    DECLARE @Changed INT = 0;

    BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @Found BIT, @CurOrder INT, @Cur BIT;
    SELECT @Found = 1, @CurOrder = p.[Order], @Cur = p.IsActive
    FROM [PumpIT].dbo.BRN_Pump p WITH (UPDLOCK, HOLDLOCK)
    WHERE p.SSBranchId = @BranchId AND p.PumpNo = @No;

    IF @Found IS NULL
        THROW 51000, 'AGORA:NOT_FOUND:There is no such pump at a site you may see.', 1;
    IF ISNULL(@CurOrder, -1) <> ISNULL(@ExpectedPumpOrder, -1) OR @Cur <> ISNULL(@ExpectedIsActive, 0)
        THROW 51000, 'AGORA:STALE:This pump was changed in PumpIT or by someone else since you opened the form. Open it again and redo your change.', 1;

    INSERT INTO agora.MasterWrite (BranchId, WriteId, Entity, PumpItTable, RowKey, RowLabel, KeyJson, Field,
                                   PriorValue, NewValue, Reason, WriteMode, State, CreatedAt, CreatedBy)
    SELECT @BranchId, @WriteId, N'Pump', N'BRN_Pump', @No, CONCAT(N'Pump ', @No),
           (SELECT @BranchId AS SSBranchId, @No AS PumpNo FOR JSON PATH, WITHOUT_ARRAY_WRAPPER),
           c.Field, c.PriorValue, c.NewValue, @Reason, @WriteMode, 'active', SYSUTCDATETIME(), @UserId
    FROM (VALUES
            (N'Order', CONVERT(NVARCHAR(400), @CurOrder), CONVERT(NVARCHAR(400), @Order), IIF(ISNULL(@CurOrder, -1) <> @Order, 1, 0)),
            (N'IsActive', CONVERT(NVARCHAR(10), CONVERT(INT, @Cur)), CONVERT(NVARCHAR(10), CONVERT(INT, @IsActive)), IIF(@Cur <> @IsActive, 1, 0))
         ) c (Field, PriorValue, NewValue, IsChanged)
    WHERE c.IsChanged = 1;
    SET @Changed = @@ROWCOUNT;

    IF @Changed > 0
        UPDATE p SET [Order] = @Order, IsActive = @IsActive
          FROM [PumpIT].dbo.BRN_Pump p
         WHERE p.SSBranchId = @BranchId AND p.PumpNo = @No;

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
                ELSE CONCAT(@Changed, N' change(s) written to PumpIT''s BRN_Pump, each kept with its prior value.') END AS Message,
           CASE WHEN @Changed = 0 THEN NULL ELSE @WriteId END AS WriteId;
END
