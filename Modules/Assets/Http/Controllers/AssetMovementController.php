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
use Modules\Assets\Http\Requests\MoveAssetRequest;
use Modules\Assets\Http\Requests\ReasonRequest;
use Modules\Assets\Services\AssetsService;

/**
 * Asset movements (T057): the list, recording one, and reversing one.
 *
 * What each movement type does, and in what order an asset may make them, is
 * agora.usp_Assets_Move's; a reversal is agora.usp_Assets_ReverseMovement's,
 * and only the asset's latest live movement may be reversed.
 */
class AssetMovementController extends Controller
{
    use ResolvesAssetScope;

    public function __construct(
        protected AssetsService $service,
        protected GridRegistry $registry,
        protected GridService $grids,
    ) {}

    public function index(Request $request, BranchContext $context): View
    {
        $maySave = (bool) $request->user()?->can('assets.movement.save');

        return view('assets::movement.index', [
            'grid' => $this->grids->build(
                $this->registry->findOrFail('app.assets.movement'),
                $request,
                $request->user()?->Id,
            ),
            'gridBranches' => $this->gridBranches($context),
            'maySave' => $maySave,
            'assets' => $maySave ? $this->service->pickAssets() : collect(),
            'movementTypes' => $maySave ? $this->service->masters()['movementTypes'] : collect(),
            'branches' => $this->branches(),
        ]);
    }

    public function store(MoveAssetRequest $request): RedirectResponse
    {
        $key = $request->assetKey();
        abort_if($key === null, 422, 'Choose the asset.');
        [$branch, $asset] = $key;

        abort_if($this->service->asset($branch, $asset) === null, 404, 'That asset is not on a register you can see.');

        $to = $request->validated('ToBranchId');
        if ($to !== null && $to !== '') {
            abort_unless(app(BranchContext::class)->maySee((int) $to), 403, 'You may not move assets to that site.');
        }

        try {
            $result = $this->service->move($branch, $asset, $request->validated(), $request->user()?->Id);
        } catch (AgoraProcException $e) {
            return back()->withInput()->withErrors(['refusal' => $e->getMessage()]);
        }

        return back()->with('status', (string) ($result->Message ?? 'Recorded.'));
    }

    public function reverse(ReasonRequest $request, int $branch, int $asset, int $movement): RedirectResponse
    {
        abort_if($this->service->asset($branch, $asset) === null, 404, 'That asset is not on a register you can see.');

        try {
            $result = $this->service->reverseMovement(
                $branch,
                $asset,
                $movement,
                (string) $request->validated('reason'),
                (bool) $request->user()?->can('assets.movement.edit'),
                $request->user()?->Id,
            );
        } catch (AgoraProcException $e) {
            return back()->withErrors(['refusal' => $e->getMessage()]);
        }

        return redirect()
            ->route('app.assets.register.show', ['branch' => $branch, 'asset' => $asset])
            ->with('status', (string) ($result->Message ?? 'Reversed.'));
    }
}
