<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Recon — what a committed reconciliation leaves behind (slot 13a).
 *
 * A lettered follow-on rather than an edit to v1__13, because that migration
 * has already run against the customer's instance.
 *
 * These three tables are the difference between Agora executing a
 * reconciliation and the PumpIT executable doing it. The exe stamps
 * `ReconState` and `ReconBatchNo` and leaves nothing at all — which is why
 * question 3.7 of the findings ("who stamped the historical reconciliations?")
 * could only be guessed at from 1,570 orphaned bank lines, and why there is no
 * way to undo a bad run.
 *
 *   ReconBatch  a batch number Agora allocated, with the two totals it was
 *               allocated against. If those ever differ the batch says so
 *               without anyone reconstructing it.
 *   ReconMatch  ONE ROW PER SIDE, not per pair. A pair is the wrong grain: an
 *               ABSA batch settled across a credit leg and two debit legs is
 *               three bank rows against however many deposit rows, and a pair
 *               table would either invent a cross product or lose the detail.
 *               A side row per source row is exactly what a reversal needs.
 *   ReconStamp  what Agora asked the customer's estate to become, written
 *               whether or not the write is applied. In journal mode it is a
 *               reviewed worklist and nothing in PumpIT moves; in live mode
 *               the same rows are applied and marked. Either way "what did the
 *               reconciliation do on 4 September" has one place to look.
 *
 * The reversal path is the reason ReconMatch records the SOURCE KEY of every
 * row it touched. Agora can put back precisely what it changed and nothing
 * else — the exe cannot put back anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * A batch number, allocated from the customer's own counter so it
         * cannot collide with one the executable hands out.
         */
        MigrationHelper::table('ReconBatch', function (Blueprint $table) {
            $table->unsignedBigInteger('RunId');
            $table->integer('BatchNo');
            $table->string('ReconArea', 20);

            // The reference the two sides were joined on, carried here so a
            // batch is readable without walking its matches.
            $table->string('KeyRef', 50)->nullable();
            $table->string('KeyRef2', 50)->nullable();

            $table->integer('BankLineCount')->default(0);
            $table->integer('MopsRowCount')->default(0);
            MigrationHelper::money($table, 'BankTotal');
            MigrationHelper::money($table, 'MopsTotal');

            // committed | reversed
            $table->string('State', 20)->default('committed');
            $table->dateTime('ReversedAt', 0)->nullable();
            $table->integer('ReversedBy')->nullable();
            $table->string('ReversalReason', 300)->nullable();
        });
        MigrationHelper::naturalKey('ReconBatch', ['BranchId', 'BatchNo']);

        MigrationHelper::table('ReconMatch', function (Blueprint $table) {
            $table->unsignedBigInteger('RunId');
            $table->unsignedBigInteger('RunLineId');
            $table->unsignedBigInteger('BatchId');
            $table->integer('BatchNo');

            // bank | mops
            $table->string('Side', 10);

            // The legacy table the row lives in, named in full. When these
            // tables are eventually adopted into agora, this column says which
            // rows came from where.
            $table->string('SourceTable', 60);

            // RCN_BankStatementLinesPumpIT has BankStatementLineID and so does
            // BRN_DailyBankingCashBags; the rest of the BRN_DailyBanking family
            // is keyed on a composite, so the key travels as JSON rather than
            // flattened into a string nothing can take apart again.
            $table->bigInteger('SourceId')->nullable();
            $table->string('SourceKeyJson', 400)->nullable();

            $table->date('SourceDate')->nullable();
            MigrationHelper::money($table, 'Amount');

            // What the row held BEFORE Agora touched it. A reversal restores
            // these rather than assuming the prior state was the default —
            // a line reconciled by the exe and then re-reconciled by us must
            // go back to the exe's batch number, not to zero.
            $table->integer('PriorReconState')->nullable();
            $table->integer('PriorBatchNo')->nullable();
        });

        MigrationHelper::table('ReconStamp', function (Blueprint $table) {
            $table->unsignedBigInteger('RunId');
            $table->unsignedBigInteger('MatchId');
            $table->integer('BatchNo');

            $table->string('TargetDatabase', 60);
            $table->string('TargetTable', 60);
            $table->string('TargetKeyJson', 400);
            $table->string('SetColumns', 200);

            // journal | applied | reversed | failed
            $table->string('State', 20)->default('journal');
            $table->dateTime('AppliedAt', 0)->nullable();
            $table->integer('AppliedBy')->nullable();
            $table->integer('RowsAffected')->nullable();
            $table->string('FailureMessage', 400)->nullable();
        });

        $this->indexes();

        /*
         * Retire the combined drill.
         *
         * usp_Recon_DrillProposal returned both sides in one call, which the
         * screen could use and usp_Recon_Commit could not: `INSERT INTO @t
         * EXEC` captures only the first result set. It is replaced by
         * usp_Recon_DrillBank and usp_Recon_DrillMops, so the rows the
         * operator looked at and the rows that get stamped come out of one
         * extraction rather than two copies of it.
         *
         * Dropped rather than left behind: an object nothing calls, that still
         * looks like the rule the system follows, is the worst kind of dead
         * code in a database the customer reads.
         */
        DB::unprepared('DROP PROCEDURE IF EXISTS ['.config('agora.schema').'].[usp_Recon_DrillProposal];');

        MigrationHelper::recordVersion(
            '1.2',
            'Recon: allocated batches, per-side matches with the prior state of every row, '
            .'and the stamp record — so a reconciliation Agora executes can be undone exactly.'
        );
    }

    protected function indexes(): void
    {
        $schema = config('agora.schema');

        foreach ([
            "CREATE INDEX [IX_ReconBatch_Run] ON [{$schema}].[ReconBatch] ([BranchId], [RunId], [State]);",
            "CREATE INDEX [IX_ReconMatch_Run] ON [{$schema}].[ReconMatch] ([BranchId], [RunId], [BatchNo]);",
            // The reverse lookup, and the question the findings could not
            // answer: given a bank line, what claimed it, and when?
            "CREATE INDEX [IX_ReconMatch_Source] ON [{$schema}].[ReconMatch] ([BranchId], [SourceTable], [SourceId]);",
            "CREATE INDEX [IX_ReconStamp_Run] ON [{$schema}].[ReconStamp] ([BranchId], [RunId], [State]);",
        ] as $statement) {
            DB::statement($statement);
        }
    }

    public function down(): void
    {
        foreach (['ReconStamp', 'ReconMatch', 'ReconBatch'] as $table) {
            MigrationHelper::drop($table);
        }
    }
};
