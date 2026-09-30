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
use Modules\Assets\Grids\AssetRegisterGrid;
use Modules\Assets\Http\Controllers\Concerns\ResolvesAssetScope;
use Modules\Assets\Http\Requests\ReasonRequest;
use Modules\Assets\Http\Requests\SaveAssetRequest;
use Modules\Assets\Services\AssetsService;

/**
 * The asset register and one asset's page (T057).
 *
 * Thin by design: the rules are in agora.usp_Assets_SaveAsset /
 * usp_Assets_DeleteAsset, the arithmetic in agora.fn_Assets_Depreciation.
 * Each write is locked three times (feature-rules §4) — the route's `can:`,
 * the button that is not rendered without the permission, and the procedure,
 * which is told whether the caller may edit or delete and refuses otherwise.
 *
 * Nothing here writes the customer's databases: the register is Agora's own
 * (Ryan, 27 Sep 2026, PM decision 01a0e309).
 */
class AssetRegisterController extends Controller
{
    use ResolvesAssetScope;

    public function __construct(
        protected AssetsService $service,
        protected GridRegistry $registry,
        protected GridService $grids,
    ) {}

    public function index(Request $request, BranchContext $context): View
    {
        $asAt = AssetRegisterGrid::asAt();

        return view('assets::register.index', [
            'grid' => $this->grids->build(
                $this->registry->findOrFail('app.assets.register'),
                $request,
                $request->user()?->Id,
            ),
            'gridBranches' => $this->gridBranches($context),
            'asAt' => $asAt,
            'byGroup' => $this->service->depreciationByGroup($this->scope($request, $context), $asAt),
            'provisional' => $this->service->ratesProvisional(),
            'basis' => $this->service->ratesBasis(),
            'masters' => $request->user()?->can('assets.register.save') ? $this->service->masters() : null,
            'branches' => $this->branches(),
            'pinned' => $context->isBranchWorkspace() ? $context->id() : null,
        ]);
    }

    public function show(Request $request, int $branch, int $asset, BranchContext $context): View
    {
        $found = $this->service->asset($branch, $asset, AssetRegisterGrid::asAt());

        abort_if($found === null, 404, 'That asset is not on a register you can see.');

        $user = $request->user();

        return view('assets::register.show', $found + [
            'schedule' => $this->service->schedule($branch, $asset),
            'masters' => $user?->can('assets.register.edit') || $user?->can('assets.movement.save')
                ? $this->service->masters() : null,
            'branches' => $this->branches(),
            'provisional' => $this->service->ratesProvisional(),
            'asAt' => AssetRegisterGrid::asAt(),
        ]);
    }

    public function store(SaveAssetRequest $request, BranchContext $context): RedirectResponse
    {
        $branchId = $this->resolveBranch($request->integer('BranchId') ?: null, $context);
        $fields = $request->validated();

        // An asset registered where it already stands: the site it is at must
        // be one this person may see, like the site it belongs to.
        if (! empty($fields['LocatedBranchId'])) {
            abort_unless($context->maySee((int) $fields['LocatedBranchId']), 403, 'You may not place assets at that site.');
        }

        try {
            $result = $this->service->saveAsset($branchId, null, $fields, false, $request->user()?->Id);
        } catch (AgoraProcException $e) {
            return back()->withInput()->withErrors(['refusal' => $e->getMessage()]);
        }

        return redirect()
            ->route('app.assets.register.show', ['branch' => $branchId, 'asset' => (int) $result->Id])
            ->with('status', (string) ($result->Message ?? 'Registered.'));
    }

    public function update(SaveAssetRequest $request, int $branch, int $asset): RedirectResponse
    {
        abort_if($this->service->asset($branch, $asset) === null, 404, 'That asset is not on a register you can see.');

        try {
            $result = $this->service->saveAsset(
                $branch,
                $asset,
                $request->validated(),
                (bool) $request->user()?->can('assets.register.edit'),
                $request->user()?->Id,
            );
        } catch (AgoraProcException $e) {
            return back()->withInput()->withErrors(['refusal' => $e->getMessage()]);
        }

        return redirect()
            ->route('app.assets.register.show', ['branch' => $branch, 'asset' => $asset])
            ->with('status', (string) ($result->Message ?? 'Saved.'));
    }

    public function destroy(ReasonRequest $request, int $branch, int $asset): RedirectResponse
    {
        abort_if($this->service->asset($branch, $asset) === null, 404, 'That asset is not on a register you can see.');

        try {
            $result = $this->service->deleteAsset(
                $branch,
                $asset,
                (string) $request->validated('reason'),
                (bool) $request->user()?->can('assets.register.delete'),
                $request->user()?->Id,
            );
        } catch (AgoraProcException $e) {
            return back()->withErrors(['refusal' => $e->getMessage()]);
        }

        return redirect()
            ->route('app.assets.register')
            ->with('status', (string) ($result->Message ?? 'Deleted.'));
    }

    /** The sites the depreciation panel covers: the grid's selection, or the pinned site. */
    protected function scope(Request $request, BranchContext $context): ?string
    {
        if ($context->isBranchWorkspace() && $context->id() !== null) {
            return (string) $context->id();
        }

        $given = $request->query('branches');
        $ids = collect(is_array($given) ? $given : ($given === null ? [] : explode(',', (string) $given)))
            ->reject(fn (mixed $id) => is_array($id) || is_object($id))
            ->map(fn (mixed $id) => (int) $id)
            ->filter(fn (int $id) => $id > 0 && $context->maySee($id))
            ->unique()
            ->values();

        return $ids->isEmpty() ? null : $ids->implode(',');
    }
}
