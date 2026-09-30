<?php

namespace Modules\Assets\Http\Controllers;

use App\Exceptions\AgoraProcException;
use App\Grid\GridRegistry;
use App\Grid\GridService;
use App\Http\Controllers\Controller;
use App\Support\BranchContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Assets\Http\Controllers\Concerns\ResolvesAssetScope;
use Modules\Assets\Http\Requests\ReasonRequest;
use Modules\Assets\Http\Requests\SaveRepairRequest;
use Modules\Assets\Services\AssetsService;

/**
 * Repair job cards and the comeback rule (T057).
 *
 * The rule — a second call on the same asset within 30 days — is
 * agora.vw_AssetRepairCall's; the by-supplier tally is
 * agora.usp_Assets_Comebacks'; the cost is the approved purchase request's
 * (agora.vw_AssetRepairCost). A job card is never deleted: one raised in error
 * is cancelled, and stops counting as a call.
 */
class AssetRepairController extends Controller
{
    use ResolvesAssetScope;

    public function __construct(
        protected AssetsService $service,
        protected GridRegistry $registry,
        protected GridService $grids,
    ) {}

    public function index(Request $request, BranchContext $context): View
    {
        $grid = $this->grids->build($this->registry->findOrFail('app.assets.repairs'), $request, $request->user()?->Id);
        $query = $grid->gridQuery;
        $maySave = (bool) $request->user()?->can('assets.repair.save');

        return view('assets::repairs.index', [
            'grid' => $grid,
            'gridBranches' => $this->gridBranches($context),
            // The tally covers the same sites and dates as the grid under it,
            // so the two answer one question rather than two.
            'tally' => $this->service->comebacks(
                $query->branchIds === [] ? null : implode(',', $query->branchIds),
                $query->from,
                $query->to,
            ),
            'maySave' => $maySave,
            'assets' => $maySave ? $this->service->pickAssets() : collect(),
        ]);
    }

    public function show(int $branch, int $repair): View
    {
        $found = $this->service->repair($branch, $repair);

        abort_if($found === null, 404, 'That job card is not on a register you can see.');

        return view('assets::repairs.show', $found);
    }

    public function store(SaveRepairRequest $request): RedirectResponse
    {
        $key = $request->assetKey();
        abort_if($key === null, 422, 'Choose the asset.');
        [$branch, $asset] = $key;

        abort_if($this->service->asset($branch, $asset) === null, 404, 'That asset is not on a register you can see.');

        try {
            $result = $this->service->saveRepair($branch, null, $asset, $request->validated(), false, $request->user()?->Id);
        } catch (AgoraProcException $e) {
            return back()->withInput()->withErrors(['refusal' => $e->getMessage()]);
        }

        return redirect()
            ->route('app.assets.repairs.show', ['branch' => $branch, 'repair' => (int) $result->Id])
            ->with(($result->Code ?? '') === 'COMEBACK' ? 'comeback' : 'status', (string) ($result->Message ?? 'Raised.'));
    }

    public function update(SaveRepairRequest $request, int $branch, int $repair): RedirectResponse
    {
        abort_if($this->service->repair($branch, $repair) === null, 404, 'That job card is not on a register you can see.');

        try {
            $result = $this->service->saveRepair(
                $branch,
                $repair,
                null,
                $request->validated(),
                (bool) $request->user()?->can('assets.repair.edit'),
                $request->user()?->Id,
            );
        } catch (AgoraProcException $e) {
            return back()->withInput()->withErrors(['refusal' => $e->getMessage()]);
        }

        return redirect()
            ->route('app.assets.repairs.show', ['branch' => $branch, 'repair' => $repair])
            ->with(($result->Code ?? '') === 'COMEBACK' ? 'comeback' : 'status', (string) ($result->Message ?? 'Saved.'));
    }

    public function cancel(ReasonRequest $request, int $branch, int $repair): RedirectResponse
    {
        abort_if($this->service->repair($branch, $repair) === null, 404, 'That job card is not on a register you can see.');

        try {
            $result = $this->service->cancelRepair(
                $branch,
                $repair,
                (string) $request->validated('reason'),
                (bool) $request->user()?->can('assets.repair.edit'),
                $request->user()?->Id,
            );
        } catch (AgoraProcException $e) {
            return back()->withErrors(['refusal' => $e->getMessage()]);
        }

        return redirect()
            ->route('app.assets.repairs.show', ['branch' => $branch, 'repair' => $repair])
            ->with('status', (string) ($result->Message ?? 'Cancelled.'));
    }
}
