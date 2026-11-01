<?php

namespace Tests\Unit\Core;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Modules\Core\Models\MenuItem;
use Modules\Core\Services\NavigationArtwork;
use Modules\Recon\Models\ReconRun;
use Modules\StockRecon\Models\StockReconRun;
use RuntimeException;
use Tests\TestCase;

class NavigationArtworkTest extends TestCase
{
    private NavigationArtwork $art;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (array_keys(config('database.connections')) as $connection) {
            DB::connection($connection)->beforeExecuting(function (): void {
                throw new RuntimeException('Artwork must not query the database.');
            });
        }
        $this->art = app(NavigationArtwork::class);
    }

    public function test_titles_share_configured_module_identity_and_each_report_keeps_its_own_art(): void
    {
        $tools = [
            'app.utilities.index' => 'utility-readings',
            'app.recon.index' => 'bank-reconcile', 'app.recon.dashboard' => 'bank-dashboard', 'app.recon.trace' => 'bank-trace',
            'app.stockrecon.index' => 'stock-centre', 'app.stockrecon.weekly' => 'stock-weekly', 'app.stockrecon.variance' => 'stock-variance',
            'app.cash.cashups' => 'cashups', 'app.cash.banking' => 'cash-banking', 'app.cash.zread' => 'cash-allocation',
            'app.master.stock.index' => 'product-stock', 'app.master.critical.index' => 'product-critical',
            'app.debtors.index' => 'debtor-statements',
            'app.assets.register' => 'asset-register', 'app.assets.movement' => 'asset-movement', 'app.assets.repairs' => 'asset-repairs', 'app.assets.masters' => 'asset-masters',
            'app.setup.users.index' => 'access-users', 'app.setup.roles.index' => 'access-roles',
        ];
        $this->assertSame(['kind' => 'logo', 'variant' => 'zp'], $this->art->forRoute('app.dashboard'));
        foreach (config('agora.landing_modules') as $key => $module) {
            $expected = $key === 'my-queue'
                ? ['kind' => 'report', 'variant' => 'my-queue']
                : ['kind' => 'module', 'variant' => $module['art']];
            foreach ($module['preferred_routes'] as $route) {
                $routeArt = isset($tools[$route]) ? ['kind' => 'tool', 'variant' => $tools[$route]] : $expected;
                $this->assertSame($routeArt, $this->art->forRoute($route, $module['route_parameters'] ?? []), $route);
            }
            $this->assertSame(['kind' => 'module', 'variant' => $module['art']], $this->art->forRoute('app.modules.show', ['module' => $key]));
        }
        foreach (config('reports.reports') as $key => $report) {
            $this->assertSame(['kind' => 'report', 'variant' => $key], $this->art->forRoute('app.reports.show', ['report' => $key]), $key);
            if (isset($report['detail']['route'])) {
                $this->assertSame(['kind' => 'report', 'variant' => $key], $this->art->forRoute($report['detail']['route']));
            }
        }
    }

    public function test_internal_report_urls_resolve_parameters_without_mutating_the_registered_route(): void
    {
        $route = Route::getRoutes()->getByName('app.reports.show');
        $route->bind(Request::create(route('app.reports.show', 'day-close')));
        foreach (config('reports.reports') as $key => $report) {
            foreach ([route('app.reports.show', $key), route('app.reports.show', $key, false).'?from=2026-11-01&ws=ho'] as $url) {
                $this->assertSame(['kind' => 'report', 'variant' => $key], $this->art->forUrl($url), $url);
            }
        }
        $this->assertSame(['report' => 'day-close'], $route->parameters());
        foreach (['https://external.invalid/app/reports/my-queue', '//external.invalid/app/reports/my-queue', 'javascript:alert(1)', 'https://user:password@localhost/app/reports/my-queue', '/missing-route'] as $url) {
            $this->assertSame(['kind' => 'module', 'variant' => 'cup'], $this->art->forUrl($url), $url);
        }
    }

    public function test_inactive_menu_entries_and_groups_have_identity_without_changing_availability(): void
    {
        foreach ([
            ['capture/pump-readings', 'report', 'pump-readings'],
            ['close-the-day/pump-readings', 'report', 'pump-readings'],
            ['raise/staff-short', 'report', 'staff-shorts'],
            ['raise/utility-reading', 'report', 'utility-meters'],
            ['equipment/repairs', 'module', 'assets'],
            ['people-assets/meters', 'module', 'utilities'],
            ['trading-rules/account-customers', 'module', 'debtors'],
            ['counting', 'module', 'cart'],
            ['reports', 'module', 'reports'],
        ] as [$path, $kind, $variant]) {
            $item = (new MenuItem)->forceFill(['Path' => $path]);
            $before = $item->getAttributes();
            $this->assertSame(compact('kind', 'variant'), $this->art->forItem($item), $path);
            $this->assertSame($before, $item->getAttributes());
            $this->assertNull($item->availableHref);
            $this->assertFalse($item->hasAvailableItems);
        }
        $parent = (new MenuItem)->forceFill(['Path' => 'customer-heading']);
        $parent->childItems = collect([
            (new MenuItem)->forceFill(['Path' => 'unknown-placeholder']),
            (new MenuItem)->forceFill(['RouteName' => 'app.stockrecon.index']),
        ]);
        $this->assertSame(['kind' => 'tool', 'variant' => 'stock-centre'], $this->art->forItem($parent));
        $this->assertCount(2, $parent->childItems);
        $this->assertSame(['kind' => 'module', 'variant' => 'reports'], $this->art->forItem(new MenuItem));

        $linked = (new MenuItem)->forceFill(['Path' => 'generic', 'Url' => route('app.reports.show', 'day-close')]);
        $this->assertSame(['kind' => 'report', 'variant' => 'day-close'], $this->art->forItem($linked));
        $linked->availableHref = route('app.reports.show', 'my-queue');
        $this->assertSame(['kind' => 'report', 'variant' => 'my-queue'], $this->art->forItem($linked));
    }

    public function test_page_heading_resolves_current_request_and_keeps_information_and_actions(): void
    {
        $route = Route::getRoutes()->getByName('app.reports.show');
        $route = (clone $route)->bind(Request::create(route('app.reports.show', 'day-close')));
        request()->setRouteResolver(fn () => $route);
        $html = Blade::render('<x-page-head title="Day close" eyebrow="Reports" blurb="Seven days"><x-slot:info>About the close</x-slot:info><x-slot:actions><a href="/back">Back</a></x-slot:actions></x-page-head>');
        $this->assertStringContainsString('data-feature-art-kind="report"', $html);
        $this->assertStringContainsString('data-feature-art-variant="day-close"', $html);
        $this->assertLessThan(strpos($html, '<h1>Day close</h1>'), strpos($html, 'data-report-art="day-close"'));
        $this->assertStringContainsString('About the close', $html);
        $this->assertStringContainsString('Seven days', $html);
        $this->assertStringContainsString('<a href="/back">Back</a>', $html);

        request()->setRouteResolver(fn () => (clone Route::getRoutes()->getByName('app.dashboard'))->bind(Request::create(route('app.dashboard'))));
        $dashboard = Blade::render('<x-page-head title="Choose a module" />');
        $this->assertStringContainsString('data-feature-art-kind="logo"', $dashboard);
        $this->assertStringContainsString('data-zp-mark', $dashboard);
        $this->assertStringContainsString('/images/zrp-logo.png', $dashboard);
    }

    public function test_report_art_is_decorative_and_can_be_reused_by_the_existing_loader(): void
    {
        foreach (array_keys(config('reports.reports')) as $key) {
            $html = Blade::render('<x-feature-art :art="$art" />', ['art' => $this->art->forRoute('app.reports.show', ['report' => $key])]);
            $this->assertStringContainsString('data-report-art="'.$key.'"', $html);
            $this->assertStringContainsString('data-module-art="'.($key === 'my-queue' ? 'my-queue' : 'reports').'"', $html);
            $this->assertStringContainsString('data-module-art-animated="false"', $html);
            $this->assertStringContainsString('loader-art', $html);
            $this->assertStringContainsString('aria-hidden="true"', $html);
            $this->assertStringContainsString('focusable="false"', $html);
        }
    }

    public function test_unrecognized_parameters_and_configured_variants_cannot_select_arbitrary_art(): void
    {
        foreach ([['cash'], '../../cash', 'cash.art', 'missing'] as $module) {
            $this->assertSame(['kind' => 'module', 'variant' => 'cup'], $this->art->forRoute('app.modules.show', ['module' => $module]));
        }
        $this->assertSame(['kind' => 'module', 'variant' => 'reports'], $this->art->forRoute('app.reports.show', ['report' => ['my-queue']]));
        $this->assertSame(['kind' => 'module', 'variant' => 'reports'], $this->art->forRoute('app.reports.show', ['report' => '../../private']));
        config(['agora.loaders' => ['app.unknown.*' => '../../private']]);
        $this->assertSame(['kind' => 'module', 'variant' => 'cup'], $this->art->forRoute('app.unknown.index'));
    }

    public function test_cash_tools_have_distinct_identity_in_urls_menu_items_and_page_titles(): void
    {
        $routes = [
            'app.cash.zread' => 'cash-allocation', 'app.cash.banking' => 'cash-banking',
            'app.cash.cashups' => 'cashups', 'app.cash.tillbalance' => 'till-balance',
            'app.cash.dropsafe' => 'cash-safe', 'app.cash.staffshorts' => 'cash-staff-shorts',
            'app.cash.cashups.nocashup' => 'cash-no-cashup', 'app.cash.pumpshortages' => 'pump-shortages',
            'app.cash.dropsafe.bags' => 'cash-safe-bags', 'app.cash.staffshorts.list' => 'cash-shorts-list',
            'app.cash.deductions' => 'cash-deductions', 'app.cash.deductions.stockloss' => 'cash-stock-loss',
            'app.cash.deductions.nonint' => 'cash-non-integrated', 'app.cash.deductions.payroll' => 'cash-payroll',
        ];
        $this->assertCount(count($routes), array_unique($routes));
        foreach ($routes as $route => $variant) {
            $expected = ['kind' => 'tool', 'variant' => $variant];
            $url = route($route);
            $this->assertSame($expected, $this->art->forUrl($url), $route);
            $item = (new MenuItem)->forceFill(['RouteName' => $route, 'Path' => 'capture/tool']);
            $item->availableHref = $url;
            $this->assertSame($expected, $this->art->forItem($item));
            $bound = (clone Route::getRoutes()->getByName($route))->bind(Request::create($url));
            request()->setRouteResolver(fn () => $bound);
            $title = Blade::render('<x-page-head title="Tool" />');
            $this->assertStringContainsString('data-tool-art="'.$variant.'"', $title);
            $this->assertStringContainsString('data-module-art-animated="false"', $title);
            $loader = Blade::render('<x-loader :tool="$variant" overlay />', compact('variant'));
            $this->assertStringContainsString('data-tool-art="'.$variant.'"', $loader);
            $this->assertStringContainsString('data-module-art-animated="true"', $loader);
            $this->assertStringContainsString('data-loader-overlay', $loader);
        }
        $this->assertSame(['kind' => 'tool', 'variant' => 'cash-allocation'], $this->art->forRoute('app.cash.zread.confirm'));
        $this->assertSame(['kind' => 'tool', 'variant' => 'cash-banking'], $this->art->forRoute('app.cash.banking.phone'));
        $this->assertSame(['kind' => 'report', 'variant' => 'unallocated-zreads'], $this->art->forRoute('app.reports.show', ['report' => 'unallocated-zreads']));
    }

    public function test_only_explicit_recon_area_destinations_select_area_artwork(): void
    {
        $areas = [
            'ABSA' => 'bank-absa', 'FNB' => 'bank-fnb', 'CashMachine' => 'bank-cash-machine',
            'CashBags' => 'bank-cash-bags', 'SmartATM' => 'bank-smart-atm',
        ];
        $this->assertSame(array_keys(config('recon.areas')), array_keys($areas));
        $areaRoutes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with($route->getName() ?? '', 'app.recon.') && in_array('area', $route->parameterNames(), true));
        $this->assertNotEmpty($areaRoutes);
        foreach ($areaRoutes as $route) {
            foreach ($areas as $area => $variant) {
                $expected = ['kind' => 'tool', 'variant' => $variant];
                $this->assertSame($expected, $this->art->forRoute($route->getName(), compact('area')), $route->getName());
                if (in_array('GET', $route->methods(), true)) {
                    $url = route($route->getName(), ['area' => $area, 'branch' => 1, 'rule' => 2, 'ws' => 'ho']);
                    $this->assertSame($expected, $this->art->forUrl($url), $url);
                }
            }
        }
        foreach (['cashbags', 'smartatm', 'absa', 'Fnb', 'missing', '../../FNB', ['ABSA'], null] as $area) {
            $this->assertSame(['kind' => 'tool', 'variant' => 'bank-reconcile'], $this->art->forRoute('app.recon.area', compact('area')));
        }
        foreach (['app.recon.index' => 'bank-reconcile', 'app.recon.dashboard' => 'bank-dashboard', 'app.recon.trace' => 'bank-trace', 'app.recon.run' => 'bank-reconcile', 'app.recon.group' => 'bank-reconcile'] as $route => $variant) {
            foreach (array_keys($areas) as $area) {
                $this->assertSame(['kind' => 'tool', 'variant' => $variant], $this->art->forRoute($route, compact('area')));
            }
        }
        $this->assertSame(['kind' => 'tool', 'variant' => 'bank-reconcile'], $this->art->forRoute('app.recon.missing', ['area' => 'SmartATM']));
        $this->assertSame(['kind' => 'tool', 'variant' => 'bank-reconcile'], $this->art->forUrl(route('app.recon.index', ['area' => 'FNB'])));
    }

    public function test_bound_recon_run_uses_its_saved_type_without_a_database_read(): void
    {
        foreach (['ABSA' => 'bank-absa', 'FNB' => 'bank-fnb', 'CashBags' => 'bank-cash-bags', 'CashMachine' => 'bank-cash-machine', 'SmartATM' => 'bank-smart-atm'] as $area => $variant) {
            $run = (new ReconRun)->forceFill(['Id' => 260, 'ReconArea' => $area]);
            $expected = ['kind' => 'tool', 'variant' => $variant];
            $this->assertSame($expected, $this->art->forRoute('app.recon.run', ['run' => $run]));
            $route = (clone Route::getRoutes()->getByName('app.recon.run'))->bind(Request::create(route('app.recon.run', 260)));
            $route->setParameter('run', $run);
            request()->setRouteResolver(fn () => $route);
            $this->assertSame($expected, $this->art->current());
            $this->assertStringContainsString('data-tool-art="'.$variant.'"', Blade::render('<x-page-head title="Run 260" />'));
            $this->assertSame(['kind' => 'tool', 'variant' => 'bank-reconcile'], $this->art->forRoute('app.recon.index', ['run' => $run]));
            $this->assertSame(['kind' => 'tool', 'variant' => 'bank-reconcile'], $this->art->forRoute('app.recon.missing', ['run' => $run]));
        }
        foreach ([260, '260', (object) ['ReconArea' => 'ABSA'], (new StockReconRun)->forceFill(['ReconArea' => 'ABSA'])] as $run) {
            $this->assertSame(['kind' => 'tool', 'variant' => 'bank-reconcile'], $this->art->forRoute('app.recon.run', ['run' => $run]));
        }
        $this->assertSame(['kind' => 'tool', 'variant' => 'bank-reconcile'], $this->art->forUrl(route('app.recon.run', ['run' => 260, 'area' => 'ABSA'])));
    }

    public function test_bank_logos_are_self_contained_and_shared_by_menu_title_and_startup_loader(): void
    {
        foreach (['ABSA' => ['absa.jpg', 'image/jpeg'], 'FNB' => ['fnb.svg', 'image/svg+xml']] as $area => [$file, $mime]) {
            $path = public_path('images/banks/'.$file);
            $this->assertFileExists($path);
            $source = file_get_contents($path);
            $this->assertIsString($source);
            $image = 'data:'.$mime.';base64,'.base64_encode($source);
            $variant = 'bank-'.strtolower($area);
            $url = route('app.recon.area', ['area' => $area, 'ws' => 'ho']);
            $item = (new MenuItem)->forceFill(['Path' => 'money/bank-recon/'.strtolower($area), 'Url' => $url]);
            $before = $item->getAttributes();
            $art = $this->art->forItem($item);
            $this->assertSame(['kind' => 'tool', 'variant' => $variant], $art);
            $this->assertSame($before, $item->getAttributes());
            $route = (clone Route::getRoutes()->getByName('app.recon.area'))->bind(Request::create($url));
            request()->setRouteResolver(fn () => $route);
            $renderings = [
                Blade::render('<x-feature-art :art="$art" />', compact('art')),
                Blade::render('<x-page-head title="Bank" />'),
                Blade::render('<x-loader :tool="$variant" overlay startup />', compact('variant')),
            ];
            foreach ($renderings as $html) {
                $this->assertStringContainsString('data-tool-art="'.$variant.'"', $html);
                $this->assertStringContainsString('data-module-art="'.$variant.'"', $html);
                $this->assertStringContainsString('href="'.$image.'"', $html);
                $this->assertStringContainsString('preserveAspectRatio="xMidYMid meet"', $html);
                $this->assertStringContainsString('fill="var(--badge)"', $html);
                $this->assertStringContainsString('aria-hidden="true"', $html);
                $this->assertStringContainsString('focusable="false"', $html);
                $this->assertStringNotContainsString('/images/banks/', $html);
            }
            $this->assertStringContainsString('data-module-art-animated="false"', $renderings[0]);
            $this->assertStringContainsString('data-module-art-animated="true"', $renderings[2]);
            $this->assertStringContainsString('data-loader-startup', $renderings[2]);
        }
    }
}
