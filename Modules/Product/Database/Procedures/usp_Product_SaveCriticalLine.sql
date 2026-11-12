/* ============================================================================
 * agora.usp_Product_SaveCriticalLine — put a line on the critical list, take it
 * off, or restore the customer's answer.
 *
 * Writes:  agora.StockItemCritical, and one agora.MasterChange row per field
 *          the write moved (Entity 'CriticalLine', T027) in the same
 *          transaction — AND NOTHING ELSE. PumpIT's
 *          STK_StockMasterCritical is the customer's and is read-only to Agora.
 * Reads:   agora.vw_StockItemPos (to refuse a code the site does not sell),
 *          agora.Branch.
 *
 * FOUR ACTIONS, the same vocabulary as usp_Product_SaveStockItem so nobody has
 * to learn a second one:
 *
 *   save    write or replace the override, and switch it on
 *   remove  take the line OFF the critical list — save with IsActive = 0. Its
 *           own action because that is the thing a person actually means, and
 *           it must not be confused with parking
 *   park    switch the OVERRIDE off, so the customer's own row is back in force
 *   unpark  switch it back on
 *
 * THE ONE REFUSAL THAT IS NOT BOOKKEEPING: a critical line must name a code the
 * site actually sells. Every one of the 1,895 live rows has a matching row in
 * the POS cost file — the list has never contained a code the till does not
 * know — and a critical line for a code nothing can stock is an alert that can
 * never clear. So the code is checked against agora.vw_StockItemPos.
 *
 * IT IS DELIBERATELY NOT CHECKED AGAINST THE STOCK MASTER. 602 of the 1,895 —
 * 32% — have no stock master row at that site and POS system, and they are
 * legitimate: real products the site sells that nobody counts. Requiring one
 * would refuse a third of the list the customer already has.
 *
 * Refusals: BRANCH_REQUIRED · UNKNOWN_BRANCH · POS_CODE_REQUIRED
 *           BAD_POS_SYSTEM · REASON_REQUIRED · UNKNOWN_POS_CODE
 *           NOTHING_TO_PARK · NOTHING_TO_UNPARK · OVERRIDE_NOT_FOUND
 *           BAD_ACTION
 * ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Product_SaveCriticalLine]
    @BranchId    int,
    @PosSystem   nvarchar(10),
    @PosCode     nvarchar(16),
    @Action      varchar(10)   = 'save',
    @Description nvarchar(50)  = NULL,
    @Category    nvarchar(20)  = NULL,
    @IsActive    bit           = 1,
    @Reason      nvarchar(300) = NULL,
    @UserId      int           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    SET @Action = LOWER(ISNULL(NULLIF(LTRIM(RTRIM(@Action)), ''), 'save'));
    SET @Reason = NULLIF(LTRIM(RTRIM(ISNULL(@Reason, ''))), '');
    SET @PosSystem = NULLIF(LTRIM(RTRIM(ISNULL(@PosSystem, ''))), '');
    SET @PosCode = NULLIF(LTRIM(RTRIM(ISNULL(@PosCode, ''))), '');
    SET @Description = NULLIF(LTRIM(RTRIM(ISNULL(@Description, ''))), '');
    SET @Category = NULLIF(LTRIM(RTRIM(ISNULL(@Category, ''))), '');

    IF @BranchId IS NULL
        THROW 51000, 'AGORA:BRANCH_REQUIRED:A critical line belongs to one site.', 1;

    IF NOT EXISTS (SELECT 1 FROM [agora].[Branch] WHERE BranchId = @BranchId)
        THROW 51000, 'AGORA:UNKNOWN_BRANCH:That branch is not in Agora''s branch list.', 1;

    IF @PosCode IS NULL
        THROW 51000, 'AGORA:POS_CODE_REQUIRED:A critical line is identified by its POS code.', 1;

    IF @PosSystem IS NULL OR @PosSystem NOT IN ('ARCH', 'WINBRANCH', 'AURA', 'NAMOS', 'PILOT', 'ARCHLIQ')
        THROW 51000, 'AGORA:BAD_POS_SYSTEM:POS system must be one of ARCH, WINBRANCH, AURA, NAMOS, PILOT or ARCHLIQ.', 1;

    IF @Reason IS NULL
        THROW 51000, 'AGORA:REASON_REQUIRED:Say why. A change to the critical list with no reason is one nobody can explain later.', 1;

    DECLARE @Now datetime2(0) = SYSDATETIME();
    /* agora.MasterChange is UTC, like every other writer of it. */
    DECLARE @NowUtc datetime2(0) = SYSUTCDATETIME();
    DECLARE @Existing bigint = (
        SELECT Id FROM [agora].[StockItemCritical]
        WHERE BranchId = @BranchId AND PosSystem = @PosSystem AND PosCode = @PosCode
    );

    IF @Action = 'park'
    BEGIN
        IF @Existing IS NULL
            THROW 51000, 'AGORA:NOTHING_TO_PARK:There is no Agora override for this line — what you are looking at is the customer''s own list.', 1;

        IF EXISTS (SELECT 1 FROM [agora].[StockItemCritical] WHERE Id = @Existing AND BranchId = @BranchId AND IsParked = 1)
            THROW 51000, 'AGORA:NOTHING_TO_PARK:That override is already parked.', 1;
    END

    IF @Action = 'unpark'
    BEGIN
        IF @Existing IS NULL
            THROW 51000, 'AGORA:OVERRIDE_NOT_FOUND:There is no Agora override for this line to restore.', 1;

        IF NOT EXISTS (SELECT 1 FROM [agora].[StockItemCritical] WHERE Id = @Existing AND BranchId = @BranchId AND IsParked = 1)
            THROW 51000, 'AGORA:NOTHING_TO_UNPARK:That override is already in force.', 1;
    END

    IF @Action = 'remove'
        SET @IsActive = 0;

    IF @Action NOT IN ('save', 'remove', 'park', 'unpark')
        THROW 51000, 'AGORA:BAD_ACTION:Action must be save, remove, park or unpark.', 1;

    IF @Action IN ('save', 'remove')
    BEGIN
        /*
         * The code has to be one the site sells. Checked against the POS cost
         * file and NOT against the stock master — see the header: a third of the
         * live list has no stock master row and is perfectly legitimate.
         */
        IF NOT EXISTS (
            SELECT 1 FROM [agora].[vw_StockItemPos] p
            WHERE p.BranchId = @BranchId AND p.PosSystem = @PosSystem AND p.PosCode = @PosCode
        )
            THROW 51000, 'AGORA:UNKNOWN_POS_CODE:This site''s POS file has no such code on that POS system. A critical line for a code the till does not know is an alert that can never clear.', 1;

        /* Where the caller left the description or category out, take the POS
           file's rather than writing a NULL — an override replaces its legacy row
           whole, so a NULL here would blank what the customer had. */
        IF @Description IS NULL OR @Category IS NULL
        BEGIN
            SELECT @Description = ISNULL(@Description, LEFT(p.PosDescription, 50)),
                   @Category = ISNULL(@Category, LEFT(p.Category, 20))
            FROM [agora].[vw_StockItemPos] p
            WHERE p.BranchId = @BranchId AND p.PosSystem = @PosSystem AND p.PosCode = @PosCode;
        END
    END

    /* ---- the write, and one agora.MasterChange row per field it moved ---

       Before and after are read from agora.vw_StockItemCritical, what is IN
       FORCE, so a new override's "before" is the customer's own row and a park
       shows the customer's answer coming back. The override's own state is one
       more field, Override: None, In force or Parked (T027). */

    BEGIN TRANSACTION;

    SELECT v.* INTO #Before FROM [agora].[vw_StockItemCritical] v
    WHERE v.BranchId = @BranchId AND v.PosSystem = @PosSystem AND v.PosCode = @PosCode;
    DECLARE @OverrideBefore nvarchar(20) = ISNULL((
        SELECT CASE WHEN IsParked = 1 THEN N'Parked' ELSE N'In force' END
        FROM [agora].[StockItemCritical] WHERE BranchId = @BranchId AND Id = @Existing), N'None');
    DECLARE @Code varchar(20), @Message nvarchar(400);

    IF @Action IN ('park', 'unpark')
    BEGIN
        UPDATE [agora].[StockItemCritical]
           SET IsParked = CASE WHEN @Action = 'park' THEN 1 ELSE 0 END, Reason = @Reason, UpdatedAt = @Now, UpdatedBy = @UserId
         WHERE BranchId = @BranchId AND Id = @Existing;

        SELECT @Code = CASE WHEN @Action = 'park' THEN 'PARKED' ELSE 'UNPARKED' END,
               @Message = CASE WHEN @Action = 'park'
                    THEN N'The override is switched off. This line reads from the customer''s critical list again.'
                    ELSE N'The override is in force again.' END;
    END
    ELSE IF @Existing IS NULL
    BEGIN
        INSERT INTO [agora].[StockItemCritical]
            (BranchId, PosSystem, PosCode, Description, Category, IsActive, IsParked, Reason,
             CreatedAt, CreatedBy, UpdatedAt, UpdatedBy)
        VALUES
            (@BranchId, @PosSystem, @PosCode, @Description, @Category, @IsActive, 0, @Reason,
             @Now, @UserId, @Now, @UserId);

        SET @Existing = SCOPE_IDENTITY();

        SELECT @Code = CASE WHEN @Action = 'remove' THEN 'REMOVED' ELSE 'CREATED' END,
               @Message = CASE WHEN @Action = 'remove'
                    THEN N'The line is off the critical list. It stays in the customer''s own list and can be put back.'
                    ELSE N'The override is in force. This line is on the critical list from now on.' END;
    END
    ELSE
    BEGIN
        UPDATE [agora].[StockItemCritical]
           SET Description = @Description,
               Category    = @Category,
               IsActive    = @IsActive,
               /* A save on a parked override brings it back — editing something
                  and having it stay invisible is not an outcome anyone means. */
               IsParked    = 0,
               Reason      = @Reason,
               UpdatedAt   = @Now,
               UpdatedBy   = @UserId
         WHERE BranchId = @BranchId AND Id = @Existing;

        SELECT @Code = CASE WHEN @Action = 'remove' THEN 'REMOVED' ELSE 'UPDATED' END,
               @Message = CASE WHEN @Action = 'remove'
                    THEN N'The line is off the critical list. It stays in the customer''s own list and can be put back.'
                    ELSE N'The override is in force.' END;
    END

    SELECT v.* INTO #After FROM [agora].[vw_StockItemCritical] v
    WHERE v.BranchId = @BranchId AND v.PosSystem = @PosSystem AND v.PosCode = @PosCode;
    DECLARE @OverrideAfter nvarchar(20) = ISNULL((
        SELECT CASE WHEN IsParked = 1 THEN N'Parked' ELSE N'In force' END
        FROM [agora].[StockItemCritical] WHERE BranchId = @BranchId AND Id = @Existing), N'None');

    /* Binary comparison, so a change of case in a description is a change. */
    INSERT INTO [agora].[MasterChange] (BranchId, Entity, EntityId, Field, PriorValue, NewValue, Reason, Source, CreatedAt, CreatedBy)
    SELECT @BranchId, N'CriticalLine', @Existing, c.Field, c.PriorValue, c.NewValue, @Reason, N'edit', @NowUtc, @UserId
    FROM (SELECT 1 AS One) one
    LEFT JOIN #Before b ON 1 = 1
    LEFT JOIN #After a ON 1 = 1
    CROSS APPLY (VALUES
        (N'Override',    @OverrideBefore, @OverrideAfter),
        (N'Description', CONVERT(nvarchar(400), b.Description), CONVERT(nvarchar(400), a.Description)),
        (N'Category',    CONVERT(nvarchar(400), b.Category),    CONVERT(nvarchar(400), a.Category)),
        (N'IsActive',    CONVERT(nvarchar(400), b.IsActive),    CONVERT(nvarchar(400), a.IsActive))
    ) c (Field, PriorValue, NewValue)
    WHERE c.PriorValue COLLATE Latin1_General_BIN2 <> c.NewValue COLLATE Latin1_General_BIN2
       OR (c.PriorValue IS NULL AND c.NewValue IS NOT NULL)
       OR (c.PriorValue IS NOT NULL AND c.NewValue IS NULL);

    COMMIT TRANSACTION;

    SELECT CONVERT(bit, 1) AS Ok, @Code AS Code, @Message AS Message, @Existing AS Id;
END
