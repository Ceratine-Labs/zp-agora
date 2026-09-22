/* ============================================================================
 * agora.usp_Product_SetStockItemFlag — switch ONE behaviour flag on MANY lines.
 *
 * Read by:  Setup -> Trading rules -> Stock recon master, the batch action on
 *           a ticked selection (T025).
 * Writes:   agora.StockItem — AND NOTHING ELSE, exactly as
 *           usp_Product_SaveStockItem does. PumpIT.dbo.STK_StockMaster is the
 *           customer's table and is read-only to Agora for ever.
 * Reads:    agora.vw_StockItem (what is IN FORCE).
 *
 * ---------------------------------------------------------------------------
 * WHY IT IS NOT A LOOP OVER usp_Product_SaveStockItem
 * ---------------------------------------------------------------------------
 *
 * A save REPLACES the override whole and therefore needs all twenty-two
 * columns; the caller of a batch action has exactly one of them — the flag —
 * and the other twenty-one would have to be round-tripped through the browser
 * to come back. That is how a bulk action silently rewrites a price. So this
 * procedure never takes a payload it was not given: it reads what is in force
 * and changes the ONE column named, per line, set-based.
 *
 * ONE FLAG PER CALL, not a bag of them. A form that can send seven flags at
 * once has to distinguish "off" from "not sent" for each of them, and a bulk
 * write that cannot tell those apart is a bulk write that clears six flags
 * nobody mentioned. The screen asks which flag and then asks on or off; the
 * shape of the procedure is that question.
 *
 * ---------------------------------------------------------------------------
 * SETTING A FLAG ON A LEGACY LINE CREATES AN OVERRIDE
 * ---------------------------------------------------------------------------
 *
 * 7,447 of the estate's lines are the customer's own rows and Agora holds no
 * override for them, so "monitor these forty" cannot be a column update — the
 * row to update does not exist here. It is an INSERT seeded from
 * agora.vw_StockItem, which is the same thing `retire` already does for one
 * line and for the same reason: taking the base from what is in force is what
 * stops the caller re-sending twenty-two columns and getting one of them
 * wrong.
 *
 * FROM THAT MOMENT THE LINE IS AGORA'S. The `Held by` column on the grid says
 * so, and a later change to the customer's own master no longer reaches it.
 * That is the accepted cost of an override and it is not new here — but a
 * batch action can pay it forty times in one press, so the screen says it out
 * loud before the press.
 *
 * ---------------------------------------------------------------------------
 * A PARKED OVERRIDE REFUSES THE WHOLE BATCH
 * ---------------------------------------------------------------------------
 *
 * A parked override is switched off: the customer's row is what is in force
 * and the parked row is kept for its reason. Writing a flag into it would
 * change nothing anybody can see, and unparking it — which is what `save`
 * does for a single deliberate edit — would put a whole stale row back in
 * force across a selection nobody inspected. Neither is an outcome a person
 * ticking forty boxes meant, so the batch stops and names the count.
 *
 * Refusals: REASON_REQUIRED · BAD_FLAG · NOTHING_SELECTED · NO_SUCH_ITEM
 *           OVERRIDE_PARKED
 * ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Product_SetStockItemFlag]
    @ItemsJson NVARCHAR(MAX),
    @Flag      VARCHAR(40),
    @Value     BIT           = 1,
    @Reason    NVARCHAR(300) = NULL,
    @UserId    INT           = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    SET @Flag = LTRIM(RTRIM(ISNULL(@Flag, '')));
    SET @Reason = NULLIF(LTRIM(RTRIM(ISNULL(@Reason, ''))), '');
    SET @Value = ISNULL(@Value, 0);

    /* Same rule as a single save: a master data change with no reason is what
       the legacy estate is full of, and forty of them at once is worse. */
    IF @Reason IS NULL
        THROW 51000, 'AGORA:REASON_REQUIRED:Say why. A batch change with no reason is forty changes nobody can explain later.', 1;

    /* The whitelist is this list and the CASE expressions below, which are the
       same list written twice on purpose: there is no dynamic SQL here, so a
       flag name that got past this check would still update nothing rather
       than becoming a column reference. */
    IF @Flag NOT IN ('IsActive', 'IsMonitoredItem', 'IsDoCloseQtyCalc',
                     'IsAllowNegativeQtyIssued', 'IsAllowNegativeQtyClose',
                     'IsStockItemPreProduction', 'IsPreProductionItem')
        THROW 51000, 'AGORA:BAD_FLAG:That is not a stock line flag. It must be one of IsActive, IsMonitoredItem, IsDoCloseQtyCalc, IsAllowNegativeQtyIssued, IsAllowNegativeQtyClose, IsStockItemPreProduction or IsPreProductionItem.', 1;

    /* The selection. Keyed by (branch, item) because an item number is per
       branch — see the grid procedure — so a list of item numbers alone would
       be twenty-two different products. DISTINCT: a person can tick a row,
       clear, and tick it again, and the browser is not the place to guarantee
       it only arrives once. */
    DECLARE @Items TABLE (BranchId int NOT NULL, StockItemNo nvarchar(5) NOT NULL,
                          PRIMARY KEY (BranchId, StockItemNo));

    INSERT INTO @Items (BranchId, StockItemNo)
    SELECT DISTINCT j.BranchId, j.StockItemNo
    FROM OPENJSON(ISNULL(@ItemsJson, '[]'))
         WITH (BranchId int '$.BranchId', StockItemNo nvarchar(5) '$.StockItemNo') j
    WHERE j.BranchId IS NOT NULL
      AND NULLIF(LTRIM(RTRIM(ISNULL(j.StockItemNo, ''))), '') IS NOT NULL;

    DECLARE @Selected int = (SELECT COUNT(*) FROM @Items);

    IF @Selected = 0
        THROW 51000, 'AGORA:NOTHING_SELECTED:No lines were sent. Tick the rows you want changed first.', 1;

    /* Every line must exist at the site it was sent for. A selection that
       names one that does not is a stale page or a hand-built request, and
       either way it must not half-apply. */
    DECLARE @Missing int = (
        SELECT COUNT(*)
        FROM @Items t
        WHERE NOT EXISTS (
            SELECT 1 FROM [agora].[vw_StockItem] v
            WHERE v.BranchId = t.BranchId AND v.StockItemNo = t.StockItemNo
        )
    );

    IF @Missing > 0
        THROW 51000, 'AGORA:NO_SUCH_ITEM:Some of the selected lines no longer exist at the site they were listed under. Reload the grid and tick them again.', 1;

    DECLARE @Parked int = (
        SELECT COUNT(*)
        FROM @Items t
        JOIN [agora].[StockItem] o
          ON o.BranchId = t.BranchId AND o.StockItemNo = t.StockItemNo
        WHERE o.IsParked = 1
    );

    IF @Parked > 0
        THROW 51000, 'AGORA:OVERRIDE_PARKED:Some of the selected lines have a parked Agora override. Restore or clear those one at a time first — a batch action will not put a parked row back in force.', 1;

    DECLARE @Now datetime2(0) = SYSDATETIME();

    /* ---- lines Agora does not hold yet: write the override ------------- */

    INSERT INTO [agora].[StockItem]
        (BranchId, StockItemNo, StockItemDescription, AreaNo, [Location], POSCode,
         SellingPrice, PriceType, Factor, UOMCode, IssueMultiple, IssueMultiplePercentage,
         QtyVarAllowance, IsMonitoredItem, IsDoCloseQtyCalc, IsAllowNegativeQtyIssued,
         IsAllowNegativeQtyClose, IsStockItemPreProduction, IsPreProductionItem,
         PreProductionTypeNo, Ratio, ProduceLimitPercentage, IsActive, IsParked, Reason,
         CreatedAt, CreatedBy, UpdatedAt, UpdatedBy)
    SELECT v.BranchId, v.StockItemNo, v.StockItemDescription, v.AreaNo, v.PosSystem, v.POSCode,
           v.SellingPrice, v.PriceType, v.Factor, v.UOMCode, v.IssueMultiple, v.IssueMultiplePercentage,
           v.QtyVarAllowance,
           CASE WHEN @Flag = 'IsMonitoredItem'          THEN @Value ELSE v.IsMonitoredItem END,
           CASE WHEN @Flag = 'IsDoCloseQtyCalc'         THEN @Value ELSE v.IsDoCloseQtyCalc END,
           CASE WHEN @Flag = 'IsAllowNegativeQtyIssued' THEN @Value ELSE v.IsAllowNegativeQtyIssued END,
           CASE WHEN @Flag = 'IsAllowNegativeQtyClose'  THEN @Value ELSE v.IsAllowNegativeQtyClose END,
           CASE WHEN @Flag = 'IsStockItemPreProduction' THEN @Value ELSE v.IsStockItemPreProduction END,
           CASE WHEN @Flag = 'IsPreProductionItem'      THEN @Value ELSE v.IsPreProductionItem END,
           v.PreProductionTypeNo, v.Ratio, v.ProduceLimitPercentage,
           CASE WHEN @Flag = 'IsActive'                 THEN @Value ELSE v.IsActive END,
           0, @Reason, @Now, @UserId, @Now, @UserId
    FROM @Items t
    JOIN [agora].[vw_StockItem] v
      ON v.BranchId = t.BranchId AND v.StockItemNo = t.StockItemNo
    WHERE NOT EXISTS (
        SELECT 1 FROM [agora].[StockItem] o
        WHERE o.BranchId = t.BranchId AND o.StockItemNo = t.StockItemNo
    );

    DECLARE @Created int = @@ROWCOUNT;

    /* ---- lines Agora already holds: change the one column -------------- */

    UPDATE o
       SET IsMonitoredItem          = CASE WHEN @Flag = 'IsMonitoredItem'          THEN @Value ELSE o.IsMonitoredItem END,
           IsDoCloseQtyCalc         = CASE WHEN @Flag = 'IsDoCloseQtyCalc'         THEN @Value ELSE o.IsDoCloseQtyCalc END,
           IsAllowNegativeQtyIssued = CASE WHEN @Flag = 'IsAllowNegativeQtyIssued' THEN @Value ELSE o.IsAllowNegativeQtyIssued END,
           IsAllowNegativeQtyClose  = CASE WHEN @Flag = 'IsAllowNegativeQtyClose'  THEN @Value ELSE o.IsAllowNegativeQtyClose END,
           IsStockItemPreProduction = CASE WHEN @Flag = 'IsStockItemPreProduction' THEN @Value ELSE o.IsStockItemPreProduction END,
           IsPreProductionItem      = CASE WHEN @Flag = 'IsPreProductionItem'      THEN @Value ELSE o.IsPreProductionItem END,
           IsActive                 = CASE WHEN @Flag = 'IsActive'                 THEN @Value ELSE o.IsActive END,
           /* The reason on the row is the reason for the LAST change to it,
              which is what a single save already means. */
           Reason                   = @Reason,
           UpdatedAt                = @Now,
           UpdatedBy                = @UserId
      FROM [agora].[StockItem] o
      JOIN @Items t
        ON t.BranchId = o.BranchId AND t.StockItemNo = o.StockItemNo;

    DECLARE @Updated int = @@ROWCOUNT;

    SELECT CONVERT(bit, 1) AS Ok,
           'FLAGGED' AS Code,
           CONVERT(nvarchar(400),
               CONVERT(varchar(10), @Selected)
               + CASE WHEN @Selected = 1 THEN ' line ' ELSE ' lines ' END
               + CASE WHEN @Value = 1 THEN 'switched on for ' ELSE 'switched off for ' END
               + @Flag + '. '
               + CASE WHEN @Created > 0
                      THEN CONVERT(varchar(10), @Created) + ' of them now carry an Agora override that did not exist before.'
                      ELSE 'Every one of them already had an Agora override.'
                 END) AS [Message],
           CONVERT(bigint, @Selected) AS Id,
           @Created AS Created,
           @Updated AS Updated;
END
