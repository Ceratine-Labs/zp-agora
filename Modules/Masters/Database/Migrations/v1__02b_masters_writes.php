<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * Masters — the ledger of what a reference-master edit wrote to PumpIT (T022).
 *
 * A NAMED WRITE (CLAUDE.md rule 1). Ryan's standing rule, 12 Nov 2026 —
 * "agora is replacing pumpit, always write back to pumpit with confirmation"
 * (PM decision 01a1cecf-8e08): an edit to a master PumpIT owns writes PumpIT's
 * own table, never an Agora-only copy. Each master's write is its own
 * procedure over the narrowest columns, and every value it changes is
 * recorded here first, so it can be put back:
 *
 *   SDK_CategoryGLCode   MINGP, MAXGP, MINDaysCover, MAXDaysCover, IsActive
 *                        usp_Masters_SaveCategoryBand
 *   (more masters are added to this list as their writes are built)
 *
 *   agora.MasterWrite    one row per PumpIT value a press changed: the
 *                        table, the row (RowKey, as the screen names it, and
 *                        KeyJson, PumpIT's own key), the column, its PRIOR and
 *                        NEW value as text for reading, and in ExactJson the
 *                        prior exactly as stored (a float written back from
 *                        its decimal text would not be the same float).
 *                        Rows of one press share a WriteId; the reversal,
 *                        usp_Masters_ReverseWrite, undoes a press as a whole
 *                        and refuses where PumpIT has moved since (MOVED).
 *
 * BranchId is the site of the PumpIT row, or the group entity for a
 * group-wide table. Read by agora.vw_PumpItChange (added by a Reports
 * migration once this ledger exists), as every named write's ledger is —
 * tests/Feature/Reports/AuditTrailTest finds it by its name.
 */
return new class extends Migration
{
    public function up(): void
    {
        MigrationHelper::table('MasterWrite', function (Blueprint $table) {
            $table->uuid('WriteId');
            // The master, as the reference-master screens name it: CategoryGl,
            // ProfitCentre, StandardCategory …
            $table->string('Entity', 40);
            $table->string('PumpItTable', 60);
            $table->string('RowKey', 200);
            $table->string('RowLabel', 200)->nullable();
            $table->string('KeyJson', 400);
            $table->string('Field', 40);
            $table->string('PriorValue', 400)->nullable();
            $table->string('NewValue', 400)->nullable();
            $table->string('ExactJson', 400)->nullable();
            $table->string('Reason', 400);
            $table->string('WriteMode', 10);
            $table->string('State', 10)->default('active');
            MigrationHelper::stamp($table, 'ReversedAt');
            $table->integer('ReversedBy')->nullable();
            $table->string('ReversalReason', 400)->nullable();
            $table->index(['BranchId', 'WriteId'], 'IX_MasterWrite_Write');
            $table->index(['BranchId', 'Entity', 'RowKey'], 'IX_MasterWrite_Row');
        });

        MigrationHelper::recordVersion(
            '1.49',
            'Masters (T022): agora.MasterWrite, the ledger of reference-master edits written back to PumpIT '
            .'(PM decision 01a1cecf-8e08).'
        );
    }

    /** Local sandbox only. Live is forward-only. */
    public function down(): void
    {
        MigrationHelper::drop('MasterWrite');
    }
};
