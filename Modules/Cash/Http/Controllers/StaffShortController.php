<?php

namespace Modules\Cash\Http\Controllers;

use App\Exceptions\AgoraProcException;
use App\Grid\GridService;
use App\Http\Controllers\Controller;
use App\Support\BranchContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Cash\Grids\StaffShortGrid;
use Modules\Cash\Http\Requests\StaffShortDecisionRequest;
use Modules\Cash\Http\Requests\StaffShortPostRequest;
use Modules\Cash\Http\Requests\StaffShortRequest;
use Modules\Cash\Services\StaffShortService;
use Modules\Cash\Support\StaffShortScope;
use Modules\Core\Models\Branch;

/**
 * Staff shorts (T040).
 *
 *   Staff shorts        one site: raise a short, what is waiting for an
 *                       approver, what is approved and not on a cashup yet,
 *                       and the rest of the period; a short opened (?short=)
 *                       shows its trail and the presses it is open to
 *   phone               the same site on a phone: raise a short, see the open
 *                       ones
 *   Staff shorts list   every short across sites, as a grid — head office's
 *                       "Waiting on a decision"
 *
 * Four permissions (feature-rules §4):
 *
 *   cash.staffshort.view     the screens and the grid
 *   cash.staffshort.raise    Raise — WRITES PUMPIT (a BRN_StaffShorts row and
 *                            the SS_UniqueNumber section 2 counter)
 *   cash.staffshort.approve  Approve, or Decline with a reason — WRITES PUMPIT
 *                            (that row's approval or decline columns)
 *   cash.staffshort.withdraw Withdraw an approved short not yet on a cashup —
 *                            WRITES PUMPIT (the approval undone, the short
 *                            declined; PM decision 01a0e5b5)
 *   cash.staffshort.post     Post an approved short to a cashup — WRITES PUMPIT
 *                            (the cashup's pump-shortages line, the short's
 *                            flag, the header's totals)
 *
 * every write only while config('cash.staffshort.write_mode') is 'live' (and,
 * for a post, cash.cashup.write_mode too). A staff short is transactional:
 * nothing deletes one — a wrong short is declined.
 *
 * Each press is a POST that redirects back with the scope in the URL, so a
 * reload never repeats it. A refusal comes back with its AGORA code, shown as a
 * toast and in a notice that stays.
 */
class StaffShortController extends Controller
{
    public function __construct(
        protected StaffShortService $service,
        protected GridService $grids,
    ) {}

    public function index(Request $request, BranchContext $context): View
    {
        return view('cash::staffshorts.index', $this->page($request, $context));
    }

    public function phone(Request $request, BranchContext $context): View
    {
        return view('cash::mobile.staffshorts.index', $this->page($request, $context));
    }

    public function list(Request $request, StaffShortGrid $grid): View
    {
        return view('cash::staffshorts.list', [
            'grid' => $this->grids->build($grid, $request, $request->user()?->Id),
        ]);
    }

    /** Raise a short — the press that writes PumpIT. Not reachable while the switch is off. */
    public function store(StaffShortRequest $request, BranchContext $context): RedirectResponse
    {
        $scope = StaffShortScope::fromRequest($request, $context)->withShort(null);

        abort_unless($scope->isChosen(), 404);
        abort_unless($this->service->writeMode() === 'live', 403, 'Raising staff shorts in PumpIT is switched off.');

        return $this->press($request, $scope, fn () => $this->service->raise(
            (int) $scope->branchId, $request->short(), $this->userId($request), $request->fromPhone() ? 'phone' : 'screen',
        ), opened: false);
    }

    public function approve(StaffShortDecisionRequest $request, BranchContext $context): RedirectResponse
    {
        $scope = StaffShortScope::fromRequest($request, $context)->withShort($request->shortNo());

        abort_unless($scope->isChosen(), 404);
        abort_unless($this->service->writeMode() === 'live', 403, 'Deciding staff shorts in PumpIT is switched off.');

        return $this->press($request, $scope, fn () => $this->service->approve(
            (int) $scope->branchId, $request->shortNo(), $this->userId($request), $request->note(),
        ));
    }

    public function decline(StaffShortDecisionRequest $request, BranchContext $context): RedirectResponse
    {
        $scope = StaffShortScope::fromRequest($request, $context)->withShort($request->shortNo());

        abort_unless($scope->isChosen(), 404);
        abort_unless($this->service->writeMode() === 'live', 403, 'Deciding staff shorts in PumpIT is switched off.');

        return $this->press($request, $scope, fn () => $this->service->decline(
            (int) $scope->branchId, $request->shortNo(), $request->reason(), $this->userId($request),
        ));
    }

    public function withdraw(StaffShortDecisionRequest $request, BranchContext $context): RedirectResponse
    {
        $scope = StaffShortScope::fromRequest($request, $context)->withShort($request->shortNo());

        abort_unless($scope->isChosen(), 404);
        abort_unless($this->service->writeMode() === 'live', 403, 'Deciding staff shorts in PumpIT is switched off.');

        return $this->press($request, $scope, fn () => $this->service->withdraw(
            (int) $scope->branchId, $request->shortNo(), $request->withdrawReason(), $this->userId($request),
        ));
    }

    public function post(StaffShortPostRequest $request, BranchContext $context): RedirectResponse
    {
        $scope = StaffShortScope::fromRequest($request, $context)->withShort($request->shortNo());

        abort_unless($scope->isChosen(), 404);
        abort_unless($this->service->postMode() === 'live', 403, 'Posting staff shorts to PumpIT cashups is switched off.');

        return $this->press($request, $scope, fn () => $this->service->post(
            (int) $scope->branchId, $request->shortNo(), $request->day(), $request->tillNo(), $request->shiftNo(), $this->userId($request),
        ));
    }

    /**
     * What both screens show for a site.
     *
     * @return array<string, mixed>
     */
    private function page(Request $request, BranchContext $context): array
    {
        $scope = StaffShortScope::fromRequest($request, $context);
        $site = ['shorts' => collect(), 'reasons' => collect(), 'employees' => collect(), 'summary' => null,
            'trail' => collect(), 'tills' => collect(), 'shifts' => collect()];
        $refusal = null;

        if ($scope->isChosen()) {
            try {
                $site = $this->service->site($scope);
            } catch (AgoraProcException $e) {
                $refusal = $e->getMessage();
            }
        }

        $opened = $scope->shortNo === null ? null
            : $site['shorts']->first(fn (object $s) => (int) $s->StaffShortsNo === $scope->shortNo);

        return [
            'scope' => $scope,
            'branches' => $this->branches($context),
            'pinned' => $context->isBranchWorkspace(),
            'refusal' => $refusal,
            'shorts' => $site['shorts'],
            'reasons' => $site['reasons'],
            'employees' => $site['employees'],
            'summary' => $site['summary'],
            'trail' => $site['trail'],
            'tills' => $site['tills'],
            'shifts' => $site['shifts'],
            'opened' => $opened,
            'writeMode' => $this->service->writeMode(),
            'postMode' => $this->service->postMode(),
        ];
    }

    /**
     * Run a press and come back — to the phone or the desk, whichever it was
     * pressed from — with its answer, or its refusal and its code.
     *
     * AgoraProcException only: a refusal is a message to the person. Anything
     * else is a fault and belongs on the error page and in the log.
     *
     * @param  callable(): object  $press
     */
    private function press(Request $request, StaffShortScope $scope, callable $press, bool $opened = true): RedirectResponse
    {
        $phone = $request->input('from') === 'phone';
        $route = $phone ? 'app.cash.staffshorts.phone' : 'app.cash.staffshorts';

        try {
            $status = $press();
        } catch (AgoraProcException $e) {
            return redirect()->route($route, $scope->query())->withInput()
                ->with('staffShortRefusal', $e->getMessage())
                ->with('staffShortCode', $e->code());
        }

        // A raise lands on the short it made; a decision or a post stays on it.
        $after = $opened ? $scope : $scope->withShort(isset($status->StaffShortsNo) ? (int) $status->StaffShortsNo : null);

        return redirect()->route($route, $phone ? $after->withShort(null)->query() : $after->query())
            ->with('staffShortStatus', $status->Message ?? 'Saved.')
            ->with('staffShortCode', $status->Code ?? null);
    }

    private function userId(Request $request): int
    {
        return (int) $request->user()?->Id;
    }

    /**
     * The sites this person may choose from — the same rule the scope bar
     * uses, so the list offered and the list StaffShortScope accepts agree.
     *
     * @return Collection<int, Branch>
     */
    private function branches(BranchContext $context): Collection
    {
        return Branch::query()->visibleTo($context->allowed())->ordered()->get();
    }
}
