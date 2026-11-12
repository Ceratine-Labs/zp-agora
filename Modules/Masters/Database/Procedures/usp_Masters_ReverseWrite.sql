/* Put back one press of a reference-master edit (T022). A NAMED WRITE.

   CLAUDE.md rule 1; PM decision 01a1cecf-8e08 (12 Nov 2026). The reversal
   of every usp_Masters_Save* procedure, from their one ledger,
   agora.MasterWrite: each column the press changed is set back to its prior
   value — exactly as it was stored (ExactJson) where PumpIT keeps a float —
   on the one row PumpIT's key names (KeyJson). No row is inserted or deleted.

     CategoryGl        [PumpIT].dbo.SDK_CategoryGLCode MINGP, MAXGP,
                       MINDaysCover, MAXDaysCover, IsActive
     ProfitCentre      [PumpIT].dbo.SS_ProfitCentre IsActive
     StandardCategory  [PumpIT].dbo.SS_StandardCategory SupplierGLCode,
                       SalesGLCode, IsActive
     Expense           [PumpIT].dbo.BRN_Expenses ExpenseDescription, GLCode,
                       Vat, IsActive
     Till              [PumpIT].dbo.BRN_Tills TillDescription
     Shift             [PumpIT].dbo.BRN_Shifts ShiftDescription, IsActive
     TransactionType   [PumpIT].dbo.BRN_TransactionType TypeDescription
     FuelType          [PumpIT].dbo.BRN_FuelType FuelTypeDescription
     Tank              [PumpIT].dbo.BRN_Tank TankDescription
     Pump              [PumpIT].dbo.BRN_Pump [Order], IsActive

   A text column is put back exactly as it was stored, trailing spaces and
   all; a NULL prior goes back as NULL.

   All rows of the press (@WriteId) or none, in one transaction; each ledger
   row is marked reversed with who, when and why.

   Refuses (AGORA:{Code}):
     WRITES_OFF, REASON_REQUIRED, NOT_FOUND (also a site outside
     @AllowedBranchIds)
     DENIED            the press's master is not in @AuthorizedEntities — the
                       masters the caller may edit (masters.{resource}.edit)
     ALREADY_REVERSED  nothing of this press is still in force
     TRIGGER_ON        a PumpIT trigger is switched on for the table
     MOVED             PumpIT no longer holds what the press wrote — somebody
                       changed it since — so putting the prior back would undo
                       their change too. Nothing is written.

   Returns one row (Ok, Code, Message, WriteId).
   Called by Modules/Masters/Services/MasterWriteService. Deployed by v1__02pb, and again by v1__02pd. */
CREATE OR ALTER PROCEDURE agora.usp_Masters_ReverseWrite
    @BranchId INT,
    @WriteId UNIQUEIDENTIFIER,
    @Reason NVARCHAR(400),
    @UserId INT,
    @AuthorizedEntities NVARCHAR(400) = NULL,
    @WriteMode VARCHAR(10) = 'off',
    @AllowedBranchIds NVARCHAR(MAX) = NULL,
    @ReasonMin INT = 10
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    SET @Reason = NULLIF(LTRIM(RTRIM(@Reason)), N'');

    IF ISNULL(@WriteMode, 'off') <> 'live'
        THROW 51000, 'AGORA:WRITES_OFF:Changing reference masters is switched off (masters.write_mode). Nothing was written.', 1;
    IF LEN(ISNULL(@Reason, N'')) < ISNULL(@ReasonMin, 10)
        THROW 51000, 'AGORA:REASON_REQUIRED:Say why the change is being put back — it is kept with the reversal.', 1;
    IF (NULLIF(LTRIM(RTRIM(@AllowedBranchIds)), '') IS NOT NULL
        AND NOT EXISTS (SELECT 1 FROM STRING_SPLIT(@AllowedBranchIds, ',') WHERE TRY_CONVERT(INT, value) = @BranchId))
       OR NOT EXISTS (SELECT 1 FROM agora.MasterWrite WHERE BranchId = @BranchId AND WriteId = @WriteId)
        THROW 51000, 'AGORA:NOT_FOUND:There is no such change at a site you may see.', 1;

    DECLARE @Entity NVARCHAR(40) = (SELECT TOP 1 Entity FROM agora.MasterWrite WHERE BranchId = @BranchId AND WriteId = @WriteId);
    IF NOT EXISTS (SELECT 1 FROM STRING_SPLIT(ISNULL(@AuthorizedEntities, N''), ',') WHERE LTRIM(RTRIM(value)) = @Entity)
        THROW 51000, 'AGORA:DENIED:You may not put this change back.', 1;
    IF NOT EXISTS (SELECT 1 FROM agora.MasterWrite WHERE BranchId = @BranchId AND WriteId = @WriteId AND State = 'active')
        THROW 51000, 'AGORA:ALREADY_REVERSED:This change has already been put back.', 1;

    DECLARE @Rows TABLE (Id BIGINT PRIMARY KEY, Field NVARCHAR(40), PriorValue NVARCHAR(400), NewValue NVARCHAR(400),
                         Exact NVARCHAR(40), KeyJson NVARCHAR(400));
    DECLARE @Count INT;

    BEGIN TRY
    BEGIN TRANSACTION;

    INSERT INTO @Rows
    SELECT w.Id, w.Field, w.PriorValue, w.NewValue, JSON_VALUE(w.ExactJson, '$.exact'), w.KeyJson
    FROM agora.MasterWrite w WITH (UPDLOCK, HOLDLOCK)
    WHERE w.BranchId = @BranchId AND w.WriteId = @WriteId AND w.State = 'active';
    SET @Count = @@ROWCOUNT;

    IF @Count = 0
        THROW 51000, 'AGORA:ALREADY_REVERSED:This change has already been put back.', 1;

    IF @Entity = N'CategoryGl'
    BEGIN
        IF EXISTS (SELECT 1 FROM [PumpIT].sys.triggers t
                   WHERE t.parent_id = OBJECT_ID(N'[PumpIT].dbo.SDK_CategoryGLCode') AND t.is_disabled = 0)
            THROW 51000, 'AGORA:TRIGGER_ON:PumpIT has a trigger switched on for SDK_CategoryGLCode, which would write beyond it. Nothing was put back.', 1;

        DECLARE @Json NVARCHAR(400) = (SELECT TOP 1 KeyJson FROM @Rows);
        DECLARE @System INT = JSON_VALUE(@Json, '$.POSSystemId'), @Raw VARCHAR(40) = JSON_VALUE(@Json, '$.Category');
        DECLARE @CurMin FLOAT, @CurMax FLOAT, @CurMinC FLOAT, @CurMaxC FLOAT, @CurActive BIT, @Found INT;

        SELECT @Found = 1, @CurMin = g.MINGP, @CurMax = g.MAXGP, @CurMinC = g.MINDaysCover, @CurMaxC = g.MAXDaysCover, @CurActive = g.IsActive
        FROM [PumpIT].dbo.SDK_CategoryGLCode g WITH (UPDLOCK, HOLDLOCK)
        WHERE g.SSBranchId = @BranchId AND g.POSSystemId = @System AND g.Category = @Raw;

        -- PumpIT must still hold exactly what the press wrote.
        IF @Found IS NULL OR EXISTS (
            SELECT 1 FROM @Rows r
            WHERE ISNULL(r.NewValue, N'') <> ISNULL(CASE r.Field
                WHEN N'MINGP'        THEN FORMAT(CONVERT(DECIMAL(18, 4), @CurMin), '0.####')
                WHEN N'MAXGP'        THEN FORMAT(CONVERT(DECIMAL(18, 4), @CurMax), '0.####')
                WHEN N'MINDaysCover' THEN FORMAT(CONVERT(DECIMAL(18, 2), @CurMinC), '0.##')
                WHEN N'MAXDaysCover' THEN FORMAT(CONVERT(DECIMAL(18, 2), @CurMaxC), '0.##')
                WHEN N'IsActive'     THEN CONVERT(NVARCHAR(10), CONVERT(INT, @CurActive)) END, N''))
            THROW 51000, 'AGORA:MOVED:PumpIT no longer holds what this change wrote — somebody has changed the category since. Nothing was put back.', 1;

        UPDATE g
           SET MINGP        = ISNULL((SELECT CONVERT(FLOAT, r.Exact) FROM @Rows r WHERE r.Field = N'MINGP'), g.MINGP),
               MAXGP        = ISNULL((SELECT CONVERT(FLOAT, r.Exact) FROM @Rows r WHERE r.Field = N'MAXGP'), g.MAXGP),
               MINDaysCover = ISNULL((SELECT CONVERT(FLOAT, r.Exact) FROM @Rows r WHERE r.Field = N'MINDaysCover'), g.MINDaysCover),
               MAXDaysCover = ISNULL((SELECT CONVERT(FLOAT, r.Exact) FROM @Rows r WHERE r.Field = N'MAXDaysCover'), g.MAXDaysCover),
               IsActive     = ISNULL((SELECT CONVERT(BIT, r.PriorValue) FROM @Rows r WHERE r.Field = N'IsActive'), g.IsActive)
          FROM [PumpIT].dbo.SDK_CategoryGLCode g
         WHERE g.SSBranchId = @BranchId AND g.POSSystemId = @System AND g.Category = @Raw;
    END
    ELSE IF @Entity = N'ProfitCentre'
    BEGIN
        IF EXISTS (SELECT 1 FROM [PumpIT].sys.triggers t
                   WHERE t.parent_id = OBJECT_ID(N'[PumpIT].dbo.SS_ProfitCentre') AND t.is_disabled = 0)
            THROW 51000, 'AGORA:TRIGGER_ON:PumpIT has a trigger switched on for SS_ProfitCentre, which would write beyond it. Nothing was put back.', 1;

        DECLARE @Pc INT = JSON_VALUE((SELECT TOP 1 KeyJson FROM @Rows), '$.ProfitCentreId');
        DECLARE @PcActive BIT = (SELECT p.IsActive FROM [PumpIT].dbo.SS_ProfitCentre p WITH (UPDLOCK, HOLDLOCK) WHERE p.ProfitCentreId = @Pc);

        IF @PcActive IS NULL OR EXISTS (SELECT 1 FROM @Rows r WHERE r.Field = N'IsActive' AND r.NewValue <> CONVERT(NVARCHAR(10), CONVERT(INT, @PcActive)))
            THROW 51000, 'AGORA:MOVED:PumpIT no longer holds what this change wrote — somebody has changed the profit centre since. Nothing was put back.', 1;

        UPDATE [PumpIT].dbo.SS_ProfitCentre
           SET IsActive = (SELECT CONVERT(BIT, r.PriorValue) FROM @Rows r WHERE r.Field = N'IsActive')
         WHERE ProfitCentreId = @Pc;
    END
    ELSE IF @Entity = N'StandardCategory'
    BEGIN
        IF EXISTS (SELECT 1 FROM [PumpIT].sys.triggers t
                   WHERE t.parent_id = OBJECT_ID(N'[PumpIT].dbo.SS_StandardCategory') AND t.is_disabled = 0)
            THROW 51000, 'AGORA:TRIGGER_ON:PumpIT has a trigger switched on for SS_StandardCategory, which would write beyond it. Nothing was put back.', 1;

        DECLARE @Sc INT = JSON_VALUE((SELECT TOP 1 KeyJson FROM @Rows), '$.StandardCategoryId');
        DECLARE @ScSup VARCHAR(50), @ScSales VARCHAR(50), @ScActive BIT;
        SELECT @ScSup = s.SupplierGLCode, @ScSales = s.SalesGLCode, @ScActive = s.IsActive
        FROM [PumpIT].dbo.SS_StandardCategory s WITH (UPDLOCK, HOLDLOCK) WHERE s.StandardCategoryId = @Sc;

        IF @ScActive IS NULL OR EXISTS (
            SELECT 1 FROM @Rows r
            WHERE ISNULL(r.NewValue, N'') <> ISNULL(CASE r.Field
                WHEN N'SupplierGLCode' THEN LTRIM(RTRIM(@ScSup))
                WHEN N'SalesGLCode'    THEN LTRIM(RTRIM(@ScSales))
                WHEN N'IsActive'       THEN CONVERT(NVARCHAR(10), CONVERT(INT, @ScActive)) END, N''))
            THROW 51000, 'AGORA:MOVED:PumpIT no longer holds what this change wrote — somebody has changed the standard category since. Nothing was put back.', 1;

        UPDATE s
           SET SupplierGLCode = ISNULL((SELECT CONVERT(VARCHAR(50), r.PriorValue) FROM @Rows r WHERE r.Field = N'SupplierGLCode'), s.SupplierGLCode),
               SalesGLCode    = ISNULL((SELECT CONVERT(VARCHAR(50), r.PriorValue) FROM @Rows r WHERE r.Field = N'SalesGLCode'), s.SalesGLCode),
               IsActive       = ISNULL((SELECT CONVERT(BIT, r.PriorValue) FROM @Rows r WHERE r.Field = N'IsActive'), s.IsActive)
          FROM [PumpIT].dbo.SS_StandardCategory s
         WHERE s.StandardCategoryId = @Sc;
    END
    ELSE IF @Entity = N'Expense'
    BEGIN
        IF EXISTS (SELECT 1 FROM [PumpIT].sys.triggers t
                   WHERE t.parent_id = OBJECT_ID(N'[PumpIT].dbo.BRN_Expenses') AND t.is_disabled = 0)
            THROW 51000, 'AGORA:TRIGGER_ON:PumpIT has a trigger switched on for BRN_Expenses, which would write beyond it. Nothing was put back.', 1;

        DECLARE @ExNo INT = JSON_VALUE((SELECT TOP 1 KeyJson FROM @Rows), '$.ExpenseNo');
        DECLARE @ExFound BIT, @ExDesc NVARCHAR(60), @ExGl NVARCHAR(8), @ExVat INT, @ExActive BIT;
        SELECT @ExFound = 1, @ExDesc = e.ExpenseDescription, @ExGl = e.GLCode, @ExVat = e.Vat, @ExActive = e.IsActive
        FROM [PumpIT].dbo.BRN_Expenses e WITH (UPDLOCK, HOLDLOCK) WHERE e.SSBranchId = @BranchId AND e.ExpenseNo = @ExNo;

        IF @ExFound IS NULL OR EXISTS (
            SELECT 1 FROM @Rows r
            WHERE ISNULL(r.NewValue, N'') <> ISNULL(CASE r.Field
                WHEN N'ExpenseDescription' THEN LTRIM(RTRIM(@ExDesc))
                WHEN N'GLCode'             THEN LTRIM(RTRIM(@ExGl))
                WHEN N'Vat'                THEN CONVERT(NVARCHAR(10), @ExVat)
                WHEN N'IsActive'           THEN CONVERT(NVARCHAR(10), CONVERT(INT, @ExActive)) END, N''))
            THROW 51000, 'AGORA:MOVED:PumpIT no longer holds what this change wrote — somebody has changed the expense code since. Nothing was put back.', 1;

        UPDATE e
           SET ExpenseDescription = CASE WHEN EXISTS (SELECT 1 FROM @Rows r WHERE r.Field = N'ExpenseDescription')
                                         THEN (SELECT CONVERT(NVARCHAR(60), r.PriorValue) FROM @Rows r WHERE r.Field = N'ExpenseDescription') ELSE e.ExpenseDescription END,
               GLCode   = CASE WHEN EXISTS (SELECT 1 FROM @Rows r WHERE r.Field = N'GLCode')
                               THEN (SELECT CONVERT(NVARCHAR(8), r.PriorValue) FROM @Rows r WHERE r.Field = N'GLCode') ELSE e.GLCode END,
               Vat      = CASE WHEN EXISTS (SELECT 1 FROM @Rows r WHERE r.Field = N'Vat')
                               THEN (SELECT CONVERT(INT, r.PriorValue) FROM @Rows r WHERE r.Field = N'Vat') ELSE e.Vat END,
               IsActive = CASE WHEN EXISTS (SELECT 1 FROM @Rows r WHERE r.Field = N'IsActive')
                               THEN (SELECT CONVERT(BIT, r.PriorValue) FROM @Rows r WHERE r.Field = N'IsActive') ELSE e.IsActive END
          FROM [PumpIT].dbo.BRN_Expenses e
         WHERE e.SSBranchId = @BranchId AND e.ExpenseNo = @ExNo;
    END
    ELSE IF @Entity = N'Till'
    BEGIN
        IF EXISTS (SELECT 1 FROM [PumpIT].sys.triggers t
                   WHERE t.parent_id = OBJECT_ID(N'[PumpIT].dbo.BRN_Tills') AND t.is_disabled = 0)
            THROW 51000, 'AGORA:TRIGGER_ON:PumpIT has a trigger switched on for BRN_Tills, which would write beyond it. Nothing was put back.', 1;

        DECLARE @TiNo INT = JSON_VALUE((SELECT TOP 1 KeyJson FROM @Rows), '$.TillNo');
        DECLARE @TiFound BIT, @TiDesc NVARCHAR(30);
        SELECT @TiFound = 1, @TiDesc = t.TillDescription
        FROM [PumpIT].dbo.BRN_Tills t WITH (UPDLOCK, HOLDLOCK) WHERE t.SSBranchId = @BranchId AND t.TillNo = @TiNo;

        IF @TiFound IS NULL OR EXISTS (SELECT 1 FROM @Rows r WHERE r.Field = N'TillDescription'
                                       AND ISNULL(r.NewValue, N'') <> ISNULL(LTRIM(RTRIM(@TiDesc)), N''))
            THROW 51000, 'AGORA:MOVED:PumpIT no longer holds what this change wrote — somebody has renamed the till since. Nothing was put back.', 1;

        UPDATE [PumpIT].dbo.BRN_Tills
           SET TillDescription = (SELECT CONVERT(NVARCHAR(30), r.PriorValue) FROM @Rows r WHERE r.Field = N'TillDescription')
         WHERE SSBranchId = @BranchId AND TillNo = @TiNo;
    END
    ELSE IF @Entity = N'Shift'
    BEGIN
        IF EXISTS (SELECT 1 FROM [PumpIT].sys.triggers t
                   WHERE t.parent_id = OBJECT_ID(N'[PumpIT].dbo.BRN_Shifts') AND t.is_disabled = 0)
            THROW 51000, 'AGORA:TRIGGER_ON:PumpIT has a trigger switched on for BRN_Shifts, which would write beyond it. Nothing was put back.', 1;

        DECLARE @ShNo INT = JSON_VALUE((SELECT TOP 1 KeyJson FROM @Rows), '$.ShiftNo');
        DECLARE @ShFound BIT, @ShDesc NVARCHAR(20), @ShActive BIT;
        SELECT @ShFound = 1, @ShDesc = s.ShiftDescription, @ShActive = s.IsActive
        FROM [PumpIT].dbo.BRN_Shifts s WITH (UPDLOCK, HOLDLOCK) WHERE s.SSBranchId = @BranchId AND s.ShiftNo = @ShNo;

        IF @ShFound IS NULL OR EXISTS (
            SELECT 1 FROM @Rows r
            WHERE ISNULL(r.NewValue, N'') <> ISNULL(CASE r.Field
                WHEN N'ShiftDescription' THEN LTRIM(RTRIM(@ShDesc))
                WHEN N'IsActive'         THEN CONVERT(NVARCHAR(10), CONVERT(INT, @ShActive)) END, N''))
            THROW 51000, 'AGORA:MOVED:PumpIT no longer holds what this change wrote — somebody has changed the shift since. Nothing was put back.', 1;

        UPDATE s
           SET ShiftDescription = CASE WHEN EXISTS (SELECT 1 FROM @Rows r WHERE r.Field = N'ShiftDescription')
                                       THEN (SELECT CONVERT(NVARCHAR(20), r.PriorValue) FROM @Rows r WHERE r.Field = N'ShiftDescription') ELSE s.ShiftDescription END,
               IsActive         = CASE WHEN EXISTS (SELECT 1 FROM @Rows r WHERE r.Field = N'IsActive')
                                       THEN (SELECT CONVERT(BIT, r.PriorValue) FROM @Rows r WHERE r.Field = N'IsActive') ELSE s.IsActive END
          FROM [PumpIT].dbo.BRN_Shifts s
         WHERE s.SSBranchId = @BranchId AND s.ShiftNo = @ShNo;
    END
    ELSE IF @Entity = N'TransactionType'
    BEGIN
        IF EXISTS (SELECT 1 FROM [PumpIT].sys.triggers t
                   WHERE t.parent_id = OBJECT_ID(N'[PumpIT].dbo.BRN_TransactionType') AND t.is_disabled = 0)
            THROW 51000, 'AGORA:TRIGGER_ON:PumpIT has a trigger switched on for BRN_TransactionType, which would write beyond it. Nothing was put back.', 1;

        DECLARE @TtKey NVARCHAR(2) = JSON_VALUE((SELECT TOP 1 KeyJson FROM @Rows), '$.TypeCode');
        DECLARE @TtFound BIT, @TtCur NVARCHAR(50);
        SELECT @TtFound = 1, @TtCur = x.TypeDescription
        FROM [PumpIT].dbo.BRN_TransactionType x WITH (UPDLOCK, HOLDLOCK) WHERE x.SSBranchId = @BranchId AND x.TypeCode = @TtKey;

        IF @TtFound IS NULL OR EXISTS (SELECT 1 FROM @Rows r WHERE r.Field = N'TypeDescription'
                                       AND ISNULL(r.NewValue, N'') <> ISNULL(LTRIM(RTRIM(@TtCur)), N''))
            THROW 51000, 'AGORA:MOVED:PumpIT no longer holds what this change wrote — somebody has changed the transaction type since. Nothing was put back.', 1;

        UPDATE x SET TypeDescription = (SELECT CONVERT(NVARCHAR(50), r.PriorValue) FROM @Rows r WHERE r.Field = N'TypeDescription')
          FROM [PumpIT].dbo.BRN_TransactionType x
         WHERE x.SSBranchId = @BranchId AND x.TypeCode = @TtKey;
    END
    ELSE IF @Entity = N'FuelType'
    BEGIN
        IF EXISTS (SELECT 1 FROM [PumpIT].sys.triggers t
                   WHERE t.parent_id = OBJECT_ID(N'[PumpIT].dbo.BRN_FuelType') AND t.is_disabled = 0)
            THROW 51000, 'AGORA:TRIGGER_ON:PumpIT has a trigger switched on for BRN_FuelType, which would write beyond it. Nothing was put back.', 1;

        DECLARE @FtKey INT = JSON_VALUE((SELECT TOP 1 KeyJson FROM @Rows), '$.FuelTypeNo');
        DECLARE @FtFound BIT, @FtCur NVARCHAR(35);
        SELECT @FtFound = 1, @FtCur = x.FuelTypeDescription
        FROM [PumpIT].dbo.BRN_FuelType x WITH (UPDLOCK, HOLDLOCK) WHERE x.SSBranchId = @BranchId AND x.FuelTypeNo = @FtKey;

        IF @FtFound IS NULL OR EXISTS (SELECT 1 FROM @Rows r WHERE r.Field = N'FuelTypeDescription'
                                       AND ISNULL(r.NewValue, N'') <> ISNULL(LTRIM(RTRIM(@FtCur)), N''))
            THROW 51000, 'AGORA:MOVED:PumpIT no longer holds what this change wrote — somebody has changed the fuel type since. Nothing was put back.', 1;

        UPDATE x SET FuelTypeDescription = (SELECT CONVERT(NVARCHAR(35), r.PriorValue) FROM @Rows r WHERE r.Field = N'FuelTypeDescription')
          FROM [PumpIT].dbo.BRN_FuelType x
         WHERE x.SSBranchId = @BranchId AND x.FuelTypeNo = @FtKey;
    END
    ELSE IF @Entity = N'Tank'
    BEGIN
        IF EXISTS (SELECT 1 FROM [PumpIT].sys.triggers t
                   WHERE t.parent_id = OBJECT_ID(N'[PumpIT].dbo.BRN_Tank') AND t.is_disabled = 0)
            THROW 51000, 'AGORA:TRIGGER_ON:PumpIT has a trigger switched on for BRN_Tank, which would write beyond it. Nothing was put back.', 1;

        DECLARE @TkKey NVARCHAR(4) = JSON_VALUE((SELECT TOP 1 KeyJson FROM @Rows), '$.TankNo');
        DECLARE @TkFound BIT, @TkCur NVARCHAR(50);
        SELECT @TkFound = 1, @TkCur = x.TankDescription
        FROM [PumpIT].dbo.BRN_Tank x WITH (UPDLOCK, HOLDLOCK) WHERE x.SSBranchId = @BranchId AND x.TankNo = @TkKey;

        IF @TkFound IS NULL OR EXISTS (SELECT 1 FROM @Rows r WHERE r.Field = N'TankDescription'
                                       AND ISNULL(r.NewValue, N'') <> ISNULL(LTRIM(RTRIM(@TkCur)), N''))
            THROW 51000, 'AGORA:MOVED:PumpIT no longer holds what this change wrote — somebody has changed the tank since. Nothing was put back.', 1;

        UPDATE x SET TankDescription = (SELECT CONVERT(NVARCHAR(50), r.PriorValue) FROM @Rows r WHERE r.Field = N'TankDescription')
          FROM [PumpIT].dbo.BRN_Tank x
         WHERE x.SSBranchId = @BranchId AND x.TankNo = @TkKey;
    END
    ELSE IF @Entity = N'Pump'
    BEGIN
        IF EXISTS (SELECT 1 FROM [PumpIT].sys.triggers t
                   WHERE t.parent_id = OBJECT_ID(N'[PumpIT].dbo.BRN_Pump') AND t.is_disabled = 0)
            THROW 51000, 'AGORA:TRIGGER_ON:PumpIT has a trigger switched on for BRN_Pump, which would write beyond it. Nothing was put back.', 1;

        DECLARE @PuNo NVARCHAR(4) = JSON_VALUE((SELECT TOP 1 KeyJson FROM @Rows), '$.PumpNo');
        DECLARE @PuFound BIT, @PuOrder INT, @PuActive BIT;
        SELECT @PuFound = 1, @PuOrder = p.[Order], @PuActive = p.IsActive
        FROM [PumpIT].dbo.BRN_Pump p WITH (UPDLOCK, HOLDLOCK) WHERE p.SSBranchId = @BranchId AND p.PumpNo = @PuNo;

        IF @PuFound IS NULL OR EXISTS (
            SELECT 1 FROM @Rows r
            WHERE ISNULL(r.NewValue, N'') <> ISNULL(CASE r.Field
                WHEN N'Order'    THEN CONVERT(NVARCHAR(10), @PuOrder)
                WHEN N'IsActive' THEN CONVERT(NVARCHAR(10), CONVERT(INT, @PuActive)) END, N''))
            THROW 51000, 'AGORA:MOVED:PumpIT no longer holds what this change wrote — somebody has changed the pump since. Nothing was put back.', 1;

        UPDATE p
           SET [Order]  = CASE WHEN EXISTS (SELECT 1 FROM @Rows r WHERE r.Field = N'Order')
                               THEN (SELECT CONVERT(INT, r.PriorValue) FROM @Rows r WHERE r.Field = N'Order') ELSE p.[Order] END,
               IsActive = CASE WHEN EXISTS (SELECT 1 FROM @Rows r WHERE r.Field = N'IsActive')
                               THEN (SELECT CONVERT(BIT, r.PriorValue) FROM @Rows r WHERE r.Field = N'IsActive') ELSE p.IsActive END
          FROM [PumpIT].dbo.BRN_Pump p
         WHERE p.SSBranchId = @BranchId AND p.PumpNo = @PuNo;
    END
    ELSE
        THROW 51000, 'AGORA:NOT_FOUND:This change is of a kind Agora cannot put back.', 1;

    UPDATE w
       SET State = 'reversed', ReversedAt = SYSUTCDATETIME(), ReversedBy = @UserId, ReversalReason = @Reason,
           UpdatedAt = SYSUTCDATETIME(), UpdatedBy = @UserId
      FROM agora.MasterWrite w
      JOIN @Rows r ON r.Id = w.Id
     WHERE w.BranchId = @BranchId;

    COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        DECLARE @Err NVARCHAR(2048) = ERROR_MESSAGE();
        THROW 51000, @Err, 1;
    END CATCH

    SELECT CONVERT(BIT, 1) AS Ok, 'REVERSED' AS Code,
           CONCAT(@Count, N' change(s) put back in PumpIT.') AS Message, @WriteId AS WriteId;
END
