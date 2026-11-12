<?php

namespace Modules\Masters\Http\Controllers;

use App\Exceptions\AgoraProcException;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Modules\Masters\Http\Requests\SaveBranchGroupingRequest;
use Modules\Masters\Services\BranchMasterService;

/**
 * Setup → The business → Regions, brands and formats (T021).
 *
 * Three small masters on one screen: create, rename, switch off. No delete —
 * a grouping goes out of use, and its history stays.
 */
class BranchGroupingController extends Controller
{
    public function __construct(private BranchMasterService $service) {}

    public function index(): View
    {
        return view('masters::groupings.index', [
            'groupings' => $this->service->groupings(),
            'kinds' => BranchMasterService::KINDS,
        ]);
    }

    public function store(string $kind, SaveBranchGroupingRequest $request): RedirectResponse
    {
        return $this->save($kind, null, $request);
    }

    public function update(string $kind, int $id, SaveBranchGroupingRequest $request): RedirectResponse
    {
        return $this->save($kind, $id, $request);
    }

    private function save(string $kind, ?int $id, SaveBranchGroupingRequest $request): RedirectResponse
    {
        try {
            $result = $this->service->saveGrouping($kind, $id, $request->validated());
        } catch (AgoraProcException $e) {
            return back()->withInput()->withErrors(['refusal' => $e->getMessage()]);
        }

        return redirect()->route('app.setup.branch-groupings.index')->with('status', $result->Message);
    }
}
