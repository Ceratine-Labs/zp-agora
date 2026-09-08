<?php

namespace Modules\Recon\Http\Controllers;

use App\Exceptions\AgoraProcException;
use App\Grid\GridRegistry;
use App\Grid\GridService;
use App\Http\Controllers\Controller;
use App\Support\BranchContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Modules\Recon\Grids\ReconCriteriaGrid;
use Modules\Recon\Http\Controllers\Concerns\AreaWorkbench;
use Modules\Recon\Http\Requests\PreviewRequest;
use Modules\Recon\Models\ReconRun;
use Modules\Recon\Models\ReconRunLine;
use Modules\Recon\Services\ReconCriteriaService;
use Modules\Recon\Services\ReconMatchService;
use Modules\Recon\Services\ReconPreviewRefused;
use Modules\Recon\Services\ReconService;

/**
 * The AUTO RECON workbench.
 *
 * Two presses, exactly as the PumpIT executable has: preview, then — when the
 * operator is satisfied — execute. The difference is what sits between them.
 * The exe walks the data twice, so what it stamps is not necessarily what was
 * on the screen when the decision was made; here the preview is recorded, and
 * an execution can only ever act on the rows that run already holds.
 *
 * Execute is not wired to a write yet. It writes into the CUSTOMER'S
 * database, which needs an explicit go-ahead, and the procedure it would be
 * replacing is the one we formally recommended ZP stop using on 18 August
 * 2026. The button is present, it says exactly that, and it is disabled —
 * a screen that hid the step would be a worse answer than one that names it.
 */
class ReconController extends Controller
{
    use AreaWorkbench;

    public function __construct(
        protected ReconService $service,
        protected ReconMatchService $matches,
        protected ReconCriteriaService $criteria,
        protected GridRegistry $registry,
        protected GridService $grids,
    ) {}

    /**
     * The hub: the five areas, where the reader left off, and every run.
     *
     * The run list here is the SAME grid the Runs tab renders, with no area
     * argument — one definition, one procedure, one set of saved column
     * widths, rather than a second list that would drift from it. The area
     * tabs narrow it; the hub does not.
     */
    public function index(Request $request, BranchContext $context): View
    {
        return view('recon::index', [
            'areas' => $this->service->areas(),
            'open' => ReconRun::openFor(auth()->id()),
            'grid' => $this->grids->build(
                $this->registry->findOrFail('app.recon.runs'),
                $request,
                $request->user()?->Id,
            ),
            'branches' => $context->isBranchWorkspace() ? null : $this->branches(),
            'context' => $context,
            'stampMode' => config('recon.stamp_mode'),
        ]);
    }

    /**
     * The workbench for one area — the automatic preview, which is tab one.
     *
     * The branch is chosen HERE, on the result set, which is where
     * feature-rules §3.3 puts it. The chrome carries no branch selector: head
     * office is an estate-wide workspace, and a selector in both places is not
     * two ways to say the same thing but two answers that disagree — which is
     * precisely what happened on the first cut of this screen.
     *
     * It still defaults to whatever BranchContext has pinned, so a branch user
     * never picks their own site and a head-office user who arrived by a link
     * carrying `?branch=` lands on the site the sender meant.
     */
    public function area(string $area, BranchContext $context): View
    {
        return view('recon::area', $this->workbench($area, 'auto', $context) + [
            'options' => $this->service->optionsFor($area),
            // Where the clerk left off IN THIS AREA. The hub answers the same
            // question across all five, and both come off CreatedBy — a column
            // the ledger has recorded since the module landed and nothing has
            // ever read.
            'open' => ReconRun::openFor(auth()->id(), $area),
            // Scoped by BranchScope already; the area filter is this screen's.
            'recent' => ReconRun::query()
                ->where('ReconArea', $area)
                ->orderByDesc('CreatedAt')
                ->limit(5)
                ->get(),
        ]);
    }

    /**
     * Manual match — the other half of the same job.
     *
     * Automatic reconciliation proposes; a clerk still has to be able to pair
     * a statement line with a deposit by hand when no rule can reach it. It is
     * a TAB of the area rather than a screen of its own because it is the same
     * area, the same site and the same dates — the only thing that changes is
     * who decides, the procedure or the person.
     */
    public function manualMatch(string $area, Request $request, BranchContext $context): View
    {
        $frame = $this->workbench($area, 'match', $context);
        $branchId = $this->resolveBranch((int) $request->query('branch_id', $frame['branchId']), $context);
        $state = in_array($request->query('state'), ['reconciled', 'all'], true)
            ? (string) $request->query('state')
            : 'outstanding';

        return view('recon::area', $frame + [
            'branchId' => $branchId,
            'state' => $state,
            // A site has to be chosen before there are two sides to show. Head
            // office arrives with none picked, and an empty pair of lists
            // would read as "this site is clean" rather than "you have not
            // said which site".
            ...($branchId > 0
                ? $this->matches->sides(
                    $area,
                    $branchId,
                    Carbon::parse($frame['from']),
                    Carbon::parse($frame['to']),
                    $state,
                )
                : ['bank' => collect(), 'mops' => collect(), 'summary' => null]),
        ]);
    }

    /**
     * Match what the operator ticked on both sides.
     *
     * Everything that decides whether this is allowed lives in the procedure —
     * that both sides are still outstanding, that exactly as many rows are
     * claimed as were ticked, that a variance carries a reason. A refusal
     * comes back to the screen as a refusal rather than as a silent no-op.
     */
    public function match(Request $request, string $area, BranchContext $context): RedirectResponse
    {
        $definition = $this->service->area($area);
        $branchId = $this->resolveBranch($request->integer('branch_id'), $context);

        /** @var array<int, scalar> $bank */
        $bank = is_array($request->input('bank')) ? $request->input('bank') : [];
        /** @var array<int, mixed> $mops */
        $mops = is_array($request->input('mops')) ? $request->input('mops') : [];

        $bankIds = array_values(array_filter(array_map(
            fn (mixed $id) => is_scalar($id) ? (int) $id : 0,
            $bank,
        )));

        // Each deposit arrives as the triple that names it — the family mostly
        // has no id of its own — packed by the screen into one field so a
        // half-submitted row cannot become a different row.
        $deposits = collect($mops)
            ->map(fn (mixed $value) => is_string($value) ? json_decode($value, true) : null)
            ->filter(fn (mixed $row) => is_array($row) && isset($row['key'], $row['dt'], $row['amt']))
            ->map(fn (array $row) => [
                'id' => isset($row['id']) && $row['id'] !== '' ? (int) $row['id'] : null,
                'key' => (string) $row['key'],
                'dt' => (string) $row['dt'],
                'amt' => (float) $row['amt'],
            ])
            ->values()
            ->all();

        try {
            $status = $this->matches->match(
                $definition['key'],
                $branchId,
                Carbon::parse((string) $request->input('from')),
                Carbon::parse((string) $request->input('to')),
                $bankIds,
                $deposits,
                trim((string) $request->input('reason')) ?: null,
            );
        } catch (AgoraProcException $e) {
            return back()->with('refusal', $e->getMessage());
        }

        return redirect()
            ->route('app.recon.run', $status->Id)
            ->with('executed', $status->Message)
            ->with('executedCode', $status->Code);
    }

    /** The runs made in this area — the person's own first, everyone's on request. */
    public function runList(string $area, Request $request, BranchContext $context): View
    {
        // The definition reads its area off the request, exactly as the run
        // grid reads its run: one grid, registered once, scoped by what is
        // being asked of it rather than by a second registration per area.
        $request->query->set('area', $area);

        return view('recon::area', $this->workbench($area, 'runs', $context) + [
            'grid' => $this->grids->build(
                $this->registry->findOrFail('app.recon.runs'),
                $request,
                $request->user()?->Id,
            ),
        ]);
    }

    /**
     * The extraction configuration the previews read — ours beside theirs.
     *
     * The rows the previews resolve against still come from the customer's
     * BRN_AutoReconCriteria wherever Agora has not overridden them, and Agora
     * never writes to that table. What this screen edits is
     * agora.ReconCriteria, an override the application owns, which
     * agora.vw_AutoReconCriteria puts in front of the legacy row. Ryan's
     * decision, 8 September 2026.
     */
    public function configuration(string $area, Request $request, BranchContext $context): View
    {
        $request->query->set('area', $area);

        return view('recon::area', $this->workbench($area, 'config', $context) + [
            'grid' => $this->grids->build(
                $this->registry->findOrFail('app.recon.config'),
                $request,
                $request->user()?->Id,
            ),
            'checked' => ReconCriteriaGrid::wantsNarrativeCheck(),
        ]);
    }

    /**
     * One rule's edit form, as the fragment a modal opens onto.
     *
     * HTML rather than JSON, the same contract the proposal drill works to:
     * the formats live in App\Support\Format and rebuilding them in
     * JavaScript is how the two drift apart.
     *
     * It shows the CUSTOMER'S row beside the override, always. That is the
     * accepted cost of two sources of truth — ZP can still edit their table in
     * SSMS without telling us, and a form that showed only the effective value
     * would make the divergence invisible.
     */
    public function configEdit(string $area, int $branch, int $order, BranchContext $context): View
    {
        abort_unless($context->maySee($branch), 403, 'You may not look at that branch.');

        return view('recon::partials.criteria-edit', [
            'area' => $this->service->area($area),
            'branchId' => $branch,
            'branch' => $this->branches()->firstWhere('BranchId', $branch),
            'order' => $order,
            'mayEdit' => (bool) request()->user()?->can('recon.criteria.edit'),
            ...$this->criteria->rule($branch, $area, $order),
        ]);
    }

    /**
     * Save, park or restore one override.
     *
     * Every rule about whether this is allowed lives in
     * agora.usp_Recon_SaveCriteria — that a reason is given, that the resolved
     * length is one a preview would accept, that there is something to park.
     * A refusal comes back to the screen as a refusal.
     */
    public function configSave(Request $request, string $area, BranchContext $context): RedirectResponse
    {
        $definition = $this->service->area($area);
        $branchId = $this->resolveBranch($request->integer('branch_id'), $context);
        $order = max(1, $request->integer('process_order'));
        $reason = trim((string) $request->input('reason'));
        $action = (string) $request->input('action', 'save');

        try {
            $status = match ($action) {
                'park' => $this->criteria->park($branchId, $definition['key'], $order, $reason),
                'unpark' => $this->criteria->park($branchId, $definition['key'], $order, $reason, on: true),
                default => $this->criteria->save(
                    $branchId,
                    $definition['key'],
                    $order,
                    $request->only([...array_keys(ReconCriteriaService::POSITIONS), 'FilterValue']),
                    $reason,
                ),
            };
        } catch (AgoraProcException $e) {
            return back()->with('refusal', $e->getMessage());
        }

        return back()->with('configured', $status->Message);
    }

    /**
     * Copy one site's working configuration to another.
     *
     * Previews unless the form says apply. Copying configuration onto a site
     * unseen is the kind of bulk change that should never be one press, so the
     * press that shows you is a different press from the one that does it.
     */
    public function configCopy(Request $request, string $area, BranchContext $context): RedirectResponse
    {
        $from = $request->integer('from_branch_id');
        $to = $request->integer('to_branch_id');

        abort_unless($context->maySee($from) && $context->maySee($to), 403, 'You may not touch that branch.');

        try {
            $result = $this->criteria->copy(
                $from,
                $to,
                // An area filter is optional: the button on an area's tab
                // copies that area, the one on the hub copies all five.
                $request->boolean('all_areas') ? null : $this->service->area($area)['key'],
                overwrite: $request->boolean('overwrite'),
                apply: $request->boolean('apply'),
                reason: trim((string) $request->input('reason')) ?: null,
            );
        } catch (AgoraProcException $e) {
            return back()->with('refusal', $e->getMessage());
        }

        return back()
            ->with('configured', $result['status']->Message)
            ->with('copyPlan', $result['plan']->toArray())
            ->with('copyApplied', $result['status']->Code === 'COPIED');
    }

    /**
     * Run the preview, then redirect to the run it produced.
     *
     * Post/redirect/get, and not merely out of habit. Rendering the result
     * straight out of the POST leaves the reader on a URL that only answers
     * POST — so the scope bar, which submits a GET to the current address,
     * came back 405, and the result could not be linked or refreshed. Every
     * page in the application is reachable by GET; the run permalink is that
     * GET for this one.
     *
     * A refusal — no criteria row for this branch and area, or one whose
     * resolved positions are unusable — comes back to the form as a refusal,
     * not as an empty grid. "Nothing to reconcile" and "this branch has no
     * rule" are opposite answers and the screen must never blur them: on the
     * live system that distinction is finding 1, where twenty-four of
     * twenty-six branches produce nothing and the screen does not say why.
     */
    public function preview(PreviewRequest $request, BranchContext $context): RedirectResponse
    {
        $area = $this->service->area($request->string('area')->value());
        $branchId = $this->resolveBranch($request->integer('branch_id'), $context);

        try {
            $run = $this->service->preview(
                $area['key'],
                $branchId,
                $request->from(),
                $request->to(),
                $request->options(),
                note: $request->note(),
            );
        } catch (ReconPreviewRefused $e) {
            return back()
                ->withInput()
                ->with('refusal', $e->getMessage())
                ->with('refusalProcedure', $e->procedure())
                ->with('refusalDetail', $e->detail());
        }

        return redirect()->route('app.recon.run', $run);
    }

    /** A run as it was recorded, whether or not anything was ever executed. */
    public function show(ReconRun $run, Request $request): View
    {
        /*
         * A second read of the same rows, purely to power the extract drawer.
         *
         * The table on this screen stays as it is — it carries tick boxes that
         * decide what a commit stamps, it lives inside the execute form, and a
         * row opens the two sides behind its total, none of which the grid
         * shell can express. But "how do I get this into Excel" should not
         * have a different answer depending on which component a screen uses,
         * so the rows also get a registered GridDefinition and the standard
         * CSV / XLSX / copy drawer comes with it.
         *
         * The cost is one extra query over a few hundred stored rows. The
         * alternative was a second export path with its own writers and its
         * own drawer, which is the duplication the component rule exists to
         * stop.
         */
        $request->query->set('run', (string) $run->Id);

        return view('recon::run', [
            'run' => $run->load('lines'),
            'area' => $this->service->area($run->ReconArea),
            'extract' => $this->grids->build(
                $this->registry->findOrFail('app.recon.run'),
                $request,
                $request->user()?->Id,
            ),
        ]);
    }

    /**
     * The rows behind one proposal, as a fragment the row expands into.
     *
     * HTML rather than JSON, on purpose. The formatting rules — a missing
     * figure is an em dash, never R0.00; the grouping character is an ordinary
     * space so a copied number pastes into a spreadsheet — live in
     * App\Support\Format, and rebuilding them in JavaScript is how the two
     * drift apart. The browser gets markup and inserts it.
     */
    public function line(ReconRun $run, ReconRunLine $line): View
    {
        abort_unless($line->RunId === $run->Id, 404);

        return view('recon::partials.line-detail', [
            'run' => $run,
            'line' => $line,
            'area' => $this->service->area($run->ReconArea),
            // sides(), not drill(): a committed line cannot be drilled — the
            // drill filters to what is still outstanding and committing is
            // what makes it not. See ReconService::sides().
            ...$this->service->sides($run, $line),
        ]);
    }

    /**
     * Discard previews.
     *
     * One run when the route carries one, otherwise every uncommitted run for
     * this branch — narrowed to an area when the request names one. A clerk
     * previews the same month several times while narrowing the dates, and the
     * list of attempts is not the work.
     *
     * A committed run is refused by the procedure, and the refusal is shown
     * rather than swallowed: the reason it cannot go is the point.
     */
    public function discard(Request $request, BranchContext $context, ?ReconRun $run = null): RedirectResponse
    {
        // A named run says which branch; a sweep takes it from the scope.
        $branchId = $run !== null ? $run->BranchId : $context->id();

        if ($branchId === null) {
            return back()->with('refusal', __('recon::recon.no_branch_to_clear'));
        }

        try {
            // An area filter only means anything on a sweep; discarding one
            // run is already as narrow as it gets.
            $area = $run ? null : ($request->string('area')->value() ?: null);

            $count = $this->service->discard((int) $branchId, $area, $run?->Id);
        } catch (AgoraProcException $e) {
            return back()->with('refusal', $e->getMessage());
        }

        // A discarded run's own page no longer exists, so the redirect cannot
        // be back() from there.
        $target = $run
            ? route('app.recon.area', $run->ReconArea)
            : url()->previous();

        return redirect()->to($target)->with('discarded', $count);
    }

    /**
     * Execute: stamp what the operator ticked.
     *
     * The selection is saved and then committed in one request, so the rows
     * that get stamped are the rows that were on the screen when the button
     * was pressed. The procedure re-checks each of them anyway — a preview may
     * be hours old — and reports what it skipped rather than stamping over it.
     */
    public function execute(Request $request, ReconRun $run): RedirectResponse
    {
        // The checkbox names are `lines[]`, so this is an array of strings —
        // but request input is mixed, and mapping over mixed is how a nested
        // array becomes an "Array to string conversion" 500 (which is exactly
        // what the reports module did with its branch selector this morning).
        /** @var array<int, scalar> $submitted */
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
            ->route('app.recon.run', $run)
            ->with('executed', $result['status']->Message)
            ->with('executedCode', $result['status']->Code)
            ->with('executedLines', $result['lines']->toArray());
    }

    /**
     * Reverse: put back exactly what this run stamped.
     *
     * The reason is required by the procedure, not by a form rule — it is the
     * only record of why a reconciliation was undone, and the PumpIT
     * executable has no reversal at all, which is why finding 2 was able to
     * conceal itself.
     */
    public function reverse(Request $request, ReconRun $run): RedirectResponse
    {
        $reason = trim((string) $request->input('reason'));

        try {
            $status = $this->service->reverse($run, $reason);
        } catch (AgoraProcException $e) {
            return back()->with('refusal', $e->getMessage());
        }

        return redirect()->route('app.recon.run', $run)->with('executed', $status->Message);
    }

    /**
     * Which branch a submitted run is actually about.
     *
     * A branch user cannot reconcile another site by editing the form: their
     * context is pinned by the scope bar and it wins over whatever arrived in
     * the request. For a head-office user the form is the choice — and it is
     * still checked, because a form field is not an authorisation.
     */
    protected function resolveBranch(int $submitted, BranchContext $context): int
    {
        if ($context->isBranchWorkspace() && $context->id() !== null) {
            return $context->id();
        }

        abort_unless($context->maySee($submitted), 403, 'You may not reconcile that branch.');

        return $submitted;
    }
}
