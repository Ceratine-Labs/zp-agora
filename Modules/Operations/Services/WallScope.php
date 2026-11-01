<?php

namespace Modules\Operations\Services;

use App\Support\BranchContext;
use App\Support\ProcedureService;
use Illuminate\Http\Request;
use Modules\Reports\Services\ReportsService;

class WallScope
{
    public function __construct(private ProcedureService $procedures, private BranchContext $context) {}

    /** @return array<int, array{id: int, name: string, division: string}> */
    public function sites(): array
    {
        $rows = $this->procedures->call('agora.usp_Operations_Sites', [
            'AllowedBranchIds' => implode(',', $this->context->allowed()) ?: null,
            'BranchId' => $this->context->isBranchWorkspace() ? ($this->context->id() ?? -1) : null,
        ]);

        return $rows->map(fn (object $row): array => [
            'id' => (int) $row->BranchId, 'name' => trim((string) $row->Name), 'division' => '',
        ])->all();
    }

    /** @param array<int, array{id: int, name: string, division: string}> $sites
     * @return array<int, int>
     */
    public function selected(Request $request, array $sites): array
    {
        $permitted = array_column($sites, 'id');
        if ($this->context->isBranchWorkspace() || ! $request->query->has('branches')) {
            return $permitted;
        }

        // A supplied but invalid/forbidden selection remains EMPTY, never all sites.
        return array_values(array_intersect($permitted, ReportsService::branchIds($request->query('branches'))));
    }
}
