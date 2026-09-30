<?php

namespace Modules\Cash\Http\Controllers;

use App\Exceptions\AgoraProcException;
use App\Grid\GridService;
use App\Http\Controllers\Controller;
use App\Support\BranchContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Cash\Grids\PumpShortageGrid;
use Modules\Cash\Http\Requests\TillBalanceRequest;
use Modules\Cash\Services\TillBalanceService;
use Modules\Cash\Support\TillBalanceScope;
use Modules\Core\Models\Branch;

/**
 * Till balance and pump shortages (T038).
 *
 *   Till balance     one site over a few days: the cashups with a variance
 *                    worth moving, the till balances already on them and where
 *                    each stands, and the press that captures a new one
 *   Pump shortages   what each pump attendant was short, by shift, across
 *                    sites — the cashups' pump shortages leg as a grid
 *
 * Three permissions (feature-rules §4):
 *
 *   cash.tillbalance.view     the Till balance screen
 *   cash.tillbalance.save     Capture — WRITES PUMPIT (the BRN_TillBalance pair
 *                             and the SS_UniqueNumber section 3 counter) while
 *                             config('cash.tillbalance.write_mode') is 'live'
 *   cash.pumpshortages.view   the Pump shortages grid
 *
 * There is no edit and no delete: a till balance is a transactional entry. One
 * captured wrongly is declined by the approver in PumpIT and captured again.
 *
 * The press is a POST that redirects back with the scope in the URL, so a
 * reload never repeats it and the answer can be linked.
 */
class TillBalanceController extends Controller
{
    public function __construct(
        protected TillBalanceService $service,
        protected GridService $grids,
    ) {}

    public function index(Request $request, BranchContext $context): View
    {
        $scope = TillBalanceScope::fromRequest($request, $context);
        $period = ['cashups' => collect(), 'balances' => collect()];
        $refusal = null;

        if ($scope->isChosen()) {
            try {
                $period = $this->service->period($scope);
            } catch (AgoraProcException $e) {
                $refusal = $e->getMessage();
            }
        }

        return view('cash::tillbalance.index', [
            'scope' => $scope,
            'branches' => $this->branches($context),
            'pinned' => $context->isBranchWorkspace(),
            'refusal' => $refusal,
            'cashups' => $period['cashups'],
            'balances' => $period['balances'],
            'summary' => $this->service->summary($period['cashups'], $period['balances']),
            'writeMode' => $this->service->writeMode(),
            'floor' => $this->service->varianceFloor(),
        ]);
    }

    /**
     * Capture a till balance — the press that writes PumpIT. Not reachable at
     * all while the write switch is off.
     */
    public function store(TillBalanceRequest $request, BranchContext $context): RedirectResponse
    {
        $scope = TillBalanceScope::fromRequest($request, $context);

        abort_unless($scope->isChosen(), 404);
        abort_unless($this->service->writeMode() === 'live', 403, 'Capturing till balances to PumpIT is switched off.');

        $back = redirect()->route('app.cash.tillbalance', $scope->query());

        try {
            $status = $this->service->capture(
                (int) $scope->branchId,
                $request->cashup('from'),
                $request->cashup('to'),
                $request->amount(),
                (int) $request->user()?->Id,
                $request->note(),
            );
        } catch (AgoraProcException $e) {
            return $back->withInput()->with('tillBalanceRefusal', $e->getMessage())->with('tillBalanceCode', $e->code());
        }

        return $back->with('tillBalanceStatus', $status->Message ?? 'Captured.')->with('tillBalanceCode', $status->Code ?? null);
    }

    public function pumpShortages(Request $request, PumpShortageGrid $grid): View
    {
        return view('cash::pumpshortages.index', [
            'grid' => $this->grids->build($grid, $request, $request->user()?->Id),
        ]);
    }

    /**
     * The sites this person may choose from — the same rule the scope bar
     * uses, so the list offered and the list TillBalanceScope accepts agree.
     *
     * @return Collection<int, Branch>
     */
    private function branches(BranchContext $context): Collection
    {
        return Branch::query()->visibleTo($context->allowed())->ordered()->get();
    }
}
