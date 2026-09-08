<?php

namespace Modules\Recon\Services;

use App\Exceptions\AgoraProcException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

        return ReconRunGroup::create([
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
            return $this->recordFailure($group, $branchId, $e->getCode() ?: 'REFUSED', $e->getMessage());
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
                    'code' => $e->getCode() ?: 'REFUSED',
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
