<?php

namespace Modules\StockRecon\Http\Controllers;

use App\Exceptions\AgoraProcException;
use App\Grid\GridService;
use App\Http\Controllers\Controller;
use App\Support\BranchContext;
use App\Support\BusinessDate;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Core\Models\Branch;
use Modules\StockRecon\Grids\WeeklyCaseGrid;
use Modules\StockRecon\Http\Requests\SaveWeeklyCaseRequest;
use Modules\StockRecon\Services\ReconPeriods;
use Modules\StockRecon\Services\WeeklyStockService;

class WeeklyStockController extends Controller
{
    public function __construct(private WeeklyStockService $service) {}

    public function index(Request $request, GridService $grids): View
    {
        $grid = $grids->build(new WeeklyCaseGrid, $request, $request->user()?->Id);

        return view('stockrecon::weekly.index', ['grid' => $grid] + $this->service->overview($grid->gridQuery, $request->query('snapshot')));
    }

    public function create(BranchContext $context): View
    {
        return view('stockrecon::weekly.create', [
            'sites' => Branch::query()->acrossBranches()->when($context->allowed() !== [], fn ($q) => $q->whereIn('BranchId', $context->allowed()))
                ->when($context->isBranchWorkspace(), fn ($q) => $q->where('BranchId', $context->id() ?? -1))
                ->where('IsActive', true)->where('IsTrading', true)->orderBy('Name')->get(),
            'week' => ReconPeriods::lastFullWeek(BusinessDate::today())['from'],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $input = $request->validate(['branch_id' => ['required', 'integer', 'min:1'], 'week_start' => ['required', 'date_format:Y-m-d', 'before:'.BusinessDate::today()->toDateString()],
            'period_from' => ['nullable', 'date_format:Y-m-d'], 'period_to' => ['nullable', 'date_format:Y-m-d'],
            'reporting_as_of' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.BusinessDate::today()->toDateString()]]);
        $week = Carbon::createFromFormat('!Y-m-d', $input['week_start']);
        abort_unless($week !== null && $week->isMonday() && $week->copy()->addDays(6)->lt(BusinessDate::today()), 422, 'Choose a completed Monday to Sunday week.');
        $periodFrom = isset($input['period_from']) ? Carbon::parse($input['period_from']) : $week->copy();
        $periodTo = isset($input['period_to']) ? Carbon::parse($input['period_to']) : $week->copy()->addDays(6);
        $asOf = isset($input['reporting_as_of']) ? Carbon::parse($input['reporting_as_of']) : BusinessDate::today();
        abort_unless($periodFrom->lte($week) && $periodTo->gte($week->copy()->addDays(6)) && $periodFrom->diffInDays($periodTo) <= 92
            && $periodTo->lt(BusinessDate::today()) && $asOf->gte($periodTo), 422, 'The control period must contain the week, span at most 93 days and end before today. The reporting date must be on or after the period.');
        try {
            $snapshot = $this->service->create((int) $input['branch_id'], $week, $periodFrom, $periodTo, $asOf);
        } catch (AgoraProcException $e) {
            return back()->withInput()->withErrors(['refusal' => $e->getMessage()]);
        }

        return redirect()->route('app.stockrecon.weekly', ['snapshot' => $snapshot->SnapshotRef])->with('status', $snapshot->Message);
    }

    public function storePattern(Request $request, BranchContext $context): RedirectResponse
    {
        abort_unless($context->workspace() === 'ho', 403);
        $input = $request->validate(['branches' => ['required', 'array', 'min:3'], 'branches.*' => ['required', 'integer', 'min:1', 'distinct'],
            'period_from' => ['required', 'date_format:Y-m-d'], 'period_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_from'],
            'pos_code' => ['required', 'string', 'max:80']]);
        try {
            $result = $this->service->createPattern(array_map('intval', $input['branches']), $input['period_from'], $input['period_to'], $input['pos_code']);
        } catch (AgoraProcException $e) {
            return back()->withErrors(['refusal' => $e->getMessage()]);
        }

        return redirect()->route('app.stockrecon.weekly.show', ['branch' => $result->BranchId, 'case' => $result->Id])->with('status', $result->Message);
    }

    public function show(int $branch, int $case, Request $request): View
    {
        return view($request->ajax() ? 'stockrecon::weekly.detail' : 'stockrecon::weekly.show', $this->caseData($branch, $case));
    }

    public function edit(int $branch, int $case): View
    {
        return view('stockrecon::weekly.edit', $this->caseData($branch, $case));
    }

    /** @return array<string, mixed> */
    private function caseData(int $branch, int $case): array
    {
        try {
            return $this->service->get($branch, $case);
        } catch (AgoraProcException $e) {
            abort_if($e->code() === 'WEEKLY_FORBIDDEN', 403);
            throw $e;
        }
    }

    public function reopen(int $branch, int $case, Request $request): RedirectResponse
    {
        $input = $request->validate(['ExpectedVersion' => ['required', 'regex:/^0x[0-9a-fA-F]{16}$/'],
            'Reason' => ['required', 'string', 'min:10', 'max:400']]);
        try {
            $result = $this->service->save($branch, $case, $input, 'reopen');
        } catch (AgoraProcException $e) {
            return back()->withInput()->withErrors(['refusal' => $e->getMessage()]);
        }

        return redirect()->route('app.stockrecon.weekly.show', compact('branch', 'case'))->with('status', $result->Message);
    }

    public function update(int $branch, int $case, SaveWeeklyCaseRequest $request): RedirectResponse
    {
        try {
            $result = $this->service->save($branch, $case, $request->validated(), $request->routeIs('app.stockrecon.weekly.approve') ? 'approve' : 'save');
        } catch (AgoraProcException $e) {
            return back()->withInput()->withErrors(['refusal' => $e->getMessage()]);
        }

        return redirect()->route('app.stockrecon.weekly.show', compact('branch', 'case'))->with('status', $result->Message);
    }
}
