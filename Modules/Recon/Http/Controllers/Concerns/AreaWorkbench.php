<?php

namespace Modules\Recon\Http\Controllers\Concerns;

use App\Support\BranchContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Core\Models\Branch;

/**
 * The chrome one reconciliation area shares across its five tabs.
 *
 * A trait rather than a base controller, because the two controllers that use
 * it are not versions of one another: ReconController is about a run and
 * ReconGroupController is about a press across every site. What they share is
 * the FRAME — which area, which tabs, which sites, which dates — and a frame
 * is exactly what a trait is for.
 */
trait AreaWorkbench
{
    /**
     * The five faces of one reconciliation area, in the order a morning goes.
     *
     * Declared once rather than repeated in the blade, because the strip has
     * to agree with the routes and with the permissions three times over — on
     * the tab, on the route's `can:` middleware, and on whatever the pane
     * itself offers. A list is the cheapest way to keep those three honest.
     *
     * `ho` marks a tab that only means something in head office. "Every site"
     * in a workspace pinned to one site is that site, which is the Auto tab
     * with more steps.
     */
    protected const TABS = [
        'auto' => ['label' => 'Auto reconciliation', 'route' => 'app.recon.area', 'can' => 'recon.runs.view'],
        'match' => ['label' => 'Manual match', 'route' => 'app.recon.match', 'can' => 'recon.runs.view'],
        'all' => ['label' => 'Every site', 'route' => 'app.recon.all', 'can' => 'recon.runs.view', 'ho' => true],
        'runs' => ['label' => 'Runs', 'route' => 'app.recon.runs', 'can' => 'recon.runs.view'],
        'config' => ['label' => 'Configuration', 'route' => 'app.recon.config', 'can' => 'recon.criteria.view'],
    ];

    /**
     * What every tab of an area has in common.
     *
     * The site, the dates and the stamp mode belong to the AREA, not to the
     * pane — a clerk who narrows to one branch and one week on the auto tab
     * and then opens Manual match has not changed what they are looking at.
     * So the scope is resolved once here and each pane renders inside it.
     *
     * @return array<string, mixed>
     */
    protected function workbench(string $area, string $tab, BranchContext $context): array
    {
        $days = (int) config('recon.default_days');

        return [
            'area' => $this->service->area($area),
            'tab' => $tab,
            'tabs' => $this->tabs($area, $context),
            'branches' => $this->branches(),
            'branchId' => (int) request('branch_id', $context->id() ?? 0),
            'pinned' => $context->isBranchWorkspace(),
            'from' => request('from', Carbon::today()->subDays($days)->toDateString()),
            'to' => request('to', Carbon::today()->toDateString()),
            'stampMode' => config('recon.stamp_mode'),
        ];
    }

    /**
     * The tab strip, minus whatever this user may not open.
     *
     * A tab the person cannot use is not shown greyed out: the route behind it
     * would answer 403, and a strip that offers a door it knows is locked is
     * the screen lying about what the person may do. Two things drop a tab —
     * the permission (`recon.criteria.view` is Finance and Admin, not
     * Operations) and the workspace (a branch user has no "every site").
     *
     * @return array<int, array<string, string>>
     */
    protected function tabs(string $area, BranchContext $context): array
    {
        $user = request()->user();

        return collect(self::TABS)
            ->reject(fn (array $tab) => ($tab['ho'] ?? false) && $context->isBranchWorkspace())
            ->filter(fn (array $tab) => (bool) $user?->can($tab['can']))
            ->map(fn (array $tab, string $key) => [
                'key' => $key,
                'label' => $tab['label'],
                'href' => route($tab['route'], $area),
            ])
            ->values()
            ->all();
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
}
