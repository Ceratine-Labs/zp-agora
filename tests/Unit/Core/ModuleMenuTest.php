<?php

namespace Tests\Unit\Core;

use App\Support\BranchContext;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Mockery;
use Modules\Core\Http\Controllers\LandingStubController;
use Modules\Core\Http\Controllers\ModuleMenuController;
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
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ModuleMenuTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (array_keys(config('database.connections')) as $connection) {
            DB::connection($connection)->beforeExecuting(fn () => throw new RuntimeException('Hub tests permit no database access.'));
        }
        $this->user = (new User)->forceFill(['Id' => 123, 'BranchId' => 2, 'Workspace' => 'ho']);
        $permissions = Mockery::mock(PermissionService::class);
        $permissions->shouldReceive('userHas')->andReturnUsing(fn (User $user, string $ability) => $ability !== 'hub.denied');
        $this->app->instance(PermissionService::class, $permissions);
        Route::get('/TEST-hub/cash', fn () => '')->name('app.cash.testHub');
        Route::get('/TEST-hub/bank', fn () => '')->name('app.recon.testHub');
        Route::getRoutes()->refreshNameLookups();
    }

    public function test_hubs_preserve_workflow_context_heading_links_and_the_original_menu(): void
    {
        $capture = $this->item(1, 'Capture', path: 'capture');
        $cash = $this->item(2, 'Cashups', 'app.cash.testHub', path: 'capture/cashups');
        $cash->Hint = ' Live ';
        $report = $this->item(3, 'Open cashups', url: route('app.reports.show', 'open-cashups'), path: 'capture/open-cashups');
        $capture->childItems = collect([$cash, $report]);
        $bank = $this->item(4, 'Bank reconciliation', 'app.recon.testHub', path: 'money/bank-recon');
        $bank->childItems = collect([$this->item(5, 'Same cashups', 'app.cash.testHub', path: 'money/cashups')]);
        $sections = collect([$this->section(1, 'Today', collect([$capture])), $this->section(2, 'Control', collect([$bank]))]);
        $before = [$cash->getAttributes(), $cash->availableHref, $cash->hasAvailableItems, $capture->childItems->all()];

        $hubs = $this->service($sections)->forUser($this->user, 'ho');

        $this->assertSame(['recon', 'cash'], array_keys($hubs));
        $this->assertSame('Cashups', $hubs['cash']['label']);
        $this->assertSame(route('app.modules.show', ['module' => 'cash', 'ws' => 'ho']), $hubs['cash']['href']);
        $this->assertSame(['Today · Capture'], array_column($hubs['cash']['groups'], 'label'));
        $this->assertSame(['Cashups', 'Open cashups'], array_column($hubs['cash']['groups'][0]['tools'], 'label'));
        $this->assertSame('', $hubs['cash']['groups'][0]['tools'][0]['description']);
        $this->assertSame(config('reports.reports.open-cashups.summary'), $hubs['cash']['groups'][0]['tools'][1]['description']);
        $this->assertSame(['Control · Bank reconciliation'], array_column($hubs['recon']['groups'], 'label'));
        $this->assertSame('app.recon.testHub', $hubs['recon']['groups'][0]['tools'][0]['route']);
        $this->assertSame([$cash->getAttributes(), $cash->availableHref, $cash->hasAvailableItems, $capture->childItems->all()], $before);
        $this->assertSame(['kind' => 'report', 'variant' => 'open-cashups'], $hubs['cash']['groups'][0]['tools'][1]['art']);
    }

    public function test_deduplication_preserves_distinct_query_scopes_fragments_and_workspace(): void
    {
        $base = route('app.reports.show', 'stock-count');
        $items = collect([
            $this->item(1, 'First scoped count', url: $base.'?branches%5B0%5D=13&from=2026-10-01&to=2026-11-01&ws=ho#rows'),
            $this->item(2, 'Same scope reordered', url: $base.'?to=2026-11-01&from=2026-10-01&branches%5B0%5D=13#rows'),
            $this->item(3, 'Different site', url: $base.'?branches%5B0%5D=14&from=2026-10-01&to=2026-11-01#rows'),
            $this->item(4, 'Different anchor', url: $base.'?branches%5B0%5D=13&from=2026-10-01&to=2026-11-01#summary'),
        ]);
        $hub = $this->service(collect([$this->section(1, 'Stock', $items)]), workspace: 'branch')->forUser($this->user, 'branch')['stockrecon'];
        $tools = $hub['groups'][0]['tools'];
        $this->assertSame(['First scoped count', 'Different site', 'Different anchor'], array_column($tools, 'label'));
        parse_str(parse_url($tools[0]['href'], PHP_URL_QUERY), $query);
        $this->assertSame(['branches' => ['13'], 'from' => '2026-10-01', 'to' => '2026-11-01', 'ws' => 'branch'], $query);
        $this->assertSame('rows', parse_url($tools[0]['href'], PHP_URL_FRAGMENT));
        $this->assertStringContainsString('ws=ho#rows', $items[0]->Url, 'The source menu URL is not rewritten.');
    }

    public function test_only_authorized_working_destinations_contribute_and_denied_ancestors_do_not_gain_access(): void
    {
        Route::get('/TEST-hub/denied', fn () => '')->middleware('can:hub.denied')->name('app.cash.testDenied');
        Route::get('/TEST-hub/record/{id}', fn () => '')->name('app.cash.testRecord');
        Route::post('/TEST-hub/write', fn () => '')->name('app.cash.testWrite');
        Route::get('/TEST-hub/stub', [LandingStubController::class, 'console'])->name('app.cash.testStub');
        Route::getRoutes()->refreshNameLookups();
        $parent = $this->item(1, 'Unticked bank parent', 'app.recon.testHub');
        $parent->childItems = collect([$this->item(2, 'Allowed cash', 'app.cash.testHub')]);
        $items = collect([
            $parent,
            $this->item(3, 'Denied permission', 'app.cash.testDenied'),
            $this->item(4, 'Unticked report route', url: route('app.reports.show', 'utility-meters')),
            $this->item(5, 'Missing', 'app.cash.missing'), $this->item(6, 'Placeholder'),
            $this->item(7, 'Write', 'app.cash.testWrite'), $this->item(8, 'Unbound', 'app.cash.testRecord'),
            $this->item(9, 'Stub', 'app.cash.testStub'), $this->item(10, 'Dashboard', 'app.dashboard'),
            $this->item(11, 'External', url: 'https://example.invalid/TEST-hub/cash'),
            $this->item(12, 'Self hub', url: route('app.modules.show', ['module' => 'cash'])),
        ]);
        $hubs = $this->service(collect([$this->section(1, 'Today', $items)]), deniedItems: [1], deniedRoutes: ['app.reports.show'])->forUser($this->user, 'ho');
        $this->assertSame(['cash'], array_keys($hubs));
        $this->assertSame(['Allowed cash'], array_column($hubs['cash']['groups'][0]['tools'], 'label'));
    }

    public function test_report_backed_module_hubs_exist_without_inventing_a_catalogue_or_capture_permission(): void
    {
        $items = collect([
            $this->item(1, 'Utility readings report', url: route('app.reports.show', 'utility-meters')),
            $this->item(2, 'Stock count report', url: route('app.reports.show', 'stock-count')),
            $this->item(3, 'Cash queue report', url: route('app.reports.show', 'open-cashups')),
        ]);
        $tiles = (new LandingModules($this->service(collect([$this->section(1, 'Today', $items)]))))->forUser($this->user, 'ho');
        $this->assertSame(['utilities', 'stockrecon', 'cash'], array_column($tiles, 'key'));
        foreach ($tiles as $tile) {
            $this->assertSame(route('app.modules.show', ['module' => $tile['key'], 'ws' => 'ho']), $tile['href']);
        }
        $catalogue = $this->item(4, 'Report library', 'app.reports.index');
        $hubs = $this->service(collect([$this->section(1, 'Trade', collect([$catalogue]))]))->forUser($this->user, 'ho');
        $this->assertSame(['reports'], array_keys($hubs));
        $this->assertSame(route('app.reports.index', ['ws' => 'ho']), $hubs['reports']['groups'][0]['tools'][0]['href']);
    }

    public function test_controller_requires_a_nonempty_permitted_hub_and_keeps_the_auth_route_stack(): void
    {
        $route = Route::getRoutes()->getByName('app.modules.show');
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertContains('auth', $route->gatherMiddleware());
        $request = Request::create(route('app.modules.show', ['module' => 'cash', 'ws' => 'branch']));
        $request->setUserResolver(fn () => $this->user);
        $context = (new BranchContext)->setWorkspace('branch');
        $service = $this->service(collect([$this->section(1, 'Today', collect([$this->item(1, 'Cashups', 'app.cash.testHub')]))]), workspace: 'branch');
        $view = (new ModuleMenuController)('cash', $request, $context, $service);
        $this->assertSame('core::module-menu', $view->name());
        $this->assertSame('cash', $view->getData()['module']['key']);
        $this->assertSame($context, $view->getData()['context']);

        foreach (['unknown-module', 'cash'] as $module) {
            $menu = $this->createMock(MenuService::class);
            if ($module === 'unknown-module') {
                $menu->expects($this->never())->method('tree');
            } else {
                $menu->expects($this->once())->method('tree')->with('branch', $this->user)->willReturn(collect());
            }
            $empty = new ModuleMenu($menu, $this->availability(), app(NavigationModules::class), app(NavigationArtwork::class));
            try {
                (new ModuleMenuController)($module, $request, $context, $empty);
                $this->fail('An unknown or inaccessible hub must fail closed.');
            } catch (HttpException $exception) {
                $this->assertSame(404, $exception->getStatusCode());
            }
        }
        $request->setUserResolver(fn () => null);
        try {
            (new ModuleMenuController)('cash', $request, $context, $service);
            $this->fail('A hub must not accept an unauthenticated request.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    private function item(int $id, string $label, ?string $route = null, ?string $url = null, string $path = ''): MenuItem
    {
        return (new MenuItem)->forceFill(['Id' => $id, 'Label' => $label, 'RouteName' => $route, 'Url' => $url, 'Path' => $path]);
    }

    /** @param Collection<int, MenuItem> $items */
    private function section(int $id, string $label, Collection $items): MenuSection
    {
        return (new MenuSection)->forceFill(['Id' => $id, 'Label' => $label])->setRelation('items', $items);
    }

    /**
     * @param  Collection<int, MenuSection>  $sections
     * @param  list<int>  $deniedItems
     * @param  list<string>  $deniedRoutes
     */
    private function service(Collection $sections, string $workspace = 'ho', array $deniedItems = [], array $deniedRoutes = []): ModuleMenu
    {
        $menu = $this->createMock(MenuService::class);
        $menu->expects($this->once())->method('tree')->with($workspace, $this->user)->willReturn($sections);

        return new ModuleMenu($menu, $this->availability($deniedItems, $deniedRoutes), app(NavigationModules::class), app(NavigationArtwork::class));
    }

    /**
     * @param  list<int>  $deniedItems
     * @param  list<string>  $deniedRoutes
     */
    private function availability(array $deniedItems = [], array $deniedRoutes = []): MenuAvailability
    {
        $access = $this->createStub(MenuAccessService::class);
        $access->method('mayView')->willReturnCallback(fn (User $user, MenuItem $item) => ! in_array($item->Id, $deniedItems, true));
        $access->method('canReachRoute')->willReturnCallback(fn (User $user, string $route) => ! in_array($route, $deniedRoutes, true));

        return new MenuAvailability($access, app(Router::class));
    }
}
