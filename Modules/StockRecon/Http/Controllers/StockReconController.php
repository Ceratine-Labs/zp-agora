<?php

namespace Modules\StockRecon\Http\Controllers;

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
use Modules\StockRecon\Http\Requests\BalancePreviewRequest;
use Modules\StockRecon\Models\StockReconRun;
use Modules\StockRecon\Models\StockReconRunLine;
use Modules\StockRecon\Services\StockReconService;

/**
 * The stock recon centre.
 *
 * Two presses, the same shape bank reconciliation has: preview, then — when the
 * operator is satisfied — balance. The difference from the legacy is what sits
 * between them. dbo.sp_UpdateAUTOStockReconBalancing has one press: it computes
 * and writes in the same statement, keeps no record of what it moved, and can
 * undo nothing. Here the preview is RECORDED, and a commit can only ever act on
 * rows that run already holds.
 *
 * WHY THE TABS ARE FEWER THAN BANK RECON'S. There, an "area" is one of five
 * genuinely different procedures, so the workbench is per-area with five faces.
 * Here an area is a PARAMETER — one procedure, any counting area — so the
 * screens are the hub that runs it, the run it produced, and the exceptions
 * that run found. Adding a tab per counting area would be twenty tabs at a site
 * and would say nothing.
 *
 * NOTHING HERE WRITES TO THE CUSTOMER'S DATABASES while
 * config('stockrecon.stamp_mode') is 'journal', which is what it ships as. The
 * commit still happens, is still confirmed, and is still reversible — it lands
 * in agora.StockReconAmendment instead of in PumpIT.
 */
class StockReconController extends Controller
{
    public function __construct(
        protected StockReconService $service,
        protected GridRegistry $registry,
        protected GridService $grids,
    ) {}

    /**
     * The hub: the form that runs a balancing, where the reader left off, and
     * every run they have made.
     *
     * The branch is chosen HERE, on the result set, which is where
     * feature-rules §3.3 puts it. The chrome carries no branch selector: head
     * office is an estate-wide workspace, and a selector in both places is not
     * two ways to say the same thing but two answers that can disagree.
     */
    public function index(Request $request, BranchContext $context): View
    {
        $branchId = (int) $request->query('branch_id', $context->id() ?? 0);
        $days = (int) config('stockrecon.default_days');

        $branches = $this->branches();

        return view('stockrecon::index', [
            'branches' => $branches,
            'branchId' => $branchId,
            'pinned' => $context->isBranchWorkspace(),
            /*
             * EVERY visible site's areas, each tagged with its branch, and
             * linked-select.js narrows the list as the site changes.
             *
             * Loading only the current site's would leave the picker dead
             * until the page came back, and a reload on every change throws
             * away the dates and the run name already typed. In a pinned
             * branch workspace there is one site anyway.
             */
            'areas' => $this->service->areas(
                $branches->pluck('BranchId')->map(fn ($id) => (int) $id)->all()
            ),
            'areaNo' => (int) $request->query('area_no', 0),
            'from' => $request->query('from', Carbon::today()->subDays($days)->toDateString()),
            'to' => $request->query('to', Carbon::today()->toDateString()),
            'options' => $this->service->options(),
            'excluded' => (array) config('stockrecon.excluded_area_groups'),
            'stampMode' => config('stockrecon.stamp_mode'),
            'open' => StockReconRun::openFor(auth()->id()),
            'grid' => $this->grids->build(
                $this->registry->findOrFail('app.stockrecon.runs'),
                $request,
                $request->user()?->Id,
            ),
            'context' => $context,
        ]);
    }

    /**
     * Run the preview, then redirect to the run it produced.
     *
     * Post/redirect/get, and not merely out of habit. Rendering the result
     * straight out of the POST leaves the reader on a URL that only answers
     * POST — so the scope bar, which submits a GET to the current address,
     * comes back 405, and the result cannot be linked or refreshed. Every page
     * in Agora is reachable by GET; the run permalink is that GET for this one.
     */
    public function preview(BalancePreviewRequest $request, BranchContext $context): RedirectResponse
    {
        $branchId = $this->resolveBranch($request->integer('branch_id'), $context);

        try {
            $run = $this->service->preview(
                $branchId,
                $request->areaNo(),
                $request->from(),
                $request->to(),
                $request->options(),
                note: $request->note(),
            );
        } catch (AgoraProcException $e) {
            return back()->withInput()->with('refusal', $e->getMessage());
        }

        return redirect()->route('app.stockrecon.run', $run);
    }

    /**
     * A run as it was recorded, whether or not anything was ever committed.
     *
     * The extract is a second read of the same rows, purely to power the
     * drawer. The table stays as it is — it carries tick boxes that decide what
     * a commit writes, it lives inside the commit form, and a row opens the
     * chain behind it, none of which the grid shell can express. But "how do I
     * get this into Excel" should not depend on which component a screen uses,
     * and in journal mode that extract IS the deliverable.
     */
    public function show(StockReconRun $run, Request $request): View
    {
        $request->query->set('run', (string) $run->Id);

        return view('stockrecon::run', $this->frame($run, 'proposals') + [
            /*
             * The chain is the unit of judgement, so the table is loaded whole
             * and ordered by it. A branch-month is thousands of lines and this
             * screen deliberately does not page: the tick boxes decide what a
             * commit writes, and a paged commit form is a form that stamps rows
             * nobody looked at.
             *
             * That is also why the form is normally run one area at a time —
             * the hub says so beside the area picker.
             */
            'run' => $run->load('lines'),
            'extract' => $this->grids->build(
                $this->registry->findOrFail('app.stockrecon.run'),
                $request,
                $request->user()?->Id,
            ),
        ]);
    }

    /**
     * The exceptions this run found.
     *
     * A real grid rather than the run's own table: thousands of rows, header
     * filters, an export and a footer total somebody takes to a branch meeting.
     * Nothing on it is ticked, because nothing on it is actionable HERE — an
     * A-class row is a conversation with a branch, not a count to amend.
     */
    public function exceptions(StockReconRun $run, Request $request): View
    {
        $request->query->set('run', (string) $run->Id);

        return view('stockrecon::run', $this->frame($run, 'exceptions') + [
            'run' => $run,
            'grid' => $this->grids->build(
                $this->registry->findOrFail('app.stockrecon.exceptions'),
                $request,
                $request->user()?->Id,
            ),
            'classes' => $this->service->exceptionClasses(),
        ]);
    }

    /**
     * The chain behind one shift — a fragment for the row, a page for a person.
     *
     * HTML rather than JSON, on purpose. The formatting rules — a missing
     * figure is an em dash, never 0.000; the grouping character is an ordinary
     * space so a copied number pastes into a spreadsheet — live in
     * App\Support\Format, and rebuilding them in JavaScript is how the two
     * drift apart. The browser gets markup and inserts it.
     *
     * TWO WAYS IN, ONE COPY OF THE MARKUP. row-detail.js sends
     * X-Requested-With, which is what ajax() reads; anything else is a person
     * who followed the exception grid's own link, pasted the address, or
     * opened it in a new tab, and they get the shell around it.
     *
     * It answered both with the fragment until 9 September 2026, so opening a
     * chain from the exceptions grid landed on unstyled text with no
     * navigation and no way back. ReconController::configEdit carries a
     * paragraph about exactly this bug, written the day before, and I
     * reproduced it here — which is why the note is now in both places.
     */
    public function line(StockReconRun $run, StockReconRunLine $line, Request $request): View
    {
        abort_unless($line->RunId === $run->Id, 404);

        $data = [
            'run' => $run,
            'line' => $line,
            ...$this->service->chain($run, $line),
        ];

        return $request->ajax()
            ? view('stockrecon::partials.chain-detail', $data)
            : view('stockrecon::chain', $data);
    }

    /**
     * Commit: write the amendments the operator ticked.
     *
     * The selection is saved and then committed in one request, so the rows
     * that get written are the rows that were on the screen when the button was
     * pressed. The procedure re-checks each of them anyway — a preview may be
     * hours old — and reports what it skipped rather than writing over it.
     */
    public function commit(Request $request, StockReconRun $run): RedirectResponse
    {
        /*
         * The checkbox names are `lines[]`, so this is an array of strings —
         * but request input is mixed, and mapping over mixed is how a nested
         * array becomes an "Array to string conversion" 500.
         *
         * @var array<int, scalar> $submitted
         */
        $submitted = is_array($request->input('lines')) ? $request->input('lines') : [];

        $lineIds = array_values(array_filter(array_map(
            fn (mixed $id) => is_scalar($id) ? (int) $id : 0,
            $submitted,
        )));

        $this->service->select($run, $lineIds);

        try {
            $result = $this->service->commit($run->fresh());
        } catch (AgoraProcException $e) {
            return back()->with('refusal', $e->getMessage());
        }

        return redirect()
            ->route('app.stockrecon.run', $run)
            ->with('committed', $result['status']->Message)
            ->with('committedCode', $result['status']->Code)
            ->with('committedLines', $result['lines']->toArray());
    }

    /**
     * Reverse: put back exactly what this run wrote.
     *
     * The reason is required by the procedure, not by a form rule — it is the
     * only record of why an amendment was undone, and the legacy procedure has
     * no reversal at all.
     */
    public function reverse(Request $request, StockReconRun $run): RedirectResponse
    {
        try {
            $status = $this->service->reverse($run, trim((string) $request->input('reason')));
        } catch (AgoraProcException $e) {
            return back()->with('refusal', $e->getMessage());
        }

        return redirect()->route('app.stockrecon.run', $run)->with('committed', $status->Message);
    }

    /**
     * Discard previews.
     *
     * One run when the route carries one, otherwise every discardable run this
     * person has made for this branch. A clerk previews the same period several
     * times while narrowing the caps, and the list of attempts is not the work.
     *
     * A committed run is refused by the procedure, and the refusal is shown
     * rather than swallowed: the reason it cannot go is the point.
     */
    public function discard(Request $request, BranchContext $context, ?StockReconRun $run = null): RedirectResponse
    {
        $branchId = $run !== null ? $run->BranchId : $context->id();

        if ($branchId === null) {
            return back()->with('refusal', __('stockrecon::stockrecon.no_branch_to_clear'));
        }

        try {
            // An area filter only means anything on a sweep; discarding one run
            // is already as narrow as it gets.
            $areaNo = $run ? null : ($request->integer('area_no') ?: null);

            $count = $this->service->discard((int) $branchId, $areaNo, $run?->Id);
        } catch (AgoraProcException $e) {
            return back()->with('refusal', $e->getMessage());
        }

        // A discarded run's own page no longer exists, so the redirect cannot
        // be back() from there.
        return redirect()
            ->to($run ? route('app.stockrecon.index') : url()->previous())
            ->with('discarded', $count);
    }

    /**
     * What every face of a run has in common.
     *
     * The tab strip is built here rather than in the blade so it can drop a tab
     * the person may not open, rather than offering a door that answers 403.
     *
     * @return array<string, mixed>
     */
    protected function frame(StockReconRun $run, string $tab): array
    {
        $user = request()->user();

        $tabs = collect([
            'proposals' => ['label' => 'Proposals', 'route' => 'app.stockrecon.run', 'can' => 'stockrecon.runs.view'],
            'exceptions' => ['label' => 'Exceptions', 'route' => 'app.stockrecon.exceptions', 'can' => 'stockrecon.exceptions.view'],
        ])
            ->filter(fn (array $item) => (bool) $user?->can($item['can']))
            ->map(fn (array $item, string $key) => [
                'key' => $key,
                'label' => $item['label'],
                'href' => route($item['route'], $run),
            ])
            ->values()
            ->all();

        return [
            'tab' => $tab,
            'tabs' => $tabs,
            'stampMode' => $run->StampMode,
            'branch' => Branch::query()->where('BranchId', $run->BranchId)->first(),
            'classes' => $this->service->exceptionClasses(),
        ];
    }

    /**
     * The sites this user may balance.
     *
     * Trading sites only. The administrative entities — the group, the property
     * companies, the trusts — count no stock, so offering them is offering a
     * run that can only come back empty.
     *
     * The head-office chrome carries no branch control, so on a head-office
     * screen this select IS the branches component feature-rules §3.3 asks for.
     * In the branch workspace the scope bar has already said which site, and
     * BranchScope has narrowed this list to it.
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
     * Which branch a submitted run is actually about.
     *
     * A branch user cannot balance another site by editing the form: their
     * context is pinned by the scope bar and it wins over whatever arrived in
     * the request. For a head-office user the form is the choice — and it is
     * still checked, because a form field is not an authorisation.
     */
    protected function resolveBranch(int $submitted, BranchContext $context): int
    {
        if ($context->isBranchWorkspace() && $context->id() !== null) {
            return $context->id();
        }

        abort_unless($context->maySee($submitted), 403, 'You may not balance that branch.');

        return $submitted;
    }
}
