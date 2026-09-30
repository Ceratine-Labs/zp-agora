<?php

namespace Modules\Utilities\Http\Controllers;

use App\Exceptions\AgoraProcException;
use App\Grid\GridRegistry;
use App\Grid\GridService;
use App\Http\Controllers\Controller;
use App\Support\BranchContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Core\Models\Branch;
use Modules\Utilities\Http\Requests\SaveReadingsRequest;
use Modules\Utilities\Services\UtilitiesService;

/**
 * Utility meters (T056): capture a day's readings, see the month per meter,
 * the meters that have stopped moving, and every reading.
 *
 * ONE SCREEN FOR THE DESK AND THE PHONE. There is no mobile view resolver in
 * Agora yet (plan §3.9, T016 — no DeviceContext, no `pick()`, no mobile
 * layout), so this is the desktop screen built to hold at 375px: the capture
 * form is one site and one DAY, which on a phone is a short stack of fields,
 * and everything wide sits in a container that scrolls on its own.
 *
 * THE ONE WRITE is to the customer's PumpIT.dbo.BRN_UtilityTransaction, through
 * agora.usp_Utilities_Save. Three locks on it, per feature-rules §4: the route
 * (`utilities.readings.save`), the form (not rendered without it, and captured
 * readings not editable without `utilities.readings.edit`), and the procedure,
 * which refuses to change a captured reading unless told the caller may.
 */
class UtilitiesController extends Controller
{
    public function __construct(
        protected UtilitiesService $service,
        protected GridRegistry $registry,
        protected GridService $grids,
    ) {}

    public function index(Request $request, BranchContext $context): View
    {
        $branches = $this->branches();
        $branchId = $this->branchFor($request, $context);
        $day = $this->day($request->query('date'));
        $monthFrom = $day->copy()->startOfMonth();
        $monthTo = $day->copy()->endOfMonth()->min(Carbon::today());

        $capture = ['lines' => collect(), 'meters' => collect(), 'lastSave' => null];
        $month = ['lines' => collect(), 'meters' => collect(), 'lastSave' => null];
        $zero = collect();
        $refusal = null;

        if ($branchId !== null) {
            try {
                $capture = $this->service->prepare($branchId, $day, $day);
                $month = $this->service->prepare($branchId, $monthFrom, $monthTo);
                $zero = $this->service->zeroUsage($branchId, $monthFrom, $monthTo);
            } catch (AgoraProcException $e) {
                $refusal = $e->getMessage();
            }
        }

        $user = $request->user();

        return view('utilities::index', [
            'branches' => $branches,
            'branchId' => $branchId,
            'branchName' => $branchId === null ? null
                : ($branches->firstWhere('BranchId', $branchId)->Name
                    ?? Branch::query()->where('BranchId', $branchId)->value('Name')
                    ?? "Site {$branchId}"),
            'pinned' => $context->isBranchWorkspace(),
            'day' => $day,
            'monthFrom' => $monthFrom,
            'monthTo' => $monthTo,
            'lines' => $capture['lines'],
            // Agora's own record of who last recorded this day. Its CreatedAt
            // is UTC (SYSUTCDATETIME), like every Agora timestamp; the view
            // parses it as UTC before saying how long ago it was.
            'lastSave' => $capture['lastSave'],
            'meters' => $month['meters'],
            'zero' => $zero,
            'refusal' => $refusal,
            'writesOn' => $this->service->writesOn(),
            'maySave' => (bool) $user?->can('utilities.readings.save'),
            'mayEdit' => (bool) $user?->can('utilities.readings.edit'),
            'untariffed' => $this->service->typesWithoutTariff(),
            'zeroRunRule' => $this->service->zeroRunRule(),
            'grid' => $this->grids->build(
                $this->registry->findOrFail('app.utilities.index'),
                $request,
                $user?->Id,
            ),
            // The grid's own branches selector (feature-rules §3.3) belongs to
            // head office; in the branch workspace the site is pinned.
            'gridBranches' => $context->isBranchWorkspace() ? null : $branches,
        ]);
    }

    public function save(SaveReadingsRequest $request, BranchContext $context): RedirectResponse
    {
        $branchId = $this->resolveBranch((int) $request->validated('branch_id'), $context);
        $date = (string) $request->validated('date');

        if (! $this->service->writesOn()) {
            return back()->with('refusal', 'Recording meter readings is switched off. Nothing was written.');
        }

        try {
            $result = $this->service->save(
                $branchId,
                (array) $request->validated('lines'),
                (bool) $request->user()?->can('utilities.readings.edit'),
                $request->validated('reason'),
                $request->user()?->Id,
            );
        } catch (AgoraProcException $e) {
            return back()->withInput()->with('refusal', $e->getMessage())->with('refusalCode', $e->code());
        }

        /*
         * Flagged on capture: anything this save left standing still for long
         * enough is said straight away, on the page the person lands back on,
         * rather than waiting for somebody to open a report. Read-only — the
         * procedure computes it fresh from the readings just written.
         */
        $savedDay = Carbon::parse($date);
        $flags = $this->service
            ->zeroUsage($branchId, $savedDay->copy()->subDays(60), $savedDay)
            ->filter(fn (object $run) => substr((string) $run->RunTo, 0, 10) === $savedDay->toDateString())
            ->map(fn (object $run) => (string) $run->Title)
            ->values()
            ->all();

        return redirect()
            ->route('app.utilities.index', ['branch_id' => $branchId, 'date' => $date])
            ->with('status', (string) ($result->Message ?? 'Saved.'))
            ->with('flagged', $flags);
    }

    /**
     * The trading sites this person may see, for the site picker. BranchScope
     * narrows Branch to the person's grants, so what the picker offers and
     * what a query returns cannot drift apart.
     *
     * @return Collection<int, Branch>
     */
    protected function branches(): Collection
    {
        return Branch::query()
            ->where('IsActive', true)
            ->where('IsTrading', true)
            ->orderBy('Name')
            ->get();
    }

    /**
     * Which site the screen is about. In the branch workspace it is the pinned
     * site whatever the URL says; in head office it is the `branch_id` asked
     * for, if this person may see it, and otherwise none — the screen then
     * asks for one rather than guessing.
     */
    protected function branchFor(Request $request, BranchContext $context): ?int
    {
        if ($context->isBranchWorkspace() && $context->id() !== null) {
            return $context->id();
        }

        $asked = $request->query('branch_id');

        if (! is_numeric($asked) || (int) $asked <= 0) {
            return null;
        }

        return $context->maySee((int) $asked) ? (int) $asked : null;
    }

    /**
     * The day to capture: the one asked for, or today. Never a day that has
     * not happened — a reading is taken, not forecast — so a future date is
     * brought back to today rather than offering boxes nobody can honestly
     * fill.
     */
    protected function day(mixed $asked): Carbon
    {
        $today = Carbon::today();

        if (! is_string($asked) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $asked) !== 1) {
            return $today;
        }

        try {
            $day = Carbon::createFromFormat('Y-m-d', $asked)->startOfDay();
        } catch (\Throwable) {
            return $today;
        }

        return $day->greaterThan($today) ? $today : $day;
    }

    /**
     * Which branch a submitted form is actually about. A branch user cannot
     * record another site's readings by editing the form: their context is
     * pinned and wins. For head office the form is the choice — and it is
     * still checked, because a form field is not an authorisation.
     */
    protected function resolveBranch(int $submitted, BranchContext $context): int
    {
        if ($context->isBranchWorkspace() && $context->id() !== null) {
            return $context->id();
        }

        abort_unless($context->maySee($submitted), 403, 'You may not record readings for that site.');

        return $submitted;
    }
}
