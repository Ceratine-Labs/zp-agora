<?php

namespace Modules\Cash\Http\Controllers;

use App\Exceptions\AgoraProcException;
use App\Grid\GridService;
use App\Http\Controllers\Controller;
use App\Support\Badges\BadgeRegistry;
use App\Support\BranchContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Cash\Grids\DropSafeGrid;
use Modules\Cash\Http\Requests\DropSafeCollectRequest;
use Modules\Cash\Http\Requests\DropSafeRequest;
use Modules\Cash\Http\Requests\DropSafeVoidRequest;
use Modules\Cash\Services\DropSafeService;
use Modules\Cash\Support\DropSafeScope;
use Modules\Core\Models\Branch;

/**
 * The drop safe (T039).
 *
 *   Drop safe        one site: what is in the safe now (the "In the safe"
 *                    chip), the form that drops a bag or corrects one, the
 *                    collection of ticked bags, and the collections of the
 *                    last few days
 *   phone            the same site on a phone: drop a bag, see the safe
 *   Drop safe bags   every bag across sites, as a grid
 *
 * Five permissions (feature-rules §4):
 *
 *   cash.dropsafe.view     the screens and the grid
 *   cash.dropsafe.save     Drop a bag — WRITES PUMPIT (a BRN_DropSafe row)
 *   cash.dropsafe.edit     Correct a bag still in the safe and on no cashup —
 *                          WRITES PUMPIT (that row)
 *   cash.dropsafe.void     Void a bag captured in error, still in the safe and
 *                          on no cashup — DELETES its BRN_DropSafe row, after
 *                          keeping the whole row in agora.DropSafeEvent (Ryan's
 *                          named go-ahead, 27 Sep 2026, for this delete only)
 *   cash.dropsafe.collect  Collect ticked bags — WRITES PUMPIT (a
 *                          BRN_DropSafe_Collection row and each bag's
 *                          CollectionId)
 *
 * every write only while config('cash.dropsafe.write_mode') is 'live'. A bag on
 * a cashup or in a collection is never changed and never voided.
 *
 * Each press is a POST that redirects back with the scope in the URL, so a
 * reload never repeats it. A refusal comes back with its AGORA code, which the
 * screen shows in a toast and in a notice that stays.
 */
class DropSafeController extends Controller
{
    public function __construct(
        protected DropSafeService $service,
        protected GridService $grids,
    ) {}

    public function index(Request $request, BranchContext $context): View
    {
        return view('cash::dropsafe.index', $this->page($request, $context));
    }

    public function phone(Request $request, BranchContext $context): View
    {
        return view('cash::mobile.dropsafe.index', $this->page($request, $context));
    }

    /** Drop a bag — the press that writes PumpIT. Not reachable while the switch is off. */
    public function store(DropSafeRequest $request, BranchContext $context): RedirectResponse
    {
        $scope = DropSafeScope::fromRequest($request, $context)->withBag(null);

        abort_unless($scope->isChosen(), 404);
        abort_unless($this->service->writeMode() === 'live', 403, 'Capturing drop safe bags to PumpIT is switched off.');

        return $this->press($request, $scope, $context, fn () => $this->service->drop(
            (int) $scope->branchId, $request->bagNo(), $request->bag(), $this->userId($request), $request->note(),
        ));
    }

    /** Correct a bag still in the safe and on no cashup. */
    public function update(DropSafeRequest $request, BranchContext $context): RedirectResponse
    {
        $scope = DropSafeScope::fromRequest($request, $context)->withBag($request->bagNo());

        abort_unless($scope->isChosen(), 404);
        abort_unless($this->service->writeMode() === 'live', 403, 'Capturing drop safe bags to PumpIT is switched off.');

        return $this->press($request, $scope, $context, fn () => $this->service->edit(
            (int) $scope->branchId, $request->bagNo(), $request->newBagNo(), $request->bag(), $this->userId($request), $request->note(),
        ));
    }

    /** Void a bag captured in error — the one press that deletes, and says why. */
    public function void(DropSafeVoidRequest $request, BranchContext $context): RedirectResponse
    {
        $scope = DropSafeScope::fromRequest($request, $context)->withBag($request->bagNo());

        abort_unless($scope->isChosen(), 404);
        abort_unless($this->service->writeMode() === 'live', 403, 'Capturing drop safe bags to PumpIT is switched off.');

        return $this->press($request, $scope, $context, fn () => $this->service->void(
            (int) $scope->branchId, $request->bagNo(), $request->reason(), $this->userId($request),
        ));
    }

    /** Hand the ticked bags to the security company. */
    public function collect(DropSafeCollectRequest $request, BranchContext $context): RedirectResponse
    {
        $scope = DropSafeScope::fromRequest($request, $context)->withBag(null);

        abort_unless($scope->isChosen(), 404);
        abort_unless($this->service->writeMode() === 'live', 403, 'Capturing drop safe collections to PumpIT is switched off.');

        return $this->press($request, $scope, $context, fn () => $this->service->collect(
            (int) $scope->branchId, $request->bags(), $request->collection(), $this->userId($request), $request->note(),
        ));
    }

    public function bags(Request $request, DropSafeGrid $grid): View
    {
        return view('cash::dropsafe.bags', [
            'grid' => $this->grids->build($grid, $request, $request->user()?->Id),
        ]);
    }

    /**
     * What both screens show for a site.
     *
     * @return array<string, mixed>
     */
    private function page(Request $request, BranchContext $context): array
    {
        $scope = DropSafeScope::fromRequest($request, $context);
        $site = ['bags' => collect(), 'collections' => collect(), 'reasons' => collect(),
            'limitReasons' => collect(), 'employees' => collect(), 'summary' => null];
        $refusal = null;

        if ($scope->isChosen()) {
            try {
                $site = $this->service->site($scope);
            } catch (AgoraProcException $e) {
                $refusal = $e->getMessage();
            }
        }

        // The bag opened: only one still in the safe and on no cashup. It is
        // offered for correction to somebody who may edit, and for voiding to
        // somebody who may void — two grants, two panels. Anything else opens
        // the page as if no bag were named; the procedures would refuse anyway.
        $opened = $scope->bagNo === null ? null
            : $site['bags']->first(fn (object $b) => strcasecmp((string) $b->BagNo, $scope->bagNo) === 0 && (bool) $b->CanEdit);
        $editing = $opened !== null && $request->user()?->can('cash.dropsafe.edit') ? $opened : null;
        $voiding = $opened !== null && $request->user()?->can('cash.dropsafe.void') ? $opened : null;

        return [
            'scope' => $scope,
            'branches' => $this->branches($context),
            'pinned' => $context->isBranchWorkspace(),
            'refusal' => $refusal,
            'bags' => $site['bags'],
            'collections' => $site['collections'],
            'reasons' => $site['reasons'],
            'limitReasons' => $site['limitReasons'],
            'employees' => $site['employees'],
            'summary' => $site['summary'],
            'editing' => $editing,
            'voiding' => $voiding,
            'writeMode' => $this->service->writeMode(),
            'threshold' => $this->service->threshold(),
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
    private function press(Request $request, DropSafeScope $scope, BranchContext $context, callable $press): RedirectResponse
    {
        $phone = $request->input('from') === 'phone';

        try {
            $status = $press();
        } catch (AgoraProcException $e) {
            $back = $phone
                ? redirect()->route('app.cash.dropsafe.phone', $scope->withBag(null)->query())
                : redirect()->route('app.cash.dropsafe', $scope->query());

            return $back->withInput()
                ->with('dropSafeRefusal', $e->getMessage())
                ->with('dropSafeCode', $e->code());
        }

        // The safe moved: the console's figure for this person's scope is stale.
        app(BadgeRegistry::class)->forget(DropSafeService::BADGE, $context);

        $scope = $scope->withBag(null);
        $back = $phone
            ? redirect()->route('app.cash.dropsafe.phone', $scope->query())
            : redirect()->route('app.cash.dropsafe', $scope->query());

        return $back->with('dropSafeStatus', $status->Message ?? 'Saved.')->with('dropSafeCode', $status->Code ?? null);
    }

    private function userId(Request $request): int
    {
        return (int) $request->user()?->Id;
    }

    /**
     * The sites this person may choose from — the same rule the scope bar
     * uses, so the list offered and the list DropSafeScope accepts agree.
     *
     * @return Collection<int, Branch>
     */
    private function branches(BranchContext $context): Collection
    {
        return Branch::query()->visibleTo($context->allowed())->ordered()->get();
    }
}
