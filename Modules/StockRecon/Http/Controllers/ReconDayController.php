<?php

namespace Modules\StockRecon\Http\Controllers;

use App\Exceptions\AgoraProcException;
use App\Http\Controllers\Controller;
use App\Support\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Modules\Core\Models\Branch;
use Modules\StockRecon\Services\ReconDayService;

/**
 * Is each day reconciled? The month calendar on the stock recon centre, and
 * one day behind a cell. Every answer is agora.usp_StockRecon_DayStatus's.
 */
class ReconDayController extends Controller
{
    public function __construct(protected ReconDayService $days) {}

    /**
     * The calendar, as a fragment the centre fetches after it shows: across
     * every site it is a second or two, and nobody opens the centre to wait.
     */
    public function calendar(Request $request, BranchContext $context): View
    {
        $month = ReconDayService::month($request->query('month'));
        $asked = (int) $request->query('branch_id', 0);

        // BranchScope has already narrowed this to the sites the person sees;
        // a pinned branch workspace is one site.
        $branches = Branch::query()->where('IsActive', true)->where('IsTrading', true)->orderBy('Name')->get();
        $ids = $asked > 0 && $context->maySee($asked)
            ? [$asked]
            : $branches->pluck('BranchId')->map(fn ($id) => (int) $id)->all();

        $sites = $this->days->calendar($ids, $month);
        $query = fn (Carbon $m) => route('app.stockrecon.days', array_filter(['month' => $m->format('Y-m'), 'branch_id' => $asked ?: null]));

        return view('stockrecon::days.calendar', [
            'rows' => $sites->map(fn (array $s) => ['label' => $s['name'], 'branchId' => $s['branchId'], 'days' => $s['days']]),
            'month' => $month,
            'prev' => $query($month->copy()->subMonth()),
            'next' => $month->copy()->addMonth()->lte(now()->startOfMonth()->addMonth()) ? $query($month->copy()->addMonth()) : null,
            'legend' => ReconDayService::LEGEND,
        ]);
    }

    /** One site, one day: its areas, what keeps it open, and the runs over it. */
    public function show(int $branch, string $day, BranchContext $context): View
    {
        abort_unless($context->maySee($branch), 403, 'You may not see that site.');
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 && strtotime($day) !== false, 404);

        try {
            $detail = $this->days->day($branch, $day);
        } catch (AgoraProcException $e) {
            abort(404, $e->getMessage());
        }

        return view('stockrecon::days.show', $detail + [
            'branch' => Branch::query()->where('BranchId', $branch)->first(),
            'date' => Carbon::parse($day),
        ]);
    }
}
