<?php

namespace Modules\StockRecon\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\StockRecon\Services\BalancingProfileService;
use Modules\StockRecon\Services\CountingAreaService;

class BalancingProfileController extends Controller
{
    public function __construct(private CountingAreaService $areas, private BalancingProfileService $profiles) {}

    public function show(int $branch, int $area): View
    {
        return view('stockrecon::counting-areas.balancing-config', $this->areas->get($branch, $area) + [
            'profiles' => $this->profiles->profiles(), 'mappings' => $this->profiles->mappings($branch, $area),
        ]);
    }

    public function store(int $branch, int $area, Request $request): RedirectResponse
    {
        $this->areas->get($branch, $area);
        $this->profiles->save($this->validated($request), (int) $request->user()->Id);

        return back()->with('status', 'Profile created.');
    }

    public function update(int $branch, int $area, int $profile, Request $request): RedirectResponse
    {
        $this->areas->get($branch, $area);
        $this->profiles->save($this->validated($request, true), (int) $request->user()->Id, $profile);

        return back()->with('status', 'Profile saved. Existing previews must be refreshed.');
    }

    public function map(int $branch, int $area, Request $request): RedirectResponse
    {
        $this->areas->get($branch, $area);
        $input = $request->validate(['item' => 'required|string|max:10', 'profile' => 'required|integer|min:1']);
        $this->profiles->map($branch, $area, $input['item'], (int) $input['profile'], (int) $request->user()->Id);

        return back()->with('status', 'Item profile mapped. Existing previews must be refreshed.');
    }

    public function unmap(int $branch, int $area, Request $request): RedirectResponse
    {
        $this->areas->get($branch, $area);
        $input = $request->validate(['item' => 'required|string|max:10']);
        $this->profiles->unmap($branch, $area, $input['item'], (int) $request->user()->Id);

        return back()->with('status', 'Item now uses the generic profile. Existing previews must be refreshed.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $updating = false): array
    {
        return $request->validate([
            'name' => 'required|string|max:100', 'revision' => $updating ? 'required|integer|min:1' : 'nullable|integer',
            'MaxAmendment' => 'required|numeric|between:1,999999', 'MaxAmendmentPct' => 'required|numeric|between:0.01,1',
            'MinShiftsInChain' => 'required|integer|between:2,30', 'KeepBreaksUpTo' => 'required|numeric|between:0,99',
            'HoldBackIncomplete' => 'nullable|boolean',
        ]);
    }
}
