<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Assets — the register, its movements and its repair job cards (slot 17, T057).
 *
 * WHY THESE ARE AGORA'S OWN TABLES. The plan said "adopt ASSET_AssetType and
 * the register tables". Discovery on the live instance (27 Sep 2026) found the
 * customer's register is not in use: ASSET_AssetRegister holds 8 rows,
 * ASSET_AssetRepair 3 and ASSET_AssetMovement 1, all captured by one user on
 * the afternoon of 4 June 2026, two of them with a malformed item code and
 * one printer registered at two sites. Since the instance restarted on 7 Sep
 * 2026, nothing has written to any ASSET_ table (0 user_updates). The legacy
 * register also has nowhere to put a condition, a useful life, a disposal or
 * a net book value. Ryan decided (PM decision 01a0e309, 27 Sep 2026):
 *
 *   · the register, movements and job cards are NEW tables here;
 *   · the four legacy masters are read through agora views and never written;
 *   · the 8 pilot rows are not migrated;
 *   · there are no PumpIT writes in this module at all.
 *
 * WHICH BRANCH A ROW CARRIES. `BranchId` on all three tables is the site the
 * asset BELONGS to: the register it is on. It never changes, which is why it
 * can lead the clustered key and every unique key. Where the asset physically
 * IS lives in `LocatedBranchId`, and only a movement changes it. A movement and
 * a job card carry the asset's BranchId, so an asset's whole history sits in
 * one contiguous run of each table. The site a repair happened at is on the job
 * card as `SiteBranchId`, because an asset on loan is repaired, and paid for,
 * where it stands.
 *
 * NUMBERS AGORA GENERATES (the legacy app numbered in client code nobody has,
 * and did it wrongly on two of eight rows):
 *
 *   · Asset      `{group}{seq}-{branch}`, e.g. BE0007-8: the group code, a
 *                sequence per (site, group) padded to four, the owning site.
 *                The legacy shape (`OE001-3`), one digit wider. Fixed when the
 *                asset is registered: re-grouping an asset later does not
 *                renumber it, because the number is on a sticker.
 *   · Movement   `MV{no}-{branch}`, a sequence per owning site.
 *   · Job card   `JC{no}-{branch}`, a sequence per owning site.
 *
 * Each sequence is taken under UPDLOCK, HOLDLOCK in the writing procedure, and
 * each is unique on a key that leads with BranchId.
 *
 * TRANSACTIONS ARE NEVER DELETED. A movement is reversed (the row stays, marked
 * reversed, with who and why) and a job card is cancelled (the same). An asset
 * is master data and soft-deletes — and only while it has no history at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = config('agora.schema');

        /*
         * The register. One row per asset, for its whole life: a disposal or a
         * write-off changes its Status and dates the event, it does not remove
         * the row, because the history that references it has to stay readable.
         */
        MigrationHelper::table('Asset', function (Blueprint $table) {
            $table->string('AssetNo', 30);
            // The per-(site, group) sequence behind AssetNo. Kept as a number
            // so the next one is a MAX, not a parse of a string.
            $table->integer('AssetSeq');

            // Validated against agora.vw_AssetType — the customer's 431 types
            // under 21 groups, keyed (group, type) because CE28 exists twice.
            $table->string('AssetGroupCode', 10);
            $table->string('AssetTypeCode', 10);

            // The legacy form's own three identifying fields, same widths.
            $table->string('Make', 100);
            $table->string('Model', 100)->nullable();
            $table->string('SerialNo', 100)->nullable();
            $table->string('Description', 200)->nullable();

            // Where it is now. Changed by agora.usp_Assets_Move and nothing else.
            $table->integer('LocatedBranchId');

            // Who owns it, when it is not simply this site: an oil company's
            // pump, a brewery's fridge. Validated against the customer's
            // ASSET_AssetOwner list (31 names, branches and third parties).
            $table->string('AssetOwner', 100)->nullable();

            // Validated against agora.vw_Supplier at the owning site.
            $table->string('SupplierCode', 16)->nullable();

            // Depreciation starts in the month of purchase.
            $table->date('DatePurchased');
            $table->date('DateAllocated')->nullable();
            MigrationHelper::money($table, 'Cost');

            // NEW · GOOD · FAIR · POOR · UNSERVICEABLE — agora.vw_AssetCondition,
            // which the save procedure checks and the forms are offered from.
            $table->string('Condition', 20)->default('GOOD');

            // IN_SERVICE · AT_REPAIR · DISPOSED · WRITTEN_OFF. Set by movements.
            $table->string('Status', 20)->default('IN_SERVICE');
            $table->date('DisposedOn')->nullable();
            $table->decimal('DisposalProceeds', 18, 2)->nullable();

            // Why the row last changed. Required on every edit by the save
            // procedure; a register nobody can explain is what we replaced.
            $table->string('Reason', 300)->nullable();
        });
        MigrationHelper::naturalKey('Asset', ['BranchId', 'AssetNo']);
        MigrationHelper::rowVersion('Asset');
        MigrationHelper::softDeletes('Asset');

        DB::statement("CREATE INDEX [IX_Asset_Located] ON [{$schema}].[Asset] ([LocatedBranchId], [Status]);");
        DB::statement("CREATE INDEX [IX_Asset_Sequence] ON [{$schema}].[Asset] ([BranchId], [AssetGroupCode], [AssetSeq]);");

        /*
         * Movements: transfer, out to repair, back from repair, sold, traded
         * in, written off. Each row carries the asset's state before and after,
         * which is what makes a reversal exact: it puts back what the row says
         * was there, rather than guessing.
         */
        MigrationHelper::table('AssetMovement', function (Blueprint $table) {
            $table->integer('MovementNo');
            $table->bigInteger('AssetId');

            // A code from agora.vw_AssetMovementType: the customer's five plus
            // Agora's RR (returned from repair), which the legacy list lacks.
            $table->string('MovementType', 10);
            $table->date('MovementDate');

            $table->integer('FromBranchId');
            $table->integer('ToBranchId');
            $table->string('FromStatus', 20);
            $table->string('ToStatus', 20);

            // Sold or traded in: what it fetched. Null for everything else.
            $table->decimal('Proceeds', 18, 2)->nullable();
            $table->string('Reason', 300)->nullable();

            // The job card this movement closed (a return from repair stamps
            // the open card's return date), so a reversal can reopen exactly it.
            $table->bigInteger('RepairId')->nullable();

            $table->dateTime('ReversedAt', 0)->nullable();
            $table->integer('ReversedBy')->nullable();
            $table->string('ReverseReason', 300)->nullable();
        });
        MigrationHelper::naturalKey('AssetMovement', ['BranchId', 'MovementNo']);
        DB::statement("CREATE INDEX [IX_AssetMovement_Asset] ON [{$schema}].[AssetMovement] ([BranchId], [AssetId], [MovementDate]);");
        DB::statement("
            ALTER TABLE [{$schema}].[AssetMovement] ADD [MovementRef] AS
                CONCAT('MV',
                       CASE WHEN [MovementNo] < 100000 THEN RIGHT(CONCAT('0000', [MovementNo]), 5)
                            ELSE CONVERT(varchar(12), [MovementNo]) END,
                       '-', [BranchId]) PERSISTED;
        ");

        /*
         * Repair job cards. One per call-out on one asset. The cost is NOT a
         * column: it is the approved purchase request's, read from the
         * customer's BRN_Transaction / BRN_TransactionLine through
         * TransactionNo (Ryan, 27 Sep 2026: the job card carries the PR number
         * as the legacy OrderNo did, so the cost comes from the approved
         * request). Two sources for one cost would disagree within a week.
         */
        MigrationHelper::table('AssetRepair', function (Blueprint $table) {
            $table->integer('RepairNo');
            $table->bigInteger('AssetId');

            // Where the asset stood when it was called in, which is also the
            // site whose purchase request pays for it.
            $table->integer('SiteBranchId');

            // The legacy form's DateOfRepair: the day the call was made. The
            // comeback rule measures between these.
            $table->date('CallDate');

            $table->string('SupplierCode', 16)->nullable();
            $table->string('SupplierReference', 100)->nullable();
            $table->string('Problem', 1000);

            // BRN_Transaction.TransactionNo at SiteBranchId — CR000550, CA004411.
            // Unique per site across both type codes on live, so the number
            // alone identifies the request.
            $table->string('TransactionNo', 30)->nullable();

            $table->date('DateExpectedReturn')->nullable();
            $table->date('DateActualReturn')->nullable();

            $table->boolean('IsCancelled')->default(false);
            $table->dateTime('CancelledAt', 0)->nullable();
            $table->integer('CancelledBy')->nullable();
            $table->string('CancelReason', 300)->nullable();
        });
        MigrationHelper::naturalKey('AssetRepair', ['BranchId', 'RepairNo']);
        MigrationHelper::rowVersion('AssetRepair');
        DB::statement("CREATE INDEX [IX_AssetRepair_Asset] ON [{$schema}].[AssetRepair] ([BranchId], [AssetId], [CallDate]);");
        DB::statement("CREATE INDEX [IX_AssetRepair_Request] ON [{$schema}].[AssetRepair] ([SiteBranchId], [TransactionNo]);");
        DB::statement("
            ALTER TABLE [{$schema}].[AssetRepair] ADD [JobCardNo] AS
                CONCAT('JC',
                       CASE WHEN [RepairNo] < 100000 THEN RIGHT(CONCAT('0000', [RepairNo]), 5)
                            ELSE CONVERT(varchar(12), [RepairNo]) END,
                       '-', [BranchId]) PERSISTED;
        ");

        $this->views();
        $this->depreciation();

        MigrationHelper::recordVersion(
            '1.16',
            'Assets: an Agora-owned register (cost, condition, status), its movements and its repair job cards; '
            .'read-only views over the customer\'s four asset masters; the comeback rule (a second call on the '
            .'same asset within 30 days) and the straight-line depreciation function.'
        );
    }

    /**
     * The customer's four masters, read-only, and the comeback rule.
     */
    protected function views(): void
    {
        $schema = config('agora.schema');
        $erp = config('agora.source_databases.erp');
        $group = (int) config('agora.group_branch_id', 2);

        /*
         * The masters are estate-wide in PumpIT — no SSBranchId — so they carry
         * the group entity's id, never NULL, like every other global master.
         * Codes and descriptions are trimmed once, here: the live type list
         * carries trailing spaces ("Air Gages ", "Chip Dunk ").
         */
        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_AssetGroup] AS
            SELECT CONVERT(int, {$group})                          AS BranchId,
                   RTRIM(g.AssetGroupCode)                         AS AssetGroupCode,
                   RTRIM(ISNULL(g.AssetGroupDescription, ''))      AS AssetGroupDescription
            FROM [{$erp}].dbo.ASSET_AssetGroup g;
        ");

        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_AssetType] AS
            SELECT CONVERT(int, {$group})                          AS BranchId,
                   RTRIM(t.AssetGroupCode)                         AS AssetGroupCode,
                   RTRIM(t.AssetTypeCode)                          AS AssetTypeCode,
                   RTRIM(ISNULL(t.AssetTypeDescription, ''))       AS AssetTypeDescription
            FROM [{$erp}].dbo.ASSET_AssetType t;
        ");

        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_AssetOwner] AS
            SELECT CONVERT(int, {$group})                          AS BranchId,
                   RTRIM(o.AssetOwnerDescription)                  AS AssetOwner
            FROM [{$erp}].dbo.ASSET_AssetOwner o;
        ");

        /*
         * Movement types, and what each one DOES. The meaning is in this view
         * rather than in the procedure so that a code the customer adds to
         * their list arrives with an honest `Kind` of NULL — which
         * usp_Assets_Move refuses — instead of being silently treated as one
         * of the known ones.
         *
         * RR (returned from repair) is Agora's. The legacy list has RM, "Repairs
         * & Maintenance", for the asset going out, and nothing for it coming
         * back. It is added here, marked `agora`, rather than written into the
         * customer's table.
         */
        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_AssetMovementType] AS
            SELECT CONVERT(int, {$group})                                  AS BranchId,
                   RTRIM(m.AssetMovementTypeCode)                          AS MovementType,
                   RTRIM(ISNULL(m.AssetMovementTypeDescription, ''))       AS MovementTypeDescription,
                   CASE RTRIM(m.AssetMovementTypeCode)
                        WHEN 'MBS' THEN 'TRANSFER'
                        WHEN 'RM'  THEN 'TO_REPAIR'
                        WHEN 'S'   THEN 'DISPOSAL'
                        WHEN 'TI'  THEN 'DISPOSAL'
                        WHEN 'WO'  THEN 'WRITE_OFF'
                   END                                                     AS Kind,
                   CONVERT(varchar(6), 'legacy')                           AS [Source]
            FROM [{$erp}].dbo.ASSET_AssetMovementType m

            UNION ALL

            SELECT CONVERT(int, {$group}), N'RR', N'Returned from repair', 'FROM_REPAIR', CONVERT(varchar(6), 'agora');
        ");

        /*
         * The condition vocabulary, in one place. agora.usp_Assets_SaveAsset
         * refuses anything not listed here and agora.usp_Assets_Masters offers
         * exactly these to the forms, so the two cannot disagree. A view rather
         * than a table because nobody edits it: a new condition is a decision,
         * and a lettered migration is where a decision lands.
         */
        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_AssetCondition] AS
            SELECT CONVERT(int, {$group})       AS BranchId,
                   CONVERT(nvarchar(20), c.Condition) AS Condition,
                   CONVERT(nvarchar(40), c.Label)     AS Label,
                   c.SortOrder
            FROM (VALUES ('NEW', 'New', 1),
                         ('GOOD', 'Good', 2),
                         ('FAIR', 'Fair', 3),
                         ('POOR', 'Poor', 4),
                         ('UNSERVICEABLE', 'Unserviceable', 5)) c (Condition, Label, SortOrder);
        ");

        /*
         * A purchase request, as a job card needs to know it: one row per
         * (site, request number), with its supplier, whether it was approved or
         * declined, and what it adds up to.
         *
         * THE KEY IS (SSBranchId, TransactionNo), and that is measured, not
         * assumed: on live the number is unique per site across both type
         * codes — 01 cash (CA…) and 02 credit (CR…) — 0 duplicates in 54,294
         * requests on 27 Sep 2026. The lines are joined on all three columns of
         * their own key, so the sum cannot fan.
         *
         * Read from PumpIT directly rather than through the Reports module's
         * agora.vw_PurchaseRequest: a view is checked when it is created, and
         * slot 17 is created before slot 22 on a fresh database.
         */
        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_AssetPurchaseRequest] AS
            SELECT t.SSBranchId                                   AS BranchId,
                   t.TransactionNo,
                   t.TypeCode,
                   RTRIM(t.SupplierCode)                          AS SupplierCode,
                   CONVERT(date, t.PurchaseRequestDate)           AS RequestDate,
                   CONVERT(bit, ISNULL(t.Approved, 0))            AS Approved,
                   CONVERT(bit, ISNULL(t.IsDeclined, 0))          AS IsDeclined,
                   CONVERT(decimal(18,2), ISNULL(l.Amount, 0))    AS Amount,
                   ISNULL(l.Lines, 0)                             AS Lines,
                   f.FirstLine,
                   CASE WHEN ISNULL(t.IsDeclined, 0) = 1 THEN 'Declined'
                        WHEN ISNULL(t.Approved, 0) = 1   THEN 'Approved'
                        ELSE 'Awaiting approval' END              AS RequestState
            FROM [{$erp}].dbo.BRN_Transaction t
            OUTER APPLY (
                SELECT SUM(x.PurchaseAmount) AS Amount,
                       COUNT(*)              AS Lines
                FROM [{$erp}].dbo.BRN_TransactionLine x
                WHERE x.SSBranchId = t.SSBranchId
                  AND x.TransactionNo = t.TransactionNo
                  AND x.TypeCode = t.TypeCode
            ) l
            OUTER APPLY (
                SELECT TOP 1 CONVERT(nvarchar(200), y.PurchaseRequestDescription) AS FirstLine
                FROM [{$erp}].dbo.BRN_TransactionLine y
                WHERE y.SSBranchId = t.SSBranchId
                  AND y.TransactionNo = t.TransactionNo
                  AND y.TypeCode = t.TypeCode
                ORDER BY y.ItemNo
            ) f;
        ");

        /*
         * WHAT A REPAIR COST, in one place (Ryan, 27 Sep 2026): the APPROVED
         * purchase request's amount, and nothing typed. One row per job card.
         *
         *   · no request attached      -> no cost, 'No request yet'
         *   · awaiting approval        -> no cost yet
         *   · approved                 -> the request's amount
         *   · declined                 -> no cost (the save procedure refuses
         *                                 to attach one; a request declined
         *                                 AFTER it was attached lands here)
         *
         * One request can pay for several cards — one call-out, three fridges.
         * `SharedBy` says how many live cards carry it, and every total built
         * on this view counts a request ONCE (the procedures group on
         * SiteBranchId + TransactionNo before they add). Splitting the amount
         * between the cards would be inventing an allocation nobody asked for.
         */
        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_AssetRepairCost] AS
            SELECT r.BranchId,
                   r.Id,
                   r.SiteBranchId,
                   r.TransactionNo,
                   p.TypeCode,
                   p.SupplierCode                                 AS RequestSupplierCode,
                   p.RequestDate,
                   p.Amount                                       AS RequestAmount,
                   p.FirstLine                                    AS RequestFirstLine,
                   CASE WHEN r.TransactionNo IS NULL THEN 'No request yet'
                        WHEN p.TransactionNo IS NULL THEN 'Request not found'
                        ELSE p.RequestState END                   AS RequestState,
                   CASE WHEN p.Approved = 1 AND p.IsDeclined = 0 THEN p.Amount END AS RepairCost,
                   CASE WHEN r.TransactionNo IS NULL THEN 0
                        ELSE (SELECT COUNT(*) FROM [{$schema}].[AssetRepair] s
                               WHERE s.SiteBranchId = r.SiteBranchId AND s.TransactionNo = r.TransactionNo
                                 AND s.IsCancelled = 0) END       AS SharedBy
            FROM [{$schema}].[AssetRepair] r
            LEFT JOIN [{$schema}].[vw_AssetPurchaseRequest] p
                   ON p.BranchId = r.SiteBranchId
                  AND p.TransactionNo = r.TransactionNo;
        ");

        /*
         * THE COMEBACK RULE, in one place (T057; Ryan, 27 Sep 2026).
         *
         * A comeback is a second repair call on the SAME ASSET within 30 days
         * of the call before it. Measured call date to call date, inclusive:
         * a second call the same day is 0 days and is a comeback; one 30 days
         * later is a comeback; 31 is not.
         *
         * Cancelled job cards are not calls — a card raised in error must not
         * make the next genuine one look like a comeback — so they are left
         * out before the previous call is looked for. Ties on the same day are
         * broken by the card's id, so the earlier-raised card is the first call.
         *
         * `PrevSupplierCode` is the point of the forensic: the comeback counts
         * against the supplier whose repair did not hold, which is the PREVIOUS
         * card's supplier, not necessarily the one called back.
         *
         * agora.usp_Assets_GridRepairs and agora.usp_Assets_Comebacks both read
         * this view; neither restates the rule.
         */
        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_AssetRepairCall] AS
            WITH calls AS (
                SELECT r.BranchId,
                       r.Id,
                       r.AssetId,
                       r.RepairNo,
                       r.JobCardNo,
                       r.SiteBranchId,
                       r.CallDate,
                       r.SupplierCode,
                       LAG(r.Id)           OVER (PARTITION BY r.BranchId, r.AssetId ORDER BY r.CallDate, r.Id) AS PrevRepairId,
                       LAG(r.JobCardNo)    OVER (PARTITION BY r.BranchId, r.AssetId ORDER BY r.CallDate, r.Id) AS PrevJobCardNo,
                       LAG(r.CallDate)     OVER (PARTITION BY r.BranchId, r.AssetId ORDER BY r.CallDate, r.Id) AS PrevCallDate,
                       LAG(r.SupplierCode) OVER (PARTITION BY r.BranchId, r.AssetId ORDER BY r.CallDate, r.Id) AS PrevSupplierCode,
                       LAG(r.SiteBranchId) OVER (PARTITION BY r.BranchId, r.AssetId ORDER BY r.CallDate, r.Id) AS PrevSiteBranchId
                FROM [{$schema}].[AssetRepair] r
                WHERE r.IsCancelled = 0
            )
            SELECT c.BranchId,
                   c.Id,
                   c.AssetId,
                   c.RepairNo,
                   c.JobCardNo,
                   c.SiteBranchId,
                   c.CallDate,
                   c.SupplierCode,
                   c.PrevRepairId,
                   c.PrevJobCardNo,
                   c.PrevCallDate,
                   c.PrevSupplierCode,
                   c.PrevSiteBranchId,
                   DATEDIFF(DAY, c.PrevCallDate, c.CallDate)            AS DaysSincePrevious,
                   CONVERT(int, 30)                                     AS WindowDays,
                   CONVERT(bit, CASE WHEN c.PrevRepairId IS NOT NULL
                                      AND DATEDIFF(DAY, c.PrevCallDate, c.CallDate) <= 30
                                     THEN 1 ELSE 0 END)                 AS IsComeback
            FROM calls c;
        ");
    }

    /**
     * Straight-line depreciation, monthly, by group — the one place the
     * arithmetic lives.
     *
     * A function rather than a procedure because three procedures need the
     * same answer INSIDE a query: the register grid (NBV per row), the
     * depreciation summary, and the month-by-month schedule, which is this
     * same function asked at every month end. A procedure's result cannot be
     * joined to, and three copies of the formula would drift.
     *
     * The rates are an argument, not a table: they live in
     * Modules/Assets/Config/config.php (Ryan, 27 Sep 2026 — SARS wear-and-tear,
     * provisional), and AssetsService::ratesJson() hands them over as
     *   {"BE": {"life_months": 72, "residual_pct": 0}, ...}
     * A group missing from the JSON, or with no life, is not depreciated.
     *
     * The conventions (see the config for why):
     *   · the month of purchase is charged in full, the month of disposal not
     *     at all;
     *   · a month is charged at its month end — as at 27 Sep, August is the
     *     last month charged;
     *   · Accumulated(n) = ROUND((cost − residual) × n ÷ life, 2), so the
     *     charge for month n is Accumulated(n) − Accumulated(n−1) and the
     *     charges add up to exactly the depreciable amount.
     */
    protected function depreciation(): void
    {
        $schema = config('agora.schema');

        DB::unprepared("
            CREATE OR ALTER FUNCTION [{$schema}].[fn_Assets_Depreciation] (@RatesJson nvarchar(max), @AsAt date)
            RETURNS TABLE
            AS RETURN
            WITH rate AS (
                SELECT j.[key] COLLATE DATABASE_DEFAULT                               AS AssetGroupCode,
                       TRY_CONVERT(int, JSON_VALUE(j.value, '$.life_months'))           AS LifeMonths,
                       ISNULL(TRY_CONVERT(decimal(9,4), JSON_VALUE(j.value, '$.residual_pct')), 0) AS ResidualPct
                FROM OPENJSON(ISNULL(@RatesJson, N'{}')) j
            )
            SELECT a.BranchId,
                   a.Id                                   AS AssetId,
                   a.AssetGroupCode,
                   a.Cost,
                   a.DatePurchased,
                   a.DisposedOn,
                   CASE WHEN r.LifeMonths > 0 THEN r.LifeMonths END AS LifeMonths,
                   v.Residual,
                   v.Depreciable,
                   w.AsAtMonthEnd,
                   w.ChargedTo,
                   m.MonthsCharged,
                   CASE WHEN r.LifeMonths > 0 THEN CONVERT(decimal(18,2), ROUND(v.Depreciable / r.LifeMonths, 2)) END AS MonthlyCharge,
                   acc.Accumulated,

                   /* This month's charge: Accumulated(n) - Accumulated(n-1),
                      and only when month n IS the as-at month. An asset fully
                      depreciated earlier, or disposed of this month, is 0. */
                   CASE WHEN r.LifeMonths > 0 AND m.MonthsCharged > 0
                             AND EOMONTH(a.DatePurchased, m.MonthsCharged - 1) = w.AsAtMonthEnd
                        THEN acc.Accumulated - CONVERT(decimal(18,2), ROUND(v.Depreciable * (m.MonthsCharged - 1) / r.LifeMonths, 2))
                        WHEN r.LifeMonths > 0 THEN CONVERT(decimal(18,2), 0)
                   END                                    AS ChargeForMonth,

                   /* Net book value as at @AsAt. An asset disposed of on or
                      before @AsAt is off the register: its NBV is 0, and what
                      it carried when it went is NbvAtDisposal. */
                   CASE WHEN r.LifeMonths > 0 THEN
                        CASE WHEN x.IsGone = 1 THEN CONVERT(decimal(18,2), 0) ELSE a.Cost - acc.Accumulated END
                   END                                    AS Nbv,
                   CASE WHEN r.LifeMonths > 0 AND x.IsGone = 1 THEN a.Cost - acc.Accumulated END AS NbvAtDisposal,
                   CASE WHEN r.LifeMonths > 0 AND x.IsGone = 1
                        THEN ISNULL(a.DisposalProceeds, 0) - (a.Cost - acc.Accumulated)
                   END                                    AS ProfitOnDisposal,

                   CASE
                        WHEN r.LifeMonths IS NULL OR r.LifeMonths <= 0 THEN 'NO_RATE'
                        WHEN x.IsGone = 1                              THEN 'STOPPED'
                        WHEN a.DatePurchased > w.AsAtMonthEnd          THEN 'NOT_STARTED'
                        WHEN m.MonthsCharged >= r.LifeMonths           THEN 'FULLY_DEPRECIATED'
                        ELSE 'DEPRECIATING'
                   END                                    AS DepreciationState
            FROM [{$schema}].[Asset] a
            LEFT JOIN rate r
                   ON r.AssetGroupCode = a.AssetGroupCode
            CROSS APPLY (SELECT CONVERT(decimal(18,2), ROUND(a.Cost * r.ResidualPct / 100, 2)) AS Residual) v0
            CROSS APPLY (SELECT v0.Residual AS Residual, CONVERT(decimal(18,2), a.Cost - v0.Residual) AS Depreciable) v
            CROSS APPLY (SELECT CONVERT(bit, CASE WHEN a.DisposedOn IS NOT NULL AND a.DisposedOn <= @AsAt THEN 1 ELSE 0 END) AS IsGone) x
            CROSS APPLY (
                /* The last month end on or before @AsAt, and the last month end
                   that may be charged at all: the one before the month of
                   disposal. */
                SELECT CASE WHEN @AsAt = EOMONTH(@AsAt) THEN @AsAt ELSE EOMONTH(@AsAt, -1) END AS AsAtMonthEnd,
                       CASE WHEN a.DisposedOn IS NULL
                                 OR EOMONTH(a.DisposedOn, -1) >= CASE WHEN @AsAt = EOMONTH(@AsAt) THEN @AsAt ELSE EOMONTH(@AsAt, -1) END
                            THEN CASE WHEN @AsAt = EOMONTH(@AsAt) THEN @AsAt ELSE EOMONTH(@AsAt, -1) END
                            ELSE EOMONTH(a.DisposedOn, -1)
                       END AS ChargedTo
            ) w
            CROSS APPLY (
                SELECT CASE
                           WHEN r.LifeMonths IS NULL OR r.LifeMonths <= 0 THEN 0
                           WHEN w.ChargedTo < a.DatePurchased             THEN 0
                           WHEN DATEDIFF(MONTH, a.DatePurchased, w.ChargedTo) + 1 > r.LifeMonths THEN r.LifeMonths
                           ELSE DATEDIFF(MONTH, a.DatePurchased, w.ChargedTo) + 1
                       END AS MonthsCharged
            ) m
            CROSS APPLY (
                SELECT CONVERT(decimal(18,2), CASE
                           WHEN r.LifeMonths IS NULL OR r.LifeMonths <= 0 THEN NULL
                           WHEN m.MonthsCharged >= r.LifeMonths THEN v.Depreciable
                           ELSE ROUND(v.Depreciable * m.MonthsCharged / r.LifeMonths, 2)
                       END) AS Accumulated
            ) acc
            WHERE a.DeletedAt IS NULL;
        ");
    }

    /**
     * Local sandbox only. Live is forward-only; dropping these would take the
     * register with them, and nothing else holds it.
     */
    public function down(): void
    {
        $schema = config('agora.schema');

        DB::unprepared("DROP FUNCTION IF EXISTS [{$schema}].[fn_Assets_Depreciation];");

        foreach (['vw_AssetRepairCall', 'vw_AssetRepairCost', 'vw_AssetPurchaseRequest', 'vw_AssetCondition', 'vw_AssetMovementType', 'vw_AssetOwner', 'vw_AssetType', 'vw_AssetGroup'] as $view) {
            DB::unprepared("DROP VIEW IF EXISTS [{$schema}].[{$view}];");
        }

        MigrationHelper::drop('AssetRepair');
        MigrationHelper::drop('AssetMovement');
        MigrationHelper::drop('Asset');
    }
};
