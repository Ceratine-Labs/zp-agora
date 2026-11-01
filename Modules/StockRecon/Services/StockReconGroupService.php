<?php

namespace Modules\StockRecon\Services;

use App\Exceptions\AgoraProcException;
use App\Support\ProcedureService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\StockRecon\Models\StockReconRun;
use Modules\StockRecon\Models\StockReconRunGroup;
use Throwable;

/** Persistent orchestration only; the existing preview procedure owns the arithmetic. */
class StockReconGroupService
{
    public function __construct(protected StockReconService $previews, protected ProcedureService $procedures) {}

    /** @param array<int, int> $branches
     * @param  array<string, scalar|null>  $options
     */
    public function start(array $branches, Carbon $from, Carbon $to, array $options, ?string $note): StockReconRunGroup
    {
        abort_if($branches === [], 422, 'Select at least one site.');

        $group = StockReconRunGroup::create([
            'BranchId' => config('agora.group_branch_id', 2),
            'GroupRef' => (string) Str::uuid(),
            'BranchIdsJson' => array_values(array_unique($branches)),
            'FromDate' => $from->toDateString(),
            'ToDate' => $to->toDateString(),
            'ParamsJson' => $options,
            'Note' => $note,
            'CreatedBy' => auth()->id(),
        ]);

        return $group->fresh() ?? $group;
    }

    /** @return Collection<int, object> */
    public function results(StockReconRunGroup $group): Collection
    {
        return $this->procedures->call('agora.usp_StockRecon_GetGroup', [
            'GroupRef' => $group->GroupRef,
            'UserId' => auth()->id(),
        ]);
    }

    /** @param array<int, int> $allowed
     * @return Collection<int, object>
     */
    public function recent(array $allowed): Collection
    {
        return $this->procedures->call('agora.usp_StockRecon_GetGroups', [
            'UserId' => auth()->id(),
            'AllowedBranchIds' => implode(',', $allowed) ?: null,
        ]);
    }

    public function runBranch(StockReconRunGroup $group, int $branch, bool $retry = false): StockReconRun
    {
        abort_unless(in_array($branch, $group->BranchIdsJson, true), 404);
        $db = DB::connection(config('agora.connections.app'));
        // A session lock survives a failed preview transaction. It prevents two
        // browser tabs from creating two runs, including while recording failure.
        $resource = 'StockReconGroup:'.strtoupper($group->GroupRef);
        $lock = $db->selectOne("DECLARE @r int; EXEC @r = sys.sp_getapplock @Resource=?, @LockMode='Exclusive', @LockOwner='Session', @LockTimeout=0; SELECT @r AS Result;", [$resource]);
        abort_if((int) ($lock->Result ?? -1) < 0, 409, 'This group is already previewing a site. Resume when it finishes.');

        try {
            $existing = StockReconRun::query()->acrossBranches()
                ->where('GroupRef', $group->GroupRef)->where('BranchId', $branch)->orderByDesc('Id')->first();
            if ($existing !== null && (! $retry || $existing->Status !== 'failed')) {
                return $existing;
            }

            try {
                return $db->transaction(fn () => $this->previews->preview(
                    $branch, null, $group->FromDate, $group->ToDate, $group->ParamsJson,
                    groupRef: $group->GroupRef, note: $group->Note,
                ));
            } catch (Throwable $e) {
                report($e);

                // SQL failures may contain credentials or internal query text.
                // Keep diagnostics in the server log and a useful safe result here.
                return StockReconRun::create([
                    'BranchId' => $branch,
                    'GroupRef' => $group->GroupRef,
                    'FromDate' => $group->FromDate->toDateString(),
                    'ToDate' => $group->ToDate->toDateString(),
                    'Status' => 'failed',
                    'StampMode' => config('stockrecon.stamp_mode'),
                    'ProcedureName' => 'agora.usp_StockRecon_PreviewBalancing',
                    'ParamsJson' => json_encode($group->ParamsJson),
                    'Note' => $group->Note,
                    'FailureCode' => $e instanceof AgoraProcException ? $e->code() : 'FAULT',
                    'FailureMessage' => $e instanceof AgoraProcException ? Str::limit($e->getMessage(), 380, '') : 'Preview failed. Retry this site; contact support if it fails again.',
                    'CreatedBy' => auth()->id(),
                ]);
            }
        } finally {
            $db->statement("EXEC sys.sp_releaseapplock @Resource=?, @LockOwner='Session';", [$resource]);
        }
    }
}
