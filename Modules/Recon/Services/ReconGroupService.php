<?php

namespace Modules\Recon\Services;

use App\Exceptions\AgoraProcException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Recon\Jobs\PostGroupSite;
use Modules\Recon\Models\ReconGroupPost;
use Modules\Recon\Models\ReconRun;
use Modules\Recon\Models\ReconRunGroup;
use Throwable;

/**
 * One press, every site.
 *
 * The recon clerks run the same area for twenty-six branches one at a time.
 * This is that morning as a single action: a date range, no site, and a run
 * per branch under one GroupRef.
 *
 * WHAT IT DELIBERATELY IS NOT. It is not a second preview path. Every branch
 * goes through ReconService::preview() with exactly the arguments the group
 * stored, so a run launched from here is indistinguishable from one launched
 * by hand — same procedure, same recording, same commit, same reversal. A
 * group is a way of pressing the button, not a different reconciliation.
 *
 * ONE BRANCH PER CALL, AND WHY. Twenty-six previews of a busy month is minutes
 * of wall clock, and a request that does all of them either times out or shows
 * a spinner for two minutes and cannot say which site it is on. The screen
 * drives them one at a time and each call is a single ordinary preview, so a
 * branch that refuses is a recorded row rather than a group that half
 * happened.
 *
 * A REFUSAL IS RECORDED, NOT SWALLOWED. A branch with no BRN_AutoReconCriteria
 * row is finding 1 — twenty-four of twenty-six branches producing nothing on
 * the live system, with the screen never saying why. In a group that
 * distinction is the whole answer: "nothing to reconcile at Empangeni" and
 * "Empangeni has no rule configured" look identical in a count and are
 * opposite facts. So a refused branch becomes a ReconRun with Status =
 * 'failed' and the reason on it, which is what those columns were added for.
 */
class ReconGroupService
{
    public function __construct(protected ReconService $service) {}

    /**
     * Open a group over a set of sites. Nothing is previewed yet.
     *
     * @param  array<int, int>  $branchIds
     * @param  array<string, scalar|null>  $options
     */
    public function start(
        string $area,
        Carbon $from,
        Carbon $to,
        array $branchIds,
        array $options = [],
        ?string $note = null,
    ): ReconRunGroup {
        // The area is resolved first so an unknown one refuses before a row is
        // written, rather than leaving an empty group behind.
        $this->service->area($area);

        $group = ReconRunGroup::create([
            // Never one of the sites: a group is not about a site. See the
            // migration's header.
            'BranchId' => (int) config('agora.group_branch_id', 2),
            'GroupRef' => (string) Str::uuid(),
            'ReconArea' => $area,
            'FromDate' => $from->toDateString(),
            'ToDate' => $to->toDateString(),
            'ParamsJson' => json_encode($options),
            'Note' => $note,
            'Status' => 'running',
            'BranchCount' => count($branchIds),
            'CreatedBy' => auth()->id(),
            'CreatedAt' => now(),
        ]);

        /*
         * Read back, so GroupRef is the value the DATABASE holds.
         *
         * Str::uuid() is lowercase and SQL Server returns a UNIQUEIDENTIFIER
         * uppercase, so without this the redirect after creating a group used
         * one spelling and every link after it used the other. The model used
         * to paper over that with an accessor, and the accessor emptied the
         * runs relation — see the header of ReconRunGroup.
         */
        return $group->fresh() ?? $group;
    }

    /**
     * Preview one site of the group.
     *
     * Returns the run either way — a refusal is a run with Status 'failed' and
     * the reason on it, so the group screen has a row to render and the trace
     * has something to find.
     *
     * Idempotent by branch: asking twice returns what is already there rather
     * than previewing the same site twice. The screen drives this from a
     * browser, and a browser retries.
     */
    public function runBranch(ReconRunGroup $group, int $branchId): ReconRun
    {
        $existing = $group->runs()->where('BranchId', $branchId)->first();

        if ($existing !== null) {
            return $existing;
        }

        /** @var array<string, scalar|null> $options */
        $options = $group->params();

        try {
            $run = $this->service->preview(
                $group->ReconArea,
                $branchId,
                $group->FromDate->copy()->startOfDay(),
                $group->ToDate->copy()->startOfDay(),
                $options,
                groupRef: $group->GroupRef,
                note: $group->Note,
            );

            $this->tally($group, failed: false);

            return $run;
        } catch (ReconPreviewRefused $e) {
            // The branch has no usable rule. An answer, and the one the live
            // system hides.
            return $this->recordFailure($group, $branchId, 'NO_CRITERIA', $e->getMessage());
        } catch (AgoraProcException $e) {
            return $this->recordFailure($group, $branchId, $e->code() ?: 'REFUSED', $e->getMessage());
        } catch (QueryException $e) {
            // A fault rather than a refusal — the estate said something we did
            // not plan for. It still must not stop the other twenty-five.
            return $this->recordFailure($group, $branchId, 'FAULT', Str::limit($e->getMessage(), 380, ''));
        } catch (Throwable $e) {
            return $this->recordFailure($group, $branchId, 'ERROR', Str::limit($e->getMessage(), 380, ''));
        }
    }

    /**
     * Commit the runs the operator ticked, one site at a time.
     *
     * SYNCHRONOUS: every site inside the caller. The group screen no longer
     * calls this — a long group timed out at 300 s on 28 Sep 2026 — and
     * queues one job per site through queuePosts() instead.
     *
     * There is no cross-branch commit procedure and there should not be:
     * usp_Recon_Commit is branch-scoped, re-checks every row against the
     * estate as it stands, and reports per row what it skipped. A procedure
     * that looped branches internally would have to defeat that scoping and
     * would make one site's refusal everyone's problem.
     *
     * So the loop is here, one transaction per branch. A branch that refuses
     * is reported and the rest still go — which is the same shape as the
     * preview above, for the same reason: twenty-six sites is twenty-six
     * separate answers, and one of them being no does not make the others no.
     *
     * @param  array<int, int>  $runIds
     * @return array<int, array{run: ReconRun, ok: bool, message: string, code: string, rows: int}>
     */
    public function commit(ReconRunGroup $group, array $runIds): array
    {
        $results = [];
        $branches = 0;
        $rows = 0;
        $total = 0.0;

        foreach ($group->runs()->whereIn('Id', $runIds)->get() as $run) {
            if ($run->Status !== 'previewed') {
                $results[] = [
                    'run' => $run,
                    'ok' => false,
                    'code' => 'NOT_PREVIEWED',
                    'message' => 'This run is '.$run->Status.' and cannot be committed again.',
                    'rows' => 0,
                ];

                continue;
            }

            try {
                // The group screen ticks sites, not lines, so nothing has
                // marked this run's proposals as selected — and the commit
                // procedure requires it. Without this every site refuses with
                // AGORA:NOTHING_SELECTED no matter how much it would have
                // reconciled. See ReconService::selectAll().
                $this->service->selectAll($run);

                $result = $this->service->commit($run);
                $committed = $run->fresh();

                $branches++;
                $rows += $committed->CommittedRows;
                $total += (float) $committed->CommittedTotal;

                $results[] = [
                    'run' => $committed,
                    'ok' => true,
                    'code' => (string) ($result['status']->Code ?? 'COMMITTED'),
                    'message' => (string) ($result['status']->Message ?? 'Committed.'),
                    'rows' => $committed->CommittedRows,
                ];
            } catch (AgoraProcException $e) {
                $results[] = [
                    'run' => $run,
                    'ok' => false,
                    'code' => $e->code() ?: 'REFUSED',
                    'message' => $e->getMessage(),
                    'rows' => 0,
                ];
            }
        }

        $group->forceFill([
            'Status' => 'committed',
            'CommittedAt' => now(),
            'CommittedBy' => auth()->id(),
            'CommittedBranches' => $group->CommittedBranches + $branches,
            'CommittedRows' => $group->CommittedRows + $rows,
            'CommittedTotal' => (float) $group->CommittedTotal + $total,
            'UpdatedAt' => now(),
        ])->save();

        return $results;
    }

    /**
     * The sites still to be previewed, in the order the screen will drive them.
     *
     * @param  array<int, int>  $branchIds
     * @return array<int, int>
     */
    public function outstanding(ReconRunGroup $group, array $branchIds): array
    {
        $done = $group->runs()->pluck('BranchId')->all();

        return array_values(array_diff($branchIds, $done));
    }

    /*
    |--------------------------------------------------------------------------
    | Posting every site — one queued job per site (Ryan, 12 Nov 2026)
    |--------------------------------------------------------------------------
    |
    | commit() above posts every ticked site inside the caller's request. On
    | 28 Sep 2026 a three-month Smart ATM group took longer than the 300 s the
    | request was allowed. The press now calls queuePosts(): one
    | agora.ReconGroupPost row and one PostGroupSite job per site, and the job
    | calls postSite(), which does for ONE site exactly what commit()'s loop
    | body does — selectAll, usp_Recon_Commit, add to the group's totals only
    | on success. The group screen polls the post rows.
    |
    | What makes a retried or duplicated job harmless:
    |   · a job claims its row queued → running in one conditional UPDATE, so
    |     a second delivery of the same job finds nothing to claim;
    |   · usp_Recon_Commit takes UPDLOCK, HOLDLOCK on the run and refuses
    |     RUN_ALREADY_COMMITTED, and writes Status = 'committed' in the
    |     transaction that stamps, so the run says whether it was posted;
    |   · the group's totals move only on the row's transition into
    |     'committed', inside the same transaction as that transition.
    */

    /**
     * Queue the ticked sites, one job per site. Nothing is posted here.
     *
     * A run that is not a preview is answered now, the way commit() answers
     * it. A site whose post is already queued or running is left alone — a
     * second press, or a second tab, must not queue it twice. A refused or
     * failed one, or one stuck past `recon.group_post.stale_after`, is
     * queued again: that press is the retry.
     *
     * On QUEUE_CONNECTION=sync each job runs inside this call, so the request
     * is as long as it always was — the fallback until a worker runs.
     *
     * @param  array<int, int>  $runIds
     * @return array<int, array{run: ReconRun, state: string, code: string, message: string}>
     */
    public function queuePosts(ReconRunGroup $group, array $runIds, ?int $userId): array
    {
        $results = [];

        foreach ($group->runs()->whereIn('Id', $runIds)->get() as $run) {
            if ($run->Status !== 'previewed') {
                $results[] = [
                    'run' => $run, 'state' => 'refused', 'code' => 'NOT_PREVIEWED',
                    'message' => 'This run is '.$run->Status.' and cannot be committed again.',
                ];

                continue;
            }

            $post = $this->queuePost($group, $run, $userId);

            if ($post === null) {
                $results[] = [
                    'run' => $run, 'state' => 'active', 'code' => 'ALREADY_QUEUED',
                    'message' => 'This site is already being posted.',
                ];

                continue;
            }

            try {
                PostGroupSite::dispatch($post->BranchId, $post->Id);
            } catch (Throwable $e) {
                // The queue backend refused the job. Nothing ran, so the row
                // must not sit at "queued" waiting for a worker that will
                // never get it.
                report($e);
                $this->finish($post, 'failed', 'NOT_QUEUED',
                    'The site could not be queued ('.Str::limit($e->getMessage(), 200, '').'). Nothing was posted.',
                    ['queued']);

                $results[] = ['run' => $run, 'state' => 'failed', 'code' => 'NOT_QUEUED', 'message' => 'The site could not be queued.'];

                continue;
            }

            $results[] = ['run' => $run, 'state' => 'queued', 'code' => 'QUEUED', 'message' => 'Queued.'];
        }

        return $results;
    }

    /**
     * Post one site. Called by PostGroupSite, once per delivery.
     *
     * Every outcome is written to the post row and nothing is thrown: the
     * row IS the answer, and a job that threw would be a second, worse record
     * of the same thing in the failed-jobs store.
     */
    public function postSite(int $branchId, int $postId): void
    {
        $now = now();

        $claimed = $this->postQuery($branchId, $postId)
            ->where('Status', 'queued')
            ->update([
                'Status' => 'running',
                'StartedAt' => $now,
                'Attempts' => DB::raw('[Attempts] + 1'),
                'UpdatedAt' => $now,
            ]);

        // Not queued: a duplicate delivery of a job that has already run, or
        // is running in another worker. Whichever it is, it is not ours.
        if ($claimed !== 1) {
            return;
        }

        /** @var ReconGroupPost $post */
        $post = $this->postQuery($branchId, $postId)->firstOrFail();
        $run = $this->runFor($post);

        if ($run === null) {
            $this->finish($post, 'failed', 'RUN_NOT_FOUND', 'The run behind this site no longer exists.');

            return;
        }

        if ($run->Status === 'committed') {
            // Posted already, by an earlier attempt of this post whose worker
            // stopped before it could say so. The run is the truth.
            $this->recordCommitted($post, $run, 'ALREADY_POSTED',
                'Found already posted on this attempt; nothing was stamped again.');

            return;
        }

        if ($run->Status !== 'previewed') {
            $this->finish($post, 'refused', 'NOT_PREVIEWED',
                'This run is '.$run->Status.' and cannot be committed again.');

            return;
        }

        try {
            // As commit() does, for the reason ReconService::selectAll() gives:
            // the group screen ticks sites, not lines.
            $this->service->selectAll($run);

            $result = $this->service->commit($run, $post->UpdatedBy ?? $post->CreatedBy);

            $this->recordCommitted($post, $run->fresh() ?? $run,
                (string) ($result['status']->Code ?? 'COMMITTED'),
                (string) ($result['status']->Message ?? 'Committed.'));
        } catch (AgoraProcException $e) {
            if ($e->code() === 'RUN_ALREADY_COMMITTED') {
                // Another path posted it between the check above and the
                // procedure's own lock. Posted is posted; nothing was stamped
                // twice.
                $this->recordCommitted($post, $run->fresh() ?? $run, 'ALREADY_POSTED', $e->getMessage());

                return;
            }

            $this->finish($post, 'refused', $e->code() ?: 'REFUSED', $e->getMessage());
        } catch (Throwable $e) {
            // A fault, not a refusal. usp_Recon_Commit is one transaction under
            // XACT_ABORT, so it either stamped and marked the run committed or
            // did neither — ask the run rather than assume.
            report($e);

            $fresh = $run->fresh();

            if ($fresh !== null && $fresh->Status === 'committed') {
                $this->recordCommitted($post, $fresh, 'COMMITTED',
                    'Posted; the answer after it failed ('.Str::limit($e->getMessage(), 200, '').').');

                return;
            }

            $this->finish($post, 'failed', $e instanceof QueryException ? 'FAULT' : 'ERROR',
                Str::limit($e->getMessage(), 380, ''));
        }
    }

    /**
     * The queue gave up on a job: it timed out, its worker died and it was
     * redelivered past its one try, or it threw. Reconcile the row from the
     * run, which the procedure keeps truthful.
     */
    public function abandonPost(int $branchId, int $postId, ?Throwable $error): void
    {
        /** @var ReconGroupPost|null $post */
        $post = $this->postQuery($branchId, $postId)->first();

        if ($post === null || ! in_array($post->Status, ['queued', 'running'], true)) {
            return;
        }

        $run = $this->runFor($post);

        if ($run !== null && $run->Status === 'committed') {
            $this->recordCommitted($post, $run, 'ALREADY_POSTED',
                'Posted; the worker stopped before it could say so.');

            return;
        }

        $why = $error === null ? 'no reason given' : class_basename($error).': '.Str::limit($error->getMessage(), 200, '');

        $this->finish($post, 'failed', 'INTERRUPTED',
            'The worker stopped before this site answered ('.$why.'). Nothing was stamped; post it again.',
            ['queued', 'running']);
    }

    /**
     * Every post in the group, keyed by site (a group has one run per site).
     *
     * @return Collection<int, ReconGroupPost>
     */
    public function posts(ReconRunGroup $group): Collection
    {
        return ReconGroupPost::query()->acrossBranches()
            ->where('GroupRef', $group->GroupRef)
            ->get()
            ->keyBy('BranchId');
    }

    /**
     * The run's post row, made queued: a new row, or a finished-but-not-
     * committed (or stale) one put back on the queue. Null when it is queued
     * or running already, or committed.
     */
    protected function queuePost(ReconRunGroup $group, ReconRun $run, ?int $userId): ?ReconGroupPost
    {
        $now = now();
        $stale = $now->copy()->subMinutes((int) config('recon.group_post.stale_after', 30));

        $requeued = ReconGroupPost::query()->acrossBranches()
            ->where('BranchId', $run->BranchId)
            ->where('RunId', $run->Id)
            ->where(fn ($q) => $q->whereIn('Status', ['refused', 'failed'])
                ->orWhere(fn ($q) => $q->whereIn('Status', ['queued', 'running'])
                    ->whereRaw('COALESCE([StartedAt], [QueuedAt]) < ?', [$stale])))
            ->update([
                'Status' => 'queued', 'Code' => null, 'Message' => null,
                'QueuedAt' => $now, 'StartedAt' => null, 'FinishedAt' => null,
                'UpdatedAt' => $now, 'UpdatedBy' => $userId,
            ]);

        $existing = ReconGroupPost::query()->acrossBranches()
            ->where('BranchId', $run->BranchId)
            ->where('RunId', $run->Id)
            ->first();

        if ($existing !== null) {
            return $requeued === 1 ? $existing : null;
        }

        try {
            return ReconGroupPost::create([
                'BranchId' => $run->BranchId,
                'GroupRef' => $group->GroupRef,
                'RunId' => $run->Id,
                'Status' => 'queued',
                'QueuedAt' => $now,
                'CreatedBy' => $userId,
                'UpdatedBy' => $userId,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another press made it between the read and the insert.
            return null;
        }
    }

    /**
     * Mark the post committed and add the run to the group's totals — once.
     *
     * The transition guard (Status <> 'committed') and the totals are one
     * transaction, so a redelivered job, failed() and the job itself can all
     * arrive here for the same run and the group counts it one time.
     */
    protected function recordCommitted(ReconGroupPost $post, ReconRun $run, string $code, string $message): void
    {
        $now = now();
        $rows = (int) $run->CommittedRows;
        $total = round((float) $run->CommittedTotal, 2);
        $userId = $post->UpdatedBy ?? $post->CreatedBy;

        DB::connection(config('agora.connections.app'))->transaction(function () use ($post, $now, $rows, $total, $userId, $code, $message) {
            $moved = $this->postQuery($post->BranchId, $post->Id)
                ->where('Status', '<>', 'committed')
                ->update([
                    'Status' => 'committed',
                    'Code' => Str::limit($code, 60, ''),
                    'Message' => Str::limit($message, 400, ''),
                    'CommittedRows' => $rows,
                    'CommittedTotal' => $total,
                    'FinishedAt' => $now,
                    'UpdatedAt' => $now,
                ]);

            if ($moved !== 1) {
                return;
            }

            // The same figures commit() adds, set-based so two sites
            // finishing in two workers cannot lose one another's.
            DB::connection(config('agora.connections.app'))
                ->table(config('agora.schema').'.ReconRunGroup')
                ->where('GroupRef', $post->GroupRef)
                ->update([
                    'Status' => 'committed',
                    'CommittedAt' => $now,
                    'CommittedBy' => $userId,
                    'CommittedBranches' => DB::raw('[CommittedBranches] + 1'),
                    'CommittedRows' => DB::raw('[CommittedRows] + '.$rows),
                    'CommittedTotal' => DB::raw('[CommittedTotal] + '.sprintf('%.2F', $total)),
                    'UpdatedAt' => $now,
                ]);
        });
    }

    /**
     * Record a post that ended without committing.
     *
     * Only from the states named: by default running (this job's claim) or
     * failed (failed() spoke first for a job that then answered after all),
     * never over a committed row.
     *
     * @param  array<int, string>  $from
     */
    protected function finish(ReconGroupPost $post, string $status, string $code, string $message, array $from = ['running', 'failed']): void
    {
        $now = now();

        $this->postQuery($post->BranchId, $post->Id)
            ->whereIn('Status', $from)
            ->update([
                'Status' => $status,
                'Code' => Str::limit($code, 60, ''),
                'Message' => Str::limit($message, 400, ''),
                'FinishedAt' => $now,
                'UpdatedAt' => $now,
            ]);
    }

    /** @return Builder<ReconGroupPost> */
    protected function postQuery(int $branchId, int $postId): Builder
    {
        return ReconGroupPost::query()->acrossBranches()
            ->where('BranchId', $branchId)
            ->where('Id', $postId);
    }

    protected function runFor(ReconGroupPost $post): ?ReconRun
    {
        /** @var ReconRun|null $run */
        $run = ReconRun::query()->acrossBranches()
            ->where('BranchId', $post->BranchId)
            ->where('Id', $post->RunId)
            ->first();

        return $run;
    }

    /** A refused or faulted branch, written down as the run it did not become. */
    protected function recordFailure(ReconRunGroup $group, int $branchId, string $code, string $message): ReconRun
    {
        $run = ReconRun::create([
            'BranchId' => $branchId,
            'GroupRef' => $group->GroupRef,
            'ReconArea' => $group->ReconArea,
            'FromDate' => $group->FromDate->toDateString(),
            'ToDate' => $group->ToDate->toDateString(),
            'Status' => 'failed',
            'StampMode' => config('recon.stamp_mode'),
            'ProcedureName' => config('agora.schema').'.'.$this->service->area($group->ReconArea)['procedure'],
            'ParamsJson' => $group->ParamsJson,
            'Note' => $group->Note,
            'FailureCode' => $code,
            'FailureMessage' => Str::limit($message, 380, ''),
            'CreatedBy' => auth()->id(),
            'CreatedAt' => now(),
        ]);

        $this->tally($group, failed: true);

        return $run;
    }

    /**
     * Move the group's counters on, and close it when every site is answered.
     *
     * An atomic increment rather than a read-modify-write: the screen drives
     * the branches one at a time, but a person with two tabs open on the same
     * group is not a scenario worth losing a count to.
     */
    protected function tally(ReconRunGroup $group, bool $failed): void
    {
        $column = $failed ? 'FailedCount' : 'CompletedCount';

        DB::connection(config('agora.connections.app'))
            ->table(config('agora.schema').'.ReconRunGroup')
            ->where('Id', $group->Id)
            ->update([
                $column => DB::raw('['.$column.'] + 1'),
                'UpdatedAt' => now(),
            ]);

        $group->refresh();

        if ($group->isFinished() && $group->Status === 'running') {
            $group->forceFill([
                'Status' => 'complete',
                'CompletedAt' => now(),
            ])->save();
        }
    }
}
