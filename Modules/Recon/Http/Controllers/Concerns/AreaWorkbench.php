<?php

namespace Modules\Recon\Http\Controllers\Concerns;

use App\Support\BranchContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Modules\Core\Models\Branch;

/**
 * The chrome one reconciliation area shares across its tabs.
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
     * The faces of one reconciliation area, in the order a morning goes.
     *
     * Declared once rather than repeated in the blade, because the strip has
     * to agree with the routes and with the permissions three times over — on
     * the tab, on the route's `can:` middleware, and on whatever the pane
     * itself offers. A list is the cheapest way to keep those three honest.
     *
     * THE CENTRE IS ONE FACE (23 Sep 2026). Auto reconciliation, Suggestions
     * and Manual match used to be three tabs here, each its own page with its
     * own site-and-dates form. ZP asked for them consolidated: pick the site
     * and the period once, and the three become tabs INSIDE the centre, over
     * the one scope. Their routes still answer, for every link already out
     * there, but the strip no longer offers them beside the centre — two
     * doors to the same room is how a clerk ends up with two scopes.
     *
     * `ho` marks a tab that only means something in head office. "Every site"
     * in a workspace pinned to one site is that site, which is the centre
     * with more steps.
     */
    protected const TABS = [
        'auto' => ['label' => 'Recon centre', 'route' => 'app.recon.area', 'can' => 'recon.runs.view'],
        'all' => ['label' => 'Every site', 'route' => 'app.recon.all', 'can' => 'recon.runs.view', 'ho' => true],
        'runs' => ['label' => 'Runs', 'route' => 'app.recon.runs', 'can' => 'recon.runs.view'],
        'config' => ['label' => 'Configuration', 'route' => 'app.recon.config', 'can' => 'recon.criteria.view'],
    ];

    /**
     * The tabs inside the centre, each a fragment fetched the first time it is
     * opened — so a site-month costs only what the clerk looks at.
     *
     * `needs` names a flag on the area's definition in config/recon.php. The
     * Suggestions tab exists only where most bank lines carry no reference —
     * FNB — so an area without the flag does not offer it.
     */
    protected const CENTRE_TABS = [
        'auto' => ['label' => 'Auto reconciliation'],
        'suggest' => ['label' => 'Suggestions', 'needs' => 'suggest'],
        'match' => ['label' => 'Manual match'],
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
            'scope' => $this->scopeQuery(),
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
        $definition = $this->service->area($area);

        return collect(self::TABS)
            ->reject(fn (array $tab) => ($tab['ho'] ?? false) && $context->isBranchWorkspace())
            ->reject(fn (array $tab) => isset($tab['needs']) && ! ($definition[$tab['needs']] ?? false))
            ->filter(fn (array $tab) => (bool) $user?->can($tab['can']))
            ->map(fn (array $tab, string $key) => [
                'key' => $key,
                'label' => $tab['label'],
                // The site and dates travel with every tab, so moving from
                // the centre to the Runs tab and back does not drop them.
                'href' => route($tab['route'], [$area] + $this->scopeQuery()),
            ])
            ->values()
            ->all();
    }

    /**
     * The tabs inside the centre, minus any this area does not offer.
     *
     * @return array<int, array<string, string>>
     */
    protected function centreTabs(string $area): array
    {
        $definition = $this->service->area($area);

        return collect(self::CENTRE_TABS)
            ->reject(fn (array $tab) => isset($tab['needs']) && ! ($definition[$tab['needs']] ?? false))
            ->map(fn (array $tab, string $key) => ['key' => $key, 'label' => $tab['label']])
            ->values()
            ->all();
    }

    /**
     * The scope as it arrived, for putting back onto a link.
     *
     * Only what the request actually carried: a link that invents dates the
     * person never chose would open the next screen on a period they did not
     * ask for.
     *
     * @return array<string, string>
     */
    protected function scopeQuery(): array
    {
        return array_filter([
            'branch_id' => (string) request('branch_id', ''),
            'from' => (string) request('from', ''),
            'to' => (string) request('to', ''),
        ], fn (string $value) => $value !== '');
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
     * Every site this user may see, trading or not.
     *
     * CONFIGURATION IS NOT RECONCILIATION. A run only makes sense for a
     * trading site — an administrative entity keeps no day and has no bank
     * statement — but an extraction RULE exists for whatever branch the
     * customer configured one against, and five of them on the live estate are
     * administrative: AJLG Properties, Zululand Petroleum itself, Arcum
     * Venandi, Jakarie Vulstasie and Thokozile Trust. Their rules can never do
     * anything, which is a finding worth showing rather than a reason to hide
     * the rows.
     *
     * So the configuration screens read this and the reconciliation screens
     * read branches() above. Using the trading list for both is what made a
     * rule page say "Site 1" instead of "AJLG Properties".
     *
     * @return Collection<int, Branch>
     */
    protected function allBranches(): Collection
    {
        return Branch::query()
            ->where('IsActive', true)
            ->orderBy('Name')
            ->get();
    }

    /**
     * One site by its id, whether or not it trades.
     *
     * The caller has already established that this person may see it; this is
     * only about putting a name to the number.
     */
    protected function branch(int $branchId): ?Branch
    {
        return Branch::query()->where('BranchId', $branchId)->first();
    }
}
