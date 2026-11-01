<?php

namespace Modules\StockRecon\Http\Controllers;

use App\Grid\GridService;
use App\Http\Controllers\Controller;
use App\Support\BranchContext;
use App\Support\BusinessDate;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Modules\StockRecon\Grids\WaterMeterGrid;
use Modules\StockRecon\Services\WaterMeterService;

class WaterMeterController extends Controller
{
    public function index(Request $request, GridService $grids, WaterMeterGrid $definition, WaterMeterService $meters, BranchContext $context): View
    {
        if (! $request->filled('from') && ! $request->filled('to')) {
            $today = BusinessDate::today();
            $request->query->add(['from' => $today->copy()->startOfMonth()->toDateString(), 'to' => $today->toDateString()]);
        }

        return view('stockrecon::water-meters', [
            'grid' => $grids->build($definition, $request, $request->user()?->Id),
            'branches' => $context->isBranchWorkspace() ? null : $meters->sites(),
        ]);
    }
}
