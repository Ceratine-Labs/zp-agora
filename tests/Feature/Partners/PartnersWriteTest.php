<?php

namespace Tests\Feature\Partners;

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
 * Trading partners' named write (T024; CLAUDE.md rule 1, PM decision
 * 01a1cecf-8e08): supplier and customer edits written back to BRN_Suppliers /
 * BRN_Customers with their priors in agora.PartnersWrite, the reversal, the
 * stale and moved refusals, the all-sites supplier insert, the customer insert
 * and its duplicate guard, and the permission split.
 *
 * LOCAL CONTAINER AND PUMPIT STUB ONLY: every estate connection is pinned to
 * 127.0.0.1 and asserted before anything runs. Fixtures are TEST- rows at
 * TEST- branches 965 and 966. A new supplier is inserted at every site the
 * local stub carries the list at, so its code is removed from every site in
 * tearDown, with every PartnersWrite row these tests made.
 */
class PartnersWriteTest extends TestCase
{
    private const BRANCH = 965;

    private const OTHER = 966;

    /** @var array<int, User> */
    private array $users = [];

    /** @var array<int, string> codes a test added, removed from every site afterwards */
    private array $added = [];

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
        config(['partners.write_mode' => 'live']);
        $this->safe = true;
        $this->cleanUp();

        foreach ([self::BRANCH => 'TEST-Partners site', self::OTHER => 'TEST-Partners other'] as $id => $name) {
            $this->db()->table('agora.Branch')->insert([
                'BranchId' => $id, 'Name' => $name, 'IsTrading' => 1, 'IsActive' => 1, 'SortOrder' => $id,
                'Status' => 'Trading', 'POSType' => 'BRANCH', 'IsAdminEntity' => 0, 'CreatedAt' => now(),
            ]);
        }
        foreach ([self::BRANCH => ['TPS01', 0], self::OTHER => ['TPS02', 1]] as $branch => [$account, $cash]) {
            $this->db()->table('PumpIT.dbo.BRN_Suppliers')->insert([
                'SSBranchId' => $branch, 'SupplierCode' => 'APS-9990', 'SupplierName' => 'TEST-Partner supplier',
                'SupplierID' => $account, 'IsCash' => $cash, 'IsStock' => 1, 'isActive' => 1, 'Department' => 'TEST-dept',
            ]);
        }
        $customer = fn (string $code, string $name, string $account, int $mop = 0) => $this->db()->table('PumpIT.dbo.BRN_Customers')->insert([
            'SSBranchId' => self::BRANCH, 'CustomerCode' => $code, 'CustomerName' => $name, 'CustomerContact' => 'TEST-contact',
            'DebtorID' => $account, 'DebtorGroupID' => $account, 'IsMOP' => $mop, 'MOPTypeId' => $mop ? 3 : 1, 'PosSystemId' => 5,
        ]);
        $customer('TPC00001', 'TEST-Partner customer', 'TPCA1');
        $customer('TPC00002', 'TEST-Partner control', '', 1);
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

    public function test_a_supplier_edit_writes_the_name_everywhere_and_this_sites_flags_with_priors_and_puts_back_exactly(): void
    {
        $result = $this->saveSupplier(['SupplierName' => 'TEST-Partner supplier renamed', 'IsCash' => 1, 'ContactPerson' => 'TEST-Thandi']);
        $this->assertSame('SAVED', $result->Code);
        $this->assertSame(2, (int) $result->Sites, 'The name is written at both sites carrying the code.');

        $here = $this->supplierRow(self::BRANCH);
        $there = $this->supplierRow(self::OTHER);
        $this->assertSame('TEST-Partner supplier renamed', $here->SupplierName);
        $this->assertSame('TEST-Partner supplier renamed', $there->SupplierName);
        $this->assertSame('TEST-Thandi', $here->ContactPerson);
        $this->assertSame(1, (int) $here->IsCash);
        $this->assertNull($there->ContactPerson, 'Contact is this site\'s only.');
        $this->assertSame(1, (int) $there->IsCash, 'The other site\'s flag was already 1 and is not touched.');
        $this->assertSame('TPS01', $here->SupplierID, 'The POS account number is never written.');

        $ledger = $this->ledger($result->WriteId);
        $this->assertSame(
            [
                self::BRANCH.' ContactPerson  → TEST-Thandi',
                self::BRANCH.' IsCash 0 → 1',
                self::BRANCH.' SupplierName TEST-Partner supplier → TEST-Partner supplier renamed',
                self::OTHER.' SupplierName TEST-Partner supplier → TEST-Partner supplier renamed',
            ],
            $ledger->map(fn ($w) => "{$w->BranchId} {$w->Field} {$w->PriorValue} → {$w->NewValue}")->sort()->values()->all()
        );
        $this->assertSame($ledger->count(), (int) $this->db()->table('agora.vw_PumpItChange')->where('Ledger', 'PartnersWrite')
            ->whereIn('LedgerId', $ledger->pluck('Id'))->count(), 'One audit-trail row per value written.');

        $back = $this->reverse($result->WriteId);
        $this->assertSame('REVERSED', $back->Code);
        $here = $this->supplierRow(self::BRANCH);
        $this->assertSame('TEST-Partner supplier', $here->SupplierName);
        $this->assertNull($here->ContactPerson, 'Put back to exactly what was stored — NULL, not blank.');
        $this->assertSame(0, (int) $here->IsCash);
        $this->assertSame('TEST-Partner supplier', $this->supplierRow(self::OTHER)->SupplierName);
        $this->assertSame(['reversed'], $this->ledger($result->WriteId)->pluck('State')->unique()->values()->all());

        $this->assertRefused('ALREADY_REVERSED', fn () => $this->reverse($result->WriteId));
    }

    public function test_a_stale_form_is_refused_and_nothing_is_written(): void
    {
        $this->db()->table('PumpIT.dbo.BRN_Suppliers')->where('SSBranchId', self::BRANCH)->where('SupplierCode', 'APS-9990')
            ->update(['Department' => 'TEST-changed in PumpIT']);

        $this->assertRefused('STALE', fn () => $this->saveSupplier(['SupplierName' => 'TEST-Partner supplier renamed']));
        $this->assertSame('TEST-Partner supplier', $this->supplierRow(self::OTHER)->SupplierName);
        $this->assertSame(0, $this->db()->table('agora.PartnersWrite')->where('PartnerCode', 'APS-9990')->count());

        $this->assertRefused('REASON_REQUIRED', fn () => $this->saveSupplier(['Department' => 'TEST-changed in PumpIT', 'IsStock' => 0], 'short'));
        $this->assertRefused('DENIED', fn () => $this->saveSupplier(['Department' => 'TEST-changed in PumpIT', 'IsStock' => 0], authorized: 0));
        $this->assertRefused('WRITES_OFF', fn () => $this->saveSupplier(['Department' => 'TEST-changed in PumpIT', 'IsStock' => 0], mode: 'off'));
        $this->assertSame('NOTHING_CHANGED', $this->saveSupplier(['Department' => 'TEST-changed in PumpIT'],
            expected: ['ExpectedDepartment' => 'TEST-changed in PumpIT'])->Code);
    }

    public function test_a_change_made_in_pumpit_since_is_not_undone_by_a_put_back(): void
    {
        $result = $this->saveSupplier(['IsStock' => 0]);
        $this->db()->table('PumpIT.dbo.BRN_Suppliers')->where('SSBranchId', self::BRANCH)->where('SupplierCode', 'APS-9990')
            ->update(['IsStock' => 1]);

        $this->assertRefused('MOVED', fn () => $this->reverse($result->WriteId));
        $this->assertSame(['active'], $this->ledger($result->WriteId)->pluck('State')->unique()->values()->all());
    }

    public function test_a_new_supplier_is_one_new_code_at_every_site_carrying_the_list(): void
    {
        $sites = collect($this->db()->select('SELECT DISTINCT s.SSBranchId AS BranchId FROM PumpIT.dbo.BRN_Suppliers s
            JOIN agora.Branch b ON b.BranchId = s.SSBranchId'))->pluck('BranchId')->map(fn ($v) => (int) $v)->sort()->values();
        $this->assertContains(self::BRANCH, $sites->all());

        $result = $this->addSupplier();
        $this->added[] = $result->SupplierCode;
        $this->assertSame('APS-9991', $result->SupplierCode, 'The next APS code across every site, under the lock.');
        $this->assertSame($sites->count(), (int) $result->Sites);

        $rows = collect($this->db()->select('SELECT * FROM PumpIT.dbo.BRN_Suppliers WHERE SupplierCode = ?', [$result->SupplierCode]));
        $this->assertSame($sites->all(), $rows->pluck('SSBranchId')->map(fn ($v) => (int) $v)->sort()->values()->all(), 'One row at every site, nowhere else.');
        foreach ($rows as $row) {
            $this->assertSame('TEST-New supplier', $row->SupplierName);
            $this->assertSame('', $row->SupplierID, 'No POS account yet: PumpIT links it from the till.');
            $this->assertSame([1, 0, 1], [(int) $row->IsStock, (int) $row->IsCash, (int) $row->isActive]);
        }
        $this->assertSame($sites->count(), $this->ledger($result->WriteId)->where('Verb', 'supplier-add')->count());

        $this->assertRefused('NOT_REVERSIBLE', fn () => $this->reverse($result->WriteId));

        $second = $this->addSupplier();
        $this->added[] = $second->SupplierCode;
        $this->assertSame('APS-9992', $second->SupplierCode, 'The next press takes the next code.');

        $this->assertRefused('BAD_SERIES', fn () => $this->addSupplier(['Series' => 'XYZ']));
        $this->assertRefused('ESTATE_ONLY', fn () => $this->addSupplier(['AllowedBranchIds' => (string) self::BRANCH]));
        $this->assertRefused('DENIED', fn () => $this->addSupplier(['Authorized' => 0]));
    }

    public function test_a_customer_edit_and_its_put_back_and_the_payment_control_refusal(): void
    {
        $result = $this->saveCustomer(['CustomerName' => 'TEST-Partner customer renamed', 'Telephone' => '0350000000']);
        $this->assertSame('SAVED', $result->Code);
        $row = $this->customerRow('TPC00001');
        $this->assertSame('TEST-Partner customer renamed', $row->CustomerName);
        $this->assertSame('0350000000', $row->TelNoA);
        $this->assertSame('TPCA1', $row->DebtorID, 'The till account is never written.');
        $this->assertSame(['CustomerName', 'TelNoA'], $this->ledger($result->WriteId)->pluck('Field')->sort()->values()->all());

        $this->reverse($result->WriteId);
        $row = $this->customerRow('TPC00001');
        $this->assertSame('TEST-Partner customer', $row->CustomerName);
        $this->assertNull($row->TelNoA);

        $this->assertRefused('PAYMENT_CONTROL', fn () => $this->saveCustomer(['CustomerName' => 'TEST-x'], code: 'TPC00002',
            expected: ['ExpectedCustomerName' => 'TEST-Partner control']));
        $this->assertRefused('STALE', fn () => $this->saveCustomer(['CustomerName' => 'TEST-x'], expected: ['ExpectedCustomerName' => 'TEST-old']));
    }

    public function test_a_new_customer_follows_the_sites_code_and_the_duplicate_guard_refuses_a_taken_till_account(): void
    {
        $this->assertSame('TPC00003', app(ProcedureService::class)->call('agora.usp_Partners_NextCustomerCode', ['BranchId' => self::BRANCH])->first()->NextCode);

        $result = $this->addCustomer(['AccountNo' => 'TPCA9']);
        $this->assertSame('TPC00003', $result->CustomerCode);
        $this->assertFalse((bool) $result->OnTill, 'The till has no TPCA9; allowed, and said.');
        $row = $this->customerRow('TPC00003');
        $this->assertSame(['TPCA9', 'TPCA9', 0, 1, 5], [$row->DebtorID, $row->DebtorGroupID, (int) $row->IsMOP, (int) $row->MOPTypeId, (int) $row->PosSystemId]);
        $this->assertSame(1, $this->ledger($result->WriteId)->where('Verb', 'customer-add')->count());
        $this->assertRefused('NOT_REVERSIBLE', fn () => $this->reverse($result->WriteId));

        $this->assertRefused('ACCOUNT_TAKEN', fn () => $this->addCustomer(['AccountNo' => 'TPCA1']));
        $this->assertNull($this->customerRow('TPC00004'), 'A refusal writes nothing.');
        $this->assertRefused('ACCOUNT_REQUIRED', fn () => $this->addCustomer(['AccountNo' => '']));
        $this->assertRefused('NO_PREFIX', fn () => $this->addCustomer(['BranchId' => self::OTHER, 'AccountNo' => 'TPCA8']));
    }

    public function test_view_edit_and_create_are_separate_permissions_and_the_screens_write_through_the_procedures(): void
    {
        $edit = route('app.setup.suppliers.edit', ['branch' => self::BRANCH, 'code' => 'APS-9990']);
        $update = route('app.setup.suppliers.update', ['branch' => self::BRANCH, 'code' => 'APS-9990']);

        $this->actingAs($this->person(['suppliers.view']));
        $this->get(route('app.setup.suppliers.show', ['branch' => self::BRANCH, 'code' => 'APS-9990']))->assertOk()
            ->assertDontSee('Edit supplier')->assertSee('Changes written to PumpIT');
        $this->get(route('app.setup.suppliers.index'))->assertOk()->assertDontSee('New supplier');
        $this->get($edit)->assertForbidden();
        $this->put($update, $this->supplierForm())->assertForbidden();
        $this->get(route('app.setup.suppliers.create'))->assertForbidden();
        $this->get(route('app.setup.customers.create'))->assertForbidden();

        $this->actingAs($this->person(['suppliers.view', 'suppliers.edit']));
        $this->get(route('app.setup.suppliers.create'))->assertForbidden();
        $this->get($edit)->assertOk()->assertSee('data-confirm', false)->assertSee('PumpIT BRN_Suppliers')->assertSee('name="ExpectedIsCash"', false);
        $this->put($update, $this->supplierForm(['Department' => 'TEST-via the screen']))
            ->assertRedirect(route('app.setup.suppliers.show', ['branch' => self::BRANCH, 'code' => 'APS-9990']));
        $this->assertSame('TEST-via the screen', $this->supplierRow(self::BRANCH)->Department);
        $this->get(route('app.setup.suppliers.show', ['branch' => self::BRANCH, 'code' => 'APS-9990']))->assertOk()
            ->assertSee('TEST-via the screen')->assertSee('Put back');
        $this->put($update, $this->supplierForm(['Department' => 'TEST-again']))->assertSessionHasErrors('refusal');

        $this->actingAs($this->person(['customers.view', 'customers.create']));
        $this->get(route('app.setup.customers.create', ['branch' => self::BRANCH]))->assertOk()->assertSee('TPC00003');
        $this->get(route('app.setup.customers.edit', ['branch' => self::BRANCH, 'code' => 'TPC00001']))->assertForbidden();

        $this->actingAs($this->person(['suppliers.view', 'suppliers.create', 'customers.view', 'customers.edit']));
        $this->get(route('app.setup.suppliers.index'))->assertOk()->assertSee('New supplier');
        $this->get(route('app.setup.suppliers.create'))->assertOk()->assertSee('APS-9991')->assertSee('INSERT at', false);
        $this->get(route('app.setup.customers.edit', ['branch' => self::BRANCH, 'code' => 'TPC00001']))->assertOk()
            ->assertSee('PumpIT BRN_Customers')->assertSee('name="ExpectedCustomerName"', false);
        $this->get(route('app.setup.customers.edit', ['branch' => self::BRANCH, 'code' => 'TPC00002']))->assertNotFound();

        config(['partners.write_mode' => 'off']);
        $this->actingAs($this->person(['suppliers.view', 'suppliers.edit']));
        $this->get($edit)->assertNotFound();
        $this->get(route('app.setup.suppliers.show', ['branch' => self::BRANCH, 'code' => 'APS-9990']))->assertOk()->assertDontSee('Edit supplier');
    }

    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $expected
     */
    private function saveSupplier(array $new, string $reason = 'TEST-a supplier edit', int $authorized = 1, string $mode = 'live', array $expected = []): object
    {
        $current = ['SupplierName' => 'TEST-Partner supplier', 'ContactPerson' => null, 'Department' => 'TEST-dept',
            'IsActive' => 1, 'IsCash' => 0, 'IsStock' => 1];
        $params = ['BranchId' => self::BRANCH, 'SupplierCode' => 'APS-9990'] + $new + $current;
        foreach ($current as $field => $value) {
            $params['Expected'.$field] = $expected['Expected'.$field] ?? $value;
        }

        return app(ProcedureService::class)->write('agora.usp_Partners_SaveSupplier', $params + [
            'Reason' => $reason, 'UserId' => null, 'Authorized' => $authorized, 'WriteMode' => $mode, 'ReasonMin' => 10,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function addSupplier(array $overrides = []): object
    {
        return app(ProcedureService::class)->write('agora.usp_Partners_AddSupplier', $overrides + [
            'Series' => 'APS', 'SupplierName' => 'TEST-New supplier', 'IsStock' => 1, 'IsCash' => 0,
            'Reason' => 'TEST-a new supplier', 'UserId' => null, 'Authorized' => 1, 'WriteMode' => 'live', 'AllowedBranchIds' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $expected
     */
    private function saveCustomer(array $new, string $code = 'TPC00001', array $expected = []): object
    {
        $current = ['CustomerName' => 'TEST-Partner customer', 'ContactPerson' => 'TEST-contact', 'Department' => null,
            'JobTitle' => null, 'Telephone' => null, 'Telephone2' => null];
        $params = ['BranchId' => self::BRANCH, 'CustomerCode' => $code] + $new + $current;
        foreach ($current as $field => $value) {
            $params['Expected'.$field] = $expected['Expected'.$field] ?? $value;
        }

        return app(ProcedureService::class)->write('agora.usp_Partners_SaveCustomer', $params + [
            'Reason' => 'TEST-a customer edit', 'UserId' => null, 'Authorized' => 1, 'WriteMode' => 'live',
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function addCustomer(array $overrides = []): object
    {
        return app(ProcedureService::class)->write('agora.usp_Partners_AddCustomer', $overrides + [
            'BranchId' => self::BRANCH, 'CustomerName' => 'TEST-New customer', 'AccountNo' => 'TPCA9',
            'Reason' => 'TEST-a new customer', 'UserId' => null, 'Authorized' => 1, 'WriteMode' => 'live',
        ]);
    }

    private function reverse(string $writeId): object
    {
        return app(ProcedureService::class)->write('agora.usp_Partners_ReverseWrite', [
            'WriteId' => $writeId, 'Reason' => 'TEST-put it back', 'UserId' => null,
            'AuthorizedSuppliers' => 1, 'AuthorizedCustomers' => 1, 'WriteMode' => 'live',
        ]);
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed> */
    private function supplierForm(array $overrides = []): array
    {
        return $overrides + [
            'SupplierName' => 'TEST-Partner supplier', 'ContactPerson' => '', 'Department' => 'TEST-dept',
            'IsActive' => 1, 'IsCash' => 0, 'IsStock' => 1,
            'ExpectedSupplierName' => 'TEST-Partner supplier', 'ExpectedContactPerson' => '', 'ExpectedDepartment' => 'TEST-dept',
            'ExpectedIsActive' => 1, 'ExpectedIsCash' => 0, 'ExpectedIsStock' => 1,
            'Reason' => 'TEST-through the edit screen',
        ];
    }

    private function assertRefused(string $code, callable $call): void
    {
        try {
            $call();
            $this->fail("Expected the procedure to refuse with {$code}.");
        } catch (AgoraProcException $e) {
            $this->assertSame($code, $e->code(), $e->getMessage());
        }
    }

    private function supplierRow(int $branch): object
    {
        $row = $this->db()->table('PumpIT.dbo.BRN_Suppliers')->where('SSBranchId', $branch)->where('SupplierCode', 'APS-9990')->first();
        $this->assertNotNull($row);

        return $row;
    }

    private function customerRow(string $code): ?object
    {
        return $this->db()->table('PumpIT.dbo.BRN_Customers')->where('SSBranchId', self::BRANCH)->where('CustomerCode', $code)->first();
    }

    /** @return Collection<int, \stdClass> */
    private function ledger(string $writeId): Collection
    {
        return $this->db()->table('agora.PartnersWrite')->where('WriteId', $writeId)->get();
    }

    /** @param array<int, string> $actions */
    private function person(array $actions): User
    {
        $user = User::query()->acrossBranches()->create([
            'BranchId' => 2, 'EmailAddress' => 'TEST-partners-write-'.Str::uuid().'@agora.invalid', 'UserName' => 'TEST-Partners write',
            'IsActive' => true, 'IsLocked' => false, 'Workspace' => 'ho', 'PasswordHash' => 'TEST-only-'.Str::random(24),
        ]);
        $this->users[] = $user;
        foreach ($actions as $action) {
            $permission = $this->db()->table('agora.Permission')->where('Code', 'partners.'.$action)->value('Id');
            $this->assertNotNull($permission, "partners.{$action} is seeded");
            $this->db()->table('agora.UserPermission')->insert(['BranchId' => 2, 'UserId' => $user->Id, 'PermissionId' => $permission]);
        }
        app(PermissionService::class)->forget($user);
        app(BranchContext::class)->setWorkspace('ho')->setAllowed([]);

        return $user;
    }

    private function cleanUp(): void
    {
        $branches = [self::BRANCH, self::OTHER];
        $codes = array_merge(['APS-9990', 'TPC00001', 'TPC00002', 'TPC00003', 'TPC00004'], $this->added);
        $this->db()->table('agora.PartnersWrite')->whereIn('PartnerCode', $codes)->delete();
        $this->db()->table('agora.PartnersWrite')->where('Reason', 'like', 'TEST-%')->delete();
        $this->db()->table('PumpIT.dbo.BRN_Suppliers')->whereIn('SupplierCode', $this->added)->where('SupplierName', 'like', 'TEST-%')->delete();
        $this->db()->table('PumpIT.dbo.BRN_Suppliers')->where('SupplierName', 'TEST-New supplier')->delete();
        $this->db()->table('PumpIT.dbo.BRN_Customers')->whereIn('SSBranchId', $branches)->delete();
        $this->db()->table('PumpIT.dbo.BRN_Suppliers')->whereIn('SSBranchId', $branches)->delete();
        $this->db()->table('agora.Branch')->whereIn('BranchId', $branches)->delete();
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }
}
