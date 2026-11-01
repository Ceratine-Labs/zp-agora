<?php

namespace Tests\Unit\Core;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Modules\Core\Models\MenuItem;
use Modules\Core\Services\NavigationModules;
use RuntimeException;
use Tests\TestCase;

class NavigationModulesTest extends TestCase
{
    private NavigationModules $modules;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (array_keys(config('database.connections')) as $connection) {
            DB::connection($connection)->beforeExecuting(function (): void {
                throw new RuntimeException('Module grouping must not query or change permissions.');
            });
        }
        $this->modules = app(NavigationModules::class);
    }

    public function test_reports_group_with_the_work_they_describe_instead_of_their_shared_route_or_illustration(): void
    {
        $expected = [
            'my-queue' => 'operations', 'day-close' => 'reports', 'overnight-loads' => 'reports',
            'unallocated-zreads' => 'cash', 'open-cashups' => 'cash', 'purchase-approvals' => 'reports',
            'staff-shorts' => 'cash', 'drop-safe' => 'cash', 'waste' => 'reports', 'pump-readings' => 'reports',
            'fuel-input' => 'reports', 'daily-banking' => 'cash', 'stock-count' => 'stockrecon',
            'utility-meters' => 'utilities', 'import-pos' => 'reports', 'audit-trail' => 'reports',
        ];
        $this->assertSame(array_keys(config('reports.reports')), array_keys($expected));
        foreach ($expected as $report => $module) {
            $item = $this->item(1, 'custom/path');
            $item->availableHref = route('app.reports.show', ['report' => $report, 'ws' => 'ho', 'branches' => [13]]);
            $this->assertSame($module, $this->modules->forItem($item)['key'], $report.' active');
            $item->availableHref = null;
            $item->Url = route('app.reports.show', $report, false);
            $this->assertSame($module, $this->modules->forItem($item)['key'], $report.' stored URL');
            $item->Url = null;
            $item->RouteName = 'app.reports.show';
            $item->Path = 'custom/'.$report;
            $this->assertSame($module, $this->modules->forItem($item)['key'], $report.' inactive');
        }
    }

    public function test_grouping_keeps_first_appearance_and_intra_module_order_without_changing_the_permitted_tree(): void
    {
        $cash = $this->item(1, 'capture/daily-banking', 'app.cash.banking');
        $cash->availableHref = route('app.cash.banking', ['ws' => 'ho', 'date' => '2026-11-01']);
        $cash->hasAvailableItems = true;
        $report = $this->item(2, 'capture/pump-readings');
        $nested = $this->item(3, 'custom/mixed');
        $nested->childItems = collect([$this->item(31, 'capture/utility-meters'), $this->item(32, 'capture/cashups')]);
        $moreCash = $this->item(4, 'capture/cashups', 'app.cash.cashups');
        $utility = $this->item(5, 'capture/utility-meters');
        $inactiveCash = $this->item(6, 'capture/staff-shorts');
        $other = $this->item(7, 'customer/future');
        $items = collect([$cash, $report, $nested, $moreCash, $utility, $inactiveCash, $other]);
        $before = $this->state($items);

        $groups = $this->modules->groups($items);

        $this->assertSame(['cash', 'reports', null, 'utilities', 'other'], array_column($groups, 'key'));
        $this->assertSame(['Cashups', 'Reports', null, 'Utilities', 'Other'], array_column($groups, 'label'));
        $this->assertSame([[1, 4, 6], [2], [3], [5], [7]], array_map(fn ($group) => $group['items']->pluck('Id')->all(), $groups));
        $this->assertSame($nested, $groups[2]['items']->sole());
        $this->assertSame($cash, $groups[0]['items']->first());
        $this->assertSame($before, $this->state($items), 'Attributes, permissions, hrefs, activity and original ordering remain untouched.');
        $this->assertSame(['utilities', 'cash'], array_column($this->modules->groups($nested->childItems), 'key'));
        $this->assertSame($before, $this->state($items), 'Grouping children does not move them to their parent’s groups.');
        $this->assertSame([], $this->modules->groups(collect()));
    }

    public function test_nested_headings_are_standalone_but_their_own_destination_can_have_a_module_label(): void
    {
        $parent = $this->item(1, 'money/bank-recon', 'app.recon.index');
        $parent->availableHref = route('app.recon.index');
        $parent->childItems = collect([$this->item(2, 'stock/recon-centre', 'app.stockrecon.index'), $this->item(3, 'capture/cashups', 'app.cash.cashups')]);
        $this->assertNull($this->modules->forItem($parent));
        $this->assertSame(['key' => 'recon', 'label' => 'Bank recon'], $this->modules->forDestination($parent));
        $groups = $this->modules->groups(collect([$parent]));
        $this->assertNull($groups[0]['key']);
        $this->assertNull($groups[0]['label']);
        $this->assertSame($parent, $groups[0]['items']->sole());

        $parent->availableHref = null;
        $parent->RouteName = null;
        $parent->Path = 'custom/mixed';
        $this->assertSame(['key' => 'other', 'label' => 'Other'], $this->modules->forDestination($parent), 'A mixed group never borrows its first child’s identity.');
    }

    public function test_named_routes_follow_landing_metadata_and_override_stale_paths(): void
    {
        foreach (config('agora.landing_modules') as $key => $module) {
            if ($key === 'my-queue') {
                continue;
            }
            foreach ($module['preferred_routes'] as $route) {
                $item = $this->item(1, 'capture/utility-meters', $route);
                $this->assertSame($key, $this->modules->forItem($item)['key'], $route);
            }
        }
        $this->assertSame(['key' => 'stockrecon', 'label' => 'Stock recon'], $this->modules->forItem($this->item(1, 'other', 'app.stockrecon.index')));
        $this->assertSame(['key' => 'operations', 'label' => 'Operations'], $this->modules->forItem($this->item(1, 'other', 'app.dashboard')));
        config(['agora.landing_modules.custom' => ['label' => 'Customer module', 'route_prefixes' => ['app.customer.']]]);
        $this->assertSame(['key' => 'custom', 'label' => 'Customer module'], $this->modules->forItem($this->item(1, 'other', 'app.customer.index')));
    }

    public function test_seeded_inactive_paths_keep_semantic_modules_in_each_workspace(): void
    {
        foreach ([
            'capture/zread' => 'cash', 'close-the-day/zread' => 'cash', 'the-day/zreads-no-cashup' => 'cash',
            'raise/staff-short' => 'cash', 'money/drop-safe-bags' => 'cash', 'money/banking-exceptions' => 'cash',
            'capture/till-balance' => 'cash', 'money/pump-shortages' => 'cash', 'money/deductions' => 'cash',
            'counting/month-end' => 'stockrecon', 'governance/area-locks' => 'stockrecon', 'business/counting-areas' => 'stockrecon',
            'stock/recon-centre/exceptions' => 'stockrecon', 'stock/variance' => 'stockrecon',
            'raise/utility-reading' => 'utilities', 'people-assets/meters' => 'utilities',
            'money/bank-recon/trace' => 'recon', 'money/debtors-statements' => 'debtors',
            'trading-rules/products' => 'products', 'production/critical-lines' => 'products',
            'equipment/register' => 'assets', 'people-assets/asset-repairs' => 'assets',
            'people-assets/users' => 'access', 'reports/exco/library' => 'reports', 'operations-wall' => 'operations',
        ] as $path => $module) {
            $item = $this->item(1, $path);
            $this->assertSame($module, $this->modules->forItem($item)['key'], $path);
            $this->assertFalse($item->hasAvailableItems);
            $this->assertNull($item->availableHref);
        }
    }

    public function test_external_unknown_and_parameterized_urls_do_not_dispatch_or_rewrite_links(): void
    {
        foreach (['https://external.invalid/app/reports/open-cashups', '//external.invalid/app/cash/cashups', 'javascript:alert(1)', '/unknown-module'] as $url) {
            $item = $this->item(1, 'customer/future');
            $item->Url = $url;
            $this->assertSame(['key' => 'other', 'label' => 'Other'], $this->modules->forItem($item));
            $this->assertSame($url, $item->Url);
        }
        $item = $this->item(1, 'unrelated');
        $item->availableHref = route('app.reports.show', ['report' => 'stock-count', 'from' => '2026-10-01', 'to' => '2026-11-01', 'branches' => [-1]]);
        $href = $item->availableHref;
        $this->assertSame(['key' => 'stockrecon', 'label' => 'Stock recon'], $this->modules->forItem($item));
        $this->assertSame($href, $item->availableHref);
        $this->assertFalse($item->hasAvailableItems, 'Decoration must not grant activity or access.');
    }

    public function test_url_module_identity_is_independent_of_artwork_and_does_not_rebind_shared_routes(): void
    {
        config(['agora.landing_modules.cash.art' => 'a-new-tool-drawing']);
        $cash = $this->item(1, 'unrelated');
        $cash->availableHref = route('app.cash.cashups');
        $this->assertSame(['key' => 'cash', 'label' => 'Cashups'], $this->modules->forDestination($cash));
        $cash->availableHref = route('app.modules.show', ['module' => 'cash']);
        $this->assertSame(['key' => 'cash', 'label' => 'Cashups'], $this->modules->forDestination($cash));
        $this->assertNull($this->modules->forRoute('app.modules.show', ['module' => 'unknown']));

        $route = Route::getRoutes()->getByName('app.reports.show');
        $route->bind(Request::create(route('app.reports.show', 'day-close')));
        $cash->availableHref = route('app.reports.show', 'stock-count');
        $this->assertSame('stockrecon', $this->modules->forDestination($cash)['key']);
        $this->assertSame(['report' => 'day-close'], $route->parameters());
        $this->assertSame('utilities', $this->modules->forRoute('app.reports.show', ['report' => 'utility-meters'])['key']);
    }

    private function item(int $id, string $path, ?string $route = null): MenuItem
    {
        return (new MenuItem)->forceFill(['Id' => $id, 'Path' => $path, 'RouteName' => $route, 'Label' => 'Entry '.$id, 'Hint' => 'Kept hint', 'PermissionCode' => 'kept.permission']);
    }

    /**
     * @param  Collection<int, MenuItem>  $items
     * @return array<int, mixed>
     */
    private function state(Collection $items): array
    {
        return $items->map(fn (MenuItem $item) => [
            $item->getAttributes(), $item->availableHref, $item->hasAvailableItems,
            $item->childItems === null ? null : $this->state($item->childItems),
        ])->all();
    }
}
