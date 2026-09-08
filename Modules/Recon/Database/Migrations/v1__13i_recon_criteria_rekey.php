<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Recon — an override shadows a RULE, not a (site, area, order) (slot 13i).
 *
 * v1__13h keyed agora.ReconCriteria on (BranchId, BankReconArea, ProcessOrder)
 * and had the view shadow the legacy row on the same triple. That assumed the
 * triple identifies a rule in BRN_AutoReconCriteria. IT DOES NOT, and the
 * customer's own data says so: branch 23 has TWO FNB rules, both ProcessOrder
 * 1, ids 283 and 293, identical in every column. 133 rules, 132 distinct
 * triples.
 *
 * Two things were wrong because of it, and the second is the serious one:
 *
 *  1. The configuration grid fanned out. Its key list deduplicated to 132 and
 *     then LEFT JOINed the effective view and the legacy view on the triple,
 *     so branch 23's FNB rule met itself twice on each side and became FOUR
 *     rows: 135 where the estate holds 133. Caught by reading the count
 *     against the view's, which is the check this project's rules exist to
 *     make habitual.
 *
 *  2. An override on that rule would have shadowed BOTH of them. The view's
 *     NOT EXISTS matched the triple, so one override row would have silently
 *     replaced two legacy rules with one — changing what a preview resolves
 *     against in a way nothing on the screen would have shown.
 *
 * Nothing was lost: agora.ReconCriteria is empty on every instance, because
 * this shipped an hour before the defect was found. That is the only reason
 * the key can be changed rather than migrated around.
 *
 * THE REAL IDENTITY IS AutoReconId, which IS unique across all 133 rows. So an
 * override that REPLACES a rule now names it — `LegacyAutoReconId` — and the
 * view shadows on that. An override that ADDS a rule to a branch with none has
 * no legacy id, so it keeps the triple, and that is fine because the triple is
 * only ambiguous where a legacy row already exists.
 *
 * Two filtered unique indexes rather than one key, because those are two
 * different statements: one override per legacy rule, and one addition per
 * (site, area, order).
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = config('agora.schema');

        /*
         * MigrationHelper::naturalKey() made this a unique index; it is now the
         * wrong statement about the data. Dropped by name, and only if it is
         * there, so this is safe on an instance built after the fix.
         */
        DB::unprepared("
            IF EXISTS (SELECT 1 FROM sys.indexes
                       WHERE name = 'UX_ReconCriteria_Natural'
                         AND object_id = OBJECT_ID('[{$schema}].[ReconCriteria]'))
                DROP INDEX [UX_ReconCriteria_Natural] ON [{$schema}].[ReconCriteria];
        ");

        // One override per legacy rule.
        DB::unprepared("
            IF NOT EXISTS (SELECT 1 FROM sys.indexes
                           WHERE name = 'UX_ReconCriteria_Legacy'
                             AND object_id = OBJECT_ID('[{$schema}].[ReconCriteria]'))
                CREATE UNIQUE INDEX [UX_ReconCriteria_Legacy]
                    ON [{$schema}].[ReconCriteria] ([BranchId], [LegacyAutoReconId])
                    WHERE [LegacyAutoReconId] IS NOT NULL;
        ");

        // One ADDITION per (site, area, order) — an override with no legacy
        // rule behind it, which is the twenty-four-branches case.
        DB::unprepared("
            IF NOT EXISTS (SELECT 1 FROM sys.indexes
                           WHERE name = 'UX_ReconCriteria_Added'
                             AND object_id = OBJECT_ID('[{$schema}].[ReconCriteria]'))
                CREATE UNIQUE INDEX [UX_ReconCriteria_Added]
                    ON [{$schema}].[ReconCriteria] ([BranchId], [BankReconArea], [ProcessOrder])
                    WHERE [LegacyAutoReconId] IS NULL;
        ");

        $this->view();
    }

    /**
     * The view, shadowing on the rule's own id.
     *
     * Its column list is unchanged, so the seven procedures that read it — the
     * five previews and both drills — still see exactly the shape they always
     * have, and with no override present they still see exactly the customer's
     * rows.
     */
    protected function view(): void
    {
        $schema = config('agora.schema');
        $erp = config('agora.source_databases.erp');

        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_AutoReconCriteria] AS

            /* The override, where one is active. A row here REPLACES the rule
               it names rather than patching it. */
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

            /* The customer's own rule, wherever no active override names it.
               By AutoReconId, which is unique across the estate — the triple
               is not: branch 23 has two FNB rules both at ProcessOrder 1, and
               shadowing on the triple would have replaced both with one. */
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
                  AND o.LegacyAutoReconId = c.AutoReconId
            );
        ");
    }

    /** Local sandbox only; live is forward-only. */
    public function down(): void
    {
        $schema = config('agora.schema');

        foreach (['UX_ReconCriteria_Legacy', 'UX_ReconCriteria_Added'] as $index) {
            DB::unprepared("
                IF EXISTS (SELECT 1 FROM sys.indexes
                           WHERE name = '{$index}' AND object_id = OBJECT_ID('[{$schema}].[ReconCriteria]'))
                    DROP INDEX [{$index}] ON [{$schema}].[ReconCriteria];
            ");
        }
    }
};
