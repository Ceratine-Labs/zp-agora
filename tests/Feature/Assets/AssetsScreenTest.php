<?php

namespace Tests\Feature\Assets;

use App\Grid\GridRegistry;
use Illuminate\Support\Str;
use Modules\Assets\Grids\AssetMovementGrid;
use Modules\Assets\Grids\AssetRegisterGrid;
use Modules\Assets\Grids\AssetRepairGrid;
use Modules\Core\Models\Permission;
use Modules\Core\Models\User;
use Modules\Core\Models\UserPermission;
use Modules\Core\Services\PermissionService;
use Tests\TestCase;

/**
 * The Assets screens and the boundary around their writes (T057).
 *
 * The procedure tests cover what is computed and refused. This covers the
 * layer above: who may open each screen, who is offered which form, that a
 * refusal reaches the person as words rather than a 500, and that the
 * register says its rates are provisional.
 *
 * People are granted permissions BY CODE, not by profile, so a change to who
 * holds what elsewhere cannot quietly change what this proves.
 */
class AssetsScreenTest extends TestCase
{
    use AssetsFixture;

    /** @var array<string, class-string> */
    private const GRIDS = [
        'app.assets.register' => AssetRegisterGrid::class,
        'app.assets.movement' => AssetMovementGrid::class,
        'app.assets.repairs' => AssetRepairGrid::class,
    ];

    /** @var array<int, User> */
    private array $made = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessLocalStub('This fixture writes assets and users.');

        if (Permission::query()->acrossBranches()->where('Code', 'assets.register.view')->doesntExist()) {
            $this->markTestSkipped('Run: php artisan seed:master --only=AssetsPermissionSeeder');
        }

        // The lines config/grids.php carries once this lane is merged. Until
        // then the screens cannot find their grids; the registration itself is
        // asserted by test_the_three_asset_grids_are_registered below, which
        // does NOT add them.
        foreach (self::GRIDS as $key => $class) {
            if (! app(GridRegistry::class)->has($key)) {
                config(['grids.grids' => config('grids.grids') + [$key => $class]]);
            }
        }

        $this->cleanUpAssets();
        $this->seedAssetMasters();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->cleanUpAssets();

            foreach ($this->made as $user) {
                UserPermission::query()->acrossBranches()->where('UserId', $user->Id)->delete();
                $this->db()->table('agora.UserGridColumn')->where('UserId', $user->Id)->delete();
                User::query()->acrossBranches()->where('Id', $user->Id)->forceDelete();
            }
        }

        parent::tearDown();
    }

    public function test_the_three_asset_grids_are_registered(): void
    {
        // Fails until config/grids.php carries these three lines — the extract
        // and the saved column layout both find a grid by its key.
        $registered = (require base_path('config/grids.php'))['grids'];

        foreach (self::GRIDS as $key => $class) {
            $this->assertSame($class, $registered[$key] ?? null, "config/grids.php is missing '{$key}' => {$class}::class");
        }
    }

    public function test_the_register_says_its_rates_are_provisional_and_names_its_procedures(): void
    {
        $asset = $this->registerAsset(['Make' => 'TEST-Anvil', 'Cost' => 4000, 'DatePurchased' => '2026-05-22']);

        $this->actingAs($this->person(['register.view']))
            ->get(route('app.assets.register', ['branches' => [self::BRANCH]]))
            ->assertOk()
            ->assertSee('Provisional rates')
            ->assertSee('usp_Assets_GridRegister')
            ->assertSee('agora.usp_Assets_Depreciate')
            ->assertSee('TSTA0001-999')
            ->assertSee(route('app.assets.register.show', ['branch' => self::BRANCH, 'asset' => $asset]), false)
            ->assertDontSee('Register an asset');
    }

    public function test_an_assets_page_shows_its_value_history_and_schedule_and_offers_only_what_the_person_may_do(): void
    {
        $asset = $this->registerAsset(['Make' => 'TEST-Salton', 'Cost' => 35000, 'DatePurchased' => '2025-04-01']);
        $this->raiseRepair($asset, '2026-08-01');
        $url = route('app.assets.register.show', ['branch' => self::BRANCH, 'asset' => $asset]);

        $this->actingAs($this->person(['register.view']))
            ->get($url)
            ->assertOk()
            ->assertSee('TSTA0001-999')
            ->assertSee('Depreciation schedule')
            ->assertSee('JC00001-999')
            ->assertDontSee('data-modal-open="edit-asset"', false)
            ->assertDontSee('data-modal-open="move-asset"', false)
            ->assertDontSee('data-modal-open="delete-asset"', false);

        $this->actingAs($this->person(['register.view', 'register.edit', 'register.delete', 'movement.save', 'repair.save']))
            ->get($url)
            ->assertOk()
            ->assertSee('data-modal-open="edit-asset"', false)
            ->assertSee('data-modal-open="move-asset"', false)
            ->assertSee('data-modal-open="raise-repair"', false)
            // It has a job card, so it leaves by a movement, not a delete.
            ->assertDontSee('data-modal-open="delete-asset"', false);
    }

    public function test_somebody_who_may_save_can_register_an_asset_and_a_refusal_comes_back_as_words(): void
    {
        $person = $this->person(['register.view', 'register.save']);

        $this->actingAs($person)
            ->get(route('app.assets.register'))
            ->assertOk()
            ->assertSee('Register an asset');

        $this->actingAs($person)
            ->post(route('app.assets.register.store'), [
                'BranchId' => self::BRANCH,
                'AssetType' => self::GROUP.'|TSTA01',
                'Make' => 'TEST-Nestle',
                'Model' => '555',
                'SerialNo' => 'TEST-F41654',
                'DatePurchased' => '2026-05-05',
                'Cost' => '20000',
                'Condition' => 'GOOD',
            ])
            ->assertRedirect()
            ->assertSessionHas('status', 'Registered as TSTA0001-999.');

        $this->assertSame((int) $person->Id,
            (int) $this->db()->table('agora.Asset')->where('BranchId', self::BRANCH)->value('CreatedBy'),
            'The procedure stamps who registered it.');

        // The same machine again: refused by the procedure, said in words.
        $this->actingAs($person)
            ->from(route('app.assets.register'))
            ->post(route('app.assets.register.store'), [
                'BranchId' => self::BRANCH, 'AssetType' => self::GROUP.'|TSTA01', 'Make' => 'TEST-Nestle',
                'SerialNo' => 'TEST-F41654', 'DatePurchased' => '2026-05-05', 'Cost' => '20000', 'Condition' => 'GOOD',
            ])
            ->assertRedirect(route('app.assets.register'))
            ->assertSessionHasErrors(['refusal']);
    }

    public function test_a_reader_may_not_write_and_nobody_without_view_reaches_a_screen(): void
    {
        $asset = $this->registerAsset();
        $reader = $this->person(['register.view', 'movement.view', 'repair.view']);

        $this->actingAs($reader)->post(route('app.assets.register.store'), [])->assertForbidden();
        $this->actingAs($reader)->put(route('app.assets.register.update', ['branch' => self::BRANCH, 'asset' => $asset]), [])->assertForbidden();
        $this->actingAs($reader)->delete(route('app.assets.register.destroy', ['branch' => self::BRANCH, 'asset' => $asset]), ['reason' => 'x'])->assertForbidden();
        $this->actingAs($reader)->post(route('app.assets.movement.store'), [])->assertForbidden();
        $this->actingAs($reader)->post(route('app.assets.repairs.store'), [])->assertForbidden();

        $nobody = $this->person([]);
        foreach (['app.assets.register', 'app.assets.movement', 'app.assets.repairs', 'app.assets.masters'] as $route) {
            $this->actingAs($nobody)->get(route($route))->assertForbidden();
        }

        $this->assertSame(1, $this->db()->table('agora.Asset')->where('BranchId', self::BRANCH)->count(), 'Nothing was written.');
    }

    public function test_a_movement_recorded_from_the_screen_moves_the_asset_and_shows_on_the_movement_list(): void
    {
        $asset = $this->registerAsset();
        $elsewhere = $this->otherBranch();
        $person = $this->person(['register.view', 'movement.view', 'movement.save']);

        $this->actingAs($person)
            ->from(route('app.assets.register.show', ['branch' => self::BRANCH, 'asset' => $asset]))
            ->post(route('app.assets.movement.store'), [
                'asset' => self::BRANCH.':'.$asset,
                'MovementType' => 'MBS',
                'MovementDate' => '2026-08-01',
                'ToBranchId' => $elsewhere,
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame($elsewhere, (int) $this->db()->table('agora.Asset')->where('BranchId', self::BRANCH)->where('Id', $asset)->value('LocatedBranchId'));

        $this->actingAs($person)
            ->get(route('app.assets.movement', ['branches' => [self::BRANCH], 'from' => '2026-07-01', 'to' => '2026-08-31']))
            ->assertOk()
            ->assertSee('MV00001-999')
            ->assertSee('usp_Assets_GridMovements');
    }

    public function test_the_repair_screen_carries_the_supplier_tally_and_a_job_cards_page_names_its_comeback(): void
    {
        $fridge = $this->registerAsset();
        $this->raiseRepair($fridge, '2026-08-01', self::S1);
        $comeback = $this->raiseRepair($fridge, '2026-08-11', self::S2);
        $person = $this->person(['repair.view', 'register.view']);

        $this->actingAs($person)
            ->get(route('app.assets.repairs', ['branches' => [self::BRANCH], 'from' => '2026-07-01', 'to' => '2026-08-31']))
            ->assertOk()
            ->assertSee('Comebacks by supplier')
            ->assertSee('agora.usp_Assets_Comebacks')
            ->assertSee('TEST-Snowman')
            ->assertSee('JC00002-999');

        $this->actingAs($person)
            ->get(route('app.assets.repairs.show', ['branch' => self::BRANCH, 'repair' => $comeback]))
            ->assertOk()
            ->assertSee('A comeback')
            ->assertSee('JC00001-999')
            ->assertSee('TEST-Snowman');
    }

    public function test_the_masters_are_read_only_and_show_the_provisional_period_beside_each_group(): void
    {
        $this->actingAs($this->person(['register.view']))
            ->get(route('app.assets.masters'))
            ->assertOk()
            ->assertSee('Provisional rates')
            ->assertSee('TEST-Asset group')
            ->assertSee('Not depreciated')
            ->assertSee('agora.usp_Assets_Masters');

        // Read-only by construction: the masters have a GET and nothing else.
        $writes = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with((string) $r->getName(), 'app.assets.masters') && $r->methods() !== ['GET', 'HEAD']);
        $this->assertCount(0, $writes, 'Agora never writes the customer\'s asset masters.');

        $this->actingAs($this->person(['register.view']))
            ->get(route('app.assets.masters', ['tab' => 'movements']))
            ->assertOk()
            ->assertSee('Returned from repair')
            ->assertSee('Moved Between Sites');
    }

    public function test_an_asset_on_another_register_is_not_found_rather_than_confirmed(): void
    {
        $this->actingAs($this->person(['register.view']))
            ->get(route('app.assets.register.show', ['branch' => self::BRANCH, 'asset' => 987654321]))
            ->assertNotFound();
    }

    /* ------------------------------------------------------------------ */

    /** @param  array<int, string>  $grants  resource.action, e.g. register.view */
    private function person(array $grants): User
    {
        $user = User::query()->acrossBranches()->create([
            'BranchId' => (int) config('agora.group_branch_id', 2),
            'EmailAddress' => 'TEST-t057-'.uniqid().'@agora.invalid',
            'UserName' => 'TEST-assets',
            'IsActive' => true,
            'IsLocked' => false,
            'Workspace' => 'ho',
            'PasswordHash' => 'TEST-only-'.Str::random(24),
        ]);

        foreach ($grants as $grant) {
            [$resource, $action] = explode('.', $grant);
            $permission = Permission::query()->acrossBranches()
                ->where('Code', Permission::code('assets', $resource, $action))->firstOrFail();

            UserPermission::query()->acrossBranches()->create([
                'BranchId' => (int) $user->BranchId,
                'UserId' => (int) $user->Id,
                'PermissionId' => (int) $permission->Id,
            ]);
        }

        app(PermissionService::class)->forget($user);
        $this->made[] = $user;

        return $user;
    }
}
