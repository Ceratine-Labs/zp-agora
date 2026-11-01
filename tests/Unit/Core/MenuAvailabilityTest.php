<?php

namespace Tests\Unit\Core;

use Illuminate\Routing\Router;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Mockery;
use Modules\Core\Http\Controllers\LandingStubController;
use Modules\Core\Models\MenuItem;
use Modules\Core\Models\MenuSection;
use Modules\Core\Models\User;
use Modules\Core\Services\MenuAccessService;
use Modules\Core\Services\MenuAvailability;
use Modules\Core\Services\PermissionService;
use Tests\TestCase;

/** Availability annotates the already permitted catalogue without querying it. */
class MenuAvailabilityTest extends TestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = (new User)->forceFill(['Id' => 123, 'BranchId' => 2, 'Workspace' => 'ho']);
        $permissions = Mockery::mock(PermissionService::class);
        $permissions->shouldReceive('userHas')->andReturnUsing(fn (User $user, string $ability) => $ability === 'menu.allowed');
        $this->app->instance(PermissionService::class, $permissions);
    }

    public function test_active_descendants_keep_their_ancestors_without_exposing_denied_parent_links(): void
    {
        Route::get('/TEST-menu/parent', fn () => '')->name('app.testmenu.parent');
        Route::get('/TEST-menu/working', fn () => '')->middleware('can:menu.allowed')->name('app.testmenu.working');
        Route::getRoutes()->refreshNameLookups();
        $working = $this->item(3, 'Working', 'app.testmenu.working');
        $placeholder = $this->item(4, 'Not built');
        $group = $this->item(2, 'Nested group');
        $group->childItems = collect([$working, $placeholder]);
        // The permission-filtered menu kept this parent only as an ancestor.
        $parent = $this->item(1, 'Unticked parent', 'app.testmenu.parent');
        $parent->childItems = collect([$group]);
        $dead = $this->item(5, 'Future work');
        $sections = collect([$this->section(1, collect([$parent])), $this->section(2, collect([$dead]))]);

        $result = $this->service(deniedItems: [1])->annotate($sections, $this->user);

        $this->assertSame([1, 2], $result->pluck('Id')->all(), 'Turning active off must still have every permitted section.');
        $this->assertSame([3, 4], $group->childItems->pluck('Id')->all(), 'Placeholders remain in the catalogue for toggle-off.');
        $this->assertTrue($parent->hasAvailableItems);
        $this->assertTrue($group->hasAvailableItems);
        $this->assertTrue($working->hasAvailableItems);
        $this->assertFalse($placeholder->hasAvailableItems);
        $this->assertFalse($dead->hasAvailableItems);
        $this->assertNull($parent->availableHref, 'Keeping the ancestor must not grant its own link.');
        $this->assertSame(route('app.testmenu.working'), $working->availableHref);
        $this->assertTrue($result[0]->items->contains(fn ($item) => $item->hasAvailableItems));
        $this->assertFalse($result[1]->items->contains(fn ($item) => $item->hasAvailableItems));
    }

    public function test_only_authorized_working_get_destinations_are_active_and_dashboard_remains_available(): void
    {
        Route::get('/TEST-menu/locked', fn () => '')->middleware('can:menu.denied')->name('app.testmenu.locked');
        Route::get('/TEST-menu/stub', [LandingStubController::class, 'console'])->name('app.testmenu.stub');
        Route::post('/TEST-menu/write', fn () => '')->name('app.testmenu.write');
        Route::get('/TEST-menu/record/{record}', fn () => '')->name('app.testmenu.record');
        Route::get('/TEST-menu/public', fn () => '')->name('testmenu.public');
        Route::getRoutes()->refreshNameLookups();
        $service = $this->service();
        foreach ([
            $this->item(1, 'Not built'),
            $this->item(2, 'Missing', 'app.testmenu.missing'),
            $this->item(3, 'Denied', 'app.testmenu.locked'),
            $this->item(4, 'Placeholder controller', 'app.testmenu.stub'),
            $this->item(5, 'Write', 'app.testmenu.write'),
            $this->item(6, 'Needs a record', 'app.testmenu.record'),
            $this->item(7, 'External', url: 'https://example.invalid/TEST-menu/record/123'),
            $this->item(10, 'Outside the app', 'testmenu.public'),
        ] as $item) {
            $this->assertNull($service->destination($item, $this->user), $item->Label);
        }
        $this->assertFalse($this->item(9, 'Needs a record', 'app.testmenu.record')->isLink(),
            'MenuService checks parent links before annotation; missing parameters must not crash the menu.');

        $destination = $service->destination($this->item(8, 'Modules', 'app.dashboard'), $this->user);
        $this->assertNotNull($destination, 'The dashboard is useful in the menu even though it is not its own landing tile.');
        $this->assertSame('app.dashboard', $destination[0]->getName());
        $this->assertSame(route('app.dashboard'), $destination[1]);
    }

    private function item(int $id, string $label, ?string $route = null, ?string $url = null): MenuItem
    {
        return (new MenuItem)->forceFill(['Id' => $id, 'Label' => $label, 'RouteName' => $route, 'Url' => $url]);
    }

    /** @param Collection<int, MenuItem> $items */
    private function section(int $id, Collection $items): MenuSection
    {
        $section = (new MenuSection)->forceFill(['Id' => $id, 'Workspace' => 'ho', 'Label' => 'TEST section '.$id]);
        $section->setRelation('items', $items);

        return $section;
    }

    private function service(array $deniedItems = []): MenuAvailability
    {
        $access = Mockery::mock(MenuAccessService::class);
        $access->shouldReceive('mayView')->andReturnUsing(fn (User $user, MenuItem $item) => ! in_array((int) $item->Id, $deniedItems, true));
        $access->shouldReceive('canReachRoute')->andReturn(true);

        return new MenuAvailability($access, $this->app->make(Router::class));
    }
}
