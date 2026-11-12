<?php

namespace Modules\Fuel\Http\Controllers;

use App\Exceptions\AgoraProcException;
use App\Grid\GridRegistry;
use App\Grid\GridService;
use App\Http\Controllers\Controller;
use App\Support\BranchContext;
use App\Support\BusinessDate;
use App\Support\ProcedureService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Core\Models\Branch;
use Modules\Fuel\Grids\FuelPriceGrid;
use Modules\Fuel\Services\FuelChart;
use Modules\Fuel\Services\FuelPriceMoves;

class FuelController extends Controller
{
    public function index(Request $request, BranchContext $context, GridRegistry $registry, GridService $grids, FuelChart $charts, FuelPriceMoves $prices): View
    {
        /** @var FuelPriceGrid $definition */
        $definition = $registry->findOrFail((string) $request->route()?->getName());
        $today = BusinessDate::today();
        foreach (['from' => $today->copy()->subDays(365)->toDateString(), 'to' => $today->toDateString()] as $key => $value) {
            if (! $request->query->has($key)) {
                $request->query->set($key, $value);
            }
        }
        $grid = $grids->build($definition, $request, $request->user()?->Id);
        $metric = in_array($request->query('metric'), ['sell', 'cost', 'margin'], true) ? $request->query('metric') : 'sell';
        $chart = ['series' => [], 'changes' => collect()];
        $chartMessage = null;
        if (count($grid->gridQuery->branchIds) !== 1 || $grid->gridQuery->branchIds[0] < 1) {
            $chartMessage = 'Select one site in the grid filters to see its chart and price-change log.';
        } elseif ($grid->gridQuery->from !== null && $grid->gridQuery->to !== null
            && abs(Carbon::parse($grid->gridQuery->from)->diffInDays(Carbon::parse($grid->gridQuery->to))) > 366) {
            $chartMessage = 'Use a year or less for the chart and price-change log. The grid still shows the full selected range.';
        } elseif ($grid->refusal === null) {
            try {
                $chart = $charts->build($definition, $grid->gridQuery, $metric);
            } catch (AgoraProcException $e) {
                $chartMessage = $e->getMessage();
            }
        }
        // The matrix's price-change log is the pump price (BRN_FuelPrice), not
        // the DOE periods in the grid above it: the DOE matrix moves once a
        // month and the pumps do not. It covers every site in the selection,
        // so it does not wait for one site to be picked as the chart does.
        $pump = ['summary' => collect(), 'moves' => collect()];
        $pumpMessage = null;
        if ($definition->dataset() === 'periods' && $grid->refusal === null) {
            try {
                $pump = $prices->build($grid->gridQuery);
            } catch (AgoraProcException $e) {
                $pumpMessage = $e->getMessage();
            }
        }
        $allowed = FuelPriceGrid::allowed();
        $branches = Branch::query()->acrossBranches()->where('IsActive', true)
            ->when($allowed !== null, fn ($query) => $query->whereIn('BranchId', explode(',', $allowed)))
            ->orderBy('Name')->get();

        return view('fuel::index', $chart + [
            'grid' => $grid, 'dataset' => $definition->dataset(), 'metric' => $metric,
            'chartMessage' => $chartMessage, 'branches' => $context->isBranchWorkspace() ? null : $branches,
            'pump' => $pump, 'pumpMessage' => $pumpMessage,
        ]);
    }

    public function detail(string $dataset, string $key, Request $request, ProcedureService $procedures): View
    {
        $dates = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d']]);
        $sets = $procedures->callSets('usp_Fuel_GridPrices', [
            'Dataset' => $dataset, 'RowKey' => $key, 'DateFrom' => $dates['from'], 'DateTo' => $dates['to'],
            'AllowedBranchIds' => FuelPriceGrid::allowed(),
        ]);
        $row = ($sets[0] ?? collect())->first();
        abort_if($row === null, 404);

        return view($request->ajax() ? 'fuel::detail' : 'fuel::show', ['row' => $row, 'dataset' => $dataset]);
    }
}
