<?php

namespace Modules\Product\Http\Controllers;

use App\Exceptions\AgoraProcException;
use App\Grid\GridRegistry;
use App\Grid\GridService;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Product\Http\Requests\SaveCriticalLineRequest;
use Modules\Product\Services\CriticalLineService;

/**
 * Setup → Trading rules → Critical lines (T025).
 *
 * Thin. The listing is a GridDefinition the grid service builds; the write is
 * one call to the service, which is one call to the procedure that owns every
 * rule. A refusal comes back to the screen as an error rather than as a 500,
 * because "this site\'s POS file has no such code" is an instruction and a
 * stack trace is not.
 */
class CriticalLineController extends Controller
{
    public function __construct(
        private GridRegistry $registry,
        private GridService $grids,
        private CriticalLineService $service,
    ) {}

    public function index(Request $request): View
    {
        return view('product::critical.index', [
            'grid' => $this->grids->build(
                $this->registry->findOrFail('app.master.critical'),
                $request,
                $request->user()?->Id,
            ),
            'outOfStock' => $this->service->outOfStockCount($request),
        ]);
    }

    public function update(SaveCriticalLineRequest $request): RedirectResponse
    {
        try {
            $result = $this->service->save(
                (int) $request->validated('BranchId'),
                (string) $request->validated('PosSystem'),
                (string) $request->validated('PosCode'),
                (string) $request->validated('action'),
                $request->validated(),
                $request->user()?->Id,
            );
        } catch (AgoraProcException $e) {
            return back()->withInput()->withErrors(['refusal' => $e->getMessage()]);
        }

        return back()->with('status', $result->Message ?? 'Saved.');
    }
}
