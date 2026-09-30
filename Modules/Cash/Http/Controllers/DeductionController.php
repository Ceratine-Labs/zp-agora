<?php

namespace Modules\Cash\Http\Controllers;

use App\Exceptions\AgoraProcException;
use App\Grid\GridDefinition;
use App\Grid\GridService;
use App\Http\Controllers\Controller;
use App\Support\BranchContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Cash\Grids\Deductions\CashShortGrid;
use Modules\Cash\Grids\Deductions\NonIntDetailGrid;
use Modules\Cash\Grids\Deductions\NonIntSummaryGrid;
use Modules\Cash\Grids\Deductions\ScheduleGrid;
use Modules\Cash\Grids\Deductions\StockLossDetailGrid;
use Modules\Cash\Grids\Deductions\StockLossSummaryGrid;
use Modules\Cash\Http\Requests\DeductionPeriodRequest;
use Modules\Cash\Services\DeductionService;
use Modules\Core\Models\Branch;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cash → Deductions (T041): PumpIT's five head-office payroll reports, as link
 * tabs each with its own grid, and the Payroll tab where a pay period's
 * schedule is built and the payroll file taken.
 *
 *   cash.deductions.view    the six tabs
 *   cash.deductions.build   build a site's schedule for a pay period (writes
 *                           agora.DeductionRun / agora.Deduction — Agora only)
 *   cash.deductions.export  take the payroll file (marks the runs exported)
 */
class DeductionController extends Controller
{
    public function __construct(
        protected DeductionService $service,
        protected GridService $grids,
        protected BranchContext $context,
    ) {}

    public function cashShorts(Request $request, CashShortGrid $grid): View
    {
        return $this->tab($request, $grid, 'cash');
    }

    public function stockLoss(Request $request, StockLossSummaryGrid $grid): View
    {
        return $this->tab($request, $grid, 'stock');
    }

    public function stockLossDetail(Request $request, StockLossDetailGrid $grid): View
    {
        return $this->tab($request, $grid, 'stockdetail');
    }

    public function nonInt(Request $request, NonIntSummaryGrid $grid): View
    {
        return $this->tab($request, $grid, 'nonint');
    }

    public function nonIntDetail(Request $request, NonIntDetailGrid $grid): View
    {
        return $this->tab($request, $grid, 'nonintdetail');
    }

    public function payroll(Request $request, ScheduleGrid $grid): View
    {
        return $this->tab($request, $grid, 'payroll', [
            'branches' => Branch::query()->trading()->visibleTo($this->context->allowed())->ordered()->get(),
        ]);
    }

    /** Build each chosen site's schedule for the period. */
    public function build(DeductionPeriodRequest $request, BranchContext $context): RedirectResponse
    {
        $sites = $request->branchIds($context);
        $result = $this->service->build($sites, $request->from(), $request->to(), (int) $request->user()?->Id, $request->note());

        $names = Branch::query()->whereIn('BranchId', $sites)->pluck('Name', 'BranchId');

        return redirect()->route('app.cash.deductions.payroll', $this->scope($request))
            ->with('deductionBuilt', collect($result['built'])->map(
                fn (object $s, int $id) => ($names[$id] ?? "Site {$id}").': '.$s->Message)->values()->all())
            ->with('deductionRefused', collect($result['refused'])->map(
                fn (string $m, int $id) => ($names[$id] ?? "Site {$id}").': '.$m)->values()->all());
    }

    /** The payroll file, as CSV; the runs it came from are marked exported. */
    public function export(DeductionPeriodRequest $request, BranchContext $context): StreamedResponse|RedirectResponse
    {
        try {
            $file = $this->service->exportPayroll($request->branchIds($context), $request->from(), $request->to(),
                (int) $request->user()?->Id);
        } catch (AgoraProcException $e) {
            return redirect()->route('app.cash.deductions.payroll', $this->scope($request))
                ->with('deductionRefused', [$e->getMessage()]);
        }

        return $this->service->csv($file['lines'], $request->from(), $request->to());
    }

    /**
     * One tab: its grid, and the stat strip read from the grid's own grand
     * totals — so the strip and the footer come out of the same result set.
     *
     * @param  array<string, mixed>  $with
     */
    private function tab(Request $request, GridDefinition $grid, string $tab, array $with = []): View
    {
        $result = $this->grids->build($grid, $request, $request->user()?->Id);
        [$defaultFrom, $defaultTo] = $this->service->defaultPeriod();
        $from = $result->gridQuery->from ?? $defaultFrom->toDateString();
        $to = $result->gridQuery->to ?? $defaultTo->toDateString();

        return view('cash::deductions.index', [
            'tab' => $tab,
            'grid' => $result,
            'totals' => $result->totals,
            'rows' => $result->total,
            'from' => $from,
            'to' => $to,
            'branchIds' => $result->gridQuery->branchIds,
            'leniency' => $this->service->leniency(),
            // T042: what is approved but on no cashup, so on no deduction — on the two tabs a short reaches.
            'unposted' => in_array($tab, ['cash', 'payroll'], true)
                ? $this->service->unposted($result->gridQuery->branchIds, $this->context->allowed(), Carbon::parse($from), Carbon::parse($to))
                : null,
        ] + $with);
    }

    /** @return array<string, mixed> the grid's own scope, carried back to the Payroll tab */
    private function scope(DeductionPeriodRequest $request): array
    {
        return array_filter([
            'branches' => implode(',', array_map('intval', (array) $request->input('branches', []))) ?: null,
            'from' => Carbon::parse((string) $request->input('from'))->toDateString(),
            'to' => Carbon::parse((string) $request->input('to'))->toDateString(),
        ]);
    }
}
