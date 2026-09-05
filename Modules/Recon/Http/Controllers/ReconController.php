<?php

namespace Modules\Recon\Http\Controllers;

use App\Exceptions\AgoraProcException;
use App\Http\Controllers\Controller;
use App\Support\BranchContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Core\Models\Branch;
use Modules\Recon\Http\Requests\PreviewRequest;
use Modules\Recon\Models\ReconRun;
use Modules\Recon\Models\ReconRunLine;
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
    public function __construct(protected ReconService $service) {}

    /** The hub: the five areas, and what has been previewed lately. */
    public function index(BranchContext $context): View
    {
        return view('recon::index', [
            'areas' => $this->service->areas(),
            'recent' => ReconRun::query()
                ->orderByDesc('CreatedAt')
                ->limit(10)
                ->get(),
            'context' => $context,
            'stampMode' => config('recon.stamp_mode'),
        ]);
    }

    /**
     * The workbench for one area.
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
        $days = (int) config('recon.default_days');

        return view('recon::area', [
            'area' => $this->service->area($area),
            'options' => $this->service->optionsFor($area),
            'branches' => $this->branches(),
            'branchId' => (int) request('branch_id', $context->id() ?? 0),
            'pinned' => $context->isBranchWorkspace(),
            // Scoped by BranchScope already; the area filter is this screen's.
            'recent' => ReconRun::query()
                ->where('ReconArea', $area)
                ->orderByDesc('CreatedAt')
                ->limit(5)
                ->get(),
            'from' => request('from', Carbon::today()->subDays($days)->toDateString()),
            'to' => request('to', Carbon::today()->toDateString()),
            'stampMode' => config('recon.stamp_mode'),
        ]);
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
    public function show(ReconRun $run): View
    {
        return view('recon::run', [
            'run' => $run->load('lines'),
            'area' => $this->service->area($run->ReconArea),
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
            ...$this->service->drill($run, $line),
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
     * The sites this user may reconcile.
     *
     * Trading sites only. The six administrative entities — the group, the
     * property companies, the trusts — keep no trading day and have no bank
     * statement to reconcile, so offering them is offering a run that can only
     * come back empty.
     *
     * The head-office chrome carries no branch control, so on a head-office
     * screen this select IS the branches component feature-rules §3.3 asks
     * for. In the branch workspace the scope bar has already said which site,
     * and BranchScope has narrowed this list to it.
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
