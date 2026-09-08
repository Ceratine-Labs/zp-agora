<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Recon — the extraction configuration, in a table Agora is allowed to write
 * (slot 13h).
 *
 * WHY THIS EXISTS. ZP asked to be able to change the auto-recon configuration
 * from the application. That configuration is `BRN_AutoReconCriteria` and it
 * lives in PumpIT, which Agora never writes to — so an editor over it cannot
 * exist. Ryan chose the way through on 8 September 2026: an Agora-owned table
 * that SHADOWS the legacy rows, and `agora.vw_AutoReconCriteria` — already the
 * only way the five previews and both drills reach the configuration — decides
 * which of the two a caller sees.
 *
 * A ROW HERE REPLACES ITS LEGACY ROW ENTIRELY. Not a patch, not a column-wise
 * COALESCE. Two reasons, and both are about being able to read the screen:
 *
 *   · A per-column merge cannot express "this rule has no FILTER_Value" when
 *     the legacy row has one, because NULL would mean "inherit". Replacement
 *     has no such hole.
 *   · The editor has to show the legacy values beside the effective ones or a
 *     divergence is invisible — that is the accepted cost of two sources of
 *     truth. Comparing two complete rows is a thing a person can do; comparing
 *     a row against a sparse patch is not.
 *
 * AN OVERRIDE MAY ALSO ADD A RULE THE CUSTOMER NEVER HAD, and that is the
 * larger half. Finding 1 is that twenty-four of twenty-six branches have no
 * criteria row at all, so their previews can only refuse. Those branches need
 * a rule invented, not corrected — so the view's second arm returns overrides
 * that match no legacy row, with a NEGATIVE AutoReconId. Negative because the
 * legacy ids are positive identities and the previews use AutoReconId as a
 * tie-break in their rule ordering: a negative can never collide, and it is
 * obvious on sight which rows are ours.
 *
 * REVERTING IS `IsActive = 0`, NOT A DELETE. The row stays, with the reason it
 * was made and who made it, and the view stops seeing it. A configuration
 * change that vanishes without trace is the thing this table exists to end.
 *
 * Nothing here writes to PumpIT. The legacy table is read exactly as it was.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * The columns mirror BRN_AutoReconCriteria's own — same names,
         * underscores and all, for the reason v1__13 gives about the legacy
         * views: `BANK_StartPosition` is what the customer greps for in SSMS
         * and what the findings document cites. Renaming them here would make
         * the override stop being a readable diff against the row it replaces.
         */
        MigrationHelper::table('ReconCriteria', function (Blueprint $table) {
            $table->string('BankReconArea', 20);
            $table->integer('ProcessOrder');

            // The legacy row this replaces, when it replaces one. Null means
            // this override ADDS a rule the branch never had — which is the
            // case for twenty-four of twenty-six branches (finding 1).
            $table->integer('LegacyAutoReconId')->nullable();

            $table->integer('BANK_StartPosition')->nullable();
            $table->integer('BANK_EndPosition')->nullable();
            $table->integer('BANK_StartPosition2')->nullable();
            $table->integer('BANK_EndPosition2')->nullable();
            $table->integer('MOPS_StartPosition')->nullable();
            $table->integer('MOPS_EndPosition')->nullable();
            $table->string('FILTER_Value', 50)->nullable();
            $table->integer('FILTER_StartPosition')->nullable();
            $table->integer('FILTER_EndPosition')->nullable();

            // Reverting is switching this off, so the row and its reason stay.
            $table->boolean('IsActive')->default(true);

            // Required by the service on every write. A configuration change
            // with no reason is the thing the legacy estate is full of.
            $table->string('Reason', 300)->nullable();

            // Where it came from, when it was copied rather than typed.
            $table->integer('CopiedFromBranchId')->nullable();
        });

        // One override per legacy rule. BranchId leads, as every key must.
        MigrationHelper::naturalKey('ReconCriteria', ['BranchId', 'BankReconArea', 'ProcessOrder']);
        MigrationHelper::rowVersion('ReconCriteria');

        $this->view();

        MigrationHelper::recordVersion(
            '1.4',
            'Recon: an Agora-owned override for the extraction configuration, and the view that '
            .'decides whether a caller sees ours or the customer\'s.'
        );
    }

    /**
     * The view, redefined.
     *
     * Read by usp_Recon_PreviewABSA, PreviewFNB, PreviewCashMachine,
     * PreviewCashBags, PreviewSmartATM, DrillBank and DrillMops — seven
     * callers, none of which change. Its column list is exactly what it was:
     * a caller that worked yesterday sees the same shape today, and where no
     * override exists it sees the same rows.
     *
     * Enumerated rather than `SELECT *` for the reason v1__13 gives: a view
     * over a star binds its column list at creation and does not notice an
     * ALTER TABLE underneath it.
     */
    protected function view(): void
    {
        $schema = config('agora.schema');
        $erp = config('agora.source_databases.erp');

        /*
         * The customer's own rows, unshadowed.
         *
         * `vw_AutoReconCriteria` answers "what is in force", which is what the
         * previews need and all they need. The configuration SCREEN needs the
         * other question too — "what does the customer's own table say" —
         * because the accepted cost of an override is two sources of truth,
         * and a screen that could not show both would make a divergence
         * invisible. Two views rather than one with a flag: the previews must
         * never accidentally see an unshadowed row.
         */
        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_LegacyReconCriteria] AS
            SELECT c.AutoReconId,
                   c.SSBranchId AS BranchId,
                   c.BankReconArea,
                   c.ProcessOrder,
                   c.BANK_StartPosition,
                   c.BANK_EndPosition,
                   c.BANK_StartPosition2,
                   c.BANK_EndPosition2,
                   c.MOPS_StartPosition,
                   c.MOPS_EndPosition,
                   c.FILTER_Value,
                   c.FILTER_StartPosition,
                   c.FILTER_EndPosition
            FROM [{$erp}].dbo.BRN_AutoReconCriteria c;
        ");

        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_AutoReconCriteria] AS

            /* The override, where one is active. A row here REPLACES its
               legacy row rather than patching it — see the migration header. */
            SELECT CONVERT(int, -o.Id)          AS AutoReconId,
                   o.BranchId,
                   o.BankReconArea,
                   o.ProcessOrder,
                   o.BANK_StartPosition,
                   o.BANK_EndPosition,
                   o.BANK_StartPosition2,
                   o.BANK_EndPosition2,
                   o.MOPS_StartPosition,
                   o.MOPS_EndPosition,
                   o.FILTER_Value,
                   o.FILTER_StartPosition,
                   o.FILTER_EndPosition
            FROM [{$schema}].[ReconCriteria] o
            WHERE o.IsActive = 1

            UNION ALL

            /* The customer's own row, wherever no active override claims it. */
            SELECT c.AutoReconId,
                   c.SSBranchId AS BranchId,
                   c.BankReconArea,
                   c.ProcessOrder,
                   c.BANK_StartPosition,
                   c.BANK_EndPosition,
                   c.BANK_StartPosition2,
                   c.BANK_EndPosition2,
                   c.MOPS_StartPosition,
                   c.MOPS_EndPosition,
                   c.FILTER_Value,
                   c.FILTER_StartPosition,
                   c.FILTER_EndPosition
            FROM [{$erp}].dbo.BRN_AutoReconCriteria c
            WHERE NOT EXISTS (
                SELECT 1
                FROM [{$schema}].[ReconCriteria] o
                WHERE o.IsActive = 1
                  AND o.BranchId = c.SSBranchId
                  AND o.BankReconArea = c.BankReconArea
                  AND o.ProcessOrder = c.ProcessOrder
            );
        ");
    }

    /**
     * Local sandbox only. Live is forward-only — and note that putting the
     * view back the way it was is the ONLY safe half of this: dropping the
     * table while an override is active would silently change what every
     * preview resolves.
     */
    public function down(): void
    {
        $schema = config('agora.schema');
        $erp = config('agora.source_databases.erp');

        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_AutoReconCriteria] AS
            SELECT c.AutoReconId,
                   c.SSBranchId AS BranchId,
                   c.BankReconArea,
                   c.ProcessOrder,
                   c.BANK_StartPosition,
                   c.BANK_EndPosition,
                   c.BANK_StartPosition2,
                   c.BANK_EndPosition2,
                   c.MOPS_StartPosition,
                   c.MOPS_EndPosition,
                   c.FILTER_Value,
                   c.FILTER_StartPosition,
                   c.FILTER_EndPosition
            FROM [{$erp}].dbo.BRN_AutoReconCriteria c;
        ");

        DB::unprepared("DROP VIEW IF EXISTS [{$schema}].[vw_LegacyReconCriteria];");

        MigrationHelper::drop('ReconCriteria');
    }
};
