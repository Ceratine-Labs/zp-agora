<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Recon — one press across every site (slot 13g).
 *
 * A lettered follow-on rather than an edit to v1__13, which has already run
 * against the customer's instance.
 *
 * WHY THIS TABLE EXISTS AT ALL. `ReconRun.GroupRef` has been there since the
 * ledger landed, precisely so "reconcile every branch for August" could be one
 * action with no NULL branch anywhere. What it could not carry is everything
 * ABOUT the press rather than about one of its runs:
 *
 *   · which sites were MEANT to be in it. Derived from the runs, a group of
 *     twenty-six branches where four have not started yet is indistinguishable
 *     from a group of twenty-two.
 *   · the arguments, once, rather than copied onto every run and hoped to
 *     agree.
 *   · what it is called, and who pressed it.
 *
 * The recon clerks run five areas across twenty-six sites every morning and
 * refer to the result as one thing — "this morning's ABSA" — so it is one row.
 *
 * BRANCHID IS THE GROUP ENTITY (2, Zululand Petroleum), never NULL and never
 * one of the sites: a group is not about a site, and the rule that made legacy
 * rows unreportable was allowing NULL for exactly this case. The RUNS
 * underneath it each carry their own site, which is where the branch scope
 * does its work.
 */
return new class extends Migration
{
    public function up(): void
    {
        MigrationHelper::table('ReconRunGroup', function (Blueprint $table) {
            // The uuid the runs already carry. One column, two tables, and the
            // runs are found by it rather than by this row's identity — so a
            // run recorded before this table existed still joins.
            $table->uuid('GroupRef');

            $table->string('ReconArea', 20);
            $table->date('FromDate');
            $table->date('ToDate');

            // The arguments, once. Every run in the group is given exactly
            // these, so a group whose runs disagree about the reading it was
            // given cannot happen.
            $table->text('ParamsJson')->nullable();

            $table->string('Note', 300)->nullable();

            // running | complete | committed | abandoned
            //
            // "complete" means every site has been attempted, NOT that every
            // site succeeded — a branch with no criteria row is a recorded
            // failure and the group is finished with it.
            $table->string('Status', 20)->default('running');

            // What was meant to happen, so progress is a fraction of something
            // rather than a count that stops for no visible reason.
            $table->integer('BranchCount')->default(0);
            $table->integer('CompletedCount')->default(0);
            $table->integer('FailedCount')->default(0);

            $table->dateTime('CompletedAt', 0)->nullable();

            $table->dateTime('CommittedAt', 0)->nullable();
            $table->integer('CommittedBy')->nullable();
            $table->integer('CommittedBranches')->default(0);
            $table->integer('CommittedRows')->default(0);
            MigrationHelper::money($table, 'CommittedTotal');
        });
        MigrationHelper::naturalKey('ReconRunGroup', ['BranchId', 'GroupRef']);
        MigrationHelper::rowVersion('ReconRunGroup');

        DB::statement(
            'CREATE INDEX [IX_ReconRunGroup_Area] ON ['.config('agora.schema').'].[ReconRunGroup] '
            .'([BranchId], [ReconArea], [CreatedAt]) INCLUDE ([Status], [BranchCount], [CompletedCount]);'
        );

        MigrationHelper::recordVersion(
            '1.3',
            'Recon: one press across every site — the group a morning\'s runs belong to, '
            .'what it was meant to cover, and what it committed.'
        );
    }

    /**
     * Local sandbox only; live and staging are forward-only.
     */
    public function down(): void
    {
        MigrationHelper::drop('ReconRunGroup');
    }
};
