<?php

namespace Tests\Feature\Reports;

use App\Exceptions\AgoraProcException;
use App\Support\BranchContext;
use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\User;
use Modules\Core\Services\PermissionService;
use Tests\TestCase;

/**
 * Setup → Governance (T027): the master data change log and user activity.
 *
 * The acceptance: changing a category's MinGP through
 * usp_Masters_SaveCategoryBand shows in the Master data changes grid with
 * before/after, the user and the reason. Also an Agora-only change (a site's
 * own setting value, agora.MasterChange), a site master's label (Reports
 * v1__22q), the branch scope, the refusal, the screens and the menu.
 *
 * WRITES THE LOCAL CONTAINER AND PUMPIT STUB ONLY — every connection is pinned
 * to the container and asserted loopback. Its rows are its own: category
 * 'TEST-MCHG ' and till 9902 at branch 13 in the stub, TEST- branch 979 and
 * its setting value in Agora, a TEST- user and that user's activity. All are
 * deleted, with their ledger rows, in tearDown.
 */
class MasterChangesTest extends TestCase
{
    private const BRANCH = 13;

    private const KEY = '5:TEST-MCHG';

    private const TILL = 9902;

    private const SITE = 979;

    private const SETTING = 'cash.cashup.variance_tolerance';

    private ?User $user = null;

    private bool $safe = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessLocalStub('This test writes the PumpIT stub.');
        $local = config('database.connections.'.config('agora.connections.app'));
        foreach (config('agora.connections') as $name) {
            $database = config("database.connections.{$name}.database");
            config(["database.connections.{$name}" => array_replace($local, ['database' => $database])]);
            DB::purge($name);
            $this->assertContains(config("database.connections.{$name}.host"), ['127.0.0.1', 'localhost', '::1']);
        }
        $this->safe = true;
        $this->cleanUp();

        $this->db()->statement("INSERT [PumpIT].dbo.SDK_CategoryGLCode (SSBranchId, POSSystemId, Category, StandardCategory, ProfitCentre,
            IsActive, MINGP, MAXGP, MINDaysCover, MAXDaysCover) VALUES (?, 5, 'TEST-MCHG ', 'Bread', 'Conv Shop', 1, 10.800000000000001, 12, 0, 0)", [self::BRANCH]);
        $this->db()->statement("INSERT [PumpIT].dbo.BRN_Tills (SSBranchId, TillNo, TillDescription, Location) VALUES (?, ?, 'TEST-Till', 'WINBRANCH')",
            [self::BRANCH, self::TILL]);
        $this->db()->table('agora.Branch')->insert([
            'BranchId' => self::SITE, 'Name' => 'TEST-Master changes site', 'IsTrading' => 1, 'IsActive' => 1, 'SortOrder' => self::SITE,
            'Status' => 'Trading', 'POSType' => 'BRANCH', 'IsAdminEntity' => 0, 'CreatedAt' => now(),
        ]);
        $this->user = User::query()->acrossBranches()->create([
            'BranchId' => 2, 'EmailAddress' => 'TEST-master-changes-'.Str::uuid().'@agora.invalid', 'UserName' => 'TEST-Master changes',
            'IsActive' => true, 'IsLocked' => false, 'Workspace' => 'ho', 'PasswordHash' => 'TEST-only-'.Str::random(24),
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->safe) {
            $this->cleanUp();
        }
        parent::tearDown();
    }

    public function test_a_category_min_gp_change_shows_with_before_after_the_user_and_the_reason(): void
    {
        $saved = $this->procedures()->write('agora.usp_Masters_SaveCategoryBand', [
            'BranchId' => self::BRANCH, 'Key' => self::KEY, 'MinGP' => 25, 'MaxGP' => 30, 'MinDaysCover' => 0, 'MaxDaysCover' => 0,
            'IsActive' => 1, 'ExpectedMinGP' => 10.8, 'ExpectedMaxGP' => 12, 'ExpectedMinDaysCover' => 0, 'ExpectedMaxDaysCover' => 0,
            'ExpectedIsActive' => 1, 'Reason' => 'TEST-raise the bread band', 'UserId' => (int) $this->user->Id, 'Authorized' => 1, 'WriteMode' => 'live',
        ]);
        $this->assertSame(1, (int) $saved->Ok, (string) ($saved->Message ?? ''));

        [$rows, $total] = $this->masterChanges(['BranchIds' => (string) self::BRANCH, 'Search' => 'TEST-MCHG']);
        $this->assertSame(2, $total, 'One row per column changed: MINGP and MAXGP, not the three sent unchanged.');
        $pairs = [];
        foreach ($rows as $changed) {
            $pairs[$changed->Field] = [$changed->PriorValue, $changed->NewValue];
        }
        ksort($pairs);
        $this->assertSame(['MAXGP' => ['12', '30'], 'MINGP' => ['10.8', '25']], $pairs);
        $row = $rows->firstWhere('Field', 'MINGP');
        $this->assertSame(
            ['Category band', 'Category band changed', 'MINGP', '10.8', '25', 'MINGP 10.8 → 25', 'TEST-raise the bread band', 'TEST-Master changes', 'PumpIT write-back', 'active', 'SDK_CategoryGLCode', 'MasterWrite'],
            [$row->Entity, $row->Action, $row->Field, $row->PriorValue, $row->NewValue, $row->Change, $row->Reason, $row->ChangedBy, $row->Source, $row->State, $row->PumpItTable, $row->Ledger]
        );
        $this->assertStringContainsString('TEST-MCHG', $row->RowKey);

        // Typed header filters narrow in the procedure: a set on the master, a number on the ledger row, a date on the day.
        $filters = [
            ['column' => 'Entity', 'type' => 'set', 'in' => ['Category band']],
            ['column' => 'LedgerId', 'type' => 'number', 'op' => 'eq', 'value' => (string) $row->LedgerId],
            ['column' => 'ChangedAt', 'type' => 'date', 'op' => 'eq', 'value' => substr((string) $row->ChangedAt, 0, 10)],
        ];
        $this->assertSame(1, $this->masterChanges(['BranchIds' => (string) self::BRANCH, 'FiltersJson' => json_encode($filters)])[1]);
        $this->assertSame(0, $this->masterChanges(['BranchIds' => (string) self::BRANCH, 'Search' => 'TEST-MCHG',
            'FiltersJson' => json_encode([['column' => 'Entity', 'type' => 'set', 'in' => ['Setting']]])])[1]);
        $this->assertSame(1, $this->masterChanges(['BranchIds' => (string) self::BRANCH, 'Search' => 'TEST-MCHG',
            'FiltersJson' => json_encode([['column' => 'Field', 'type' => 'text', 'op' => 'eq', 'value' => 'MAXGP']])])[1]);
        $this->assertSame(0, $this->masterChanges(['BranchIds' => (string) self::BRANCH, 'Search' => 'TEST-MCHG',
            'FiltersJson' => json_encode([['column' => 'Field', 'type' => 'text', 'op' => 'eq', 'value' => 'MINDaysCover']])])[1]);

        // The branch scope: another site picked, or the caller not allowed this one, and it is gone.
        $this->assertSame(0, $this->masterChanges(['BranchIds' => '14', 'Search' => 'TEST-MCHG'])[1]);
        $this->assertSame(0, $this->masterChanges(['AllowedBranchIds' => '14', 'Search' => 'TEST-MCHG'])[1]);
        $this->assertSame(2, $this->masterChanges(['AllowedBranchIds' => '14,'.self::BRANCH, 'Search' => 'TEST-MCHG'])[1]);

        // Put back, and the same row reads as reversed.
        $write = $this->db()->table('agora.MasterWrite')->where('Id', $row->LedgerId)->value('WriteId');
        $this->procedures()->write('agora.usp_Masters_ReverseWrite', ['BranchId' => self::BRANCH, 'WriteId' => $write,
            'Reason' => 'TEST-put the band back', 'UserId' => (int) $this->user->Id, 'AuthorizedEntities' => 'CategoryGl', 'WriteMode' => 'live']);
        $this->assertSame(['reversed'], $this->masterChanges(['BranchIds' => (string) self::BRANCH, 'Search' => 'TEST-MCHG'])[0]
            ->pluck('State')->unique()->values()->all());
    }

    public function test_an_agora_only_setting_change_shows_from_master_change(): void
    {
        $saved = $this->procedures()->write('agora.usp_Core_SaveSetting', [
            'SettingKey' => self::SETTING, 'BranchId' => self::SITE, 'Mode' => 'create', 'Value' => '250',
            'Reason' => 'TEST-this site runs a bigger float', 'UserId' => (int) $this->user->Id, 'Authorized' => 1,
        ]);
        $this->assertSame(1, (int) $saved->Ok, (string) ($saved->Message ?? ''));

        [$rows, $total] = $this->masterChanges(['BranchIds' => (string) self::SITE]);
        $this->assertSame(1, $total);
        $row = $rows->sole();
        $this->assertSame(
            ['TEST-Master changes site', 'Setting', 'Site value added', self::SETTING, 'Override', null, '250.00', 'Override blank → 250.00', 'TEST-this site runs a bigger float', 'TEST-Master changes', 'Agora', 'MasterChange'],
            [$row->BranchName, $row->Entity, $row->Action, $row->RowKey, $row->Field, $row->PriorValue, $row->NewValue, $row->Change, $row->Reason, $row->ChangedBy, $row->Source, $row->Ledger]
        );
        $this->assertSame(1, $this->masterChanges(['BranchIds' => (string) self::SITE,
            'FiltersJson' => json_encode([['column' => 'Source', 'type' => 'set', 'in' => ['Agora']]])])[1]);
        $this->assertSame(0, $this->masterChanges(['BranchIds' => (string) self::SITE,
            'FiltersJson' => json_encode([['column' => 'Source', 'type' => 'set', 'in' => ['PumpIT write-back']]])])[1]);
    }

    public function test_a_site_master_reads_by_its_own_name_not_its_entity_code(): void
    {
        $this->procedures()->write('agora.usp_Masters_SaveTill', [
            'BranchId' => self::BRANCH, 'Key' => (string) self::TILL, 'TillDescription' => 'TEST-Till front', 'ExpectedTillDescription' => 'TEST-Till',
            'Reason' => 'TEST-the front till', 'UserId' => (int) $this->user->Id, 'Authorized' => 1, 'WriteMode' => 'live',
        ]);
        $id = $this->db()->table('agora.MasterWrite')->where('BranchId', self::BRANCH)->where('Entity', 'Till')->where('RowKey', (string) self::TILL)->value('Id');
        $audit = $this->db()->selectOne("SELECT Action FROM agora.vw_PumpItChange WHERE Ledger = 'MasterWrite' AND LedgerId = ?", [$id]);
        $this->assertSame('Till changed', $audit->Action, 'Reports v1__22q: the audit trail names the master.');

        $row = $this->masterChanges(['BranchIds' => (string) self::BRANCH, 'Search' => 'TEST-the front till'])[0]->sole();
        $this->assertSame(['Till', 'Till changed', 'TillDescription', 'TEST-Till', 'TEST-Till front'],
            [$row->Entity, $row->Action, $row->Field, $row->PriorValue, $row->NewValue]);
    }

    public function test_the_procedures_refuse_without_the_permission(): void
    {
        foreach (['agora.usp_Reports_GridMasterChanges', 'agora.usp_Reports_GridUserActivity'] as $procedure) {
            try {
                $this->procedures()->callSets($procedure, ['Authorized' => 0]);
                $this->fail("{$procedure} must refuse without @Authorized.");
            } catch (AgoraProcException $e) {
                $this->assertSame('DENIED', $e->code());
            }
        }
    }

    public function test_user_activity_lists_a_sign_in_with_the_person(): void
    {
        $this->procedures()->write('usp_Core_LogActivity', ['BranchId' => 2, 'UserId' => (int) $this->user->Id, 'Activity' => 'signin',
            'Detail' => 'TEST-sign-in for the grid', 'IpAddress' => '127.0.0.1', 'UserAgent' => 'TEST-agent', 'OccurredAt' => now()->format('Y-m-d H:i:s')]);

        $sets = $this->procedures()->callSets('agora.usp_Reports_GridUserActivity', ['Authorized' => 1,
            'FiltersJson' => json_encode([['column' => 'UserName', 'type' => 'text', 'op' => 'eq', 'value' => 'TEST-Master changes']])]);
        $this->assertSame(1, (int) $sets[1]->first()->TotalRows);
        $row = $sets[0]->sole();
        $this->assertSame(['Signed in', 'signin', 'TEST-sign-in for the grid', '127.0.0.1', 'Zululand Petroleum'],
            [$row->Activity, $row->ActivityCode, $row->Detail, $row->IpAddress, $row->BranchName]);

        $refused = $this->procedures()->callSets('agora.usp_Reports_GridUserActivity', ['Authorized' => 1, 'Search' => 'TEST-sign-in for the grid',
            'FiltersJson' => json_encode([['column' => 'Activity', 'type' => 'set', 'in' => ['Sign-in refused']]])]);
        $this->assertSame(0, (int) $refused[1]->first()->TotalRows);
    }

    public function test_both_screens_render_under_setup_governance_behind_their_permissions(): void
    {
        $this->grant(['reports.master-changes.view', 'reports.user-activity.view']);
        $this->actingAs($this->user);
        app(BranchContext::class)->setWorkspace('ho')->setAllowed([]);

        $this->get(route('app.setup.master-changes.index'))->assertOk()
            ->assertSee('Master data changes')->assertSee('agora.usp_Reports_GridMasterChanges')
            ->assertSee('TEST-Master changes site')                                   // the branches selector offers the sites
            ->assertSee('-extract"', false)                                           // the extract drawer
            ->assertSee('Before')->assertSee('After')->assertSee('Why');
        $this->get(route('app.setup.user-activity.index'))->assertOk()
            ->assertSee('User activity')->assertSee('agora.usp_Reports_GridUserActivity');

        $menu = $this->db()->select("SELECT i.Path, i.RouteName, i.PermissionCode FROM agora.MenuItem i JOIN agora.MenuSection s ON s.Id = i.SectionId
            WHERE s.Workspace = 'ho' AND s.Code = 'setup' AND i.Path LIKE 'governance/%' ORDER BY i.SortOrder");
        $this->assertSame([
            ['governance/master-changes', 'app.setup.master-changes.index', 'reports.master-changes.view'],
            ['governance/user-activity', 'app.setup.user-activity.index', 'reports.user-activity.view'],
        ], array_map(fn ($m) => [$m->Path, $m->RouteName, $m->PermissionCode], $menu), 'GovernanceReportsSeeder has run here.');
    }

    public function test_without_the_permission_the_screens_are_forbidden(): void
    {
        $this->grant(['reports.user-activity.view']);
        $this->actingAs($this->user);
        app(BranchContext::class)->setWorkspace('ho')->setAllowed([]);

        $this->get(route('app.setup.master-changes.index'))->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{0: Collection<int, object>, 1: int}
     */
    private function masterChanges(array $parameters): array
    {
        $sets = $this->procedures()->callSets('agora.usp_Reports_GridMasterChanges', $parameters + ['Authorized' => 1]);

        return [$sets[0], (int) $sets[1]->first()->TotalRows];
    }

    /** @param  array<int, string>  $codes */
    private function grant(array $codes): void
    {
        foreach ($codes as $code) {
            $permission = $this->db()->table('agora.Permission')->where('Code', $code)->value('Id');
            $this->assertNotNull($permission, "{$code} is seeded (GovernanceReportsSeeder)");
            $this->db()->table('agora.UserPermission')->insert(['BranchId' => 2, 'UserId' => $this->user->Id, 'PermissionId' => $permission]);
        }
        app(PermissionService::class)->forget($this->user);
    }

    private function cleanUp(): void
    {
        $this->db()->table('agora.MasterWrite')->where('BranchId', self::BRANCH)->where('Entity', 'CategoryGl')->where('RowKey', self::KEY)->delete();
        $this->db()->table('agora.MasterWrite')->where('BranchId', self::BRANCH)->where('Entity', 'Till')->where('RowKey', (string) self::TILL)->delete();
        $this->db()->statement("DELETE FROM [PumpIT].dbo.SDK_CategoryGLCode WHERE SSBranchId = ? AND POSSystemId = 5 AND Category = 'TEST-MCHG '", [self::BRANCH]);
        $this->db()->statement('DELETE FROM [PumpIT].dbo.BRN_Tills WHERE SSBranchId = ? AND TillNo = ?', [self::BRANCH, self::TILL]);
        $this->db()->table('agora.MasterChange')->where('BranchId', self::SITE)->delete();
        $this->db()->table('agora.Setting')->where('BranchId', self::SITE)->delete();
        $this->db()->table('agora.Branch')->where('BranchId', self::SITE)->delete();

        $users = User::query()->acrossBranches()->where('EmailAddress', 'like', 'TEST-master-changes-%')->pluck('Id');
        foreach ($users as $id) {
            foreach (['UserActivity', 'UserPermission', 'UserBranch', 'UserGridColumn', 'UserMenuItem'] as $table) {
                $this->db()->table('agora.'.$table)->where('UserId', $id)->delete();
            }
            User::query()->acrossBranches()->where('Id', $id)->forceDelete();
        }
    }

    private function procedures(): ProcedureService
    {
        return app(ProcedureService::class);
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }
}
