<?php

namespace Tests\Feature\People;

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
 * The people master's named write (T023; CLAUDE.md rule 1, PM decision
 * 01a1ceba-4d74): usp_People_SaveEmployee, usp_People_SaveEmployeeAreas,
 * usp_People_AddPost and usp_People_ReverseWrite, their ledger
 * agora.PeopleWrite, and the screens behind people.employees.edit,
 * people.areas.edit and people.posts.save.
 *
 * LOCAL CONTAINER ONLY. Every estate connection is pinned to it and asserted
 * local before anything is written, so "PumpIT" here is the local stub. The
 * fixture lives at two TEST- branches (967, 968) that are not in SS_Branch
 * and is removed in tearDown, ledger rows included.
 */
class EmployeeWriteTest extends TestCase
{
    private const BRANCH = 967;

    private const OTHER = 968;

    private const REASON = 'TEST-confirmed with the site manager';

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
        config(['people.write_mode' => 'live']);
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

    public function test_an_employee_edit_writes_the_three_columns_keeps_the_priors_and_is_put_back(): void
    {
        $this->actingAs($this->person(['employees.view', 'employees.edit']));
        // The write-back is confirmed first, naming the table, the person and
        // each field (Ryan, 12 Nov 2026) — the dialog's text is built from these.
        $this->get($this->url('edit'))->assertOk()->assertSee('name="ExpectedPostNo"', false)->assertSee('TEST-Site Manager')
            ->assertDontSee('Save areas')->assertSee('data-confirm="Write this to PumpIT?"', false)
            ->assertSee('data-diff="PumpIT BRN_Employee · TPW01 TEST-Surname TEST-Thandi', false)
            ->assertSee('data-diff-field="PostNo" data-diff-old="18"', false);

        $this->put($this->url('update'), $this->employeeInput(['IsActive' => 0, 'PostNo' => 46, 'NickName' => 'TEST-Tee']))
            ->assertRedirect($this->url('edit'));

        $row = $this->employee('TPW01');
        $this->assertSame([0, 46, 'TEST-Tee'], [(int) $row->IsActive, (int) $row->PostNo, $row->NickName]);
        $this->assertSame('TEST-Surname', $row->Surname, 'Only the three named columns move.');

        $ledger = $this->ledger()->keyBy('Field');
        $this->assertSame(['IsActive', 'PostNo', 'NickName'], $ledger->keys()->all());
        $this->assertSame(['1', '0'], [$ledger['IsActive']->PriorValue, $ledger['IsActive']->NewValue]);
        $this->assertSame(['18', '46'], [$ledger['PostNo']->PriorValue, $ledger['PostNo']->NewValue]);
        $this->assertSame([null, 'TEST-Tee'], [$ledger['NickName']->PriorValue, $ledger['NickName']->NewValue]);
        $this->assertSame(['live', 'active', self::REASON], [$ledger['IsActive']->WriteMode, $ledger['IsActive']->State, $ledger['IsActive']->Reason]);
        $this->assertSame(1, $ledger->pluck('WriteId')->unique()->count(), 'One press, one WriteId.');

        // In the audit trail, one row per ledger row.
        $audit = collect($this->db()->select("SELECT * FROM agora.vw_PumpItChange WHERE Ledger = 'PeopleWrite' AND BranchId = ?", [self::BRANCH]));
        $this->assertCount(3, $audit);
        $this->assertSame(['People', 'BRN_Employee'], [$audit->first()->Module, $audit->first()->PumpItTable]);
        $this->assertContains('PostNo 18 → 46', $audit->pluck('Change')->all());

        // The employee's page lists the press and offers it back.
        $writeId = (string) $ledger->first()->WriteId;
        $this->get($this->url('show'))->assertOk()->assertSee('Changes written to PumpIT')->assertSee('Put back')->assertSee(self::REASON)
            ->assertSee('Post: 46 → 18', false)->assertSee('Status: Inactive → Active', false);

        $this->post($this->reverseUrl($writeId), ['Reason' => 'TEST-entered against the wrong person'])->assertRedirect($this->url('show'));
        $row = $this->employee('TPW01');
        $this->assertSame([1, 18, null], [(int) $row->IsActive, (int) $row->PostNo, $row->NickName], 'Back exactly as it was.');
        $this->assertSame(['reversed'], $this->ledger()->pluck('State')->unique()->values()->all());
        $this->assertSame(['reversed'], collect($this->db()->select("SELECT State FROM agora.vw_PumpItChange WHERE Ledger = 'PeopleWrite' AND BranchId = ?",
            [self::BRANCH]))->pluck('State')->unique()->values()->all());

        // Twice is refused.
        $this->assertRefused('ALREADY_REVERSED', fn () => $this->proc('agora.usp_People_ReverseWrite', [
            'BranchId' => self::BRANCH, 'WriteId' => $writeId, 'Reason' => self::REASON, 'UserId' => 1,
            'AuthorizedEmployee' => 1, 'AuthorizedAreas' => 1, 'WriteMode' => 'live']));
    }

    public function test_a_stale_form_is_refused_and_a_moved_row_is_not_put_back(): void
    {
        $this->actingAs($this->person(['employees.view', 'employees.edit']));

        // PumpIT changed the post after the form was drawn.
        $this->db()->table('PumpIT.dbo.BRN_Employee')->where('SSBranchId', self::BRANCH)->where('EmployeeCode', 'TPW01')->update(['PostNo' => 46]);
        $this->from($this->url('edit'))->put($this->url('update'), $this->employeeInput(['NickName' => 'TEST-Tee']))
            ->assertRedirect($this->url('edit'))->assertSessionHasErrors('refusal');
        $this->assertNull($this->employee('TPW01')->NickName);
        $this->assertCount(0, $this->ledger());

        // A post the site does not have.
        $this->from($this->url('edit'))->put($this->url('update'), $this->employeeInput(['ExpectedPostNo' => 46, 'PostNo' => 99]))
            ->assertSessionHasErrors('refusal');
        // A reason is required.
        $this->from($this->url('edit'))->put($this->url('update'), $this->employeeInput(['ExpectedPostNo' => 46, 'Reason' => 'short']))
            ->assertSessionHasErrors('Reason');
        $this->assertCount(0, $this->ledger());

        // Saved, then changed again in PumpIT: the reversal would undo that too, so it is refused.
        $this->put($this->url('update'), $this->employeeInput(['ExpectedPostNo' => 46, 'NickName' => 'TEST-Tee']))->assertRedirect();
        $writeId = (string) $this->ledger()->first()->WriteId;
        $this->db()->table('PumpIT.dbo.BRN_Employee')->where('SSBranchId', self::BRANCH)->where('EmployeeCode', 'TPW01')->update(['NickName' => 'TEST-PumpIT']);
        $this->from($this->url('show'))->post($this->reverseUrl($writeId), ['Reason' => self::REASON])->assertSessionHasErrors('refusal');
        $this->assertSame('TEST-PumpIT', $this->employee('TPW01')->NickName);
        $this->assertSame(['active'], $this->ledger()->pluck('State')->unique()->values()->all());
    }

    public function test_areas_are_added_and_removed_with_the_deleted_row_kept_and_put_back(): void
    {
        $this->actingAs($this->person(['employees.view', 'areas.edit']));
        $this->get($this->url('edit'))->assertOk()->assertSee('Save areas')->assertDontSee('Save employee')
            ->assertSee('TEST-Fuel bay')->assertSee('TEST-Shop');

        // Holds 7; asks for 8 instead.
        $this->put($this->url('areas.update'), ['Areas' => [8], 'ExpectedAreas' => '[7]', 'AreaReason' => self::REASON])
            ->assertRedirect($this->url('edit'));
        $this->assertSame([8], $this->areas('TPW01'));

        $ledger = $this->ledger()->keyBy('Verb');
        $this->assertSame(['area-remove', 'area-add'], $ledger->keys()->all());
        $removed = json_decode((string) $ledger['area-remove']->RowJson, true);
        $this->assertSame(['TEST1', 7], [$removed['MIST_EOD'], (int) $removed['AreaNo']], 'The whole deleted row is kept.');

        // The form again is stale: it still believes the person holds 7.
        $this->from($this->url('edit'))->put($this->url('areas.update'), ['Areas' => [], 'ExpectedAreas' => '[7]', 'AreaReason' => self::REASON])
            ->assertSessionHasErrors('refusal');
        // An area the site does not count.
        $this->from($this->url('edit'))->put($this->url('areas.update'), ['Areas' => [8, 99], 'ExpectedAreas' => '[8]', 'AreaReason' => self::REASON])
            ->assertSessionHasErrors('refusal');
        $this->assertSame([8], $this->areas('TPW01'));

        $this->post($this->reverseUrl((string) $ledger['area-add']->WriteId), ['Reason' => self::REASON])->assertRedirect($this->url('show'));
        $this->assertSame([7], $this->areas('TPW01'));
        $this->assertSame('TEST1', $this->db()->table('PumpIT.dbo.BRN_EmployeeArea')->where('BranchId', self::BRANCH)
            ->where('EmployeeCode', 'TPW01')->value('MIST_EOD'), 'Re-inserted as it was.');
    }

    public function test_a_post_is_added_with_the_next_number_and_is_not_reversible(): void
    {
        $this->actingAs($this->person(['posts.view', 'posts.save']));
        $this->get(route('app.setup.employee-posts.index', ['branches' => [self::BRANCH]]))->assertOk()
            ->assertSee('Add a post at TEST-People write site')->assertSee('would be number 47');

        $this->post(route('app.setup.employee-posts.store'), ['BranchId' => self::BRANCH, 'PostDescription' => 'TEST-Barista',
            'StockControl' => 1, 'Reason' => self::REASON])->assertRedirect();
        $post = $this->db()->table('PumpIT.dbo.BRN_EmployeePost')->where('SSBranchId', self::BRANCH)->where('PostNo', 47)->first();
        $this->assertSame(['TEST-Barista', 1], [$post->PostDescription, (int) $post->StockControl]);
        $this->assertNotNull($post->CreateDateTime);

        // The same name again is refused; the next one takes 48.
        $this->from(route('app.setup.employee-posts.index'))->post(route('app.setup.employee-posts.store'),
            ['BranchId' => self::BRANCH, 'PostDescription' => 'TEST-Barista', 'Reason' => self::REASON])->assertSessionHasErrors('refusal');
        $result = $this->proc('agora.usp_People_AddPost', ['BranchId' => self::BRANCH, 'PostDescription' => 'TEST-Runner',
            'Reason' => self::REASON, 'UserId' => 1, 'Authorized' => 1, 'WriteMode' => 'live']);
        $this->assertSame(48, (int) $result->PostNo);

        $write = $this->ledger()->firstWhere('PostNo', 47);
        $this->assertSame(['post-add', 'BRN_EmployeePost'], [$write->Verb, $write->PumpItTable]);
        $this->assertRefused('NOT_REVERSIBLE', fn () => $this->proc('agora.usp_People_ReverseWrite', [
            'BranchId' => self::BRANCH, 'WriteId' => (string) $write->WriteId, 'Reason' => self::REASON, 'UserId' => 1,
            'AuthorizedEmployee' => 1, 'AuthorizedAreas' => 1, 'WriteMode' => 'live']));
    }

    public function test_each_write_has_its_own_permission_the_switch_and_the_procedure_both_refuse(): void
    {
        // View only: no Edit offered anywhere, and every write route is refused.
        $this->actingAs($this->person(['employees.view', 'posts.view', 'areas.view']));
        $this->get($this->url('show'))->assertOk()->assertDontSee('Edit employee')->assertDontSee('Put back');
        $this->get(route('app.setup.employees.index', ['branches' => [self::BRANCH]]))->assertOk()
            ->assertDontSee($this->url('edit'), false);
        $this->get(route('app.setup.employee-posts.index', ['branches' => [self::BRANCH]]))->assertOk()->assertDontSee('Add a post');
        $this->get($this->url('edit'))->assertForbidden();
        $this->put($this->url('update'), $this->employeeInput(['NickName' => 'TEST-x']))->assertForbidden();
        $this->put($this->url('areas.update'), ['Areas' => [], 'ExpectedAreas' => '[7]', 'AreaReason' => self::REASON])->assertForbidden();
        $this->post(route('app.setup.employee-posts.store'), ['BranchId' => self::BRANCH, 'PostDescription' => 'TEST-x', 'Reason' => self::REASON])
            ->assertForbidden();

        // A site outside the grant is not found, even with the permission.
        $this->actingAs($this->person(['employees.view', 'employees.edit'], [self::OTHER]));
        $this->get($this->url('edit'))->assertNotFound();

        // The procedure is the check that cannot be bypassed.
        $base = ['BranchId' => self::BRANCH, 'EmployeeCode' => 'TPW01', 'IsActive' => 0, 'PostNo' => 18, 'ExpectedIsActive' => 1,
            'ExpectedPostNo' => 18, 'Reason' => self::REASON, 'UserId' => 1, 'WriteMode' => 'live'];
        $this->assertRefused('DENIED', fn () => $this->proc('agora.usp_People_SaveEmployee', $base + ['Authorized' => 0]));
        $this->assertRefused('WRITES_OFF', fn () => $this->proc('agora.usp_People_SaveEmployee', ['WriteMode' => 'off', 'Authorized' => 1] + $base));
        $this->assertRefused('SYSTEM_ACCOUNT', fn () => $this->proc('agora.usp_People_SaveEmployee',
            ['EmployeeCode' => 'PUMPIT', 'Authorized' => 1] + $base));
        $this->assertSame(1, (int) $this->employee('TPW01')->IsActive);

        // With the switch off, the screens do not offer the write at all.
        config(['people.write_mode' => 'off']);
        $this->actingAs($this->person(['employees.view', 'employees.edit', 'areas.edit']));
        $this->get($this->url('show'))->assertOk()->assertDontSee('Edit employee');
        $this->get($this->url('edit'))->assertNotFound();
        $this->assertCount(0, $this->ledger());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function employeeInput(array $overrides = []): array
    {
        return array_replace(['IsActive' => 1, 'PostNo' => 18, 'NickName' => null, 'ExpectedIsActive' => 1, 'ExpectedPostNo' => 18,
            'ExpectedNickName' => null, 'Reason' => self::REASON], $overrides);
    }

    private function url(string $name): string
    {
        return route('app.setup.employees.'.$name, ['branch' => self::BRANCH, 'code' => 'TPW01']);
    }

    private function reverseUrl(string $writeId): string
    {
        return route('app.setup.employees.changes.reverse', ['branch' => self::BRANCH, 'code' => 'TPW01', 'write' => strtolower($writeId)]);
    }

    private function employee(string $code): object
    {
        return $this->db()->table('PumpIT.dbo.BRN_Employee')->where('SSBranchId', self::BRANCH)->where('EmployeeCode', $code)->first();
    }

    /** @return array<int, int> */
    private function areas(string $code): array
    {
        return $this->db()->table('PumpIT.dbo.BRN_EmployeeArea')->where('BranchId', self::BRANCH)->where('EmployeeCode', $code)
            ->orderBy('AreaNo')->pluck('AreaNo')->map(fn ($v) => (int) $v)->all();
    }

    /** @return Collection<int, \stdClass> */
    private function ledger(): Collection
    {
        return $this->db()->table('agora.PeopleWrite')->where('BranchId', self::BRANCH)->orderBy('Id')->get();
    }

    /** @param  array<string, mixed>  $params */
    private function proc(string $procedure, array $params): object
    {
        return app(ProcedureService::class)->write($procedure, $params);
    }

    private function assertRefused(string $code, callable $call): void
    {
        try {
            $call();
            $this->fail("Expected the procedure to refuse with {$code}.");
        } catch (AgoraProcException $e) {
            $this->assertSame($code, $e->code());
        }
    }

    private function fixture(): void
    {
        $this->db()->table('agora.Branch')->insert([
            ['BranchId' => self::BRANCH, 'Name' => 'TEST-People write site', 'IsTrading' => 1, 'IsActive' => 1, 'SortOrder' => self::BRANCH,
                'Status' => 'Trading', 'POSType' => 'BRANCH', 'IsAdminEntity' => 0, 'CreatedAt' => now()],
            ['BranchId' => self::OTHER, 'Name' => 'TEST-People write other', 'IsTrading' => 1, 'IsActive' => 1, 'SortOrder' => self::OTHER,
                'Status' => 'Trading', 'POSType' => 'BRANCH', 'IsAdminEntity' => 0, 'CreatedAt' => now()],
        ]);
        $pumpit = fn (string $table) => $this->db()->table('PumpIT.dbo.'.$table);
        $pumpit('BRN_EmployeePost')->insert([
            ['SSBranchId' => self::BRANCH, 'PostNo' => 18, 'PostDescription' => 'TEST-Fuel Cashier', 'StockControl' => 1],
            ['SSBranchId' => self::BRANCH, 'PostNo' => 46, 'PostDescription' => 'TEST-Site Manager', 'StockControl' => 1],
        ]);
        foreach (['TPW01' => ['TEST-Thandi', 'TEST-Surname'], 'PUMPIT' => ['PUMPIT', 'System']] as $code => [$first, $surname]) {
            $pumpit('BRN_Employee')->insert(['SSBranchId' => self::BRANCH, 'EmployeeCode' => $code, 'FirstName' => $first, 'Surname' => $surname,
                'NickName' => null, 'IsActive' => 1, 'IsPumpAttendant' => 0, 'PostNo' => 18]);
        }
        foreach ([7 => 'TEST-Fuel bay', 8 => 'TEST-Shop'] as $no => $name) {
            $pumpit('STK_Area')->insert(['SSBranchId' => self::BRANCH, 'AreaNo' => $no, 'AreaDescription' => $name, 'AreaGroup' => 'TEST-Group',
                'DayShift' => 1, 'AfternoonShift' => 0, 'NightShift' => 1, 'ShowReport' => 1, 'IsCaptureWaste' => 0]);
        }
        $pumpit('BRN_EmployeeArea')->insert(['BranchId' => self::BRANCH, 'EmployeeCode' => 'TPW01', 'AreaNo' => 7, 'MIST_EOD' => 'TEST1']);
    }

    /**
     * @param  array<int, string>  $actions  resource.action under people
     * @param  array<int, int>  $branches  the sites granted; none means every site
     */
    private function person(array $actions, array $branches = []): User
    {
        $user = User::query()->acrossBranches()->create([
            'BranchId' => 2, 'EmailAddress' => 'TEST-people-write-'.Str::uuid().'@agora.invalid', 'UserName' => 'TEST-People writer',
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
        $this->db()->table('agora.PeopleWrite')->whereIn('BranchId', $branches)->delete();
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
