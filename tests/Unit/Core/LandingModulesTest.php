<?php

namespace Tests\Unit\Core;

use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Mockery;
use Modules\Core\Http\Controllers\LandingStubController;
use Modules\Core\Models\MenuItem;
use Modules\Core\Models\MenuSection;
use Modules\Core\Models\User;
use Modules\Core\Services\LandingModules;
use Modules\Core\Services\MenuAccessService;
use Modules\Core\Services\MenuAvailability;
use Modules\Core\Services\MenuService;
use Modules\Core\Services\ModuleMenu;
use Modules\Core\Services\NavigationArtwork;
use Modules\Core\Services\NavigationModules;
use Modules\Core\Services\PermissionService;
use Modules\Core\View\Composers\ShellComposer;
use Tests\TestCase;

/** In-memory menu trees: these checks never read or write the estate. */
class LandingModulesTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (array_keys(config('database.connections')) as $connection) {
            DB::connection($connection)->beforeExecuting(fn () => throw new \RuntimeException('Database access forbidden in landing unit tests.'));
        }
        $this->user = (new User)->forceFill(['Id' => 123, 'BranchId' => 2, 'Workspace' => 'ho']);
        $permissions = Mockery::mock(PermissionService::class);
        $permissions->shouldReceive('userHas')->andReturnUsing(fn (User $user, string $ability) => in_array($ability, ['landing.allowed', 'operations.wall.view', 'utilities.readings.view'], true));
        $this->app->instance(PermissionService::class, $permissions);
    }

    public function test_tiles_follow_the_filtered_workspace_tree_and_open_the_module_hub(): void
    {
        Route::get('/TEST-landing/stock', fn () => '')->name('app.teststock.index');
        Route::get('/TEST-landing/stock/weekly', fn () => '')->name('app.teststock.weekly');
        Route::get('/TEST-landing/cash', fn () => '')->name('app.testcash.index');
        Route::getRoutes()->refreshNameLookups();
        config(['agora.landing_modules' => [
            'stock' => $this->metadata('Stock', 'cart', 'app.teststock.', ['app.teststock.weekly']),
            'cash' => $this->metadata('Cash', 'cashup', 'app.testcash.', ['app.testcash.index']),
        ]]);
        $index = $this->item(1, 'Balancing', 'app.teststock.index');
        $weekly = $this->item(2, 'Weekly investigation', 'app.teststock.weekly');
        $heading = $this->item(3, 'Counting');
        $heading->childItems = collect([$index, $weekly]);
        $tiles = $this->service(collect([$heading, $index]), workspace: 'branch')->forUser($this->user, 'branch');

        $this->assertCount(1, $tiles, 'A configured module without an allowed menu destination must stay absent.');
        $this->assertSame([
            'key' => 'stock', 'label' => 'Stock', 'description' => 'Open Stock', 'art' => 'cart',
            'href' => route('app.modules.show', ['module' => 'stock', 'ws' => 'branch']), 'entryLabel' => 'Stock',
        ], $tiles[0]);
    }

    public function test_a_denied_preferred_destination_does_not_hide_a_hub_with_an_allowed_tool(): void
    {
        Route::get('/TEST-landing/stock/unticked', fn () => '')->name('app.teststock.unticked');
        Route::get('/TEST-landing/stock/locked', fn () => '')->middleware('can:landing.denied')->name('app.teststock.locked');
        Route::get('/TEST-landing/stock/open', fn () => '')->middleware('can:landing.allowed')->name('app.teststock.open');
        Route::getRoutes()->refreshNameLookups();
        config(['agora.landing_modules' => [
            'stock' => $this->metadata('Stock', 'cart', 'app.teststock.', ['app.teststock.unticked', 'app.teststock.locked']),
        ]]);
        // A linked parent may survive MenuService's upward closure solely
        // because a permitted descendant needs the heading to remain visible.
        $unticked = $this->item(1, 'Unticked parent', 'app.teststock.unticked');
        $unticked->childItems = collect([
            $this->item(2, 'Permission denied', 'app.teststock.locked'),
            $this->item(3, 'Allowed work', 'app.teststock.open'),
        ]);
        $tiles = $this->service(collect([$unticked]), deniedItems: [1])->forUser($this->user, 'ho');

        $this->assertCount(1, $tiles);
        $this->assertSame(route('app.modules.show', ['module' => 'stock', 'ws' => 'ho']), $tiles[0]['href']);
        $this->assertSame('Stock', $tiles[0]['entryLabel']);
    }

    public function test_local_parameterized_report_links_offer_a_hub_only_after_route_access_is_checked(): void
    {
        Route::get('/TEST-landing/report/{code}', fn () => '')->middleware('can:landing.allowed')->name('app.testreports.show');
        Route::getRoutes()->refreshNameLookups();
        config(['agora.landing_modules' => [
            'reports' => $this->metadata('Reports', 'reports', 'app.testreports.'),
        ]]);
        $href = route('app.testreports.show', ['code' => 'day-close', 'branch' => 8]);
        $item = $this->item(1, 'Day close report', url: $href);
        $tiles = $this->service(collect([$item]))->forUser($this->user, 'ho');

        $this->assertCount(1, $tiles);
        $this->assertSame(route('app.modules.show', ['module' => 'reports', 'ws' => 'ho']), $tiles[0]['href']);
        $this->assertSame([], $this->service(collect([$item]), deniedRoutes: ['app.testreports.show'])->forUser($this->user, 'ho'));
    }

    public function test_placeholders_self_links_missing_routes_and_non_get_targets_do_not_become_tiles(): void
    {
        Route::post('/TEST-landing/stock/write', fn () => '')->name('app.teststock.write');
        Route::get('/TEST-landing/stock/{run}', fn () => '')->name('app.teststock.required');
        Route::get('/TEST-landing/stub', [LandingStubController::class, 'console'])->name('app.teststock.stub');
        Route::getRoutes()->refreshNameLookups();
        config(['agora.landing_modules' => [
            'stock' => $this->metadata('Stock', 'cart', 'app.teststock.', ['app.teststock.required']),
            'home' => $this->metadata('Home', 'cup', 'app.dashboard'),
        ]]);
        $tiles = $this->service(collect([
            $this->item(1, 'Not built'),
            $this->item(2, 'Missing', 'app.teststock.missing'),
            $this->item(3, 'Write', 'app.teststock.write'),
            $this->item(4, 'Needs a run', 'app.teststock.required'),
            $this->item(5, 'Stub', 'app.teststock.stub'),
            $this->item(6, 'This page', 'app.dashboard'),
            $this->item(7, 'External', url: 'https://example.invalid/TEST-landing/stock/123'),
            $this->item(8, 'Protocol relative', url: '//example.invalid/TEST-landing/stock/123'),
        ]))->forUser($this->user, 'ho');

        $this->assertSame([], $tiles);
    }

    public function test_module_tiles_and_destination_loaders_share_the_same_art_mapping(): void
    {
        $modules = config('agora.landing_modules');
        $this->assertNotEmpty($modules);
        $this->assertSame('Operations wall', $modules['operations']['label']);
        $this->assertSame(['my-queue', 'operations'], array_slice(array_keys($modules), 0, 2));
        foreach ($modules as $key => $module) {
            $tile = Blade::render('<x-module-art :variant="$variant" />', ['variant' => $module['art']]);
            $loader = Blade::render('<x-loader :variant="$variant" />', ['variant' => $module['art']]);
            $this->assertStringContainsString('data-module-art="'.$module['art'].'"', $tile, $key);
            $this->assertStringContainsString('data-module-art-animated="false"', $tile, $key);
            $this->assertStringContainsString('data-module-art="'.$module['art'].'"', $loader, $key);
            $this->assertStringContainsString('data-module-art-animated="true"', $loader, $key);
            foreach ($module['route_prefixes'] as $prefix) {
                $this->assertSame($module['art'], config('agora.loaders')[$prefix.'*'] ?? null, $key.' '.$prefix);
            }
            foreach ($module['route_names'] ?? [] as $route) {
                $this->assertContains(['route' => $route, 'parameters' => $module['route_parameters'] ?? [], 'art' => $module['art']], config('agora.route_loaders'));
            }
        }
    }

    public function test_my_queue_and_operations_lead_without_capturing_other_reports_or_granting_access(): void
    {
        $queueHref = route('app.reports.show', ['report' => 'my-queue', 'from' => '2026-10-01', 'branches' => [13]]);
        $dayHref = route('app.reports.show', ['report' => 'day-close']);
        $items = collect([
            $this->item(1, 'Day close', url: $dayHref),
            $this->item(2, 'Utilities', 'app.utilities.index'),
            $this->item(3, 'My queue', url: $queueHref),
            $this->item(4, 'Operations wall', 'app.operations.wall'),
        ]);

        $tiles = $this->service($items)->forUser($this->user, 'ho');
        $this->assertSame(['my-queue', 'operations', 'utilities', 'reports'], array_column($tiles, 'key'));
        $this->assertSame($queueHref.'&ws=ho', $tiles[0]['href']);
        $this->assertSame(route('app.operations.wall', ['ws' => 'ho']), $tiles[1]['href']);
        $this->assertSame(route('app.modules.show', ['module' => 'utilities', 'ws' => 'ho']), $tiles[2]['href']);
        $this->assertSame(route('app.modules.show', ['module' => 'reports', 'ws' => 'ho']), $tiles[3]['href']);

        $denied = $this->service($items, deniedItems: [3])->forUser($this->user, 'ho');
        $this->assertSame(['operations', 'utilities', 'reports'], array_column($denied, 'key'));
        $otherReport = $this->service(collect([$items[0]]))->forUser($this->user, 'ho');
        $this->assertSame(['reports'], array_column($otherReport, 'key'));
        $this->assertSame([], $this->service(collect([$items[2]]), deniedRoutes: ['app.reports.show'])->forUser($this->user, 'ho'));
    }

    public function test_the_shell_uses_checklist_only_for_the_my_queue_report_parameter(): void
    {
        $composer = Mockery::mock(ShellComposer::class);
        $composer->shouldReceive('compose')->andReturnUsing(fn ($view) => $view->with([
            'shellSections' => collect(), 'shellWorkspace' => 'ho', 'shellWorkspaces' => config('core.workspaces'),
            'shellBranchId' => null, 'shellBranches' => collect(), 'shellBranchesGranted' => false,
        ]));
        $this->app->instance(ShellComposer::class, $composer);

        foreach (['my-queue' => 'my-queue', 'day-close' => 'reports', 'my-queue-other' => 'reports'] as $report => $art) {
            $request = Request::create(route('app.reports.show', ['report' => $report]), 'GET');
            $request->setLaravelSession($this->app->make('session')->driver());
            $route = clone Route::getRoutes()->getByName('app.reports.show');
            $route->bind($request);
            $request->setRouteResolver(fn () => $route);
            $this->app->instance('request', $request);

            $html = Blade::render('<x-app-shell>Loader test</x-app-shell>');
            $document = new \DOMDocument;
            @$document->loadHTML($html);
            $overlay = (new \DOMXPath($document))->query('//*[@data-loader-overlay]')->item(0);
            $this->assertNotNull($overlay);
            $this->assertStringContainsString('data-module-art="'.$art.'"', $document->saveHTML($overlay));
        }
    }

    /**
     * @param  list<string>  $preferred
     * @return array{label: string, description: string, art: string, route_prefixes: list<string>, preferred_routes: list<string>}
     */
    private function metadata(string $label, string $art, string $prefix, array $preferred = []): array
    {
        return ['label' => $label, 'description' => 'Open '.$label, 'art' => $art, 'route_prefixes' => [$prefix], 'preferred_routes' => $preferred];
    }

    private function item(int $id, string $label, ?string $route = null, ?string $url = null): MenuItem
    {
        return (new MenuItem)->forceFill(['Id' => $id, 'Label' => $label, 'RouteName' => $route, 'Url' => $url]);
    }

    /**
     * @param  Collection<int, MenuItem>  $items
     * @param  list<int>  $deniedItems
     * @param  list<string>  $deniedRoutes
     */
    private function service(Collection $items, string $workspace = 'ho', array $deniedItems = [], array $deniedRoutes = []): LandingModules
    {
        $section = (new MenuSection)->forceFill(['Id' => 1, 'Workspace' => $workspace, 'Label' => 'TEST section']);
        $section->setRelation('items', $items);
        $menu = $this->createMock(MenuService::class);
        $menu->expects($this->once())->method('tree')->with($workspace, $this->user)->willReturn(collect([$section]));
        $access = $this->createStub(MenuAccessService::class);
        $access->method('mayView')->willReturnCallback(fn (User $user, MenuItem $item) => ! in_array((int) $item->Id, $deniedItems, true));
        $access->method('canReachRoute')->willReturnCallback(fn (User $user, string $route) => ! in_array($route, $deniedRoutes, true));

        return new LandingModules(new ModuleMenu(
            $menu, new MenuAvailability($access, $this->app->make(Router::class)),
            $this->app->make(NavigationModules::class), $this->app->make(NavigationArtwork::class),
        ));
    }
}
