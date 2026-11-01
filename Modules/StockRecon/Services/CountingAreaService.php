<?php

namespace Modules\StockRecon\Services;

use App\Support\BranchContext;
use App\Support\ProcedureService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

class CountingAreaService
{
    public function __construct(private ProcedureService $procedures) {}

    /** Permission context is resolved here, never accepted from form input.
     * @return array<string, scalar|null>
     */
    public static function access(string $action = 'view'): array
    {
        $user = Auth::user();

        return [
            'UserId' => $user?->Id,
            'Authorized' => (int) ($user?->can('stockrecon.countingareas.'.$action) ?? false),
            'Workspace' => app(BranchContext::class)->workspace(),
        ];
    }

    /** @return array{area: object, history: Collection<int, object>} */
    public function get(int $branch, int $area): array
    {
        abort_unless(app(BranchContext::class)->maySee($branch), 404);
        $sets = $this->procedures->callSets('agora.usp_StockRecon_GetCountingArea', [
            'BranchId' => $branch, 'AreaNo' => $area,
        ] + self::access());
        $row = ($sets[0] ?? collect())->first();
        abort_if($row === null, 404);

        return ['area' => $row, 'history' => $sets[1] ?? collect()];
    }

    /** @param array<string, mixed> $input */
    public function save(int $branch, int $area, array $input): object
    {
        abort_unless(app(BranchContext::class)->maySee($branch), 404);

        return $this->procedures->write('agora.usp_StockRecon_SaveCountingArea', [
            'BranchId' => $branch, 'AreaNo' => $area,
            'IsPreparedFood' => (int) $input['IsPreparedFood'],
            'ExpectedVersion' => $input['ExpectedVersion'], 'ExpectedFood' => (int) $input['ExpectedFood'],
            'Reason' => $input['reason'], 'Confirm' => (int) filter_var($input['confirm'], FILTER_VALIDATE_BOOLEAN),
        ] + self::access('save'));
    }
}
