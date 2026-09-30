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
use Illuminate\Support\Str;
use Modules\Cash\Grids\ZreadGrid;
use Modules\Cash\Http\Requests\SetZreadRequest;
use Modules\Cash\Http\Requests\ZreadRowRequest;
use Modules\Cash\Services\ZreadService;
use Modules\Cash\Support\ZreadKey;
use Modules\Cash\Support\ZreadScope;
use Modules\Core\Models\Branch;

/**
 * ZREAD Allocation (T036) — the mockup's `#/op/zread`.
 *
 * "A Z-read arrives from the till with a number and an operator ID, and
 * nothing else. Allocating it means saying which shift it belongs to and who
 * was on it. The system already knows what this branch normally does — let it
 * propose, and only touch the ones it cannot call."
 *
 * Three presses on the run bar, as the mockup has them:
 *
 *   Auto-allocate    stages the proposals at or above the threshold
 *   Clear proposals  throws the staging away
 *   Update ZREAD     saves what is staged — the only press that allocates
 *
 * and three in a row's panel: Accept suggestion, Set this Z-read, Clear.
 * Staging and saving are two permissions (cash.zread.allocate and
 * cash.zread.commit), because proposing and deciding are two acts.
 *
 * Every press is a POST that redirects back to the page with the scope in the
 * URL, so a reload never repeats a press and the answer can be linked.
 *
 * WHAT REACHES PUMPIT. Update ZREAD (and the phone's Confirm) writes TillNo,
 * ShiftNo and EmployeeCode onto the Z-read's PumpIT row when
 * config('cash.zread.stamp_mode') is 'live', and Reverse puts the prior three
 * back; both through their procedures only. Every other press writes
 * agora.ZreadAllocation alone.
 */
class ZreadController extends Controller
{
    public function __construct(
        protected ZreadService $service,
        protected GridService $grids,
    ) {}

    public function index(Request $request, BranchContext $context, ZreadGrid $grid): View
    {
        $scope = ZreadScope::fromRequest($request, $context);

        /*
         * The figures above the grid come from the proposer's own rows, all of
         * them — not the grid's page, and not the grid's filters: "how many
         * are waiting" is a fact about the period, and a header filter must
         * not make it smaller.
         */
        $rows = collect();
        $refusal = null;

        if ($scope->isChosen()) {
            try {
                $rows = $this->service->propose($scope);
            } catch (AgoraProcException $e) {
                $refusal = $e->getMessage();
            }
        }

        return view('cash::zread.index', [
            'scope' => $scope,
            'branches' => $this->branches($context),
            'pinned' => $context->isBranchWorkspace(),
            'locations' => (array) config('cash.zread.locations', []),
            'summary' => $this->service->summary($rows),
            'threshold' => $this->service->threshold(),
            'stampMode' => $this->service->stampMode(),
            'refusal' => $refusal,
            'commits' => $scope->isChosen() && $refusal === null ? $this->service->commits($scope) : collect(),
            'grid' => $scope->isChosen() && $refusal === null
                ? $this->grids->build($grid, $request, $request->user()?->Id)
                : null,
        ]);
    }

    /**
     * One Z-read's panel: the proposal with its evidence, the forms, and who
     * normally works that terminal.
     *
     * HTML, not JSON — the formats live in App\Support\Format. The fragment
     * for row-detail.js (which sends X-Requested-With), the same markup inside
     * the shell for anybody who opened the address directly.
     */
    public function row(ZreadRowRequest $request, BranchContext $context): View
    {
        $scope = ZreadScope::fromRequest($request, $context);
        $key = ZreadKey::fromRequest($request, $scope->branchId);

        abort_if($key === null, 404);

        $zread = $this->service->find($key);

        abort_if($zread === null, 404, 'That Z-read is not in the site\'s readings.');

        $data = [
            'scope' => $scope,
            'key' => $key,
            'zread' => $zread,
            'history' => $this->service->terminalHistory($key),
            'tills' => $this->service->tills($key->branchId, $key->location),
            'shifts' => $this->service->shifts($key->branchId),
            'employees' => $this->service->employees($key->branchId),
            'stampMode' => $this->service->stampMode(),
        ];

        return $request->ajax()
            ? view('cash::zread.partials.row', $data)
            : view('cash::zread.row', $data);
    }

    public function autoAllocate(Request $request, BranchContext $context): RedirectResponse
    {
        $scope = ZreadScope::fromRequest($request, $context);

        return $this->press($scope, fn () => $this->service->autoAllocate($scope, $this->userId($request)));
    }

    public function clear(Request $request, BranchContext $context): RedirectResponse
    {
        $scope = ZreadScope::fromRequest($request, $context);
        $only = ZreadKey::fromRequest($request, $scope->branchId);

        return $this->press($scope, fn () => $this->service->clear($scope, $this->userId($request), $only));
    }

    public function commit(Request $request, BranchContext $context): RedirectResponse
    {
        $scope = ZreadScope::fromRequest($request, $context);
        $only = ZreadKey::fromRequest($request, $scope->branchId);

        return $this->press($scope, fn () => $this->service->commit($scope, $this->userId($request), $only));
    }

    /**
     * Reverse one Update ZREAD press, by its CommitRef. The reason is required
     * by the procedure, not by a form rule: it is the only record of why an
     * allocation was undone.
     */
    public function reverse(Request $request, BranchContext $context): RedirectResponse
    {
        $scope = ZreadScope::fromRequest($request, $context);
        $commitRef = (string) $request->input('commit_ref', '');

        abort_unless(Str::isUuid($commitRef), 404);

        return $this->press($scope, fn () => $this->service->reverse(
            (int) $scope->branchId,
            $commitRef,
            trim((string) $request->input('reason', '')),
            $this->userId($request),
        ));
    }

    public function accept(ZreadRowRequest $request, BranchContext $context): RedirectResponse
    {
        $scope = ZreadScope::fromRequest($request, $context);
        $key = ZreadKey::fromRequest($request, $scope->branchId);

        abort_if($key === null, 404);

        return $this->press($scope, fn () => $this->service->accept($key, $this->userId($request)));
    }

    public function set(SetZreadRequest $request, BranchContext $context): RedirectResponse
    {
        $scope = ZreadScope::fromRequest($request, $context);
        $key = ZreadKey::fromRequest($request, $scope->branchId);

        abort_if($key === null, 404);

        return $this->press($scope, fn () => $this->service->set(
            $key,
            $request->integer('till_no'),
            $request->integer('shift_no'),
            trim((string) $request->input('employee_code')),
            $this->userId($request),
        ));
    }

    /**
     * The phone: confirm what the system proposes, one Z-read at a time, and
     * nothing else (plan §3.9, "Z-read confirm"). No manual set, no bulk —
     * the rows it cannot call are counted and left for a desk.
     *
     * Its own address rather than a device switch, because there is no
     * DeviceDetector or pick() in the application yet (E18). When there is,
     * the desktop route picks this blade on a phone and this route can go.
     */
    public function confirm(Request $request, BranchContext $context): View
    {
        $scope = ZreadScope::fromRequest($request, $context);
        $rows = collect();
        $refusal = null;

        if ($scope->isChosen()) {
            try {
                $rows = $this->service->propose($scope);
            } catch (AgoraProcException $e) {
                $refusal = $e->getMessage();
            }
        }

        $waiting = $rows->where('State', '!=', 'allocated');

        return view('cash::mobile.zread.index', [
            'scope' => $scope,
            'branches' => $this->branches($context),
            'pinned' => $context->isBranchWorkspace(),
            'refusal' => $refusal,
            // Staged first — somebody already looked at those — then the
            // proposals the system is surest of.
            'confirmable' => $waiting
                ->filter(fn (object $r) => $r->State === 'staged' || (bool) $r->IsComplete)
                ->sortBy(fn (object $r) => [$r->State === 'staged' ? 0 : 1,
                    ['certain' => 0, 'likely' => 1, 'review' => 2][$r->Confidence] ?? 3,
                    (string) $r->ZReadDate])
                ->values(),
            'forADesk' => $waiting->filter(fn (object $r) => $r->State === 'open' && ! (bool) $r->IsComplete)->count(),
            'stampMode' => $this->service->stampMode(),
        ]);
    }

    public function confirmOne(ZreadRowRequest $request, BranchContext $context): RedirectResponse
    {
        $scope = ZreadScope::fromRequest($request, $context);
        $key = ZreadKey::fromRequest($request, $scope->branchId);

        abort_if($key === null, 404);

        $zread = $this->service->find($key);

        abort_if($zread === null, 404, 'That Z-read is not in the site\'s readings.');

        try {
            $status = $this->service->confirm($key, $scope, $this->userId($request), $zread->State === 'staged');
        } catch (AgoraProcException $e) {
            return redirect()->route('app.cash.zread.confirm', $scope->query())->with('zreadRefusal', $e->getMessage());
        }

        return redirect()->route('app.cash.zread.confirm', $scope->query())
            ->with('zreadStatus', $status->Message ?? 'Saved.');
    }

    /**
     * Run a press and come back to the screen, with its answer or its refusal.
     *
     * AgoraProcException only: a refusal is a message to the person. Anything
     * else is a fault and belongs on the error page and in the log.
     *
     * @param  callable(): object  $press
     */
    private function press(ZreadScope $scope, callable $press): RedirectResponse
    {
        abort_unless($scope->isChosen(), 422, 'Choose a site first.');

        try {
            $status = $press();
        } catch (AgoraProcException $e) {
            return redirect()->route('app.cash.zread', $scope->query())
                ->with('zreadRefusal', $e->getMessage())
                ->with('zreadCode', $e->code());
        }

        return redirect()->route('app.cash.zread', $scope->query())
            ->with('zreadStatus', $status->Message ?? 'Done.')
            ->with('zreadCode', $status->Code ?? null);
    }

    private function userId(Request $request): int
    {
        return (int) $request->user()?->Id;
    }

    /**
     * The sites this person may choose from — the same rule the scope bar
     * uses, so the list offered and the list ZreadScope accepts agree.
     *
     * @return Collection<int, Branch>
     */
    private function branches(BranchContext $context): Collection
    {
        return Branch::query()->visibleTo($context->allowed())->ordered()->get();
    }
}
