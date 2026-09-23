<?php

namespace Modules\Recon\Http\Controllers;

use App\Exceptions\AgoraProcException;
use App\Grid\GridRegistry;
use App\Grid\GridService;
use App\Http\Controllers\Controller;
use App\Support\BranchContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
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
     * The recon centre: one area, one site, one period, three tabs.
     *
     * ZP asked on 23 Sep 2026 for the run types consolidated: pick a site and
     * a date range once, the filters fold away, auto balancing runs, and the
     * Suggestions and the Manual match sit beside it with the scope already
     * passed. So this is the default face of the area and every link that
     * already points at /app/recon/auto/{area} lands on it.
     *
     * Two states. With no scope chosen it is the form — site, dates, the rules
     * and readings — which POSTs the preview exactly as before; the preview
     * redirects back here carrying its run. With a scope, the form folds into
     * one line and the three tabs appear, each fetched the first time it is
     * opened (see the pane).
     *
     * AUTO IS A RECORDED RUN, never a read done behind the page: a preview is
     * what makes a reconciliation reproducible and reversible, so the tab
     * shows the run the scope was previewed as. With no `run` in the URL it
     * picks up this person's open preview of exactly this scope rather than
     * writing a new one on every visit — a reload must not mint a run.
     *
     * The branch is chosen HERE, on the result set, which is where
     * feature-rules §3.3 puts it. It still defaults to whatever BranchContext
     * has pinned, so a branch user never picks their own site.
     */
    public function area(string $area, Request $request, BranchContext $context): View
    {
        $frame = $this->workbench($area, 'auto', $context);
        $key = $frame['area']['key'];

        // Scoped by BranchScope, so a run on a site this person may not see
        // is simply not found.
        $run = $request->filled('run')
            ? ReconRun::query()->where('ReconArea', $key)->find($request->integer('run'))
            : null;

        // A run carries its own scope; an explicit one in the URL wins.
        if ($run !== null) {
            $frame['branchId'] = $request->filled('branch_id') ? $frame['branchId'] : (int) $run->BranchId;
            $frame['from'] = $request->filled('from') ? $frame['from'] : $run->FromDate->toDateString();
            $frame['to'] = $request->filled('to') ? $frame['to'] : $run->ToDate->toDateString();
        }

        $branchId = (int) $frame['branchId'];
        $scoped = $branchId > 0 && ($run !== null || $request->filled('from'));

        if ($scoped) {
            abort_unless($context->maySee($branchId), 403, 'You may not reconcile that branch.');

            $run ??= ReconRun::query()
                ->where('ReconArea', $key)
                ->where('BranchId', $branchId)
                ->where('FromDate', $frame['from'])
                ->where('ToDate', $frame['to'])
                ->where('CreatedBy', auth()->id())
                ->where('Status', 'previewed')
                ->orderByDesc('Id')
                ->first();
        }

        $centreScope = ['branch_id' => $branchId, 'from' => $frame['from'], 'to' => $frame['to']];
        $tabs = $this->centreTabs($area);
        $active = collect($tabs)->pluck('key')->contains($request->query('tab'))
            ? (string) $request->query('tab')
            : 'auto';

        return view('recon::area', $frame + [
            'pane' => 'centre',
            'scoped' => $scoped,
            'run' => $run,
            'centreScope' => $centreScope,
            'centreTabs' => $tabs,
            'centreTab' => $active,
            'options' => $this->service->optionsFor($area),
            // Where the clerk left off IN THIS AREA, offered only before a
            // scope is chosen — once it is, the Auto tab IS the open run.
            'open' => $scoped ? null : ReconRun::openFor(auth()->id(), $area),
        ]);
    }

    /**
     * A run's answer as the fragment the centre's Auto tab opens onto.
     *
     * The same partial the run page renders, less the page around it and the
     * extract drawer (that lives on the run page, one link away). Its execute
     * and reverse forms carry the centre's scope, so pressing either lands
     * back on the tab rather than on a different page.
     */
    public function runPanel(ReconRun $run, Request $request): View
    {
        return view('recon::partials.run-panel', [
            'run' => $run->load('lines'),
            'area' => $this->service->area($run->ReconArea),
            'freshness' => $this->service->freshness($run),
            'centre' => $this->centreBack($request, 'auto', $run),
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

        $data = $frame + [
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
            'centre' => $this->centreBack($request, 'match'),
        ];

        // The centre's Manual tab fetches the pair-by-hand card on its own;
        // anything else is a person on the standalone page.
        return $request->ajax()
            ? view('recon::partials.match-pane', $data)
            : view('recon::area', ['pane' => 'match', 'tab' => 'auto'] + $data);
    }

    /**
     * Suggestions — what the batch number could not pair, proposed by value.
     *
     * A tab between the automatic preview and the manual match because it is
     * the step between them: the batch number settles what it can, this
     * proposes pairings for what is left, and the manual match is for whatever
     * neither can reach. Only areas whose definition says `suggest` have it.
     *
     * Nothing is stored. The procedure is read live every time, because the
     * estate moves underneath a list like this one — every accepted suggestion
     * changes what is left, and a stored list would be offering rows somebody
     * has already matched.
     */
    public function suggestions(string $area, Request $request, BranchContext $context): View
    {
        $frame = $this->workbench($area, 'suggest', $context);

        abort_unless((bool) ($frame['area']['suggest'] ?? false), 404);

        $branchId = $this->resolveBranch((int) $request->query('branch_id', $frame['branchId']), $context);

        $data = $frame + [
            'branchId' => $branchId,
            // As on the manual match: no site, no answer — an empty list before
            // a site is chosen would read as "nothing to suggest".
            ...($branchId > 0
                ? $this->matches->suggestions(
                    $area,
                    $branchId,
                    Carbon::parse($frame['from']),
                    Carbon::parse($frame['to']),
                )
                : ['suggestions' => collect(), 'summary' => null]),
            'centre' => $this->centreBack($request, 'suggest'),
        ];

        return $request->ajax()
            ? view('recon::partials.suggest-results', $data)
            : view('recon::area', ['pane' => 'suggest', 'tab' => 'auto'] + $data);
    }

    /**
     * Match what the operator ticked on both sides.
     *
     * Everything that decides whether this is allowed lives in the procedure —
     * that both sides are still outstanding, that exactly as many rows are
     * claimed as were ticked, that a variance carries a reason. A refusal
     * comes back to the screen as a refusal rather than as a silent no-op.
     *
     * An accepted suggestion posts here too, with its `basis`. It goes back to
     * the Suggestions tab rather than to the run, because the clerk is working
     * down a list; and the "match every strong suggestion" press posts each one
     * as JSON, so a refusal is one row's answer rather than a dead page.
     */
    public function match(Request $request, string $area, BranchContext $context): RedirectResponse|JsonResponse
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

        $basis = Str::limit(trim((string) $request->input('basis')), 200, '') ?: null;

        try {
            $status = $this->matches->match(
                $definition['key'],
                $branchId,
                Carbon::parse((string) $request->input('from')),
                Carbon::parse((string) $request->input('to')),
                $bankIds,
                $deposits,
                trim((string) $request->input('reason')) ?: null,
                $basis,
            );
        } catch (AgoraProcException $e) {
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'code' => $e->code(), 'message' => $e->getMessage()], 422);
            }

            if ($request->input('back') === 'centre') {
                return $this->toCentre($request, $area, $branchId)->with('centreRefusal', $e->getMessage());
            }

            // Its own key on the Suggestions tab: the area's generic refusal
            // notice says "the procedure could not run for this branch",
            // which is not what a refused match is.
            return back()->with($request->input('back') === 'suggest' ? 'suggestRefusal' : 'refusal', $e->getMessage());
        }

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'code' => $status->Code,
                'message' => $status->Message,
                'batch' => $status->BatchNo,
                'run' => route('app.recon.run', $status->Id),
            ]);
        }

        if ($request->input('back') === 'centre') {
            return $this->toCentre($request, $area, $branchId)
                ->with('centreDone', $status->Message)
                ->with('centreRun', route('app.recon.run', $status->Id));
        }

        if ($request->input('back') === 'suggest') {
            // Back to the PERIOD the list was drawn for, not to the window of
            // the one suggestion just matched.
            return redirect()
                ->route('app.recon.suggest', array_filter([
                    $area,
                    'branch_id' => $branchId,
                    'from' => (string) $request->input('period_from'),
                    'to' => (string) $request->input('period_to'),
                ]))
                ->with('suggestMatched', $status->Message)
                ->with('suggestRun', route('app.recon.run', $status->Id));
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
            // Overrides the frame's trading-only list: configuration exists
            // for whatever branch the customer configured it against.
            'branches' => $this->allBranches(),
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
    public function configEdit(string $area, int $branch, int $rule, Request $request, BranchContext $context): View
    {
        abort_unless($context->maySee($branch), 403, 'You may not look at that branch.');

        $resolved = $this->criteria->rule($branch, $rule);

        // A rule id that names nothing on this site is a 404, not an empty
        // form — somebody following a stale link should be told so.
        abort_if($resolved['effective'] === null && $resolved['override'] === null, 404);

        $data = [
            'area' => $this->service->area($area),
            'branchId' => $branch,
            // By id, not out of the trading list — five configured sites are
            // administrative entities, and looking one up in a list that
            // excludes them is why this page said "Site 1" instead of
            // "AJLG Properties" when Ryan opened it on live.
            'branch' => $this->branch($branch),
            'rule' => $rule,
            // The process order comes off the rule itself, not the URL — the
            // URL names the rule and the rule knows its own order.
            // The abort above establishes that at least one of the two is
            // there, so by the time the second operand is reached the first
            // was null and the second cannot be.
            'order' => (int) ($resolved['effective']->ProcessOrder ?? $resolved['override']->ProcessOrder),
            'legacyId' => $resolved['override'] !== null
                ? $resolved['override']->LegacyAutoReconId
                : ($rule > 0 ? $rule : null),
            'mayEdit' => (bool) $request->user()?->can('recon.criteria.edit'),
            ...$resolved,
        ];

        /*
         * ONE RULE, RENDERED TWO WAYS.
         *
         * The dialog fetches the bare fragment — modal.js sends
         * X-Requested-With, which is what ajax() reads. Anything else is a
         * person: they followed the site's own link off the grid, pasted the
         * address, or have no JavaScript, and they get a page with the shell
         * around it.
         *
         * It answered both with the fragment until 8 September 2026, so
         * clicking a site name on the configuration tab landed on unstyled
         * text with no navigation and no way back. Ryan found it on live. The
         * grid's docblock claimed "the screen works with no JavaScript at all
         * (the fragment renders on its own)" — true of the markup, false of
         * the page, which is the most expensive kind of comment to be wrong.
         */
        return $request->ajax()
            ? view('recon::partials.criteria-edit', $data)
            : view('recon::criteria-rule', $data);
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
        // Which of the customer's rules this is about. Absent means it adds
        // one; the procedure refuses that where a rule already sits at this
        // process order rather than picking between two.
        $legacyId = $request->filled('legacy_auto_recon_id')
            ? $request->integer('legacy_auto_recon_id')
            : null;

        try {
            $status = match ($action) {
                'park' => $this->criteria->park($branchId, $definition['key'], $order, $reason, legacyAutoReconId: $legacyId),
                'unpark' => $this->criteria->park($branchId, $definition['key'], $order, $reason, on: true, legacyAutoReconId: $legacyId),
                default => $this->criteria->save(
                    $branchId,
                    $definition['key'],
                    $order,
                    $request->only([...array_keys(ReconCriteriaService::POSITIONS), 'FilterValue']),
                    $reason,
                    legacyAutoReconId: $legacyId,
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
        abort_if($from === $to, 422, 'A site cannot be copied onto itself.');

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
            // From the centre, the refusal lands on the centre WITH its scope:
            // a site with no rule can still be paired by hand and offered
            // suggestions, and sending the clerk back to an empty form would
            // hide both.
            $to = $request->boolean('centre')
                ? redirect()->route('app.recon.area', [$area['key'],
                    'branch_id' => $branchId, 'from' => $request->from()->toDateString(), 'to' => $request->to()->toDateString()])
                : back()->withInput();

            return $to
                ->with('refusal', $e->getMessage())
                ->with('refusalProcedure', $e->procedure())
                ->with('refusalDetail', $e->detail());
        }

        if ($request->boolean('centre')) {
            return redirect()->route('app.recon.area', [$area['key'],
                'branch_id' => $branchId,
                'from' => $request->from()->toDateString(),
                'to' => $request->to()->toDateString(),
                'run' => $run->Id,
            ]);
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
            // Checked on every open, not once: a preview resumed a fortnight
            // later is exactly the one somebody else has been working through.
            'freshness' => $this->service->freshness($run),
        ]);
    }

    /**
     * Mark a run complete: it leaves the working list and stays on record.
     *
     * Today a clerk who has looked at a preview and decided nothing needs
     * stamping can only discard it, which destroys the record of the read.
     * The procedure refuses a run with anything processed against it.
     */
    public function close(ReconRun $run): RedirectResponse
    {
        try {
            $status = $this->service->close($run);
        } catch (AgoraProcException $e) {
            return back()->with('refusal', $e->getMessage());
        }

        return redirect()->route('app.recon.run', $run)->with('tidied', $status->Message);
    }

    /** Undo a close. Closing destroys nothing, so it has to be undoable. */
    public function reopen(ReconRun $run): RedirectResponse
    {
        try {
            $status = $this->service->reopen($run);
        } catch (AgoraProcException $e) {
            return back()->with('refusal', $e->getMessage());
        }

        return redirect()->route('app.recon.run', $run)->with('tidied', $status->Message);
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
            // run is already as narrow as it gets. So do the age limit and the
            // "only mine": they come off the Runs tab's own Clear button, which
            // sits under a list that shows the clerk her own runs by default.
            $area = $run ? null : ($request->string('area')->value() ?: null);
            $olderThan = $run ? null : ($request->integer('older_than_days') ?: null);
            $mine = ! $run && $request->boolean('mine');

            $count = $this->service->discard(
                (int) $branchId,
                $area,
                $run?->Id,
                $olderThan,
                $mine ? (int) auth()->id() : null,
            );
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

        if ($request->input('back') === 'centre') {
            return $this->toCentre($request, $run->ReconArea, (int) $run->BranchId, $run)
                ->with('executed', $result['status']->Message)
                ->with('executedCode', $result['status']->Code)
                ->with('executedLines', $result['lines']->toArray());
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

        if ($request->input('back') === 'centre') {
            return $this->toCentre($request, $run->ReconArea, (int) $run->BranchId, $run)->with('executed', $status->Message);
        }

        return redirect()->route('app.recon.run', $run)->with('executed', $status->Message);
    }

    /**
     * What a form inside the centre needs to come back to it: the tab it sits
     * on, the scope, and the run on the Auto tab. Null off the centre, which
     * is what keeps every standalone page's redirects exactly as they were.
     *
     * The period is `period_from` / `period_to` on the way back because a
     * suggestion's own `from` / `to` is the window its deposits sit in, not
     * the period the clerk chose.
     *
     * @return array<string, string|int>|null
     */
    protected function centreBack(Request $request, string $tab, ?ReconRun $run = null): ?array
    {
        if ($request->query('centre') !== '1' && ! $request->ajax()) {
            return null;
        }

        return array_filter([
            'back' => 'centre',
            'tab' => $tab,
            'branch_id' => (int) $request->query('branch_id', $run?->BranchId),
            'period_from' => (string) $request->query('from', $run?->FromDate?->toDateString()),
            'period_to' => (string) $request->query('to', $run?->ToDate?->toDateString()),
            'run' => (int) $request->query('run', $run?->Id),
        ]);
    }

    /** Back to the centre, on the tab the form came from, with its scope. */
    protected function toCentre(Request $request, string $area, int $branchId, ?ReconRun $run = null): RedirectResponse
    {
        return redirect()->route('app.recon.area', array_filter([
            $area,
            'branch_id' => $branchId,
            'from' => (string) $request->input('period_from', $run?->FromDate?->toDateString()),
            'to' => (string) $request->input('period_to', $run?->ToDate?->toDateString()),
            'run' => $request->integer('run') ?: $run?->Id,
            'tab' => in_array($request->input('tab'), ['auto', 'suggest', 'match'], true) ? $request->input('tab') : null,
        ]));
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
