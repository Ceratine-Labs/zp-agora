<?php

namespace Modules\StockRecon\Http\Controllers;

use App\Grid\GridService;
use App\Http\Controllers\Controller;
use App\Support\BranchContext;
use App\Support\BusinessDate;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Modules\Core\Models\Branch;
use Modules\StockRecon\Grids\StockReconVarianceGrid;

/**
 * Original vs amended variance (ZRP, 19 Oct 2026). Read-only: one grid over
 * agora.usp_StockRecon_GridVariance and the strip of its totals.
 */
class StockReconVarianceController extends Controller
{
    public function __construct(private readonly GridService $grids) {}

    public function index(Request $request, StockReconVarianceGrid $grid, BranchContext $context): View
    {
        // The month to date, in the date range itself, so the period is on the
        // screen rather than implied by the procedure's default. Any period
        // may be chosen; the procedure bounds it by lines, not days.
        if (! $request->filled('from') && ! $request->filled('to')) {
            $today = BusinessDate::today();
            $request->query->add([
                'from' => $today->copy()->startOfMonth()->toDateString(),
                'to' => $today->toDateString(),
            ]);
        }

        return view('stockrecon::variance', [
            'grid' => $this->grids->build($grid, $request, $request->user()?->Id),
            // The grid's branches selector belongs to head office (feature-rules
            // §3.3); in the branch workspace the site is pinned. Trading sites
            // only: the group and property entities count no stock.
            'branches' => $context->isBranchWorkspace() ? null : Branch::query()
                ->where('IsActive', true)
                ->where('IsTrading', true)
                ->orderBy('Name')
                ->get(),
        ]);
    }
}
