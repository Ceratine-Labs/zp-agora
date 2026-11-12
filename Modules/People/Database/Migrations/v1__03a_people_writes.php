<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

/**
 * People — the ledger of what an employee edit wrote to PumpIT (T023, slot 03a).
 *
 * A NAMED WRITE (CLAUDE.md rule 1; Ryan's go-ahead, PM decision
 * 01a1ceba-4d74, 12 Nov 2026). Agora's employee edits write back into the
 * customer's tables, because PumpIT keeps trading on them and a copy would
 * split the source of truth. Exactly these, and nothing else:
 *
 *   BRN_Employee       UPDATE IsActive, PostNo, NickName   usp_People_SaveEmployee
 *   BRN_EmployeeArea   INSERT; DELETE one row              usp_People_SaveEmployeeArea
 *   BRN_EmployeePost   INSERT                              usp_People_AddPost
 *
 * and their reversal, usp_People_ReverseWrite, which puts each back.
 *
 *   agora.PeopleWrite  one row per PumpIT value a press changed: the column,
 *                      its PRIOR value and its NEW one, so it can be put back
 *                      exactly. A removed area keeps the whole deleted row in
 *                      RowJson (its Autoidx and MIST_EOD) so the reversal
 *                      re-inserts what was there. Rows of one press share a
 *                      WriteId; the reversal undoes a press as a whole, and
 *                      refuses where PumpIT has moved since (MOVED).
 *
 * Read by agora.vw_PumpItChange (Reports v1__22m), as every named write's
 * ledger is — tests/Feature/Reports/AuditTrailTest finds it by its name.
 */
return new class extends Migration
{
    public function up(): void
    {
        MigrationHelper::table('PeopleWrite', function (Blueprint $table) {
            $table->uuid('WriteId');
            /*
             * employee     a BRN_Employee column (Field = IsActive | PostNo | NickName)
             * area-add     a BRN_EmployeeArea row inserted
             * area-remove  a BRN_EmployeeArea row deleted (RowJson holds it)
             * post-add     a BRN_EmployeePost row inserted
             */
            $table->string('Verb', 12);
            $table->string('PumpItTable', 40);
            $table->string('EmployeeCode', 11)->nullable();
            $table->integer('AreaNo')->nullable();
            $table->smallInteger('PostNo')->nullable();
            $table->string('Field', 40);
            $table->string('PriorValue', 100)->nullable();
            $table->string('NewValue', 100)->nullable();
            $table->string('RowJson', 1000)->nullable();
            $table->string('Reason', 400);
            $table->string('WriteMode', 10);
            $table->string('State', 10)->default('active');
            MigrationHelper::stamp($table, 'ReversedAt');
            $table->integer('ReversedBy')->nullable();
            $table->string('ReversalReason', 400)->nullable();
            $table->index(['BranchId', 'WriteId'], 'IX_PeopleWrite_Write');
            $table->index(['BranchId', 'EmployeeCode'], 'IX_PeopleWrite_Employee');
        });

        MigrationHelper::recordVersion(
            '1.1',
            'People (T023): agora.PeopleWrite, the ledger of employee edits written back to BRN_Employee, '
            .'BRN_EmployeeArea and BRN_EmployeePost (PM decision 01a1ceba-4d74).'
        );
    }

    /** Local sandbox only. Live is forward-only. */
    public function down(): void
    {
        MigrationHelper::drop('PeopleWrite');
    }
};
