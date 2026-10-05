<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Stock recon — duplicate issues (slot 14h).
 *
 * The customer asked, via Ryan, whether Agora can tell when an issue was keyed
 * twice, so staff are not charged for stock that never existed (investigation
 * and decisions 01a0f60b-0386 / 01a0f60b-0459, 1 Oct 2026). The count is the
 * witness: where a shift is short by EXACTLY the issue it was given and an
 * identical issue on a neighbouring shift arrived, the second issue never
 * reached the shelf. v1__14ph deploys the procedures; docs/stock-recon.md →
 * "Duplicate issues" has the rule. This adds what they read and record.
 *
 *   StockAreaSetting       NEW. Per area: is it PREPARED FOOD. Rule 5 of the
 *                          duplicate test — in hot food, bakery, a QSR kitchen,
 *                          grab & go or a butchery, a batch thrown away
 *                          unrecorded looks exactly like a duplicate, so those
 *                          areas are never D1. PumpIT's own pre-production
 *                          flags are 0 on all 7,450 items, so the flag is
 *                          Agora's, proposed from the area group and name
 *                          (AreaFoodSeeder) and CONFIRMED by head office.
 *   vw_StockAreaFood       Every area with its flag: head office's row where
 *                          there is one, the proposal otherwise. The proposal
 *                          is written ONCE, here, and the seeder reads it.
 *
 *   StockReconRunLine gains
 *     DupTier              'D1' a clear duplicate (removed before balancing and,
 *                          on commit, written as QtyIssued = 0), 'D2' a possible
 *                          one (listed for the site manager, never changed).
 *     DupTwinDate,         The shift with the identical issue the test paired it
 *     DupTwinShift,        with, that shift's variance, and the issue itself —
 *     DupTwinVar, DupQty   enough for a screen to show both sides.
 *     DupReason            Why a D2 is not clear; why a D1 was not removed.
 *     QtyIssuedNew         The issue after the commit: 0 on a D1 the commit
 *                          writes, NULL where the issue is not touched.
 *     CountsMove           The counts move (what WouldAmend alone meant until
 *                          now). WouldAmend is now "the counts OR the issue
 *                          move"; backfilled from it, which is exact for every
 *                          run recorded before this.
 *   StockReconRun gains DuplicatesClear and DuplicatesPossible, for the header.
 *   StockReconAmendment gains PriorQtyIssued and NewQtyIssued — NULL unless the
 *                          commit wrote the issue, which is what lets
 *                          usp_StockRecon_Reverse put it back.
 *
 * Agora's own tables. Nothing in the customer's estate changes here; the write
 * to [PumpIT].dbo.STK_StockReconLine.QtyIssued is the procedures', switched by
 * config('stockrecon.issue_mode').
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = config('agora.schema');

        MigrationHelper::table('StockAreaSetting', function (Blueprint $table) {
            $table->integer('AreaNo');
            // Prepared food: never a clear duplicate. See the docblock.
            $table->boolean('IsPreparedFood')->default(false);
            // What the value rests on: the area group or name it was proposed
            // from, or head office's own call.
            $table->string('FoodBasis', 60)->nullable();
            // NULL until head office confirms the value; the proposal stands
            // meanwhile, and says so on the screen that reads it.
            $table->dateTime('ConfirmedAt', 0)->nullable();
            $table->integer('ConfirmedBy')->nullable();
        });

        MigrationHelper::naturalKey('StockAreaSetting', ['BranchId', 'AreaNo']);

        DB::unprepared("CREATE OR ALTER VIEW [{$schema}].[vw_StockAreaFood] AS".$this->foodView($schema).';');

        DB::statement("
            ALTER TABLE [{$schema}].[StockReconRunLine]
                ADD [DupTier]      NVARCHAR(2)   NULL,
                    [DupTwinDate]  DATE          NULL,
                    [DupTwinShift] INT           NULL,
                    [DupTwinVar]   DECIMAL(18,3) NULL,
                    [DupQty]       DECIMAL(18,3) NULL,
                    [DupReason]    NVARCHAR(80)  NULL,
                    [QtyIssuedNew] DECIMAL(18,3) NULL,
                    [CountsMove]   BIT NOT NULL CONSTRAINT [DF_StockReconRunLine_CountsMove] DEFAULT 0;
        ");

        // Until now WouldAmend meant exactly "the counts move".
        DB::statement("UPDATE [{$schema}].[StockReconRunLine] SET [CountsMove] = [WouldAmend] WHERE [WouldAmend] = 1;");

        DB::statement("
            ALTER TABLE [{$schema}].[StockReconRun]
                ADD [DuplicatesClear]    INT NOT NULL CONSTRAINT [DF_StockReconRun_DuplicatesClear]    DEFAULT 0,
                    [DuplicatesPossible] INT NOT NULL CONSTRAINT [DF_StockReconRun_DuplicatesPossible] DEFAULT 0;
        ");

        DB::statement("
            ALTER TABLE [{$schema}].[StockReconAmendment]
                ADD [PriorQtyIssued] DECIMAL(18,3) NULL,
                    [NewQtyIssued]   DECIMAL(18,3) NULL;
        ");

        // Enumerated, not a star — a view binds its column list at creation
        // (see v1__14b), so the new columns are invisible until it is redefined.
        DB::unprepared("CREATE OR ALTER VIEW [{$schema}].[vw_StockReconRunLine] AS".$this->runLineView($schema).';');

        MigrationHelper::recordVersion(
            '1.25',
            'Stock recon: duplicate issues. A clear duplicate (D1) is removed before balancing and written as '
            .'QtyIssued = 0 with its prior kept in agora.StockReconAmendment; a possible one (D2) is listed. '
            .'agora.StockAreaSetting holds the prepared-food flag per area that keeps food areas out of D1.'
        );
    }

    /** Local sandbox only. Live and staging are forward-only — see CLAUDE.md. */
    public function down(): void
    {
        $schema = config('agora.schema');

        (require __DIR__.'/v1__14g_stockrecon_kept_breaks.php')->up();

        foreach (['DupTier', 'DupTwinDate', 'DupTwinShift', 'DupTwinVar', 'DupQty', 'DupReason', 'QtyIssuedNew'] as $c) {
            DB::statement("ALTER TABLE [{$schema}].[StockReconRunLine] DROP COLUMN IF EXISTS [{$c}];");
        }

        DB::statement("ALTER TABLE [{$schema}].[StockReconRunLine] DROP CONSTRAINT IF EXISTS [DF_StockReconRunLine_CountsMove];");
        DB::statement("ALTER TABLE [{$schema}].[StockReconRunLine] DROP COLUMN IF EXISTS [CountsMove];");

        foreach (['DuplicatesClear', 'DuplicatesPossible'] as $c) {
            DB::statement("ALTER TABLE [{$schema}].[StockReconRun] DROP CONSTRAINT IF EXISTS [DF_StockReconRun_{$c}];");
            DB::statement("ALTER TABLE [{$schema}].[StockReconRun] DROP COLUMN IF EXISTS [{$c}];");
        }

        foreach (['PriorQtyIssued', 'NewQtyIssued'] as $c) {
            DB::statement("ALTER TABLE [{$schema}].[StockReconAmendment] DROP COLUMN IF EXISTS [{$c}];");
        }

        DB::statement("DROP VIEW IF EXISTS [{$schema}].[vw_StockAreaFood];");
        MigrationHelper::drop('StockAreaSetting');
    }

    /**
     * THE PROPOSAL, written once. Prepared food when the customer's own area
     * group says so (Food Items, QSR Items, Butchery Items) or the area's name
     * does — which on the estate today adds only 'Grab & Go', filed under
     * Retail Items. Head office's row wins wherever there is one.
     */
    private function foodView(string $schema): string
    {
        return "
            SELECT a.BranchId,
                   a.AreaNo,
                   a.AreaDescription,
                   a.AreaGroup,
                   CONVERT(bit, COALESCE(s.IsPreparedFood, p.Proposed))       AS IsPreparedFood,
                   CONVERT(bit, p.Proposed)                                   AS ProposedPreparedFood,
                   CONVERT(bit, CASE WHEN s.ConfirmedAt IS NOT NULL THEN 1 ELSE 0 END) AS IsConfirmed,
                   COALESCE(s.FoodBasis, p.Basis)                             AS FoodBasis,
                   s.ConfirmedAt,
                   s.ConfirmedBy
            FROM [{$schema}].vw_StockArea a
            CROSS APPLY (
                SELECT CASE WHEN a.AreaGroup IN (N'Food Items', N'QSR Items', N'Butchery Items')
                            THEN N'Area group ' + a.AreaGroup
                            WHEN a.AreaDescription LIKE N'%hot%food%'
                              OR a.AreaDescription LIKE N'%bakery%'
                              OR a.AreaDescription LIKE N'%kitchen%'
                              OR a.AreaDescription LIKE N'%grab%go%'
                              OR a.AreaDescription LIKE N'%butcher%'
                              OR a.AreaDescription LIKE N'%restaurant%'
                              OR a.AreaDescription LIKE N'% BOH%'
                            THEN N'Area name' END AS Basis
            ) b
            CROSS APPLY (SELECT CASE WHEN b.Basis IS NULL THEN 0 ELSE 1 END AS Proposed, b.Basis) p
            LEFT JOIN [{$schema}].StockAreaSetting s
                   ON s.BranchId = a.BranchId AND s.AreaNo = a.AreaNo";
    }

    /** v1__14g's definition plus the duplicate-issue columns. Nothing else changes. */
    private function runLineView(string $schema): string
    {
        return "
            SELECT rl.BranchId,
                   rl.Id,
                   rl.RunId,
                   rl.[LineNo],
                   rl.AreaNo,
                   rl.StockItemNo,
                   rl.TransactionDate,
                   rl.ShiftNo,
                   rl.SellPrice,
                   rl.QtyOpen,
                   rl.QtyIssued,
                   rl.QtyClose,
                   rl.QtyPOS,
                   rl.QtyVar,
                   rl.QtyOpenNew,
                   rl.QtyCloseNew,
                   rl.QtyVarNew,
                   rl.AmendClose,
                   rl.IsDormant,
                   rl.ActiveSeq,
                   rl.ActiveLen,
                   rl.ChainNetVar,
                   rl.FlagNetOver,
                   rl.FlagSoldMoreThanOnHand,
                   rl.FlagCloseExceedsOnHand,
                   rl.FlagIssueWentNowhere,
                   rl.FlagBigAmendment,
                   rl.FlagPctAmendment,
                   rl.FlagNegativeClose,
                   rl.FlagShortChain,
                   rl.FlagDormantMoved,
                   rl.FlagChainBroken,
                   rl.ChainBlocked,
                   rl.ExceptionCode,
                   rl.Outcome,
                   rl.WouldAmend,
                   rl.Selected,
                   rl.CommitState,
                   rl.BlockReason,
                   rl.CreatedAt,
                   rl.CreatedBy,
                   rl.UpdatedAt,
                   rl.UpdatedBy,
                   rl.PrevClose,
                   rl.ChainBreak,
                   rl.QtyOpenNow,
                   rl.QtyCloseNow,
                   rl.QtyIssuedOrig,
                   rl.IssueMultiple,
                   rl.FlagPackOver,
                   rl.FlagNoPosLink,
                   rl.RestoresHand,
                   rl.FlagAuditConflict,
                   rl.AuditCode,
                   rl.BreakKept,
                   rl.DupTier,
                   rl.DupTwinDate,
                   rl.DupTwinShift,
                   rl.DupTwinVar,
                   rl.DupQty,
                   rl.DupReason,
                   rl.QtyIssuedNew,
                   rl.CountsMove,
                   ISNULL(rl.ItemDescription, m.StockItemDescription) AS ItemDescription,
                   ISNULL(rl.POSCode,         m.POSCode)              AS POSCode,
                   ISNULL(rl.StockLocation,   m.StockLocation)        AS StockLocation,
                   ISNULL(rl.AreaDescription, a.AreaDescription)      AS AreaDescription,
                   m.UOMCode,
                   CASE WHEN rl.EmployeeCodes IS NOT NULL THEN rl.EmployeeCodes
                        ELSE se.EmployeeCodes END                     AS EmployeeCodes,
                   CASE WHEN rl.EmployeeCodes IS NOT NULL THEN rl.EmployeeNames
                        ELSE se.EmployeeNames END                     AS EmployeeNames,
                   CASE WHEN rl.EmployeeCodes IS NOT NULL THEN rl.EmployeeCount
                        ELSE ISNULL(se.EmployeeCount, 0) END          AS EmployeeCount
            FROM [{$schema}].StockReconRunLine rl
            OUTER APPLY (SELECT TOP 1 m.StockItemDescription, m.POSCode, m.StockLocation, m.UOMCode
                           FROM [{$schema}].vw_StockMaster m
                          WHERE m.BranchId = rl.BranchId AND m.StockItemNo = rl.StockItemNo
                          ORDER BY CASE WHEN m.AreaNo = rl.AreaNo THEN 0 ELSE 1 END, m.StockLocation) m
            LEFT JOIN [{$schema}].vw_StockArea a
                   ON a.BranchId = rl.BranchId AND a.AreaNo = rl.AreaNo
            LEFT JOIN [{$schema}].vw_StockReconShiftEmployee se
                   ON se.BranchId        = rl.BranchId
                  AND se.TransactionDate = rl.TransactionDate
                  AND se.ShiftNo         = rl.ShiftNo
                  AND se.AreaNo          = rl.AreaNo";
    }
};
