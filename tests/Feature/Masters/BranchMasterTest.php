<?php

namespace Tests\Feature\Masters;

use App\Exceptions\AgoraProcException;
use App\Support\BranchContext;
use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\Branch;
use Modules\Core\Models\User;
use Modules\Core\Services\BranchMigrationService;
use Modules\Core\Services\PermissionService;
use Tests\TestCase;

/**
 * The branch master (T021): usp_Migrate_Branch against SS_Branch, the
 * repointed vw_Branch, and Setup → The business → Branches / Regions, brands
 * and formats.
 *
 * Runs against the local container only, with every estate connection pinned
 * to it. The migration assertions read the local PumpIT stub, which
 * `php artisan agora:sync-local-branches` fills from the customer's instance —
 * so they hold for the real 31 branches, not a fixture. Edits are made to two
 * TEST- branches (963, 964) that are not in SS_Branch, and are cleaned up.
 */
class BranchMasterTest extends TestCase
{
    private const BRANCH = 963;

    private const OTHER = 964;

    private const ADMIN = [1, 2, 4, 14, 20, 27];

    /** @var array<int, User> */
    private array $users = [];

    private bool $safe = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessLocalStub();
        $local = config('database.connections.'.config('agora.connections.app'));
        foreach (config('agora.connections') as $name) {
            $database = config("database.connections.{$name}.database");
            config(["database.connections.{$name}" => array_replace($local, ['database' => $database])]);
            DB::purge($name);
            $this->assertContains(config("database.connections.{$name}.host"), ['127.0.0.1', 'localhost', '::1']);
        }
        $this->safe = true;
        $this->cleanUp();
        foreach ([self::BRANCH => 'TEST-Branch master site', self::OTHER => 'TEST-Branch master other'] as $id => $name) {
            $this->db()->table('agora.Branch')->insert([
                'BranchId' => $id, 'Name' => $name, 'IsTrading' => 1, 'IsActive' => 1, 'SortOrder' => $id,
                'Status' => 'Trading', 'POSType' => 'BRANCH', 'IsAdminEntity' => 0, 'CreatedAt' => now(),
            ]);
        }
    }

    protected function tearDown(): void
    {
        if ($this->safe) {
            $this->cleanUp();
            foreach ($this->users as $user) {
                foreach (['UserPermission', 'UserBranch', 'UserGridColumn', 'UserMenuItem'] as $table) {
                    $this->db()->table('agora.'.$table)->where('UserId', $user->Id)->delete();
                }
                User::query()->acrossBranches()->where('Id', $user->Id)->forceDelete();
            }
        }
        parent::tearDown();
    }

    public function test_the_migration_carries_every_ss_branch_row_with_its_derived_values(): void
    {
        $source = collect($this->db()->select('SELECT * FROM agora.vw_LegacyBranch ORDER BY BranchId'));
        $this->assertCount(31, $source, 'SS_Branch holds 31 branches.');

        $result = app(BranchMigrationService::class)->run();
        $this->assertSame('UNCHANGED', $result['status']->Code, 'A re-run touches nothing already migrated.');
        $this->assertCount(31, $result['branches']);

        $agora = Branch::query()->acrossBranches()->whereIn('BranchId', $source->pluck('BranchId'))->get()->keyBy('BranchId');
        $this->assertCount(31, $agora, 'Every SSBranchId is an agora.Branch BranchId — never re-numbered.');

        foreach ($source as $row) {
            $branch = $agora[(int) $row->BranchId];
            $this->assertNotNull($branch->MigratedAt, "Branch {$row->BranchId} migrated");
            $this->assertSame($row->Name, $branch->LegacyName);
            $this->assertSame((int) $row->ClassId, (int) $branch->ClassId);
            $this->assertSame((int) $row->RegionId, (int) $this->db()->table('agora.Region')->where('Id', $branch->RegionId)->value('LegacyId'));
            $this->assertSame((int) $row->BrandId, (int) $this->db()->table('agora.Brand')->where('Id', $branch->BrandId)->value('LegacyId'));
            $this->assertSame((int) $row->ClassId, (int) $this->db()->table('agora.Format')->where('Id', $branch->FormatId)->value('LegacyId'));
            $this->assertSame($row->POSType ?? 'BRANCH', $branch->POSType);
            $this->assertSame($branch->Status === 'Trading', $branch->IsTrading);
        }

        $this->assertSame([26], $agora->where('POSType', 'NAMOS')->keys()->all(), 'Total Hluhluwe is the one NAMOS site.');
        $this->assertSame('Total Hluhluwe', $agora[26]->Name);

        $admin = $agora->where('IsAdminEntity', true);
        $this->assertSame(self::ADMIN, $admin->keys()->sort()->values()->all());
        $this->assertSame(['Non-trading'], $admin->pluck('Status')->unique()->values()->all());
        $this->assertSame(
            ['AJLG Properties', 'Arcum Venandi', 'Jakarie Vulstasie', 'Thokozile Trust', 'Zickyza Properties', 'Zululand Petroleum'],
            $admin->pluck('Name')->sort()->values()->all()
        );

        // Excluded from the trading lists by default: Branch::trading() feeds the
        // scope bar and every trading picker.
        $trading = Branch::query()->acrossBranches()->trading()->pluck('BranchId')->map(fn ($v) => (int) $v)->all();
        $this->assertEmpty(array_intersect(self::ADMIN, $trading));
        $this->assertContains(26, $trading);

        // Teds and Wimpy Ladysmith stay two branches.
        $this->assertSame(['Teds Convenience Centre', 'Wimpy Ladysmith'], [$agora[30]->Name, $agora[31]->Name]);
    }

    public function test_vw_branch_reads_agora_branch_with_the_groupings(): void
    {
        $row = $this->db()->selectOne('SELECT * FROM agora.vw_Branch WHERE BranchId = 26');
        $this->assertSame('Total Hluhluwe', $row->Name);
        $this->assertSame('NAMOS', $row->POSType);
        $this->assertNotNull($row->RegionName);
        $this->assertSame('Total', $row->BrandName);

        // A branch only Agora knows is visible to every report that joins the view.
        $this->assertSame('TEST-Branch master site', $this->db()->selectOne('SELECT Name FROM agora.vw_Branch WHERE BranchId = ?', [self::BRANCH])->Name);
    }

    public function test_the_listing_hides_administrative_entities_until_asked_and_the_extract_agrees(): void
    {
        $this->actingAs($this->person(['branches.view']));

        $this->get(route('app.setup.branches.index'))->assertOk()
            ->assertSee('Total Hluhluwe')->assertSee('NAMOS')->assertSee('TEST-Branch master site')
            ->assertDontSee('Arcum Venandi')->assertSee('usp_Masters_GridBranches')->assertDontSee('Edit branch');

        $this->get(route('app.setup.branches.index', ['admin' => 1]))->assertOk()->assertSee('Arcum Venandi');

        $csv = $this->get(route('app.grids.extract', ['grid' => 'app.setup.branches']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Total Hluhluwe', $csv);
        $this->assertStringNotContainsString('Zickyza Properties', $csv);

        $csv = $this->get(route('app.grids.extract', ['grid' => 'app.setup.branches', 'admin' => 1]))->assertOk()->streamedContent();
        $this->assertStringContainsString('Zickyza Properties', $csv);

        $this->get(route('app.setup.branches.show', ['branch' => 26]))->assertOk()->assertSee('History')->assertSee('Migrated from SS_Branch');
        $this->get(route('app.setup.branches.show', ['branch' => 26]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertSee('Profit centres')->assertDontSee('<!DOCTYPE', false);
    }

    public function test_view_and_edit_are_separate_permissions(): void
    {
        $this->actingAs($this->person([]))->get(route('app.setup.branches.index'))->assertRedirect(route('app.access-required'));
        $this->actingAs($this->person(['branches.view']));
        $this->get($this->editUrl())->assertForbidden();
        $this->put($this->updateUrl(), $this->input())->assertForbidden();
        $this->getJson(route('app.grids.extract', ['grid' => 'app.setup.branches']))->assertOk();
        $this->assertSame(0, $this->changes());

        // The procedure is the check that cannot be bypassed.
        try {
            app(ProcedureService::class)->write('agora.usp_Masters_SaveBranch', array_replace($this->procInput(), ['Authorized' => 0]));
            $this->fail('An unauthorised save must be refused.');
        } catch (AgoraProcException $e) {
            $this->assertSame('BRANCH_DENIED', $e->code());
        }
    }

    public function test_an_edit_is_audited_moves_the_trading_flag_and_refuses_stale_or_contradictory_forms(): void
    {
        $user = $this->person(['branches.view', 'branches.edit']);
        $this->actingAs($user);
        $this->get($this->editUrl())->assertOk()->assertSee('name="ExpectedVersion"', false)->assertSee('Reason for the change');

        $input = $this->input(['Status' => 'Mothballed', 'ProfitCentreIds' => [4, 5]]);
        $this->put($this->updateUrl(), $input)->assertRedirect(route('app.setup.branches.show', ['branch' => self::BRANCH]));

        $branch = $this->db()->table('agora.Branch')->where('BranchId', self::BRANCH)->first();
        $this->assertSame('Mothballed', $branch->Status);
        $this->assertEquals(0, $branch->IsTrading, 'A mothballed branch leaves the trading lists.');
        $this->assertNotContains(self::BRANCH, Branch::query()->acrossBranches()->trading()->pluck('BranchId')->map(fn ($v) => (int) $v)->all());
        $this->assertSame([4, 5], $this->db()->table('agora.BranchProfitCentre')->where('BranchId', self::BRANCH)
            ->orderBy('ProfitCentreId')->pluck('ProfitCentreId')->map(fn ($v) => (int) $v)->all());

        $changes = $this->db()->table('agora.MasterChange')->where('Entity', 'Branch')->where('EntityId', self::BRANCH)->get()->keyBy('Field');
        $this->assertSame(['Status', 'ProfitCentres'], $changes->keys()->all());
        $this->assertSame(['Trading', 'Mothballed'], [$changes['Status']->PriorValue, $changes['Status']->NewValue]);
        $this->assertEquals($user->Id, $changes['Status']->CreatedBy);
        $this->assertSame($input['Reason'], $changes['Status']->Reason);

        // The same form again: its version is now stale.
        $this->from($this->editUrl())->put($this->updateUrl(), $input)->assertSessionHasErrors('refusal');

        // An administrative entity that trades is a contradiction the procedure refuses.
        $this->from($this->editUrl())->put($this->updateUrl(), $this->input(['IsAdminEntity' => 1, 'Status' => 'Trading']))
            ->assertSessionHasErrors('refusal');

        // A reason is required.
        $this->from($this->editUrl())->put($this->updateUrl(), $this->input(['Reason' => 'short']))->assertSessionHasErrors('Reason');
        $this->assertSame(2, $this->changes());

        $this->get(route('app.setup.branches.show', ['branch' => self::BRANCH]))->assertOk()->assertSee($input['Reason']);
    }

    public function test_a_grouping_is_created_renamed_and_cannot_be_switched_off_while_in_use(): void
    {
        $this->actingAs($this->person(['groupings.view', 'groupings.save', 'groupings.edit', 'branches.view', 'branches.edit']));
        $reason = 'TEST-checked with head office';

        $this->get(route('app.setup.branch-groupings.index'))->assertOk()->assertSee('Central KZN and Inland')->assertSee('High Way');
        $this->post(route('app.setup.branch-groupings.store', ['kind' => 'Region']), ['Name' => 'TEST-Region', 'Reason' => $reason])->assertRedirect();
        $region = $this->db()->table('agora.Region')->where('Name', 'TEST-Region')->first();
        $this->assertNotNull($region);
        $this->assertNull($region->LegacyId);

        // Duplicate names are refused.
        $this->from(route('app.setup.branch-groupings.index'))
            ->post(route('app.setup.branch-groupings.store', ['kind' => 'Region']), ['Name' => 'TEST-Region', 'Reason' => $reason])
            ->assertSessionHasErrors('refusal');

        // Put a branch in it, then try to switch it off.
        $this->put($this->updateUrl(), $this->input(['RegionId' => $region->Id]))->assertRedirect();
        $version = $this->db()->selectOne('SELECT CONVERT(varchar(18), CONVERT(binary(8), RowVer), 1) AS V FROM agora.Region WHERE Id = ?', [$region->Id])->V;
        $url = route('app.setup.branch-groupings.update', ['kind' => 'Region', 'id' => $region->Id]);
        $this->from(route('app.setup.branch-groupings.index'))
            ->put($url, ['Name' => 'TEST-Region', 'IsActive' => 0, 'ExpectedVersion' => $version, 'Reason' => $reason])
            ->assertSessionHasErrors('refusal');

        $this->put($url, ['Name' => 'TEST-Region renamed', 'IsActive' => 1, 'ExpectedVersion' => $version, 'Reason' => $reason])->assertRedirect();
        $this->assertSame('TEST-Region renamed', $this->db()->table('agora.Region')->where('Id', $region->Id)->value('Name'));
        // Created: Name and IsActive; renamed: Name.
        $this->assertSame(3, $this->db()->table('agora.MasterChange')->where('Entity', 'Region')->where('EntityId', $region->Id)->count());
        $this->assertSame('TEST-Region renamed', $this->db()->selectOne('SELECT RegionName FROM agora.vw_Branch WHERE BranchId = ?', [self::BRANCH])->RegionName);
    }

    /** @param  array<int, string>  $actions  resource.action under masters. */
    private function person(array $actions): User
    {
        $user = User::query()->acrossBranches()->create([
            'BranchId' => 2, 'EmailAddress' => 'TEST-branch-master-'.Str::uuid().'@agora.invalid', 'UserName' => 'TEST-Branch master',
            'IsActive' => true, 'IsLocked' => false, 'Workspace' => 'ho', 'PasswordHash' => 'TEST-only-'.Str::random(24),
        ]);
        $this->users[] = $user;
        foreach ($actions as $action) {
            $permission = $this->db()->table('agora.Permission')->where('Code', 'masters.'.$action)->value('Id');
            $this->assertNotNull($permission, "masters.{$action} is seeded");
            $this->db()->table('agora.UserPermission')->insert(['BranchId' => 2, 'UserId' => $user->Id, 'PermissionId' => $permission]);
        }
        app(PermissionService::class)->forget($user);
        app(BranchContext::class)->setWorkspace('ho')->setAllowed([]);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function input(array $overrides = []): array
    {
        $branch = $this->db()->selectOne('SELECT Name, RegionId, BrandId, FormatId, Status, POSType, IsAdminEntity, IsActive,
            CONVERT(varchar(18), CONVERT(binary(8), RowVer), 1) AS Version FROM agora.Branch WHERE BranchId = ?', [self::BRANCH]);

        return array_replace([
            'Name' => $branch->Name, 'RegionId' => $branch->RegionId, 'BrandId' => $branch->BrandId, 'FormatId' => $branch->FormatId,
            'Status' => $branch->Status, 'POSType' => $branch->POSType, 'IsAdminEntity' => (int) $branch->IsAdminEntity,
            'IsActive' => (int) $branch->IsActive, 'ProfitCentreIds' => [], 'ExpectedVersion' => $branch->Version,
            'Reason' => 'TEST-site confirmed closed by operations',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function procInput(): array
    {
        $input = $this->input(['Status' => 'Mothballed']);

        return ['BranchId' => self::BRANCH, 'Name' => $input['Name'], 'Status' => $input['Status'], 'POSType' => $input['POSType'],
            'IsAdminEntity' => 0, 'IsActive' => 1, 'ExpectedVersion' => $input['ExpectedVersion'], 'Reason' => $input['Reason']];
    }

    private function editUrl(): string
    {
        return route('app.setup.branches.edit', ['branch' => self::BRANCH]);
    }

    private function updateUrl(): string
    {
        return route('app.setup.branches.update', ['branch' => self::BRANCH]);
    }

    private function changes(): int
    {
        return $this->db()->table('agora.MasterChange')->where('Entity', 'Branch')->whereIn('EntityId', [self::BRANCH, self::OTHER])->count();
    }

    private function cleanUp(): void
    {
        $regions = $this->db()->table('agora.Region')->where('Name', 'like', 'TEST-%')->pluck('Id');
        $this->db()->table('agora.MasterChange')->where('Entity', 'Region')->whereIn('EntityId', $regions)->delete();
        $this->db()->table('agora.MasterChange')->where('Entity', 'Branch')->whereIn('EntityId', [self::BRANCH, self::OTHER])->delete();
        $this->db()->table('agora.BranchProfitCentre')->whereIn('BranchId', [self::BRANCH, self::OTHER])->delete();
        $this->db()->table('agora.Branch')->whereIn('BranchId', [self::BRANCH, self::OTHER])->delete();
        $this->db()->table('agora.Region')->whereIn('Id', $regions)->delete();
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }
}
