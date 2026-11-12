<?php

namespace Modules\People\Services;

use App\Support\BranchContext;
use App\Support\ProcedureService;
use Illuminate\Support\Collection;

/**
 * The people master's reads (T023). Every rule is in the procedures; this
 * hands them the caller's granted sites, resolved here and never taken from
 * the request.
 *
 * There is no save. The employees are PumpIT's BRN_Employee, and where an
 * edit made in Agora would land is not decided yet.
 */
class EmployeeService
{
    public function __construct(private ProcedureService $procedures, private BranchContext $context) {}

    /**
     * One employee, or a 404 when there is none or the site is not the caller's.
     *
     * @return array{employee: object, areas: Collection<int, object>, shifts: Collection<int, object>,
     *               shiftSummary: Collection<int, object>}
     */
    public function get(int $branch, string $code): array
    {
        abort_unless($this->context->maySee($branch), 404);

        $sets = $this->procedures->callSets('agora.usp_People_GetEmployee', [
            'BranchId' => $branch,
            'EmployeeCode' => trim($code),
            'AllowedBranchIds' => $this->allowedCsv(),
            'ShiftLimit' => (int) config('people.shift_limit', 30),
        ]);

        $employee = ($sets[0] ?? collect())->first();
        abort_if($employee === null, 404);

        return [
            'employee' => $employee,
            'areas' => $sets[1] ?? collect(),
            'shifts' => $sets[2] ?? collect(),
            'shiftSummary' => $sets[3] ?? collect(),
        ];
    }

    /**
     * The post names at the caller's sites, for the employee list's post
     * picker — from the same procedure as the Employee posts screen.
     *
     * @param  array<int, int>  $branchIds  the sites in scope; empty for all the caller may see
     * @return array<int, string>
     */
    public function postNames(array $branchIds = []): array
    {
        $ids = $branchIds !== [] ? $branchIds : $this->context->allowed();

        return $this->procedures->call('agora.usp_People_GridPosts', [
            'BranchIds' => $ids === [] ? null : implode(',', $ids),
            'PageSize' => 100000,
        ])->pluck('PostDescription')->filter()->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
    }

    /** The next post number at a branch — agora.usp_People_NextPost, the port of sp_GetNextEmployeePost. */
    public function nextPost(int $branch): ?int
    {
        if (! $this->context->maySee($branch)) {
            return null;
        }

        $row = $this->procedures->call('agora.usp_People_NextPost', ['BranchId' => $branch])->first();

        return $row === null ? null : (int) $row->NextPostNo;
    }

    private function allowedCsv(): ?string
    {
        $allowed = $this->context->allowed();

        return $allowed === [] ? null : implode(',', $allowed);
    }
}
