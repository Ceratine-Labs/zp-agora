/* ============================================================================
 * agora.usp_Product_SaveStockItem — change, add, park or restore one stock line.
 *
 * Writes:  agora.StockItem — AND NOTHING ELSE. In particular it never touches
 *          PumpIT.dbo.STK_StockMaster, which is the customer's table and is
 *          read-only to Agora for ever. That is the whole reason agora.StockItem
 *          exists (Ryan's decision, 20 September 2026).
 * Reads:   agora.vw_StockItem (to check a code against what is IN FORCE),
 *          agora.vw_StockArea, agora.Branch.
 *
 * FOUR ACTIONS, because reverting is not deleting:
 *
 *   save    write or replace the override for (branch, item), and switch it on
 *   park    switch an override off. The row stays, with the reason it was made
 *           and who made it, and agora.vw_StockItem stops seeing it — so the
 *           customer's own row, or nothing at all, is back in force
 *   unpark  switch it back on
 *   retire  the shorthand for what PumpIT cannot express at all: save with
 *           IsActive = 0. Its own action because "retire this line" is a thing
 *           a person means, and making them re-send twenty-two columns to say
 *           it would be an invitation to send one of them wrong
 *
 * AN OVERRIDE REPLACES ITS LEGACY ROW ENTIRELY rather than patching it, so
 * every column arrives on every save. A NULL here means "this item has no such
 * value", never "leave what was there" — see v1__05_product_tables' header for
 * why a per-column merge cannot express the difference.
 *
 * ---------------------------------------------------------------------------
 * THE POS CODE RULE, WHICH THE CUSTOMER'S OWN CHECK GETS WRONG
 * ---------------------------------------------------------------------------
 *
 * A POS code is unique per (branch, POS SYSTEM) — not per branch. Across the
 * live estate there are 97 collisions on (branch, code) and ZERO on (branch,
 * system, code): every one of them is a legitimate item carried in two POS
 * systems at one site. sp_DuplicatePOSCODE checks the two-column version and
 * therefore reports 97 false positives.
 *
 * It is checked against agora.vw_StockItem — what is IN FORCE — and not against
 * agora.StockItem. A code may collide with a legacy row this table has never
 * heard of, and a check that could not see that would let two live lines share
 * a code at one till. That is also why the index on agora.StockItem is not
 * unique: it would enforce a weaker rule while looking like it enforced this
 * one.
 *
 * ---------------------------------------------------------------------------
 * WHAT IS DELIBERATELY NOT VALIDATED
 * ---------------------------------------------------------------------------
 *
 * IssueMultiple, IssueMultiplePercentage, ProduceLimitPercentage and
 * IsDoCloseQtyCalc have NO server-side rule anywhere in PumpIT. The capture
 * procedure reads them out of the master and hands them to the screen; whatever
 * enforces them lives in the old ASP application. They are stored here exactly
 * as sent, and inventing a rule for them would be worse than having none —
 * a refusal nobody can explain is how a system loses the person using it.
 * Open question with ZP, carried on the task.
 *
 * SellingPrice IS NOT GUARDED EITHER, and that is worth knowing rather than
 * fixing here: for PriceType 'Selling Price' and 'Factor' the customer's
 * sp_UpdateSTK_StockMasterByPriceType recomputes it per branch out of
 * DBF_STDB, so a typed value on those two will be overwritten in PumpIT the
 * next time somebody runs it. 5,800 of 7,447 rows are in that position. The
 * screen says so; the procedure does not refuse it, because a person
 * deliberately overriding a derived price is exactly what an override is for.
 *
 * Refusals: BRANCH_REQUIRED · UNKNOWN_BRANCH · ITEM_REQUIRED · REASON_REQUIRED
 *           NO_SUCH_AREA · BAD_PRICE_TYPE · BAD_POS_SYSTEM · POS_CODE_TAKEN
 *           DESCRIPTION_REQUIRED · UOM_REQUIRED · ITEM_EXISTS
 *           NOTHING_TO_PARK · NOTHING_TO_UNPARK · OVERRIDE_NOT_FOUND
 * ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Product_SaveStockItem]
    @BranchId                 int,
    @StockItemNo              nvarchar(5),
    @Action                   varchar(10)    = 'save',
    @StockItemDescription     nvarchar(50)   = NULL,
    @AreaNo                   int            = NULL,
    @PosSystem                nvarchar(10)   = NULL,
    @POSCode                  nvarchar(50)   = NULL,
    @SellingPrice             decimal(18,4)  = NULL,
    @PriceType                nvarchar(20)   = NULL,
    @Factor                   decimal(18,4)  = 0,
    @UOMCode                  nvarchar(10)   = NULL,
    @IssueMultiple            decimal(18,4)  = 0,
    @IssueMultiplePercentage  decimal(18,4)  = 0,
    @QtyVarAllowance          decimal(18,4)  = 0,
    @IsMonitoredItem          bit            = 0,
    @IsDoCloseQtyCalc         bit            = 1,
    @IsAllowNegativeQtyIssued bit            = 0,
    @IsAllowNegativeQtyClose  bit            = 0,
    @IsStockItemPreProduction bit            = 0,
    @IsPreProductionItem      bit            = 0,
    @PreProductionTypeNo      int            = 0,
    @Ratio                    decimal(18,4)  = 0,
    @ProduceLimitPercentage   decimal(18,4)  = 0,
    @IsActive                 bit            = 1,
    @Reason                   nvarchar(300)  = NULL,
    @UserId                   int            = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    SET @Action = LOWER(ISNULL(NULLIF(LTRIM(RTRIM(@Action)), ''), 'save'));
    SET @Reason = NULLIF(LTRIM(RTRIM(ISNULL(@Reason, ''))), '');
    SET @StockItemNo = NULLIF(LTRIM(RTRIM(ISNULL(@StockItemNo, ''))), '');
    SET @StockItemDescription = NULLIF(LTRIM(RTRIM(ISNULL(@StockItemDescription, ''))), '');
    SET @POSCode = NULLIF(LTRIM(RTRIM(ISNULL(@POSCode, ''))), '');
    SET @PosSystem = NULLIF(LTRIM(RTRIM(ISNULL(@PosSystem, ''))), '');
    SET @PriceType = NULLIF(LTRIM(RTRIM(ISNULL(@PriceType, ''))), '');
    SET @UOMCode = NULLIF(LTRIM(RTRIM(ISNULL(@UOMCode, ''))), '');

    /* ---- what every action needs ------------------------------------- */

    IF @BranchId IS NULL
        THROW 51000, 'AGORA:BRANCH_REQUIRED:A stock line belongs to one site, so the branch is not optional.', 1;

    IF NOT EXISTS (SELECT 1 FROM [agora].[Branch] WHERE BranchId = @BranchId)
        THROW 51000, 'AGORA:UNKNOWN_BRANCH:That branch is not in Agora''s branch list.', 1;

    IF @StockItemNo IS NULL
        THROW 51000, 'AGORA:ITEM_REQUIRED:An item number is required. It is unique within this site only.', 1;

    /* A master data change with no reason is what the legacy estate is full
       of, and it is the thing the override table exists to end. Required on
       every action, including the ones that undo. */
    IF @Reason IS NULL
        THROW 51000, 'AGORA:REASON_REQUIRED:Say why. An override with no reason is a change nobody can explain later.', 1;

    DECLARE @Now datetime2(0) = SYSDATETIME();
    DECLARE @Existing bigint = (
        SELECT Id FROM [agora].[StockItem]
        WHERE BranchId = @BranchId AND StockItemNo = @StockItemNo
    );

    /* ---- park and unpark: no payload, just the switch ------------------ */

    IF @Action = 'park'
    BEGIN
        IF @Existing IS NULL
            THROW 51000, 'AGORA:NOTHING_TO_PARK:There is no Agora override for this line — what you are looking at is the customer''s own row.', 1;

        IF EXISTS (SELECT 1 FROM [agora].[StockItem] WHERE Id = @Existing AND BranchId = @BranchId AND IsParked = 1)
            THROW 51000, 'AGORA:NOTHING_TO_PARK:That override is already parked.', 1;

        UPDATE [agora].[StockItem]
           SET IsParked = 1, Reason = @Reason, UpdatedAt = @Now, UpdatedBy = @UserId
         WHERE BranchId = @BranchId AND Id = @Existing;

        SELECT CONVERT(bit, 1) AS Ok, 'PARKED' AS Code,
               'The override is switched off. This line reads from the customer''s master again, and the row stays here with the reason.' AS Message,
               @Existing AS Id;
        RETURN;
    END

    IF @Action = 'unpark'
    BEGIN
        IF @Existing IS NULL
            THROW 51000, 'AGORA:OVERRIDE_NOT_FOUND:There is no Agora override for this line to restore.', 1;

        IF NOT EXISTS (SELECT 1 FROM [agora].[StockItem] WHERE Id = @Existing AND BranchId = @BranchId AND IsParked = 1)
            THROW 51000, 'AGORA:NOTHING_TO_UNPARK:That override is already in force.', 1;

        /* Restoring an override can re-introduce a POS code collision that
           did not exist while it was parked, so the same check runs here. */
        DECLARE @ParkedSystem nvarchar(10), @ParkedCode nvarchar(50);
        SELECT @ParkedSystem = [Location], @ParkedCode = POSCode
        FROM [agora].[StockItem] WHERE BranchId = @BranchId AND Id = @Existing;

        IF @ParkedCode IS NOT NULL AND EXISTS (
            SELECT 1 FROM [agora].[vw_StockItem] v
            WHERE v.BranchId = @BranchId
              AND v.PosSystem = @ParkedSystem
              AND v.POSCode = @ParkedCode
              AND v.StockItemNo <> @StockItemNo
        )
            THROW 51000, 'AGORA:POS_CODE_TAKEN:Another live line at this site already uses that POS code on that POS system. Restoring this override would put two lines on one till code.', 1;

        UPDATE [agora].[StockItem]
           SET IsParked = 0, Reason = @Reason, UpdatedAt = @Now, UpdatedBy = @UserId
         WHERE BranchId = @BranchId AND Id = @Existing;

        SELECT CONVERT(bit, 1) AS Ok, 'UNPARKED' AS Code,
               'The override is in force again.' AS Message,
               @Existing AS Id;
        RETURN;
    END

    /* ---- retire: save, with the one column PumpIT has nowhere to put ---- */

    IF @Action = 'retire'
    BEGIN
        SET @IsActive = 0;

        /* Retiring a line that has no override yet means writing one, and it
           has to carry the whole row — so take what is in force as the base
           rather than making the caller re-send twenty-two columns to say one
           word. Sending them is how one of them arrives wrong. */
        IF @Existing IS NULL
        BEGIN
            SELECT @StockItemDescription     = v.StockItemDescription,
                   @AreaNo                   = v.AreaNo,
                   @PosSystem                = v.PosSystem,
                   @POSCode                  = v.POSCode,
                   @SellingPrice             = v.SellingPrice,
                   @PriceType                = v.PriceType,
                   @Factor                   = v.Factor,
                   @UOMCode                  = v.UOMCode,
                   @IssueMultiple            = v.IssueMultiple,
                   @IssueMultiplePercentage  = v.IssueMultiplePercentage,
                   @QtyVarAllowance          = v.QtyVarAllowance,
                   @IsMonitoredItem          = v.IsMonitoredItem,
                   @IsDoCloseQtyCalc         = v.IsDoCloseQtyCalc,
                   @IsAllowNegativeQtyIssued = v.IsAllowNegativeQtyIssued,
                   @IsAllowNegativeQtyClose  = v.IsAllowNegativeQtyClose,
                   @IsStockItemPreProduction = v.IsStockItemPreProduction,
                   @IsPreProductionItem      = v.IsPreProductionItem,
                   @PreProductionTypeNo      = v.PreProductionTypeNo,
                   @Ratio                    = v.Ratio,
                   @ProduceLimitPercentage   = v.ProduceLimitPercentage
            FROM [agora].[vw_StockItem] v
            WHERE v.BranchId = @BranchId AND v.StockItemNo = @StockItemNo;

            IF @@ROWCOUNT = 0
                THROW 51000, 'AGORA:OVERRIDE_NOT_FOUND:There is no such line at this site to retire.', 1;
        END
        ELSE
        BEGIN
            SELECT @StockItemDescription     = o.StockItemDescription,
                   @AreaNo                   = o.AreaNo,
                   @PosSystem                = o.[Location],
                   @POSCode                  = o.POSCode,
                   @SellingPrice             = o.SellingPrice,
                   @PriceType                = o.PriceType,
                   @Factor                   = o.Factor,
                   @UOMCode                  = o.UOMCode,
                   @IssueMultiple            = o.IssueMultiple,
                   @IssueMultiplePercentage  = o.IssueMultiplePercentage,
                   @QtyVarAllowance          = o.QtyVarAllowance,
                   @IsMonitoredItem          = o.IsMonitoredItem,
                   @IsDoCloseQtyCalc         = o.IsDoCloseQtyCalc,
                   @IsAllowNegativeQtyIssued = o.IsAllowNegativeQtyIssued,
                   @IsAllowNegativeQtyClose  = o.IsAllowNegativeQtyClose,
                   @IsStockItemPreProduction = o.IsStockItemPreProduction,
                   @IsPreProductionItem      = o.IsPreProductionItem,
                   @PreProductionTypeNo      = o.PreProductionTypeNo,
                   @Ratio                    = o.Ratio,
                   @ProduceLimitPercentage   = o.ProduceLimitPercentage
            FROM [agora].[StockItem] o
            WHERE o.BranchId = @BranchId AND o.Id = @Existing;
        END
    END

    /* ---- save (and retire, which has just filled itself in) ------------ */

    IF @Action NOT IN ('save', 'retire')
        THROW 51000, 'AGORA:BAD_ACTION:Action must be save, retire, park or unpark.', 1;

    IF @StockItemDescription IS NULL
        THROW 51000, 'AGORA:DESCRIPTION_REQUIRED:A stock line needs a description — it is what the counter reads on the sheet.', 1;

    IF @UOMCode IS NULL
        THROW 51000, 'AGORA:UOM_REQUIRED:A unit of measure is required.', 1;

    IF @PriceType IS NULL OR @PriceType NOT IN ('Selling Price', 'Set Price', 'Factor')
        THROW 51000, 'AGORA:BAD_PRICE_TYPE:Price type must be Selling Price, Set Price or Factor.', 1;

    IF @PosSystem IS NULL OR @PosSystem NOT IN ('ARCH', 'WINBRANCH', 'AURA', 'NAMOS', 'PILOT', 'ARCHLIQ')
        THROW 51000, 'AGORA:BAD_POS_SYSTEM:POS system must be one of ARCH, WINBRANCH, AURA, NAMOS, PILOT or ARCHLIQ.', 1;

    /* The area is per branch — AreaNo 1 is a different shelf at every site —
       so it is checked against THIS branch's areas and not against a list. */
    IF @AreaNo IS NULL OR NOT EXISTS (
        SELECT 1 FROM [agora].[vw_StockArea] a WHERE a.BranchId = @BranchId AND a.AreaNo = @AreaNo
    )
        THROW 51000, 'AGORA:NO_SUCH_AREA:That counting area does not exist at this site. Areas are numbered per site.', 1;

    /* The POS code rule — see the header. Checked against what is IN FORCE,
       excluding this line itself. */
    IF @POSCode IS NOT NULL AND EXISTS (
        SELECT 1 FROM [agora].[vw_StockItem] v
        WHERE v.BranchId = @BranchId
          AND v.PosSystem = @PosSystem
          AND v.POSCode = @POSCode
          AND v.StockItemNo <> @StockItemNo
    )
        THROW 51000, 'AGORA:POS_CODE_TAKEN:Another line at this site already uses that POS code on that POS system.', 1;

    IF @Existing IS NULL
    BEGIN
        INSERT INTO [agora].[StockItem]
            (BranchId, StockItemNo, StockItemDescription, AreaNo, [Location], POSCode,
             SellingPrice, PriceType, Factor, UOMCode, IssueMultiple, IssueMultiplePercentage,
             QtyVarAllowance, IsMonitoredItem, IsDoCloseQtyCalc, IsAllowNegativeQtyIssued,
             IsAllowNegativeQtyClose, IsStockItemPreProduction, IsPreProductionItem,
             PreProductionTypeNo, Ratio, ProduceLimitPercentage, IsActive, IsParked, Reason,
             CreatedAt, CreatedBy, UpdatedAt, UpdatedBy)
        VALUES
            (@BranchId, @StockItemNo, @StockItemDescription, @AreaNo, @PosSystem, @POSCode,
             @SellingPrice, @PriceType, @Factor, @UOMCode, @IssueMultiple, @IssueMultiplePercentage,
             @QtyVarAllowance, @IsMonitoredItem, @IsDoCloseQtyCalc, @IsAllowNegativeQtyIssued,
             @IsAllowNegativeQtyClose, @IsStockItemPreProduction, @IsPreProductionItem,
             @PreProductionTypeNo, @Ratio, @ProduceLimitPercentage, @IsActive, 0, @Reason,
             @Now, @UserId, @Now, @UserId);

        SET @Existing = SCOPE_IDENTITY();

        SELECT CONVERT(bit, 1) AS Ok,
               CASE WHEN @Action = 'retire' THEN 'RETIRED' ELSE 'CREATED' END AS Code,
               CASE WHEN @Action = 'retire'
                    THEN 'The line is retired in Agora. It stays in the customer''s estate and in every count that references it.'
                    ELSE 'The override is in force. This screen and every read of the stock master resolve to it from now on.'
               END AS Message,
               @Existing AS Id;
        RETURN;
    END

    UPDATE [agora].[StockItem]
       SET StockItemDescription     = @StockItemDescription,
           AreaNo                   = @AreaNo,
           [Location]               = @PosSystem,
           POSCode                  = @POSCode,
           SellingPrice             = @SellingPrice,
           PriceType                = @PriceType,
           Factor                   = @Factor,
           UOMCode                  = @UOMCode,
           IssueMultiple            = @IssueMultiple,
           IssueMultiplePercentage  = @IssueMultiplePercentage,
           QtyVarAllowance          = @QtyVarAllowance,
           IsMonitoredItem          = @IsMonitoredItem,
           IsDoCloseQtyCalc         = @IsDoCloseQtyCalc,
           IsAllowNegativeQtyIssued = @IsAllowNegativeQtyIssued,
           IsAllowNegativeQtyClose  = @IsAllowNegativeQtyClose,
           IsStockItemPreProduction = @IsStockItemPreProduction,
           IsPreProductionItem      = @IsPreProductionItem,
           PreProductionTypeNo      = @PreProductionTypeNo,
           Ratio                    = @Ratio,
           ProduceLimitPercentage   = @ProduceLimitPercentage,
           IsActive                 = @IsActive,
           /* A save on a parked override brings it back — editing something
              and having it stay invisible is not an outcome anyone means. */
           IsParked                 = 0,
           Reason                   = @Reason,
           UpdatedAt                = @Now,
           UpdatedBy                = @UserId
     WHERE BranchId = @BranchId AND Id = @Existing;

    SELECT CONVERT(bit, 1) AS Ok,
           CASE WHEN @Action = 'retire' THEN 'RETIRED' ELSE 'UPDATED' END AS Code,
           CASE WHEN @Action = 'retire'
                THEN 'The line is retired in Agora. It stays in the customer''s estate and in every count that references it.'
                ELSE 'The override is in force. This screen and every read of the stock master resolve to it from now on.'
           END AS Message,
           @Existing AS Id;
END
