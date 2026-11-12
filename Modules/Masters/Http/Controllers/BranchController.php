<?php

namespace Modules\Masters\Http\Controllers;

use App\Exceptions\AgoraProcException;
use App\Grid\GridRegistry;
use App\Grid\GridService;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Core\Models\Branch;
use Modules\Masters\Grids\BranchMasterGrid;
use Modules\Masters\Http\Requests\SaveBranchRequest;
use Modules\Masters\Services\BranchMasterService;

/**
 * Setup → The business → Branches (T021). Listing, one branch, and its edit.
 *
 * There is no create and no delete: a branch's id is PumpIT's SSBranchId and
 * arrives through agora.usp_Migrate_Branch, never typed here.
 */
class BranchController extends Controller
{
    public function __construct(private BranchMasterService $service) {}

    public function index(Request $request, GridRegistry $registry, GridService $grids): View
    {
        return view('masters::branches.index', [
            'grid' => $grids->build($registry->findOrFail('app.setup.branches'), $request, $request->user()?->Id),
            'includesAdmin' => BranchMasterGrid::includesAdmin(),
        ]);
    }

    public function show(int $branch, Request $request): View
    {
        return view($request->ajax() ? 'masters::branches.detail' : 'masters::branches.show', $this->service->get($branch));
    }

    public function edit(int $branch): View
    {
        return view('masters::branches.edit', $this->service->get($branch) + [
            'statuses' => Branch::STATUSES,
            'posTypes' => Branch::POS_TYPES,
        ]);
    }

    public function update(int $branch, SaveBranchRequest $request): RedirectResponse
    {
        try {
            $result = $this->service->save($branch, $request->validated());
        } catch (AgoraProcException $e) {
            return back()->withInput()->withErrors(['refusal' => $e->getMessage()]);
        }

        return redirect()->route('app.setup.branches.show', ['branch' => $branch])->with('status', $result->Message);
    }
}
