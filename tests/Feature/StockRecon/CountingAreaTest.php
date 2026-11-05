<?php

namespace Tests\Feature\StockRecon;

use App\Exceptions\AgoraProcException;
use App\Support\BranchContext;
use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\User;
use Modules\Core\Services\MenuAccessService;
use Modules\Core\Services\PermissionService;
use Tests\TestCase;

class CountingAreaTest extends TestCase
{
    private const BRANCH = 986;

    private const OTHER = 987;

    /** @var array<int, User> */
    private array $users = [];

    private bool $safe = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessLocalStub();
        // Every configured estate connection uses the SAME loopback instance.
        // No accidental lookup can reach a customer host during these tests.
        $local = config('database.connections.'.config('agora.connections.app'));
        foreach (config('agora.connections') as $name) {
            $database = config("database.connections.{$name}.database");
            config(["database.connections.{$name}" => array_replace($local, ['database' => $database])]);
            DB::purge($name);
            $this->assertContains(config("database.connections.{$name}.host"), ['127.0.0.1', 'localhost', '::1']);
        }
        $this->safe = true;
        $this->cleanUp();
        foreach ([[self::BRANCH, 801, 'TEST-Bakery', 'Food Items'], [self::BRANCH, 802, 'TEST-Store', 'Retail Items'], [self::OTHER, 801, 'TEST-Other bakery', 'Food Items']] as [$branch, $area, $name, $group]) {
            $this->db()->table('PumpIT.dbo.STK_Area')->insert([
                'SSBranchId' => $branch, 'AreaNo' => $area, 'AreaDescription' => $name, 'AreaGroup' => $group,
                'DayShift' => 1, 'AfternoonShift' => 0, 'NightShift' => 0, 'ShowReport' => 1, 'IsCaptureWaste' => 1,
            ]);
        }
        $this->db()->table('agora.StockAreaSetting')->insert([
            'BranchId' => self::BRANCH, 'AreaNo' => 801, 'IsPreparedFood' => 1,
            'FoodBasis' => 'Area group Food Items', 'CreatedAt' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->safe) {
            $this->cleanUp();
            foreach ($this->users as $user) {
                $this->db()->table('agora.StockReconProfileEvent')->where('CreatedBy', $user->Id)->delete();
                $this->db()->table('agora.StockReconBalancingProfile')->where('CreatedBy', $user->Id)->where('Name', 'TEST area profile')->delete();
                foreach (['UserPermission', 'UserBranch', 'UserGridColumn', 'UserMenuItem'] as $table) {
                    $this->db()->table('agora.'.$table)->where('UserId', $user->Id)->delete();
                }
                User::query()->acrossBranches()->where('Id', $user->Id)->forceDelete();
            }
        }
        parent::tearDown();
    }

    public function test_balancing_configuration_is_readable_and_requires_save_permission(): void
    {
        $user = $this->person(['view']);
        $profileView = $this->db()->table('agora.Permission')->where('Code', 'stockrecon.profiles.view')->value('Id');
        $profileSave = $this->db()->table('agora.Permission')->where('Code', 'stockrecon.profiles.save')->value('Id');
        $this->db()->table('agora.UserPermission')->insert(['BranchId' => 2, 'UserId' => $user->Id, 'PermissionId' => $profileView]);
        app(PermissionService::class)->forget($user);
        $this->actingAs($user)->get($this->url('balancing-config'))->assertOk()->assertSee('Generic')->assertSee('Item mappings')->assertDontSee('Create profile');
        $this->post($this->url('profiles.store'), [])->assertForbidden();
        $this->db()->table('agora.UserPermission')->insert(['BranchId' => 2, 'UserId' => $user->Id, 'PermissionId' => $profileSave]);
        app(PermissionService::class)->forget($user);
        $this->get($this->url('balancing-config'))->assertOk()->assertSee('Create profile');
    }

    public function test_authorized_profile_edit_is_audited_and_rejects_a_stale_revision(): void
    {
        $user = $this->person(['view']);
        foreach (['view', 'save'] as $action) {
            $permission = $this->db()->table('agora.Permission')->where('Code', 'stockrecon.profiles.'.$action)->value('Id');
            $this->db()->table('agora.UserPermission')->insert(['BranchId' => 2, 'UserId' => $user->Id, 'PermissionId' => $permission]);
        }
        app(PermissionService::class)->forget($user);
        $this->actingAs($user);
        $payload = ['name' => 'TEST area profile', 'MaxAmendment' => 50, 'MaxAmendmentPct' => 0.5,
            'MinShiftsInChain' => 2, 'KeepBreaksUpTo' => 3];
        $this->post($this->url('profiles.store'), $payload)->assertRedirect();
        $profile = $this->db()->table('agora.StockReconBalancingProfile')->where('CreatedBy', $user->Id)->where('Name', $payload['name'])->first();
        $this->assertNotNull($profile);
        $url = route('app.stockrecon.countingareas.profiles.update', ['branch' => self::BRANCH, 'area' => 801, 'profile' => $profile->Id]);
        $this->put($url, array_replace($payload, ['revision' => 1, 'MaxAmendment' => 40]))->assertRedirect();
        $this->put($url, array_replace($payload, ['revision' => 1, 'MaxAmendment' => 30]))->assertSessionHasErrors('revision');
        $fresh = $this->db()->table('agora.StockReconBalancingProfile')->where('Id', $profile->Id)->first();
        $this->assertEquals(40, $fresh->MaxAmendment);
        $this->assertEquals(2, $fresh->Revision);
        $this->assertSame(2, $this->db()->table('agora.StockReconProfileEvent')->where('CreatedBy', $user->Id)->count());
    }

    public function test_listing_detail_edit_and_csv_leave_safe_proposals_unchanged(): void
    {
        $user = $this->person(['view', 'edit', 'save']);
        $this->actingAs($user);
        $before = $this->setting();
        $this->get(route('app.stockrecon.countingareas', ['branches' => [self::BRANCH]]))
            ->assertOk()->assertSee('TEST-Bakery')->assertSee('Proposed')->assertSee('usp_StockRecon_GridCountingAreas')
            ->assertSee('data-detail-url="'.$this->url('show').'"', false);
        $this->get($this->url('show'))->assertOk()->assertSee('Confirmation history')->assertSee('Edit classification');
        $this->get($this->url('show'), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertSee('Confirmation history')->assertDontSee('<!DOCTYPE', false);
        $this->get($this->url('edit'))->assertOk()->assertSee('Explicit confirmation')->assertSee('name="ExpectedVersion"', false);
        $csv = $this->get(route('app.grids.extract', ['grid' => 'app.stockrecon.countingareas', 'branches' => [self::BRANCH], 'f' => ['AreaDescription' => ['q' => 'TEST-Bakery', 'eq' => 1]]]))->assertOk();
        $this->assertStringContainsString('TEST-Bakery', $csv->streamedContent());
        $this->assertStringNotContainsString('TEST-Store', $csv->streamedContent());
        $this->assertEquals($before, $this->setting());
        $this->assertSame(0, $this->auditCount());
    }

    public function test_view_edit_save_and_direct_grid_endpoints_have_separate_access(): void
    {
        $this->actingAs($this->person([]))->get(route('app.stockrecon.countingareas'))->assertRedirect(route('app.access-required'));
        $this->getJson(route('app.grids.extract', ['grid' => 'app.stockrecon.countingareas']))->assertForbidden();
        $this->postJson(route('app.grids.columns.store', ['grid' => 'app.stockrecon.countingareas']), [])->assertForbidden();
        $this->actingAs($this->person(['view']))->get($this->url('show'))->assertOk()->assertDontSee('Edit classification');
        $this->get($this->url('edit'))->assertForbidden();
        $this->put($this->url('update'), $this->input() + ['Authorized' => 1, 'Workspace' => 'ho'])->assertForbidden();
        $this->actingAs($this->person(['view', 'edit']))->get($this->url('edit'))->assertOk()->assertDontSee('>Confirm classification</button>', false);
        $this->put($this->url('update'), $this->input())->assertForbidden();
        $this->assertSame(0, $this->auditCount());
    }

    public function test_granted_sites_and_head_office_scope_apply_to_screen_export_and_write(): void
    {
        $user = $this->person(['view', 'edit', 'save'], [self::BRANCH]);
        $this->actingAs($user);
        $page = $this->get(route('app.stockrecon.countingareas', ['branches' => [self::BRANCH, self::OTHER]]))->assertOk();
        $page->assertSee('TEST-Bakery')->assertDontSee('TEST-Other bakery');
        $this->get($this->url('show', self::OTHER))->assertNotFound();
        $this->put($this->url('update', self::OTHER), $this->input())->assertNotFound();
        $csv = $this->get(route('app.grids.extract', ['grid' => 'app.stockrecon.countingareas', 'branches' => [self::OTHER]]))->assertOk();
        $this->assertStringNotContainsString('TEST-Other bakery', $csv->streamedContent());
        $this->get(route('app.stockrecon.countingareas', ['ws' => 'branch', 'branch' => self::BRANCH]))->assertForbidden();
        $this->assertSame(0, $this->auditCount());
    }

    public function test_explicit_confirmation_saves_prior_values_actor_and_reason_and_refuses_stale_forms(): void
    {
        $user = $this->person(['view', 'edit', 'save']);
        $this->actingAs($user);
        $input = $this->input();
        $this->from($this->url('edit'))->put($this->url('update'), array_diff_key($input, ['confirm' => true]))
            ->assertSessionHasErrors('confirm');
        $this->assertNull($this->setting()->ConfirmedAt);
        $this->put($this->url('update'), $input)->assertRedirect($this->url('show'));
        $setting = $this->setting();
        $this->assertEquals(0, $setting->IsPreparedFood);
        $this->assertNotNull($setting->ConfirmedAt);
        $this->assertEquals($user->Id, $setting->ConfirmedBy);
        $audit = $this->db()->table('agora.StockAreaSettingChange')->where('BranchId', self::BRANCH)->first();
        $this->assertEquals(1, $audit->PriorIsPreparedFood);
        $this->assertEquals(0, $audit->NewIsPreparedFood);
        $this->assertNull($audit->PriorConfirmedAt);
        $this->assertEquals($user->Id, $audit->CreatedBy);
        $this->assertSame($input['reason'], $audit->Reason);
        $this->from($this->url('edit'))->put($this->url('update'), $input)->assertSessionHasErrors('refusal');
        $this->assertSame(1, $this->auditCount());
        $this->get($this->url('show'))->assertOk()->assertSee($input['reason']);
        $this->assertSame('Food Items', $this->db()->table('PumpIT.dbo.STK_Area')->where('SSBranchId', self::BRANCH)->where('AreaNo', 801)->value('AreaGroup'));
    }

    public function test_confirmation_can_materialise_a_new_area_and_same_value_confirmation_is_audited(): void
    {
        $this->actingAs($this->person(['view', 'edit', 'save']));
        $this->put($this->url('update'), array_replace($this->input(), ['IsPreparedFood' => 1]))->assertRedirect();
        $this->assertEquals(1, $this->setting()->IsPreparedFood);
        $this->assertNotNull($this->setting()->ConfirmedAt);
        $new = ['IsPreparedFood' => 0, 'ExpectedFood' => 0, 'ExpectedVersion' => 'new', 'reason' => 'TEST-Checked retail storage only', 'confirm' => 1];
        $this->put($this->url('update', self::BRANCH, 802), $new)->assertRedirect();
        $this->assertNotNull($this->db()->table('agora.StockAreaSetting')->where('BranchId', self::BRANCH)->where('AreaNo', 802)->value('ConfirmedAt'));
        $this->assertSame(2, $this->auditCount());
        $this->get(route('app.stockrecon.countingareas', ['branches' => [self::BRANCH], 'f' => ['State' => ['in' => ['Confirmed']], 'AreaNo' => ['q' => '802']]]))
            ->assertOk()->assertSee('TEST-Store')->assertDontSee('TEST-Bakery');
    }

    public function test_the_procedure_refuses_missing_authority_wrong_workspace_ungranted_site_and_no_confirmation(): void
    {
        $user = $this->person(['view', 'edit', 'save'], [self::BRANCH]);
        $base = ['BranchId' => self::BRANCH, 'AreaNo' => 801, 'IsPreparedFood' => 0,
            'ExpectedVersion' => $this->input()['ExpectedVersion'], 'ExpectedFood' => 1,
            'Reason' => 'TEST-Checked the counting area', 'Confirm' => 1, 'UserId' => $user->Id,
            'Authorized' => 1, 'Workspace' => 'ho'];
        foreach ([['Authorized' => 0], ['Workspace' => 'branch'], ['BranchId' => self::OTHER], ['Confirm' => 0], ['UserId' => null], ['AreaNo' => 99999]] as $bad) {
            try {
                app(ProcedureService::class)->write('agora.usp_StockRecon_SaveCountingArea', array_replace($base, $bad));
                $this->fail('The procedure must refuse an unauthorized or unconfirmed change.');
            } catch (AgoraProcException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->assertSame(0, $this->auditCount());
        $this->assertNull($this->setting()->ConfirmedAt);
    }

    public function test_a_menu_restriction_applies_to_the_screen_and_direct_export(): void
    {
        $user = $this->person(['view', 'edit', 'save']);
        $otherMenu = $this->db()->table('agora.MenuItem')->where('RouteName', 'app.stockrecon.index')->value('Id');
        $this->db()->table('agora.UserMenuItem')->insert(['BranchId' => 2, 'UserId' => $user->Id, 'MenuItemId' => $otherMenu]);
        app(MenuAccessService::class)->forget($user->Id);
        $this->actingAs($user)->get(route('app.stockrecon.countingareas'))->assertForbidden();
        $this->get($this->url('show'))->assertForbidden();
        $this->get(route('app.grids.extract', ['grid' => 'app.stockrecon.countingareas']))->assertForbidden();
    }

    /**
     * @param  array<int, string>  $actions
     * @param  array<int, int>  $branches
     */
    private function person(array $actions, array $branches = []): User
    {
        $user = User::query()->acrossBranches()->create([
            'BranchId' => 2, 'EmailAddress' => 'TEST-area-'.Str::uuid().'@agora.invalid', 'UserName' => 'TEST-Area reviewer',
            'IsActive' => true, 'IsLocked' => false, 'Workspace' => 'ho', 'PasswordHash' => 'TEST-only-'.Str::random(24),
        ]);
        $this->users[] = $user;
        foreach ($actions as $action) {
            $permission = $this->db()->table('agora.Permission')->where('Code', 'stockrecon.countingareas.'.$action)->value('Id');
            $this->db()->table('agora.UserPermission')->insert(['BranchId' => 2, 'UserId' => $user->Id, 'PermissionId' => $permission]);
        }
        foreach ($branches as $branch) {
            $this->db()->table('agora.UserBranch')->insert(['BranchId' => $branch, 'UserId' => $user->Id]);
        }
        app(PermissionService::class)->forget($user);
        app(BranchContext::class)->setWorkspace('ho')->setAllowed($branches);

        return $user;
    }

    /** @return array<string, mixed> */
    private function input(): array
    {
        $version = $this->db()->selectOne('SELECT CONVERT(varchar(18), CONVERT(binary(8), RowVer), 1) AS Token FROM agora.StockAreaSetting WHERE BranchId = ? AND AreaNo = 801', [self::BRANCH]);

        return ['IsPreparedFood' => 0, 'ExpectedVersion' => $version->Token, 'ExpectedFood' => 1,
            'reason' => 'TEST-Checked: this area stores packaged retail stock', 'confirm' => 1];
    }

    private function url(string $action, int $branch = self::BRANCH, int $area = 801): string
    {
        return route('app.stockrecon.countingareas.'.$action, compact('branch', 'area'));
    }

    private function setting(): object
    {
        return $this->db()->table('agora.StockAreaSetting')->where('BranchId', self::BRANCH)->where('AreaNo', 801)->first();
    }

    private function auditCount(): int
    {
        return $this->db()->table('agora.StockAreaSettingChange')->whereIn('BranchId', [self::BRANCH, self::OTHER])->count();
    }

    private function cleanUp(): void
    {
        foreach (['StockAreaSettingChange', 'StockAreaSetting'] as $table) {
            $this->db()->table('agora.'.$table)->whereIn('BranchId', [self::BRANCH, self::OTHER])->delete();
        }
        $this->db()->table('PumpIT.dbo.STK_Area')->whereIn('SSBranchId', [self::BRANCH, self::OTHER])->delete();
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }
}
