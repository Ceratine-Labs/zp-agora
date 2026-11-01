<?php

namespace Modules\StockRecon\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\BranchContext;
use App\Support\BusinessDate;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Core\Models\Branch;
use Modules\StockRecon\Http\Requests\GroupPreviewRequest;
use Modules\StockRecon\Models\StockReconRunGroup;
use Modules\StockRecon\Services\ReconPeriods;
use Modules\StockRecon\Services\StockReconGroupService;

class StockReconGroupController extends Controller
{
    public function __construct(protected StockReconGroupService $groups) {}

    public function form(BranchContext $context): View
    {
        $this->headOffice($context);

        return view('stockrecon::group-form', [
            'sites' => $this->sites($context),
            'from' => ReconPeriods::lastFullWeek(BusinessDate::today())['from'],
            'today' => BusinessDate::today()->toDateString(),
            'recent' => $this->groups->recent($context->allowed()),
        ]);
    }

    public function start(GroupPreviewRequest $request, BranchContext $context): RedirectResponse
    {
        $this->headOffice($context);
        $branches = array_map('intval', $request->validated('branches'));
        $allowed = $this->sites($context)->pluck('BranchId')->map(fn ($id) => (int) $id)->all();
        abort_unless(array_diff($branches, $allowed) === [], 403, 'Select only sites you may reconcile.');
        $group = $this->groups->start($branches, $request->from(), $request->to(), $request->options(), $request->note());

        return redirect()->route('app.stockrecon.group', $group->GroupRef)->with('group_auto', true);
    }

    public function show(string $group, BranchContext $context): View
    {
        $model = $this->group($group, $context);

        return view('stockrecon::group', ['group' => $model, 'results' => $this->groups->results($model)]);
    }

    public function runBranch(string $group, int $branch, Request $request, BranchContext $context): JsonResponse|RedirectResponse
    {
        $model = $this->group($group, $context);
        abort_unless(in_array($branch, $model->BranchIdsJson, true), 404);
        $request->validate(['retry' => ['sometimes', 'boolean']]);
        $run = $this->groups->runBranch($model, $branch, $request->boolean('retry'));

        if (! $request->expectsJson()) {
            return redirect()->route('app.stockrecon.group', $model->GroupRef);
        }

        return response()->json([
            'status' => $run->Status,
            'message' => $run->FailureMessage,
            'url' => $run->Status === 'failed' ? null : route('app.stockrecon.run', $run->Id),
        ]);
    }

    protected function group(string $ref, BranchContext $context): StockReconRunGroup
    {
        $this->headOffice($context);
        $model = StockReconRunGroup::query()->acrossBranches()
            ->where('GroupRef', $ref)->where('CreatedBy', auth()->id())->firstOrFail();
        // Re-check current grants on every read and POST. Revocation must not
        // leave the group's stored selection acting as a permanent grant.
        foreach ($model->BranchIdsJson as $branch) {
            abort_unless($context->maySee($branch), 403, 'Your site access has changed. Start a group with your current sites.');
        }

        return $model;
    }

    /** @return Collection<int, Branch> */
    protected function sites(BranchContext $context): Collection
    {
        return Branch::query()->acrossBranches()
            ->when($context->allowed() !== [], fn ($query) => $query->whereIn('BranchId', $context->allowed()))
            ->where('IsActive', true)->where('IsTrading', true)->orderBy('Name')->get();
    }

    protected function headOffice(BranchContext $context): void
    {
        abort_if($context->isBranchWorkspace(), 403, 'Every-site previews are a head-office action.');
    }
}
