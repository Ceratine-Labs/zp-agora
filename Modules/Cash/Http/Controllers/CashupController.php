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
use Modules\Cash\Grids\CashupGrid;
use Modules\Cash\Grids\ZreadNoCashupGrid;
use Modules\Cash\Http\Requests\CashupLegRequest;
use Modules\Cash\Http\Requests\CashupPressRequest;
use Modules\Cash\Services\CashupService;
use Modules\Cash\Support\CashupScope;
use Modules\Core\Models\Branch;

/**
 * Daily banking and cashups (T037) — the mockup's `#/op/banking` and
 * `#/op/cashups`.
 *
 *   Daily Banking   one site, one day: the day's cashups, and one of them
 *                   opened — its Z-read, its legs as tabs, what it actually
 *                   totals and the variance, and the presses that act on it
 *   Cashups         the manager's view: every cashup in a period across sites,
 *                   with the queues (open, the Z-read disagrees) as a parameter
 *   Z-reads with no cashup   the queue of takings nobody has cashed up
 *   the phone       the day's cashups, read and confirmed, nothing else
 *
 * Four presses, four permissions (feature-rules §4):
 *
 *   cash.cashup.view     every screen
 *   cash.cashup.save     "Save card amounts" and "Add from PumpIT" — the two
 *                        presses that WRITE PUMPIT (the header, the leg and,
 *                        for a pool leg, the pool row's banked mark), and only
 *                        while config('cash.cashup.write_mode') is 'live'
 *   cash.cashup.confirm  agree to a cashup's figures (Agora only)
 *   cash.cashup.close    a manager's sign-off, and reopening one (Agora only)
 *
 * There is no delete: a cashup is a transactional entry.
 *
 * Every press is a POST that redirects back with the scope in the URL, so a
 * reload never repeats a press and the answer can be linked.
 */
class CashupController extends Controller
{
    public function __construct(
        protected CashupService $service,
        protected GridService $grids,
    ) {}

    public function banking(Request $request, BranchContext $context): View
    {
        $scope = CashupScope::fromRequest($request, $context);
        $day = ['cashups' => collect(), 'lines' => collect(), 'zreads' => collect(), 'events' => collect()];
        $one = null;
        $refusal = null;
        $cards = null;
        $pool = null;
        $poolChannel = null;

        if ($scope->isChosen()) {
            try {
                $day = $this->service->day($scope->withCashup(null, null));

                if ($scope->isOne()) {
                    $opened = $this->service->day($scope);
                    $one = $opened['cashups']->first();
                    $day['lines'] = $opened['lines'];
                    $day['events'] = $opened['events'];

                    if ($request->boolean('cards')) {
                        $cards = $this->service->cardLoad($scope);
                    }

                    $asked = (string) $request->query('pool', '');

                    // Only for whoever may add, while it can still be added to.
                    if ($asked !== '' && $this->service->isPool($asked)
                        && in_array($asked, $this->service->savableChannels(), true)
                        && (bool) $request->user()?->can('cash.cashup.save')
                        && ($one === null || $one->Status !== 'Closed')) {
                        $poolChannel = $asked;
                        $pool = $this->service->pool($scope, $asked);
                    }
                }
            } catch (AgoraProcException $e) {
                $refusal = $e->getMessage();
            }
        }

        return view('cash::banking.index', [
            'scope' => $scope,
            'branches' => $this->branches($context),
            'pinned' => $context->isBranchWorkspace(),
            'refusal' => $refusal,
            'cashups' => $day['cashups'],
            'zreads' => $day['zreads'],
            'one' => $one,
            'lines' => $day['lines'],
            'events' => $day['events'],
            'cards' => $cards,
            'pool' => $pool,
            'poolChannel' => $poolChannel,
            'summary' => $this->service->summary($day['cashups'], $day['zreads']),
            'channels' => $this->service->channels(),
            'writeMode' => $this->service->writeMode(),
            'savable' => $this->service->savableChannels(),
            'held' => $this->service->heldChannels(),
            'tolerance' => $this->service->varianceTolerance(),
        ]);
    }

    public function cashups(Request $request, CashupGrid $grid): View
    {
        return view('cash::cashups.index', [
            'show' => in_array($request->query('show'), ['open', 'disagree'], true) ? $request->query('show') : 'all',
            'grid' => $this->grids->build($grid, $request, $request->user()?->Id),
        ]);
    }

    public function noCashup(Request $request, ZreadNoCashupGrid $grid): View
    {
        return view('cash::cashups.nocashup', [
            'grid' => $this->grids->build($grid, $request, $request->user()?->Id),
        ]);
    }

    /**
     * One cashup opened underneath its row on the Cashups grid.
     *
     * HTML, not JSON — the formats live in App\Support\Format. The fragment for
     * row-detail.js (which sends X-Requested-With); the Daily Banking screen
     * for anybody who opened the address directly, because that is where the
     * whole of the cashup is.
     */
    public function row(Request $request, BranchContext $context): View|RedirectResponse
    {
        $scope = CashupScope::fromRequest($request, $context);

        abort_unless($scope->isOne(), 404);

        if (! $request->ajax()) {
            return redirect()->route('app.cash.banking', $scope->query());
        }

        $day = $this->service->day($scope);
        $one = $day['cashups']->first();

        abort_if($one === null, 404, 'PumpIT holds no cashup for that till and shift on that day.');

        return view('cash::cashups.row', [
            'scope' => $scope,
            'one' => $one,
            'lines' => $day['lines'],
            'channels' => $this->service->channels(),
            'tolerance' => $this->service->varianceTolerance(),
        ]);
    }

    /**
     * Save the card terminal's batches as the cashup's FNB leg — the press that
     * writes PumpIT. Not reachable at all while the write switch is off.
     */
    public function saveCards(CashupPressRequest $request, BranchContext $context): RedirectResponse
    {
        $scope = CashupScope::fromRequest($request, $context);

        abort_unless($scope->isOne(), 404);
        abort_unless($this->service->writeMode() === 'live', 403, 'Saving cashups to PumpIT is switched off.');

        return $this->press($request, $scope, fn () => $this->service->saveCardLoad(
            $scope, $this->userId($request), $request->note()
        ));
    }

    /**
     * Add ticked pool rows to one leg of the cashup — writes PumpIT: the leg
     * rows, the header's totals and each pool row's banked mark. Not reachable
     * at all while the write switch is off.
     */
    public function saveLeg(CashupLegRequest $request, BranchContext $context): RedirectResponse
    {
        $scope = CashupScope::fromRequest($request, $context);

        abort_unless($scope->isOne(), 404);
        abort_unless($this->service->writeMode() === 'live', 403, 'Saving cashups to PumpIT is switched off.');

        return $this->press($request, $scope, fn () => $this->service->savePool(
            $scope, (string) $request->input('channel'), $request->poolKeys(), $this->userId($request), $request->note()
        ));
    }

    /** A manager reopens a closed cashup; the reason is required by the procedure. */
    public function reopen(CashupPressRequest $request, BranchContext $context): RedirectResponse
    {
        $scope = CashupScope::fromRequest($request, $context);

        abort_unless($scope->isOne(), 404);

        return $this->press($request, $scope, fn () => $this->service->reopen($scope, $this->userId($request), $request->note()));
    }

    public function confirm(CashupPressRequest $request, BranchContext $context): RedirectResponse
    {
        $scope = CashupScope::fromRequest($request, $context);

        abort_unless($scope->isOne(), 404);

        return $this->press($request, $scope, fn () => $this->service->confirm($scope, $this->userId($request), $request->note()));
    }

    public function close(CashupPressRequest $request, BranchContext $context): RedirectResponse
    {
        $scope = CashupScope::fromRequest($request, $context);

        abort_unless($scope->isOne(), 404);

        return $this->press($request, $scope, fn () => $this->service->close($scope, $this->userId($request), $request->note()));
    }

    /**
     * The phone: the day's cashups at one site, each with its figures and —
     * for one still open — Confirm. Nothing else (plan §3.9, "cashup confirm").
     *
     * Its own address rather than a device switch, as the Z-read phone screen
     * is: there is no DeviceDetector or pick() in the application yet (E18).
     */
    public function phone(Request $request, BranchContext $context): View
    {
        $scope = CashupScope::fromRequest($request, $context)->withCashup(null, null);
        $cashups = collect();
        $refusal = null;

        if ($scope->isChosen()) {
            try {
                $cashups = $this->service->day($scope)['cashups'];
            } catch (AgoraProcException $e) {
                $refusal = $e->getMessage();
            }
        }

        return view('cash::mobile.banking.index', [
            'scope' => $scope,
            'branches' => $this->branches($context),
            'pinned' => $context->isBranchWorkspace(),
            'refusal' => $refusal,
            // Waiting on a person first, then the biggest variance.
            'cashups' => $cashups->sortBy(fn (object $c) => [
                $c->Status === 'Open' ? 0 : ($c->Status === 'Confirmed' ? 1 : 2),
                -abs((float) $c->Variance),
            ])->values(),
            'tolerance' => $this->service->varianceTolerance(),
        ]);
    }

    /**
     * Run a press and come back — to the phone, the Cashups grid or Daily
     * Banking, whichever it was pressed from — with its answer or its refusal.
     *
     * AgoraProcException only: a refusal is a message to the person. Anything
     * else is a fault and belongs on the error page and in the log.
     *
     * @param  callable(): object  $press
     */
    private function press(CashupPressRequest $request, CashupScope $scope, callable $press): RedirectResponse
    {
        $back = match ($request->input('from')) {
            'phone' => redirect()->route('app.cash.banking.phone', $scope->withCashup(null, null)->query()),
            'cashups' => redirect()->to(url()->previous(route('app.cash.cashups'))),
            default => redirect()->route('app.cash.banking', $scope->query()),
        };

        try {
            $status = $press();
        } catch (AgoraProcException $e) {
            return $back->with('cashupRefusal', $e->getMessage())->with('cashupCode', $e->code());
        }

        return $back->with('cashupStatus', $status->Message ?? 'Done.')->with('cashupCode', $status->Code ?? null);
    }

    private function userId(Request $request): int
    {
        return (int) $request->user()?->Id;
    }

    /**
     * The sites this person may choose from — the same rule the scope bar
     * uses, so the list offered and the list CashupScope accepts agree.
     *
     * @return Collection<int, Branch>
     */
    private function branches(BranchContext $context): Collection
    {
        return Branch::query()->visibleTo($context->allowed())->ordered()->get();
    }
}
