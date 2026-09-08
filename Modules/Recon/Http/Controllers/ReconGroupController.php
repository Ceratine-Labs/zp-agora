<?php

namespace Modules\Recon\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\BranchContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Core\Models\Branch;
use Modules\Recon\Http\Controllers\Concerns\AreaWorkbench;
use Modules\Recon\Http\Requests\GroupPreviewRequest;
use Modules\Recon\Models\ReconRunGroup;
use Modules\Recon\Services\ReconGroupService;
use Modules\Recon\Services\ReconService;

/**
 * The master controller: one date, every site.
 *
 * A recon clerk's morning is the same area for twenty-six branches, one press
 * at a time, twenty-six times. This is that morning as one action — a period,
 * no site, a run per branch under one GroupRef, reviewed together and posted
 * together.
 *
 * HEAD OFFICE ONLY, and not by permission but by shape. In the branch
 * workspace the site is already pinned; "every site" would mean the one site
 * the person is already looking at, which is the Auto tab. So a branch
 * workspace is refused here rather than shown a group of one.
 *
 * THE PREVIEWS ARE DRIVEN ONE AT A TIME FROM THE BROWSER. Twenty-six previews
 * of a busy month is minutes of wall clock; a single request either times out
 * or shows a spinner that cannot say which site it is on. Each branch is its
 * own small POST, so the screen fills in as it goes, a refusal is a row rather
 * than a dead page, and a reload picks up where it stopped.
 */
class ReconGroupController extends Controller
{
    use AreaWorkbench;

    public function __construct(
        protected ReconService $service,
        protected ReconGroupService $groups,
    ) {}

    /** The form: a period, the readings, and no site. */
    public function form(string $area, BranchContext $context): View
    {
        $this->refuseBranchWorkspace($context);

        return view('recon::area', $this->workbench($area, 'all', $context) + [
            'options' => $this->service->optionsFor($area),
            'sites' => $this->sites($context),
            'groups' => ReconRunGroup::query()
                ->where('ReconArea', $area)
                ->orderByDesc('CreatedAt')
                ->limit(5)
                ->get(),
        ]);
    }

    /**
     * Open the group and send the operator to it.
     *
     * Nothing is previewed here: the group is the intention, and the screen
     * carries it out. That is what makes a browser that gave up half way
     * leave a group somebody can resume rather than a half-finished mystery.
     */
    public function start(GroupPreviewRequest $request, string $area, BranchContext $context): RedirectResponse
    {
        $this->refuseBranchWorkspace($context);

        $group = $this->groups->start(
            $area,
            $request->from(),
            $request->to(),
            $this->sites($context)->pluck('BranchId')->map(fn (mixed $id) => (int) $id)->all(),
            $request->options(),
            $request->note(),
        );

        return redirect()->route('app.recon.group', $group->GroupRef);
    }

    /** The group as it stands, whether it is finished or half way through. */
    public function show(string $group, BranchContext $context): View
    {
        $this->refuseBranchWorkspace($context);

        // Eager loaded: lazy loading is disabled outside production, and
        // runsByBranch() reaches for the relation.
        $model = $this->group($group)->load('runs');
        $sites = $this->sites($context);

        return view('recon::group', [
            'group' => $model,
            'area' => $this->service->area($model->ReconArea),
            'sites' => $sites,
            'runs' => $model->runsByBranch(),
            'outstanding' => $this->groups->outstanding($model, $sites->pluck('BranchId')
                ->map(fn (mixed $id) => (int) $id)->all()),
            'stampMode' => config('recon.stamp_mode'),
        ]);
    }

    /**
     * Preview one site of the group, and hand back its row.
     *
     * HTML rather than JSON, for the reason the proposal drill is HTML: the
     * number formats live in App\Support\Format and rebuilding them in
     * JavaScript is how the two drift apart. The browser gets markup and puts
     * it in place of the pending row.
     */
    public function runBranch(string $group, int $branch, BranchContext $context): View
    {
        $this->refuseBranchWorkspace($context);

        $model = $this->group($group);

        // A branch id in a URL is not an authorisation, and this one loops in
        // from a script. It has to be a site this person may reconcile and a
        // site the group was opened over.
        abort_unless($context->maySee($branch), 403, 'You may not reconcile that branch.');
        abort_unless($this->sites($context)->contains('BranchId', $branch), 404);

        $run = $this->groups->runBranch($model, $branch);

        return view('recon::partials.group-row', [
            'group' => $model->fresh() ?? $model,
            'area' => $this->service->area($model->ReconArea),
            'branch' => $this->sites($context)->firstWhere('BranchId', $branch),
            'run' => $run,
        ]);
    }

    /**
     * Post the sites the operator ticked.
     *
     * One transaction per branch, through the same usp_Recon_Commit a single
     * run uses — it is branch-scoped, it re-checks every row against the
     * estate as it stands now, and it reports what it skipped. A branch that
     * refuses is reported and the rest still go: twenty-six sites is
     * twenty-six answers, and one of them being no does not make the others
     * no.
     */
    public function execute(Request $request, string $group, BranchContext $context): RedirectResponse
    {
        $this->refuseBranchWorkspace($context);

        $model = $this->group($group);

        /** @var array<int, scalar> $submitted */
        $submitted = is_array($request->input('runs')) ? $request->input('runs') : [];

        $runIds = array_values(array_filter(array_map(
            fn (mixed $id) => is_scalar($id) ? (int) $id : 0,
            $submitted,
        )));

        if ($runIds === []) {
            return back()->with('refusal', __('recon::recon.no_runs_selected'));
        }

        $results = $this->groups->commit($model, $runIds);

        return redirect()
            ->route('app.recon.group', $model->GroupRef)
            ->with('posted', collect($results)->map(fn (array $r) => [
                'BranchId' => $r['run']->BranchId,
                'RunId' => $r['run']->Id,
                'Ok' => $r['ok'],
                'Code' => $r['code'],
                'Message' => $r['message'],
                'Rows' => $r['rows'],
            ])->all());
    }

    /**
     * The group, by the uuid its runs already carry.
     *
     * Resolved acrossBranches deliberately: the group row carries the GROUP
     * entity's id rather than a site, so a scope narrowed to any one branch
     * would hide every group there is. The guard is the workspace check above,
     * which is the honest one — this screen is head office by shape.
     */
    protected function group(string $groupRef): ReconRunGroup
    {
        /** @var ReconRunGroup $model */
        $model = ReconRunGroup::query()
            ->acrossBranches()
            ->where('GroupRef', $groupRef)
            ->firstOrFail();

        return $model;
    }

    /**
     * The sites this group runs over.
     *
     * Trading sites the person may see. The six administrative entities keep
     * no trading day and have no bank statement, so including them would add
     * six guaranteed-empty rows to every group.
     *
     * @return Collection<int, Branch>
     */
    protected function sites(BranchContext $context): Collection
    {
        return $this->branches()
            ->filter(fn (Branch $branch) => $context->maySee((int) $branch->BranchId))
            ->values();
    }

    /**
     * "Every site" means nothing where the workspace is one site.
     *
     * A branch user reaching this would get a group of exactly the branch they
     * are already on, which is the Auto tab with more steps. Refused rather
     * than quietly reduced.
     */
    protected function refuseBranchWorkspace(BranchContext $context): void
    {
        abort_if(
            $context->isBranchWorkspace(),
            403,
            'Running every site is a head-office action. Your workspace is pinned to one branch — '
            .'use Auto reconciliation for it.'
        );
    }
}
