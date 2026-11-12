<?php

namespace Modules\Recon\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\BranchContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
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

        [$sites, $unconfigured] = $this->configured($this->sites($context), $area);

        return view('recon::area', $this->workbench($area, 'all', $context) + [
            'options' => $this->service->optionsFor($area),
            'sites' => $sites,
            'unconfigured' => $unconfigured,
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

        // Only the sites that can answer. See configured().
        [$sites] = $this->configured($this->sites($context), $area);

        $group = $this->groups->start(
            $area,
            $request->from(),
            $request->to(),
            $sites->pluck('BranchId')->map(fn (mixed $id) => (int) $id)->all(),
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
        $runs = $model->runsByBranch();

        // A group opened before sites without rules were left out still has a
        // failed run for each of them, and those rows must keep rendering —
        // so a site with a run in THIS group stays in, rules or not.
        [$sites, $unconfigured] = $this->configured($this->sites($context), $model->ReconArea, $runs->keys()->all());

        return view('recon::group', [
            'group' => $model,
            'area' => $this->service->area($model->ReconArea),
            'sites' => $sites,
            'unconfigured' => $unconfigured,
            'runs' => $runs,
            'posts' => $this->groups->posts($model),
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
        abort_unless($this->configured($this->sites($context), $model->ReconArea)[0]->contains('BranchId', $branch), 404);

        $run = $this->groups->runBranch($model, $branch);

        return view('recon::partials.group-row', [
            'group' => $model->fresh() ?? $model,
            'area' => $this->service->area($model->ReconArea),
            'branch' => $this->sites($context)->firstWhere('BranchId', $branch),
            'run' => $run,
        ]);
    }

    /**
     * Queue the sites the operator ticked: one job per site.
     *
     * Each job posts its site through the same usp_Recon_Commit a single run
     * uses — branch-scoped, re-checking every row against the estate as it
     * stands, reporting what it skipped. Until 12 Nov 2026 this request did
     * every site itself, and a three-month Smart ATM group timed out at
     * 300 s (28 Sep 2026). Now it only queues, and the screen polls posts().
     *
     * On the sync queue connection the jobs run inside this request, so the
     * sites that already answered are flashed as before.
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

        $results = $this->groups->queuePosts($model, $runIds, $this->userId());
        $posts = $this->groups->posts($model);

        // What has an answer already: refused before queueing, or (on the
        // sync connection) posted or refused by its job inside this request.
        $answered = collect($results)->map(function (array $r) use ($posts) {
            $post = $posts->get($r['run']->BranchId);

            if ($r['state'] === 'queued' && $post !== null && $post->isFinished()) {
                return [
                    'BranchId' => $r['run']->BranchId, 'RunId' => $r['run']->Id,
                    'Ok' => $post->Status === 'committed', 'Code' => (string) $post->Code,
                    'Message' => (string) $post->Message, 'Rows' => $post->CommittedRows,
                ];
            }

            if (in_array($r['state'], ['queued', 'active'], true)) {
                return null;
            }

            return [
                'BranchId' => $r['run']->BranchId, 'RunId' => $r['run']->Id, 'Ok' => false,
                'Code' => $r['code'], 'Message' => $r['message'], 'Rows' => 0,
            ];
        })->filter()->values();

        return redirect()
            ->route('app.recon.group', $model->GroupRef)
            ->with('posted', $answered->all());
    }

    /**
     * Where the queued posts have got to, for the screen to poll.
     *
     * JSON carrying each posted site's row as HTML, for the reason runBranch()
     * returns HTML: the formats live in App\Support\Format. `active` reaching
     * zero is the screen's cue to reload for the totals.
     */
    public function posts(string $group, BranchContext $context): JsonResponse
    {
        $this->refuseBranchWorkspace($context);

        $model = $this->group($group)->load('runs');
        $runs = $model->runsByBranch();
        $posts = $this->groups->posts($model);
        $area = $this->service->area($model->ReconArea);
        $sites = $this->sites($context)->keyBy('BranchId');

        $rows = [];

        foreach ($posts as $branchId => $post) {
            $branch = $sites->get($branchId);

            if ($branch === null) {
                continue;
            }

            $rows[(string) $branchId] = view('recon::partials.group-row', [
                'group' => $model, 'area' => $area, 'branch' => $branch,
                'run' => $runs->get($branchId), 'post' => $post,
            ])->render();
        }

        return response()->json([
            'active' => $posts->filter(fn ($post) => $post->isActive())->count(),
            'finished' => $posts->filter(fn ($post) => $post->isFinished())->count(),
            'total' => $posts->count(),
            'rows' => $rows,
        ]);
    }

    /** The signed-in user's id, as an int, for the jobs to pass to the procedure. */
    protected function userId(): ?int
    {
        $id = auth()->id();

        return is_numeric($id) ? (int) $id : null;
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
     * Split the sites into those with a rule for this area and those without.
     *
     * A site with no extraction rule can only refuse. Until 23 Sep 2026 every
     * press recorded a failed run for each one — 236 on live, all NO_CRITERIA,
     * all the same four sites — which filled the clerks' run lists without
     * telling anybody anything new. Ryan approved leaving them out (Bank Recon
     * Close-out, q2). The second half of the pair is what the screen names
     * instead, because "no rule here" is still an answer worth seeing.
     *
     * `$keep` holds sites that stay in whatever their rules say: on an existing
     * group, the sites that already have a run in it.
     *
     * @param  Collection<int, Branch>  $sites
     * @param  array<int, int|string>  $keep
     * @return array{0: Collection<int, Branch>, 1: Collection<int, Branch>}
     */
    protected function configured(Collection $sites, string $area, array $keep = []): array
    {
        $ruled = array_flip($this->service->branchesWithRules($area));
        $keep = array_flip(array_map('intval', $keep));

        [$in, $out] = $sites->partition(
            fn (Branch $branch) => isset($ruled[(int) $branch->BranchId]) || isset($keep[(int) $branch->BranchId])
        );

        return [$in->values(), $out->values()];
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
