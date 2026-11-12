<?php

namespace Tests\Feature\People;

use App\Support\BranchContext;
use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\User;
use Modules\Core\Services\PermissionService;
use Tests\TestCase;

/**
 * The people master (T023): agora.vw_PeopleEmployee / vw_PeoplePost /
 * vw_PeopleEmployeeArea over PumpIT's BRN_Employee family, and
 * Setup → People and assets → Employees, Employee posts and Employee areas.
 *
 * Runs against the local container only, with every estate connection pinned
 * to it. The fixture is written into the LOCAL PumpIT stub at two TEST-
 * branches (961, 962) that are not in SS_Branch, and is removed in tearDown;
 * the customer's PumpIT is never touched — the module does not write it at
 * all, which the last test checks against the deployed procedure text.
 */
class EmployeeMasterTest extends TestCase
{
    private const BRANCH = 961;

    private const OTHER = 962;

    /** Code => [first name, surname, post, active]. */
    private const PEOPLE = [
        'TPE01' => ['TEST-Thandi', 'Zulu', 18, 1],
        'TPE02' => ['TEST-Sipho', 'Mkhize', 18, 1],
        'TPE03' => ['TEST-Lindiwe', 'Ndlovu', 46, 1],
        'TPE04' => ['TEST-Bongani', 'Dlamini', 18, 0],
        'PUMPIT' => ['PUMPIT', 'System', 46, 1],
    ];

    private const DAY = '2026-10-15';

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
        $this->fixture();
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

    public function test_the_employee_view_is_one_row_per_person_composes_the_name_and_keeps_the_parts(): void
    {
        $fanout = $this->db()->selectOne('SELECT COUNT(*) AS Rows, COUNT(DISTINCT CONCAT(BranchId, N\'|\', EmployeeCode)) AS Keys
            FROM agora.vw_PeopleEmployee');
        $source = $this->db()->selectOne('SELECT COUNT(*) AS Rows FROM PumpIT.dbo.BRN_Employee');
        $this->assertSame((int) $source->Rows, (int) $fanout->Rows, 'The post join does not fan the employees out.');
        $this->assertSame((int) $fanout->Rows, (int) $fanout->Keys);

        $row = $this->db()->selectOne('SELECT * FROM agora.vw_PeopleEmployee WHERE BranchId = ? AND EmployeeCode = ?', [self::BRANCH, 'TPE01']);
        $this->assertSame('Zulu TEST-Thandi', $row->EmployeeName, 'Surname FirstName, as PumpIT writes it.');
        $this->assertSame(['TEST-Thandi', 'Zulu', 'Ms', 'T'], [$row->FirstName, $row->Surname, $row->Title, $row->Initials]);
        $this->assertSame('TEST-Fuel Cashier', $row->PostDescription);
        $this->assertNull($row->DateDepart, 'FoxPro\'s zero date reads as no date.');

        // A person with no name at all is named by the code, never an empty link.
        $this->assertSame('TPE05', $this->db()->selectOne('SELECT EmployeeName FROM agora.vw_PeopleEmployee WHERE BranchId = ? AND EmployeeCode = ?',
            [self::OTHER, 'TPE05'])->EmployeeName);

        // The personal numbers and the plaintext password are not in the view at all.
        $columns = collect($this->db()->select("SELECT name FROM sys.columns WHERE object_id = OBJECT_ID('agora.vw_PeopleEmployee')"))->pluck('name');
        foreach (['IDNo', 'Password', 'XBankAccNo', 'XTaxNo', 'XCellNo', 'XDateBirth', 'XPhoto'] as $secret) {
            $this->assertNotContains($secret, $columns);
        }

        $this->assertSame('Inactive', $this->db()->selectOne('SELECT Status FROM agora.vw_PeopleEmployee WHERE BranchId = ? AND EmployeeCode = ?',
            [self::BRANCH, 'TPE04'])->Status, 'An inactive person is in the view, not filtered out.');
    }

    public function test_the_list_opens_on_the_active_people_by_branch_and_filters_by_post(): void
    {
        $this->actingAs($this->person(['employees.view']));
        $at = fn (array $query = []) => route('app.setup.employees.index', ['branches' => [self::BRANCH]] + $query);

        $this->get($at())->assertOk()->assertSee('usp_People_GridEmployees')
            ->assertSee('Zulu TEST-Thandi')->assertSee('Mkhize TEST-Sipho')->assertSee('Ndlovu TEST-Lindiwe')
            ->assertDontSee('Dlamini TEST-Bongani')->assertDontSee('System PUMPIT')
            ->assertSee('TEST-Fuel Cashier')->assertDontSee('Edit');

        $post = ['f' => ['Post' => ['q' => 'TEST-Fuel Cashier', 'eq' => 1]]];
        $this->get($at($post))->assertOk()->assertSee('Zulu TEST-Thandi')->assertSee('Mkhize TEST-Sipho')->assertDontSee('Ndlovu TEST-Lindiwe');

        $this->get($at(['status' => 'inactive']))->assertOk()->assertSee('Dlamini TEST-Bongani')->assertDontSee('Zulu TEST-Thandi');
        $this->get($at(['status' => 'all'] + $post))->assertOk()->assertSee('Dlamini TEST-Bongani')->assertSee('Zulu TEST-Thandi');
        $this->get($at(['system' => 1]))->assertOk()->assertSee('System PUMPIT');

        // The extract is what the screen shows: the same branch, post and status.
        $csv = $this->get(route('app.grids.extract', ['grid' => 'app.setup.employees', 'branches' => [self::BRANCH]] + $post))
            ->assertOk()->streamedContent();
        $this->assertStringContainsString('Zulu TEST-Thandi', $csv);
        $this->assertStringNotContainsString('Ndlovu TEST-Lindiwe', $csv);
        $this->assertStringNotContainsString('Dlamini TEST-Bongani', $csv);
        $this->assertStringNotContainsString('TEST-Other site', $csv, 'Only the branch in scope.');
    }

    public function test_an_inactive_employee_stays_visible_on_the_shifts_they_worked(): void
    {
        $this->actingAs($this->person(['employees.view']));
        $url = route('app.setup.employees.show', ['branch' => self::BRANCH, 'code' => 'TPE04']);

        $this->get($url)->assertOk()->assertSee('Dlamini TEST-Bongani')->assertSee('Inactive')
            ->assertSee('Cashup')->assertSee('Stock count')->assertSee('TEST-Fuel bay')->assertSee('Shifts worked');
        $this->get($url, ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()
            ->assertSee('Counting areas')->assertDontSee('<!DOCTYPE', false);

        // And on the views every other module reads a historic shift through:
        // the cashier of the cashup and the name on the count still resolve.
        $this->assertSame('Dlamini TEST-Bongani', $this->db()->selectOne(
            'SELECT e.EmployeeName FROM agora.vw_DailyBanking d JOIN agora.vw_Employee e ON e.BranchId = d.BranchId AND e.EmployeeCode = d.EmployeeCode
             WHERE d.BranchId = ? AND d.TransactionDate = ?', [self::BRANCH, self::DAY])->EmployeeName);
        $this->assertStringContainsString('Dlamini TEST-Bongani', $this->db()->selectOne(
            'SELECT EmployeeNames FROM agora.vw_StockReconShiftEmployee WHERE BranchId = ? AND TransactionDate = ? AND AreaNo = 7',
            [self::BRANCH, self::DAY])->EmployeeNames);
    }

    public function test_each_screen_has_its_own_permission_and_a_site_outside_the_grant_is_not_found(): void
    {
        $this->actingAs($this->person([]))->get(route('app.setup.employees.index'))->assertRedirect(route('app.access-required'));

        $this->actingAs($this->person(['employees.view']));
        $this->get(route('app.setup.employee-posts.index'))->assertForbidden();
        $this->get(route('app.setup.employee-areas.index'))->assertForbidden();

        // Granted the other site only: the first site's people are not there.
        $restricted = $this->person(['employees.view'], [self::OTHER]);
        $this->actingAs($restricted);
        $this->get(route('app.setup.employees.show', ['branch' => self::BRANCH, 'code' => 'TPE01']))->assertNotFound();
        $this->get(route('app.setup.employees.show', ['branch' => self::OTHER, 'code' => 'TPE05']))->assertOk();
        $this->get(route('app.setup.employees.index', ['branches' => [self::BRANCH]]))->assertOk()->assertDontSee('Zulu TEST-Thandi');
    }

    public function test_posts_count_their_holders_and_the_next_post_is_the_legacy_rule(): void
    {
        $this->actingAs($this->person(['posts.view', 'employees.view']));

        $this->get(route('app.setup.employee-posts.index', ['branches' => [self::BRANCH]]))->assertOk()
            ->assertSee('usp_People_GridPosts')->assertSee('TEST-Fuel Cashier')->assertSee('TEST-Site Manager')
            ->assertSee('next post at TEST-People site would be number 47', false);

        $rows = collect(app(ProcedureService::class)->call('agora.usp_People_GridPosts', ['BranchIds' => (string) self::BRANCH]))
            ->keyBy('PostNo');
        $this->assertSame([2, 3], [(int) $rows[18]->ActiveEmployees, (int) $rows[18]->TotalEmployees], 'Fuel Cashier: two active of three.');
        $this->assertSame([1, 1], [(int) $rows[46]->ActiveEmployees, (int) $rows[46]->TotalEmployees], 'The PUMPIT account is not a holder.');

        // sp_GetNextEmployeePost, rule for rule, at every branch in the stub
        // and at one with no posts at all.
        $legacy = collect($this->db()->select('SELECT SSBranchId AS BranchId, MAX(PostNo) + 1 AS NextPostNo FROM PumpIT.dbo.BRN_EmployeePost GROUP BY SSBranchId'));
        $this->assertNotEmpty($legacy);
        foreach ($legacy as $branch) {
            $this->assertSame((int) $branch->NextPostNo, $this->nextPost((int) $branch->BranchId), "Branch {$branch->BranchId}");
        }
        $this->assertSame(1, $this->nextPost(self::OTHER));
    }

    public function test_employee_areas_keep_inactive_people_and_unknown_codes_marked(): void
    {
        $this->actingAs($this->person(['areas.view', 'employees.view']));
        $at = fn (array $query = []) => route('app.setup.employee-areas.index', ['branches' => [self::BRANCH]] + $query);

        $this->get($at())->assertOk()->assertSee('usp_People_GridEmployeeAreas')->assertSee('TEST-Fuel bay')
            ->assertSee('Zulu TEST-Thandi')->assertDontSee('Dlamini TEST-Bongani')->assertDontSee('TPX99');

        $this->get($at(['status' => 'all']))->assertOk()->assertSee('Dlamini TEST-Bongani')
            ->assertSee('TPX99')->assertSee('Not on the employee list');

        $rows = collect(app(ProcedureService::class)->call('agora.usp_People_GridEmployeeAreas',
            ['BranchIds' => (string) self::BRANCH, 'Status' => 'all']));
        $this->assertCount(4, $rows);
        $this->assertSame('Not on the employee list', $rows->firstWhere('EmployeeCode', 'TPX99')->Status);
    }

    public function test_the_readers_read_and_only_the_named_writers_touch_pumpit(): void
    {
        $procedures = collect($this->db()->select(
            "SELECT o.name, m.definition FROM sys.sql_modules m JOIN sys.objects o ON o.object_id = m.object_id
             WHERE o.schema_id = SCHEMA_ID('agora') AND o.name LIKE 'usp[_]People[_]%'"))->keyBy('name');
        $writers = ['usp_People_AddPost', 'usp_People_ReverseWrite', 'usp_People_SaveEmployee', 'usp_People_SaveEmployeeAreas'];
        $readers = ['usp_People_GetEmployee', 'usp_People_GetEmployeeChanges', 'usp_People_GetEmployeeForm', 'usp_People_GridEmployeeAreas',
            'usp_People_GridEmployees', 'usp_People_GridPosts', 'usp_People_NextPost'];
        $this->assertSame(collect([...$readers, ...$writers])->sort()->values()->all(), $procedures->keys()->sort()->values()->all());

        $strip = fn (string $name) => (string) preg_replace('~/\*.*?\*/~s', '', (string) $procedures[$name]->definition);
        foreach ($readers as $name) {
            $this->assertDoesNotMatchRegularExpression('~\b(INSERT\s+(INTO\s+)?(?!#)\S*(PumpIT|agora)|UPDATE\s+\S*(PumpIT|agora)|DELETE\s+(FROM\s+)?\S*(PumpIT|agora)|MERGE)\b~i',
                $strip($name), "{$name} reads only.");
        }

        // The writers name no PumpIT table but the three of the named write
        // (CLAUDE.md rule 1, PM decision 01a1ceba-4d74) and STK_Area, which
        // they read to check an area exists.
        foreach ($writers as $name) {
            preg_match_all('~\[PumpIT\]\.dbo\.(\w+)~i', $strip($name), $m);
            $this->assertEmpty(array_diff(array_unique($m[1]), ['BRN_Employee', 'BRN_EmployeeArea', 'BRN_EmployeePost', 'STK_Area']),
                "{$name} reaches only the named tables.");
            $this->assertStringContainsString('agora.PeopleWrite', $strip($name), "{$name} keeps its ledger.");
        }
    }

    private function nextPost(int $branch): int
    {
        return (int) app(ProcedureService::class)->call('agora.usp_People_NextPost', ['BranchId' => $branch])->first()->NextPostNo;
    }

    private function fixture(): void
    {
        foreach ([self::BRANCH => 'TEST-People site', self::OTHER => 'TEST-Other site'] as $id => $name) {
            $this->db()->table('agora.Branch')->insert([
                'BranchId' => $id, 'Name' => $name, 'IsTrading' => 1, 'IsActive' => 1, 'SortOrder' => $id,
                'Status' => 'Trading', 'POSType' => 'BRANCH', 'IsAdminEntity' => 0, 'CreatedAt' => now(),
            ]);
        }

        $pumpit = fn (string $table) => $this->db()->table('PumpIT.dbo.'.$table);
        $pumpit('BRN_EmployeePost')->insert([
            ['SSBranchId' => self::BRANCH, 'PostNo' => 18, 'PostDescription' => 'TEST-Fuel Cashier', 'StockControl' => 1],
            ['SSBranchId' => self::BRANCH, 'PostNo' => 46, 'PostDescription' => 'TEST-Site Manager', 'StockControl' => 1],
        ]);
        foreach (self::PEOPLE as $code => [$first, $surname, $post, $active]) {
            $pumpit('BRN_Employee')->insert([
                'SSBranchId' => self::BRANCH, 'EmployeeCode' => $code, 'Title' => 'Ms', 'Initials' => substr($first, 5, 1),
                'FirstName' => $first, 'Surname' => $surname, 'NickName' => null, 'IsActive' => $active, 'IsPumpAttendant' => 0,
                'PostNo' => $post, 'DateJoined' => '2023-06-01', 'DateDepart' => '1899-12-30',
            ]);
        }
        $pumpit('BRN_Employee')->insert([
            'SSBranchId' => self::OTHER, 'EmployeeCode' => 'TPE05', 'FirstName' => null, 'Surname' => '', 'IsActive' => 1,
            'IsPumpAttendant' => 0, 'PostNo' => 1,
        ]);

        $pumpit('STK_Area')->insert(['SSBranchId' => self::BRANCH, 'AreaNo' => 7, 'AreaDescription' => 'TEST-Fuel bay',
            'AreaGroup' => 'TEST-Group', 'DayShift' => 1, 'AfternoonShift' => 0, 'NightShift' => 1, 'ShowReport' => 1, 'IsCaptureWaste' => 0]);
        foreach (['TPE01', 'TPE02', 'TPE04', 'TPX99'] as $code) {
            $pumpit('BRN_EmployeeArea')->insert(['BranchId' => self::BRANCH, 'EmployeeCode' => $code, 'AreaNo' => 7]);
        }

        // The shifts the inactive person worked: a cashup as the cashier, and a
        // stock count they were signed on to.
        $at = self::DAY.' 00:00:00';
        $pumpit('BRN_DailyBanking')->insert([
            'SSBranchId' => self::BRANCH, 'TillNo' => 41, 'ShiftNo' => 1, 'TransactionDate' => $at, 'EmployeeCode' => 'TPE04',
            'ClosingBalance' => 100, 'Posted' => 0, 'Imported' => 0, 'CreateDateTime' => $at,
        ]);
        $pumpit('STK_StockReconEmployees')->insert(['SSBranchId' => self::BRANCH, 'TransactionDate' => $at, 'ShiftNo' => 1,
            'AreaNo' => 7, 'EmployeeCode' => 'TPE04']);
    }

    /**
     * @param  array<int, string>  $actions  resource.action under people
     * @param  array<int, int>  $branches  the sites granted; none means every site
     */
    private function person(array $actions, array $branches = []): User
    {
        $user = User::query()->acrossBranches()->create([
            'BranchId' => 2, 'EmailAddress' => 'TEST-people-'.Str::uuid().'@agora.invalid', 'UserName' => 'TEST-People master',
            'IsActive' => true, 'IsLocked' => false, 'Workspace' => 'ho', 'PasswordHash' => 'TEST-only-'.Str::random(24),
        ]);
        $this->users[] = $user;
        foreach ($actions as $action) {
            $permission = $this->db()->table('agora.Permission')->where('Code', 'people.'.$action)->value('Id');
            $this->assertNotNull($permission, "people.{$action} is seeded");
            $this->db()->table('agora.UserPermission')->insert(['BranchId' => 2, 'UserId' => $user->Id, 'PermissionId' => $permission]);
        }
        foreach ($branches as $branch) {
            $this->db()->table('agora.UserBranch')->insert(['BranchId' => $branch, 'UserId' => $user->Id]);
        }
        app(PermissionService::class)->forget($user);
        app(BranchContext::class)->setWorkspace('ho')->setAllowed($branches);

        return $user;
    }

    private function cleanUp(): void
    {
        $branches = [self::BRANCH, self::OTHER];
        $this->db()->table('PumpIT.dbo.STK_StockReconEmployees')->whereIn('SSBranchId', $branches)->delete();
        $this->db()->table('PumpIT.dbo.BRN_DailyBanking')->whereIn('SSBranchId', $branches)->delete();
        $this->db()->table('PumpIT.dbo.BRN_EmployeeArea')->whereIn('BranchId', $branches)->delete();
        $this->db()->table('PumpIT.dbo.STK_Area')->whereIn('SSBranchId', $branches)->delete();
        $this->db()->table('PumpIT.dbo.BRN_Employee')->whereIn('SSBranchId', $branches)->delete();
        $this->db()->table('PumpIT.dbo.BRN_EmployeePost')->whereIn('SSBranchId', $branches)->delete();
        $this->db()->table('agora.Branch')->whereIn('BranchId', $branches)->delete();
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }
}
