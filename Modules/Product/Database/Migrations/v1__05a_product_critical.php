<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Product — the critical-line list, in a table Agora may write (slot 05a).
 *
 * A LETTERED FILE, not an edit to v1__05, because v1__05 has already run on
 * the customer's instance (20 September 2026, SchemaVersion 1.5). The fold-back
 * licence in CLAUDE.md only applies while a change has not left our hands, and
 * this one has.
 *
 * WHAT THE CRITICAL LIST IS. `PumpIT.dbo.STK_StockMasterCritical`: 1,895 rows
 * across 15 of the 22 sites, 1,677 of them active — the lines that must never
 * be out of stock. It is what the report library's "Out of stock now" queue is
 * built on, and on 20 September **709 active critical lines were at or below
 * zero on hand**.
 *
 * IT IS KEYED TO THE POS FILE, NOT TO THE STOCK MASTER, and that is the fact
 * that shapes everything here. Measured on the live estate:
 *
 *   · 1,895 critical lines, every single one of which HAS a row in DBF_STDB
 *   · 602 of them — 32% — have NO row in STK_StockMaster at that site and POS
 *     system. All 602 are in the POS file. They are real products the site
 *     sells that nobody counts.
 *
 * So a critical-lines screen built off the stock master would silently hide a
 * third of the list, including lines that are out of stock. This list stands on
 * its own key — (BranchId, PosSystem, PosCode) — joins the POS file for what is
 * on hand, and joins the stock master only to say whether it is counted.
 *
 * THE SHADOW RULE IS v1__05's, unchanged: a row in agora.StockItemCritical
 * replaces its legacy row whole, IsParked switches the override off and puts
 * the customer's row back, and PumpIT is never written.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Mirrors STK_StockMasterCritical's own columns, trimmed. The legacy
         * table is all CHAR and therefore blank-padded — Code CHAR(16),
         * Location CHAR(10), Desc CHAR(50), CAT CHAR(20) — and padding that
         * reaches a comparison is a bug waiting for somebody. The widths here
         * are the legacy widths so nothing truncates; the trimming happens
         * once, in the views.
         */
        MigrationHelper::table('StockItemCritical', function (Blueprint $table) {
            // The POS system and code ARE the key. There is no item number:
            // a third of this list has no stock master row to take one from.
            $table->string('PosSystem', 10);
            $table->string('PosCode', 16);

            $table->string('Description', 50)->nullable();
            $table->string('Category', 20)->nullable();

            // The line's own state: on the critical list, or taken off it.
            $table->boolean('IsActive')->default(true);

            // The OVERRIDE's state — parking restores the customer's row.
            // Named apart from IsActive for the reason v1__05's header gives
            // at length: two flags that sound alike and are not.
            $table->boolean('IsParked')->default(false);

            $table->string('Reason', 300)->nullable();
        });

        MigrationHelper::naturalKey('StockItemCritical', ['BranchId', 'PosSystem', 'PosCode']);
        MigrationHelper::rowVersion('StockItemCritical');

        $this->views();

        MigrationHelper::recordVersion(
            '1.6',
            'Product: an Agora-owned override for the critical-line list, and the view that decides '
            .'whether a caller sees ours or the customer\'s.'
        );
    }

    /**
     * Two views where v1__05 left one.
     *
     * `vw_StockItemCritical` was the customer's rows, read-only. It keeps its
     * name and becomes the RESOLVED answer — which is what its two existing
     * callers, usp_Product_GridStockItems and StockMasterService, already
     * wanted: they ask "is this line critical", and the honest answer is
     * whichever list is in force. The unshadowed read moves to
     * `vw_LegacyStockItemCritical`, beside `vw_LegacyStockItem`.
     */
    protected function views(): void
    {
        $schema = config('agora.schema');
        $erp = config('agora.source_databases.erp');

        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_LegacyStockItemCritical] AS
            SELECT c.SSBranchId                        AS BranchId,
                   RTRIM(c.[Location])                 AS PosSystem,
                   RTRIM(c.Code)                       AS PosCode,
                   NULLIF(RTRIM(ISNULL(c.[Desc], '')), '') AS Description,
                   NULLIF(RTRIM(ISNULL(c.CAT, '')), '')    AS Category,
                   c.IsActive
            FROM [{$erp}].dbo.STK_StockMasterCritical c;
        ");

        /*
         * The resolution. Same two arms and same NOT EXISTS as vw_StockItem —
         * deliberately, because two shadow views that resolve differently is
         * how somebody ends up reasoning about the wrong one.
         *
         * `Source` says which list answered, and it is not decoration: a site
         * whose critical list Agora has started curating and one that is still
         * entirely the customer's look identical without it.
         */
        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_StockItemCritical] AS

            SELECT o.BranchId,
                   o.PosSystem,
                   o.PosCode,
                   o.Description,
                   o.Category,
                   o.IsActive,
                   CONVERT(varchar(6), 'agora') AS [Source]
            FROM [{$schema}].[StockItemCritical] o
            WHERE o.IsParked = 0

            UNION ALL

            SELECT l.BranchId,
                   l.PosSystem,
                   l.PosCode,
                   l.Description,
                   l.Category,
                   l.IsActive,
                   CONVERT(varchar(6), 'legacy') AS [Source]
            FROM [{$schema}].[vw_LegacyStockItemCritical] l
            WHERE NOT EXISTS (
                SELECT 1
                FROM [{$schema}].[StockItemCritical] o
                WHERE o.IsParked = 0
                  AND o.BranchId = l.BranchId
                  AND o.PosSystem = l.PosSystem
                  AND o.PosCode = l.PosCode
            );
        ");
    }

    /**
     * Local sandbox only, and note the asymmetry: putting the view back the
     * way it was is safe, dropping the table while an override is live is not.
     */
    public function down(): void
    {
        $schema = config('agora.schema');
        $erp = config('agora.source_databases.erp');

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

        DB::unprepared("DROP VIEW IF EXISTS [{$schema}].[vw_LegacyStockItemCritical];");

        MigrationHelper::drop('StockItemCritical');
    }
};
