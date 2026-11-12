<?php

namespace Modules\Masters\Services;

use App\Support\ProcedureService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * The branch master's reads and writes (T021). Every rule is in the
 * procedures; this passes them the caller's identity and permission, which
 * are resolved here and never taken from the form.
 */
class BranchMasterService
{
    public const KINDS = ['Region', 'Brand', 'Format'];

    public function __construct(private ProcedureService $procedures) {}

    /**
     * One branch, or a 404 when it does not exist or is not the caller's.
     *
     * @return array{branch: object, profitCentres: Collection<int, object>, history: Collection<int, object>,
     *               groupings: Collection<string, Collection<int, object>>, allProfitCentres: Collection<int, object>}
     */
    public function get(int $branch): array
    {
        $sets = $this->procedures->callSets('agora.usp_Masters_GetBranch', [
            'BranchId' => $branch,
            'UserId' => Auth::user()?->Id,
        ]);

        $row = ($sets[0] ?? collect())->first();
        abort_if($row === null, 404);

        return [
            'branch' => $row,
            'profitCentres' => $sets[1] ?? collect(),
            'history' => $sets[2] ?? collect(),
            'groupings' => ($sets[3] ?? collect())->groupBy('Kind'),
            'allProfitCentres' => $sets[4] ?? collect(),
        ];
    }

    /** @param  array<string, mixed>  $input */
    public function save(int $branch, array $input): object
    {
        return $this->procedures->write('agora.usp_Masters_SaveBranch', [
            'BranchId' => $branch,
            'Name' => $input['Name'],
            'RegionId' => $input['RegionId'] ?? null,
            'BrandId' => $input['BrandId'] ?? null,
            'FormatId' => $input['FormatId'] ?? null,
            'Status' => $input['Status'],
            'POSType' => $input['POSType'],
            'IsAdminEntity' => (int) ($input['IsAdminEntity'] ?? 0),
            'IsActive' => (int) ($input['IsActive'] ?? 0),
            'ProfitCentreIds' => implode(',', array_map('intval', $input['ProfitCentreIds'] ?? [])),
            'ExpectedVersion' => $input['ExpectedVersion'],
            'Reason' => $input['Reason'],
            'UserId' => Auth::user()?->Id,
            'Authorized' => (int) (Auth::user()?->can('masters.branches.edit') ?? false),
        ]);
    }

    /** @return Collection<string, Collection<int, object>> keyed by Kind */
    public function groupings(): Collection
    {
        return $this->procedures->call('agora.usp_Masters_GetBranchGroupings', [
            'UserId' => Auth::user()?->Id,
        ])->groupBy('Kind');
    }

    /** @param  array<string, mixed>  $input */
    public function saveGrouping(string $kind, ?int $id, array $input): object
    {
        $permission = $id === null ? 'masters.groupings.save' : 'masters.groupings.edit';

        return $this->procedures->write('agora.usp_Masters_SaveBranchGrouping', [
            'Kind' => $kind,
            'Id' => $id,
            'Name' => $input['Name'],
            'IsActive' => (int) ($input['IsActive'] ?? 1),
            'ExpectedVersion' => $input['ExpectedVersion'] ?? null,
            'Reason' => $input['Reason'],
            'UserId' => Auth::user()?->Id,
            'Authorized' => (int) (Auth::user()?->can($permission) ?? false),
            'GroupBranchId' => (int) config('agora.group_branch_id', 2),
        ]);
    }
}
