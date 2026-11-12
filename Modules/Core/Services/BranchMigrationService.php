<?php

namespace Modules\Core\Services;

use App\Support\ProcedureService;
use Illuminate\Support\Collection;

/**
 * Runs agora.usp_Migrate_Branch — dbo.SS_Branch into the branch master (T021).
 *
 * The rule lives in the procedure; this only calls it and hands back what it
 * said. Re-running is safe: a branch already migrated is never touched again,
 * so an edit made on Setup → The business → Branches survives.
 */
class BranchMigrationService
{
    public function __construct(private ProcedureService $procedures) {}

    /**
     * @return array{status: object, branches: Collection<int, object>}
     */
    public function run(?int $userId = null): array
    {
        // callSets, not write(): the review set comes back beside the status
        // row. The procedure is atomic on its own (one transaction, XACT_ABORT).
        $sets = $this->procedures->callSets('agora.usp_Migrate_Branch', [
            'UserId' => $userId,
            'GroupBranchId' => (int) config('agora.group_branch_id', 2),
        ]);

        return [
            'status' => ($sets[0] ?? collect())->first() ?? (object) ['Ok' => 0, 'Code' => 'NO_STATUS_ROW', 'Message' => 'usp_Migrate_Branch returned nothing.'],
            'branches' => $sets[1] ?? collect(),
        ];
    }
}
