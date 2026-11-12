<?php

namespace Modules\Recon\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\Recon\Services\ReconGroupService;
use Throwable;

/**
 * Post ONE site of an every-site group: usp_Recon_Commit for one run.
 *
 * The press used to loop every ticked site inside the web request; a
 * three-month Smart ATM group timed out at 300 s on 28 Sep 2026. Now the
 * press writes a queued agora.ReconGroupPost per site and dispatches one of
 * these per row, so no request is long and a slow or refused site answers
 * only for itself.
 *
 * IDEMPOTENT, BY TWO LOCKS THAT ALREADY EXISTED AND ONE THAT IS NEW.
 *   · The job claims its post row queued → running in one conditional UPDATE.
 *     A duplicated delivery finds it no longer queued and does nothing.
 *   · usp_Recon_Commit re-reads the run under UPDLOCK, HOLDLOCK inside its
 *     transaction and refuses RUN_ALREADY_COMMITTED, so two workers in the
 *     same run cannot both stamp it.
 *   · The run's own Status is written in the transaction that stamps, so a
 *     retry that finds it committed records the post as committed and calls
 *     nothing.
 *
 * ONE TRY. A refusal is an answer, not a fault to retry, and a fault inside
 * the procedure rolls the whole site back (XACT_ABORT), so the operator
 * pressing again is the retry — the same one-attempt-per-press the
 * synchronous loop had. Changing that would change how often PumpIT is asked
 * to stamp, which is Ryan's call. A redelivery after a worker died is failed
 * by the queue without running, and failed() reconciles the row from the run.
 */
class PostGroupSite implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public bool $failOnTimeout = true;

    public function __construct(public int $branchId, public int $postId)
    {
        $this->timeout = (int) config('recon.group_post.timeout', 600);
        $this->onQueue((string) config('recon.group_post.queue', 'recon'));
    }

    public function handle(ReconGroupService $groups): void
    {
        $groups->postSite($this->branchId, $this->postId);
    }

    /** The worker died, timed out or the job was redelivered: say so on the row, truthfully. */
    public function failed(?Throwable $error): void
    {
        app(ReconGroupService::class)->abandonPost($this->branchId, $this->postId, $error);
    }
}
