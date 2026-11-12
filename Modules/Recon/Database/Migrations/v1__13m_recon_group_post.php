<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Recon — posting every site, one queued job per site (slot 13m).
 *
 * A lettered follow-on: v1__13g made ReconRunGroup and has run on the
 * customer's instance. Additive only — one new table, one index.
 *
 * WHY. On 28 Sep 2026 a three-month Smart ATM group timed out at 300 s: the
 * press posted every ticked site through usp_Recon_Commit inside one web
 * request. Ryan's decision (12 Nov 2026): one queued job per site, a queue
 * worker, and a group screen that polls. This table is where each site's post
 * is answered, in Agora, so the screen and a retry read Agora rather than the
 * queue backend — a flushed Redis loses nothing that matters.
 *
 * ONE ROW PER RUN, NOT PER PRESS. A run is committed at most once
 * (usp_Recon_Commit refuses RUN_ALREADY_COMMITTED under an UPDLOCK on the run),
 * so the post of a run is one thing that can be retried rather than a list of
 * attempts. Attempts counts the presses; Code and Message hold the last answer.
 *
 *   Status   queued    a job is on the queue for it
 *            running   a worker has claimed it
 *            committed the run is posted (by this job or, found on a retry, by
 *                      an earlier attempt of it)
 *            refused   usp_Recon_Commit said no (AGORA:{Code}); the run is as
 *                      it was and may be pressed again
 *            failed    a fault, or a worker that died or timed out; the run's
 *                      own status says whether anything was stamped
 *
 * BranchId is the SITE, as on the run — not the group entity. The clustered
 * key leads with it like every Agora table; the screen finds a group's posts
 * by GroupRef, the way IX_ReconRun_Group finds its runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        MigrationHelper::table('ReconGroupPost', function (Blueprint $table) {
            $table->uuid('GroupRef');
            $table->bigInteger('RunId');

            $table->string('Status', 20)->default('queued');
            $table->integer('Attempts')->default(0);

            $table->string('Code', 60)->nullable();
            $table->string('Message', 400)->nullable();

            $table->integer('CommittedRows')->default(0);
            MigrationHelper::money($table, 'CommittedTotal');

            $table->rawColumn('QueuedAt', 'DATETIME2(0)')->nullable();
            $table->rawColumn('StartedAt', 'DATETIME2(0)')->nullable();
            $table->rawColumn('FinishedAt', 'DATETIME2(0)')->nullable();
        });
        MigrationHelper::naturalKey('ReconGroupPost', ['BranchId', 'RunId']);

        DB::statement(
            'CREATE INDEX [IX_ReconGroupPost_Group] ON ['.config('agora.schema').'].[ReconGroupPost] '
            .'([GroupRef]) INCLUDE ([Status], [RunId]);'
        );

        MigrationHelper::recordVersion(
            '1.47',
            'Recon: agora.ReconGroupPost answers each site of an every-site post, '
            .'one queued job per site, so the press queues work and no web request posts a site.'
        );
    }

    /**
     * Local sandbox only; live and staging are forward-only.
     */
    public function down(): void
    {
        MigrationHelper::drop('ReconGroupPost');
    }
};
