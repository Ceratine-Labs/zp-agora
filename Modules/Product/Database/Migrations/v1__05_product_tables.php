<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Product — the stock master, in a table Agora is allowed to write (slot 05).
 *
 * WHY THIS EXISTS. The stock master is `PumpIT.dbo.STK_StockMaster`: 7,447
 * rows across 22 sites, keyed (SSBranchId, StockItemNo), and still being added
 * to — the newest row on 20 September 2026 was nine days old. PumpIT is
 * read-only to Agora, so an editor over it cannot exist. Ryan chose the way
 * through on 20 September 2026, the same one he chose for the extraction
 * configuration on 8 September: an Agora-owned table that SHADOWS the legacy
 * rows, and a view that decides which of the two a caller sees.
 *
 * A ROW HERE REPLACES ITS LEGACY ROW ENTIRELY. Not a patch, not a column-wise
 * COALESCE — the same rule, and for the same reasons, as `agora.ReconCriteria`
 * (v1__13h). Every column arrives on every save; a NULL means "this item has
 * no such value", never "leave what was there".
 *
 * AN OVERRIDE MAY ALSO ADD AN ITEM THE CUSTOMER NEVER HAD. There is no
 * separate id for that case, because unlike the recon criteria the natural key
 * here is the business key on both sides: (BranchId, StockItemNo) identifies
 * the row in PumpIT and in this table. The view's `Source` column says which
 * of the two answered.
 *
 * TWO FLAGS THAT SOUND ALIKE AND ARE NOT. Getting these confused would be the
 * expensive mistake, so they are named apart rather than both called IsActive
 * the way ReconCriteria's single flag is:
 *
 *   · `IsParked`  — the OVERRIDE is switched off. The row stays, with the
 *                   reason it was made and who made it, and the view stops
 *                   seeing it, so the customer's own row is back in force.
 *                   This is ReconCriteria's `IsActive`, inverted and renamed.
 *   · `IsActive`  — the ITEM is in use or retired. This is a property of the
 *                   stock line itself and PumpIT has nowhere to put it: the
 *                   legacy table has no active flag at all, which is why
 *                   4,434 of 7,447 items had not been counted anywhere since
 *                   1 August 2026 and nothing could hide them. Retiring an
 *                   item in Agora IS writing an override with IsActive = 0.
 *
 * There is deliberately no soft delete on top of those two. Three ways to make
 * a row disappear, each meaning something slightly different, is how a screen
 * ends up lying to somebody.
 *
 * Nothing here writes to PumpIT. The legacy table is read exactly as it was —
 * including by `trg_Sync_STK_StockMaster`, which mirrors every write to it
 * into `Alteryx.dbo.Rev_STK_StockMaster` with a SELECT *, and which Agora must
 * therefore never be tempted to add a column to.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * The columns mirror STK_StockMaster's own, for the reason v1__13h
         * gives: an override has to read as a diff against the row it
         * replaces, and the customer greps for `POSCode` and `QtyVarAllowance`
         * in SSMS. Two deliberate departures, both so a reader is not misled:
         *
         *  · The legacy `isMonitoredItem` and `isPreProductionItem` are
         *    lower-cased in PumpIT and PascalCase here, like every other
         *    boolean in Agora. The letter case is the only difference.
         *  · Every FLOAT becomes DECIMAL(18,4). PumpIT stores Factor 2.6 as
         *    2.6000000000000001 and a grid renders exactly that; the values
         *    themselves are two-place quantities, so decimal is both the
         *    honest type and the readable one. The views cast the legacy side
         *    to match, which is why a legacy row and an override of it can sit
         *    in one UNION without float's type precedence dragging both back.
         */
        MigrationHelper::table('StockItem', function (Blueprint $table) {
            // The item number. NVARCHAR(5), per branch, and referenced by 6.2
            // million rows of STK_StockReconLine — renumbering is not
            // available in either direction.
            $table->string('StockItemNo', 5);
            $table->string('StockItemDescription', 50)->nullable();

            // The counting area, which is per branch: STK_Area holds 250 rows
            // across the estate and AreaNo 1 means a different shelf at every
            // site. Validated against agora.vw_StockArea by the save proc.
            $table->integer('AreaNo');

            /*
             * The POS system this item belongs to — ARCH, WINBRANCH, AURA,
             * NAMOS, PILOT or ARCHLIQ. PumpIT calls the column `Location` and
             * it is NOT a place; the views expose it as `PosSystem` so that
             * nobody downstream has to know that. Kept under the legacy name
             * HERE, and only here, so the override still reads as a diff.
             */
            $table->string('Location', 10);

            // The till code or barcode. Unique per (branch, Location) — NOT
            // per branch, which is what the customer's own sp_DuplicatePOSCODE
            // checks and why it reports 97 false positives on rows that are
            // simply carried in two POS systems at one site.
            $table->string('POSCode', 50)->nullable();

            /*
             * DECIMAL(18,4) rather than MigrationHelper::money's (18,2),
             * because the legacy column is MONEY and fifty live prices carry
             * more than two decimal places. Two places would silently restate
             * what fifty stock lines are counted at.
             */
            $table->decimal('SellingPrice', 18, 4)->nullable();

            /*
             * How the price is arrived at, and it is not always typed:
             * `Set Price` is somebody's decision, while `Selling Price` and
             * `Factor` are recomputed per branch by the customer's
             * sp_UpdateSTK_StockMasterByPriceType out of DBF_STDB —
             * STDSELL x (1 + VAT) and STDCOST x Factor respectively. That is
             * 5,800 of 7,447 rows, so an editor that treats SellingPrice as a
             * plain input will have it overwritten from under the user.
             */
            $table->string('PriceType', 20);
            $table->decimal('Factor', 18, 4)->default(0);

            $table->string('UOMCode', 10);
            $table->decimal('IssueMultiple', 18, 4)->default(0);
            $table->decimal('IssueMultiplePercentage', 18, 4)->default(0);

            // The variance a count may show before it is flagged. Read by the
            // customer's sp_CheckQtyVarGreaterThanZeroandMoreThanQtyMaxAllowance.
            $table->decimal('QtyVarAllowance', 18, 4)->default(0);

            $table->boolean('IsMonitoredItem')->default(false);
            $table->boolean('IsDoCloseQtyCalc')->default(true);
            $table->boolean('IsAllowNegativeQtyIssued')->default(false);
            $table->boolean('IsAllowNegativeQtyClose')->default(false);

            // Pre-production: the item is made here rather than bought.
            $table->boolean('IsStockItemPreProduction')->default(false);
            $table->boolean('IsPreProductionItem')->default(false);
            $table->integer('PreProductionTypeNo')->default(0);
            $table->decimal('Ratio', 18, 4)->default(0);
            $table->decimal('ProduceLimitPercentage', 18, 4)->default(0);

            // The item's own state — see the header. PumpIT has no such column.
            $table->boolean('IsActive')->default(true);

            // The OVERRIDE's state — see the header. Parking restores the
            // customer's row without losing the record of what we did.
            $table->boolean('IsParked')->default(false);

            // Required by the save procedure on every write. A master data
            // change with no reason is what the legacy estate is full of.
            $table->string('Reason', 300)->nullable();
        });

        // One override per legacy item. BranchId leads, as every key must.
        MigrationHelper::naturalKey('StockItem', ['BranchId', 'StockItemNo']);
        MigrationHelper::rowVersion('StockItem');

        /*
         * The POS-code lookup, and deliberately NOT unique.
         *
         * The rule is that a POS code is unique per (branch, POS system) — but
         * it has to hold across what the VIEW resolves, not across this table,
         * because a code may collide with a legacy row this table has never
         * heard of. A unique index here would enforce a weaker rule than the
         * real one while looking like it enforced the real one, and would
         * additionally block a parked override from keeping the code it used
         * to hold. usp_Product_SaveStockItem checks it against
         * agora.vw_StockItem, where the question actually lives.
         */
        $schema = config('agora.schema');
        DB::statement("
            CREATE INDEX [IX_StockItem_PosCode]
                ON [{$schema}].[StockItem] ([BranchId], [Location], [POSCode]);
        ");

        /*
         * WHEN EACH LINE WAS LAST COUNTED — derived, and materialised because
         * of what it is derived from.
         *
         * Ryan asked for a "last counted" column on the listing (20 September
         * 2026). The source is STK_StockReconLine, 6.2 million rows, and the
         * honest shapes are two: a correlated MAX per grid row, which is
         * absurd, or the whole rollup recomputed per page, which measured at
         * ~4 seconds against the customer's instance for a grid that has to
         * feel instant. So it is a table, rebuilt on demand.
         *
         * The rebuild is cheap — the same measurement says the full GROUP BY
         * over 6.2M lines produces its 7,094 pairs in about four seconds — so
         * there is no incremental path and no watermark to get wrong. The
         * procedure truncates and refills, and RefreshedAt is on every row so
         * a screen can say how old the answer is rather than implying it is
         * live.
         *
         * A NULL LastCountedAt is a real answer, not a gap: 4,434 of 7,447
         * items had not been counted anywhere since 1 August 2026, and
         * branches 30 and 31 have never appeared in the count lines at all.
         * The column exists to make that visible, so nothing here invents a
         * zero to fill it.
         */
        MigrationHelper::table('StockItemCountStat', function (Blueprint $table) {
            $table->string('StockItemNo', 5);
            $table->dateTime('LastCountedAt', 0)->nullable();
            $table->integer('CountLinesAllTime')->default(0);
            $table->integer('CountLines90')->default(0);
            $table->dateTime('RefreshedAt', 0);
        });
        MigrationHelper::naturalKey('StockItemCountStat', ['BranchId', 'StockItemNo']);

        $this->views();

        MigrationHelper::recordVersion(
            '1.5',
            'Product: an Agora-owned override for the per-branch stock master, the view that '
            .'decides whether a caller sees ours or the customer\'s, the materialised last-counted '
            .'rollup, and the read-only views over counting areas, the critical list and the POS cost file.'
        );
    }

    /**
     * The views. Four, each answering exactly one question.
     */
    protected function views(): void
    {
        $schema = config('agora.schema');
        $erp = config('agora.source_databases.erp');

        /*
         * 1. The customer's own rows, unshadowed.
         *
         * `vw_StockItem` answers "what is in force", which is what a screen
         * needs. The EDITOR needs the other question too — "what does the
         * customer's own table say" — because the accepted cost of an override
         * is two sources of truth, and a screen that could not show both would
         * make a divergence invisible. Same two-view shape as the recon
         * criteria, and for the same reason.
         *
         * The casts are the point of this view existing as more than a SELECT:
         * every FLOAT and MONEY column is brought to DECIMAL(18,4) HERE, so
         * both arms of vw_StockItem agree on type. Without them float's type
         * precedence would win the UNION and drag the override side back to
         * 2.6000000000000001.
         *
         * `IsActive` is a constant 1. The legacy table has no active flag, so
         * every legacy row is in use until an override says otherwise — that
         * is a statement about the schema, not an assumption about the stock.
         */
        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_LegacyStockItem] AS
            SELECT m.SSBranchId                                   AS BranchId,
                   m.StockItemNo,
                   m.StockItemDescription,
                   m.AreaNo,
                   m.[Location]                                   AS PosSystem,
                   m.POSCode,
                   CONVERT(decimal(18,4), m.SellingPrice)         AS SellingPrice,
                   m.PriceType,
                   CONVERT(decimal(18,4), m.Factor)               AS Factor,
                   m.UOMCode,
                   CONVERT(decimal(18,4), m.IssueMultiple)        AS IssueMultiple,
                   CONVERT(decimal(18,4), m.IssueMultiplePercentage) AS IssueMultiplePercentage,
                   CONVERT(decimal(18,4), m.QtyVarAllowance)      AS QtyVarAllowance,
                   CONVERT(bit, ISNULL(m.isMonitoredItem, 0))     AS IsMonitoredItem,
                   m.IsDoCloseQtyCalc,
                   m.IsAllowNegativeQtyIssued,
                   m.IsAllowNegativeQtyClose,
                   m.IsStockItemPreProduction,
                   m.isPreProductionItem                          AS IsPreProductionItem,
                   m.PreProductionTypeNo,
                   CONVERT(decimal(18,4), m.Ratio)                AS Ratio,
                   CONVERT(decimal(18,4), m.ProduceLimitPercentage) AS ProduceLimitPercentage,
                   CONVERT(bit, 1)                                AS IsActive,
                   m.CreateDateTime                               AS CreatedAt
            FROM [{$erp}].dbo.STK_StockMaster m;
        ");

        /*
         * 2. What is in force: the override where one is live, the customer's
         *    row everywhere else.
         *
         * `Source` is how a screen says which answered. It is deliberately a
         * word rather than ReconCriteria's negative-id trick, because here the
         * key is the business key on both sides and there is no surrogate to
         * make negative.
         */
        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_StockItem] AS

            /* Ours, where an override is live. Replaces the legacy row whole. */
            SELECT o.BranchId,
                   o.StockItemNo,
                   o.StockItemDescription,
                   o.AreaNo,
                   o.[Location] AS PosSystem,
                   o.POSCode,
                   o.SellingPrice,
                   o.PriceType,
                   o.Factor,
                   o.UOMCode,
                   o.IssueMultiple,
                   o.IssueMultiplePercentage,
                   o.QtyVarAllowance,
                   o.IsMonitoredItem,
                   o.IsDoCloseQtyCalc,
                   o.IsAllowNegativeQtyIssued,
                   o.IsAllowNegativeQtyClose,
                   o.IsStockItemPreProduction,
                   o.IsPreProductionItem,
                   o.PreProductionTypeNo,
                   o.Ratio,
                   o.ProduceLimitPercentage,
                   o.IsActive,
                   CONVERT(varchar(6), 'agora') AS [Source],
                   o.CreatedAt
            FROM [{$schema}].[StockItem] o
            WHERE o.IsParked = 0

            UNION ALL

            /* Theirs, wherever no live override claims it. */
            SELECT l.BranchId,
                   l.StockItemNo,
                   l.StockItemDescription,
                   l.AreaNo,
                   l.PosSystem,
                   l.POSCode,
                   l.SellingPrice,
                   l.PriceType,
                   l.Factor,
                   l.UOMCode,
                   l.IssueMultiple,
                   l.IssueMultiplePercentage,
                   l.QtyVarAllowance,
                   l.IsMonitoredItem,
                   l.IsDoCloseQtyCalc,
                   l.IsAllowNegativeQtyIssued,
                   l.IsAllowNegativeQtyClose,
                   l.IsStockItemPreProduction,
                   l.IsPreProductionItem,
                   l.PreProductionTypeNo,
                   l.Ratio,
                   l.ProduceLimitPercentage,
                   l.IsActive,
                   CONVERT(varchar(6), 'legacy') AS [Source],
                   l.CreatedAt
            FROM [{$schema}].[vw_LegacyStockItem] l
            WHERE NOT EXISTS (
                SELECT 1
                FROM [{$schema}].[StockItem] o
                WHERE o.IsParked = 0
                  AND o.BranchId = l.BranchId
                  AND o.StockItemNo = l.StockItemNo
            );
        ");

        /*
         * 3. The counting areas, with their loss grace.
         *
         * STK_Area joins STK_AreaGroup by DESCRIPTION rather than by id —
         * `AreaGroup` is an NVARCHAR(50) holding the group's text. That is the
         * legacy estate being itself; LEFT JOIN, always, so an area whose
         * group text matches nothing still appears rather than vanishing from
         * a picker.
         */
        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_StockArea] AS
            SELECT a.SSBranchId            AS BranchId,
                   a.AreaNo,
                   a.AreaDescription,
                   a.AreaGroup,
                   g.AreaGroupId,
                   CONVERT(decimal(18,2), g.LossGraceMonthly) AS LossGraceMonthly,
                   CONVERT(decimal(18,2), g.LossGraceDaily)   AS LossGraceDaily,
                   a.DayShift,
                   a.AfternoonShift,
                   a.NightShift,
                   a.ShowReport,
                   a.IsCaptureWaste
            FROM [{$erp}].dbo.STK_Area a
            LEFT JOIN [{$erp}].dbo.STK_AreaGroup g
                   ON g.AreaGroupDescription = a.AreaGroup;
        ");

        /*
         * 4. The critical list — lines that must never be out of stock.
         *
         * Keyed by POS CODE and POS system, not by item number, so this is the
         * key the stock master joins on: (BranchId, PosSystem, PosCode). Code
         * and Location are CHAR and therefore blank-padded, so they are
         * trimmed here once rather than at every call site.
         */
        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_StockItemCritical] AS
            SELECT c.SSBranchId                        AS BranchId,
                   RTRIM(c.[Location])                 AS PosSystem,
                   RTRIM(c.Code)                       AS PosCode,
                   RTRIM(ISNULL(c.[Desc], ''))         AS Description,
                   RTRIM(ISNULL(c.CAT, ''))            AS Category,
                   c.IsActive
            FROM [{$erp}].dbo.STK_StockMasterCritical c;
        ");

        /*
         * 5. The POS cost file, on the key it is actually keyed by.
         *
         * THE WHOLE POINT OF THIS VIEW IS ITS JOIN KEY, so it exists rather
         * than each caller writing the join. DBF_STDB holds 252,511 rows and
         * its primary key is (SSBranchId, CODE, LOCATION) — all three. Joining
         * on branch and code alone turns the 7,447-row stock master into 8,161
         * rows, and that is the LESSER harm: at 713 items the two-part join
         * also attaches a DIFFERENT PRODUCT'S cost, category and description,
         * because one site really does carry the same code in two POS systems.
         * Branch 18's code 999 is a Mitchum roll-on, a prawn salad and a
         * Clover crate, one per system.
         *
         * With all three columns the join returns exactly 7,447 rows and
         * matches 7,290 of them. It needs no TOP 1 and no dedupe, and adding
         * one would hide the next real ambiguity rather than reveal it.
         *
         * CODE is CHAR(16) and LOCATION CHAR(10) against NVARCHAR columns on
         * the master: the equality holds only because SQL Server ignores
         * trailing spaces when comparing. Anything built on LEN(), a
         * concatenated key or a match done in PHP will not, and will silently
         * drop rows.
         *
         * L_SOLD is a DATE (when it last sold) and M_SOLD a QUANTITY (how much
         * this month). They read like a pair and are not one, so they are
         * named apart here.
         */
        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_StockItemPos] AS
            SELECT d.SSBranchId                          AS BranchId,
                   RTRIM(d.LOCATION)                     AS PosSystem,
                   RTRIM(d.CODE)                         AS PosCode,
                   RTRIM(ISNULL(d.[DESC], ''))           AS PosDescription,
                   RTRIM(ISNULL(d.CAT, ''))              AS Category,
                   CONVERT(decimal(18,4), d.STDCOST)     AS CostPrice,
                   CONVERT(decimal(18,4), d.STDSELL)     AS PosSellPrice,
                   CONVERT(decimal(18,3), d.QTY)         AS QtyOnHand,
                   CONVERT(decimal(18,3), d.M_SOLD)      AS QtySoldThisMonth,
                   d.L_SOLD                              AS LastSoldAt,
                   CONVERT(decimal(18,3), d.PACKSIZE)    AS PackSize,
                   RTRIM(ISNULL(d.VATCODE, ''))          AS VatCode
            FROM [{$erp}].dbo.DBF_STDB d;
        ");
    }

    /**
     * Local sandbox only. Live is forward-only — and note that dropping the
     * table while an override is live would silently change what every screen
     * resolves, which is the half of this that is never safe.
     */
    public function down(): void
    {
        $schema = config('agora.schema');

        foreach ([
            'vw_StockItemPos', 'vw_StockItemCritical', 'vw_StockArea',
            'vw_StockItem', 'vw_LegacyStockItem',
        ] as $view) {
            DB::unprepared("DROP VIEW IF EXISTS [{$schema}].[{$view}];");
        }

        MigrationHelper::drop('StockItemCountStat');
        MigrationHelper::drop('StockItem');
    }
};
