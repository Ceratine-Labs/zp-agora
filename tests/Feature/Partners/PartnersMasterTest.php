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
 * Trading partners (T024): the supplier and account-customer listings, one
 * of each, the palette search, the next free supplier code and the
 * duplicate-code guard.
 *
 * Runs against the local container only, with every estate connection pinned
 * to it. The fixtures are TEST- rows at two TEST- branches (965, 966) that are
 * not on the customer's estate: suppliers and customers in the local PumpIT
 * STUB, one till account in its DBF_CLDB_CUSTOMER, and one statement address
 * in agora.DebtorContact. All of them are removed in tearDown. Nothing in the
 * module under test writes anything; the last test proves the procedures
 * cannot.
 */
class PartnersMasterTest extends TestCase
{
    private const BRANCH = 965;

    private const OTHER = 966;

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

        foreach ([self::BRANCH => 'TEST-Partners site', self::OTHER => 'TEST-Partners other'] as $id => $name) {
            $this->db()->table('agora.Branch')->insert([
                'BranchId' => $id, 'Name' => $name, 'IsTrading' => 1, 'IsActive' => 1, 'SortOrder' => $id,
                'Status' => 'Trading', 'POSType' => 'BRANCH', 'IsAdminEntity' => 0, 'CreatedAt' => now(),
            ]);
        }

        $supplier = fn (int $branch, string $code, string $name, array $more = []) => $this->db()->table('PumpIT.dbo.BRN_Suppliers')->insert($more + [
            'SSBranchId' => $branch, 'SupplierCode' => $code, 'SupplierName' => $name, 'SupplierID' => '',
            'IsCash' => 0, 'IsStock' => 1, 'isActive' => 1, 'Department' => 'TEST-dept',
        ]);
        $supplier(self::BRANCH, 'APS-9990', 'TEST-Partner supplier', ['SupplierID' => 'TPS01']);
        $supplier(self::OTHER, 'APS-9990', 'TEST-Partner supplier renamed', ['SupplierID' => 'TPS02', 'IsCash' => 1]);
        $supplier(self::BRANCH, 'APE-9991', 'TEST-Partner creditor', ['IsStock' => 0, 'IsCash' => 1, 'isActive' => 0]);

        $customer = fn (string $code, string $name, ?string $account, int $mop = 0) => $this->db()->table('PumpIT.dbo.BRN_Customers')->insert([
            'SSBranchId' => self::BRANCH, 'CustomerCode' => $code, 'CustomerName' => $name,
            'DebtorID' => $account ?? '', 'DebtorGroupID' => $account ?? '', 'CreditLimitAmount' => 0, 'IsMOP' => $mop,
        ]);
        $customer('TPC00001', 'TEST-Partner customer', 'TPCA1');
        $customer('TPC00002', 'TEST-Partner control', null, 1);

        $this->db()->table('PumpIT.dbo.DBF_CLDB_CUSTOMER')->insert([
            'SSBranchId' => self::BRANCH, 'CNUM' => 'TPCA1', 'INIT' => '1', 'SNAM' => 'TEST-PARTNER AT THE TILL',
            'CRLIMIT' => 5000, 'TELC' => '0820000001',
        ]);
        $this->db()->table('agora.DebtorContact')->insert([
            'BranchId' => self::BRANCH, 'AccountNo' => 'TPCA1', 'Email' => 'test-partner@agora.invalid',
            'AutoSend' => 1, 'CreatedAt' => now(),
        ]);
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

    public function test_the_supplier_list_is_one_row_per_site_and_the_extract_agrees(): void
    {
        $this->actingAs($this->person(['suppliers.view']));

        $this->get(route('app.setup.suppliers.index', ['branches' => [self::BRANCH, self::OTHER]]))->assertOk()
            ->assertSee('usp_Partners_GridSuppliers')
            ->assertSee('TEST-Partner supplier')
            ->assertSee('TEST-Partner supplier renamed')
            ->assertSee('TEST-Partner creditor');

        $csv = $this->get(route('app.grids.extract', [
            'grid' => 'app.setup.suppliers', 'branches' => [self::BRANCH, self::OTHER],
            'f' => ['SupplierCode' => ['q' => 'APS-9990', 'eq' => 1]],
        ]))->assertOk()->streamedContent();
        $this->assertSame(2, substr_count($csv, 'APS-9990'), 'One supplier code, one row at each of its two sites.');
        $this->assertStringNotContainsString('APE-9991', $csv);

        // A header filter is answered by the procedure: inactive leaves the one.
        $rows = $this->grid('agora.usp_Partners_GridSuppliers', ['FiltersJson' => json_encode([['column' => 'Status', 'type' => 'set', 'in' => ['Inactive']]])]);
        $this->assertSame(['APE-9991'], $rows->pluck('SupplierCode')->all());
        $this->assertSame('APE', $rows->first()->CodeSeries);

        // Rows at a branch Agora does not know never reach the list.
        $known = (int) $this->db()->selectOne('SELECT COUNT_BIG(*) AS n FROM agora.vw_PartnerSupplier s JOIN agora.Branch b ON b.BranchId = s.BranchId')->n;
        $listed = (int) app(ProcedureService::class)->callSets('agora.usp_Partners_GridSuppliers', ['PageSize' => 1])[1]->first()->TotalRows;
        $this->assertSame($known, $listed);
    }

    public function test_a_supplier_opens_with_the_same_code_at_the_other_sites(): void
    {
        $this->actingAs($this->person(['suppliers.view']));

        $this->get(route('app.setup.suppliers.show', ['branch' => self::BRANCH, 'code' => 'APS-9990']))->assertOk()
            ->assertSee('TEST-Partner supplier')
            ->assertSee('TPS01')
            ->assertSee('At the other sites')
            ->assertSee('TEST-Partners other')
            ->assertSee('differs')
            ->assertSee('Back to suppliers');

        $this->get(route('app.setup.suppliers.show', ['branch' => self::BRANCH, 'code' => 'APS-9990']), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertSee('Open')->assertDontSee('Back to suppliers');

        $this->get(route('app.setup.suppliers.show', ['branch' => self::BRANCH, 'code' => 'APS-0000X']))->assertNotFound();

        // A site outside the person's grant does not exist for them.
        $narrow = $this->person(['suppliers.view'], [self::OTHER]);
        $this->actingAs($narrow);
        app(BranchContext::class)->setAllowed([self::OTHER]);
        $this->get(route('app.setup.suppliers.show', ['branch' => self::BRANCH, 'code' => 'APS-9990']))->assertNotFound();
    }

    public function test_the_customer_list_carries_the_till_limit_and_the_statement_address_and_hides_payment_controls(): void
    {
        $this->actingAs($this->person(['customers.view']));

        $this->get(route('app.setup.customers.index', ['branches' => [self::BRANCH]]))->assertOk()
            ->assertSee('usp_Partners_GridCustomers')
            ->assertSee('TEST-Partner customer')
            ->assertDontSee('TEST-Partner control')
            ->assertSee('test-partner@agora.invalid');
        $this->get(route('app.setup.customers.index', ['branches' => [self::BRANCH], 'controls' => 1]))->assertOk()
            ->assertSee('TEST-Partner control');

        $row = $this->grid('agora.usp_Partners_GridCustomers')->firstWhere('CustomerCode', 'TPC00001');
        $this->assertSame(5000.0, (float) $row->TillCreditLimit, 'The limit is the till\'s.');
        $this->assertSame(0.0, (float) $row->CreditLimit, 'PumpIT\'s is kept beside it, as it is.');
        $this->assertSame('0820000001', $row->TillCellNo);
        $this->assertTrue((bool) $row->OnTill);
        $this->assertTrue((bool) $row->OnMonthlyRun);

        $this->get(route('app.setup.customers.show', ['branch' => self::BRANCH, 'code' => 'TPC00001']))->assertOk()
            ->assertSee('TEST-PARTNER AT THE TILL')
            ->assertSee('R5 000.00')
            ->assertSee('test-partner@agora.invalid')
            ->assertSee('On the monthly run.');
    }

    public function test_view_permissions_are_per_resource(): void
    {
        $this->actingAs($this->person([]))->get(route('app.setup.suppliers.index'))->assertRedirect(route('app.access-required'));
        $this->actingAs($this->person(['suppliers.view']));
        $this->get(route('app.setup.suppliers.index'))->assertOk();
        $this->assertNotSame(200, $this->get(route('app.setup.customers.index'))->getStatusCode());
        $this->assertNotSame(200, $this->get(route('app.setup.customers.show', ['branch' => self::BRANCH, 'code' => 'TPC00001']))->getStatusCode());
    }

    public function test_the_palette_search_offers_one_hit_per_supplier_and_only_what_the_person_may_view(): void
    {
        $this->actingAs($this->person(['suppliers.view', 'customers.view']));

        $hits = $this->search('TEST-Partner');
        $supplier = $hits->where('kind', 'supplier')->where('code', 'APS-9990');
        $this->assertCount(1, $supplier, 'A supplier at two sites is one hit.');
        $this->assertStringContainsString('2 sites', $supplier->first()['detail']);
        $this->assertStringContainsString('/app/setup/suppliers?', $supplier->first()['url']);
        $this->assertSame(
            route('app.setup.suppliers.show', ['branch' => self::BRANCH, 'code' => 'APE-9991']),
            $hits->firstWhere('code', 'APE-9991')['url'],
            'A supplier at one site opens that site.'
        );
        $this->assertSame(
            route('app.setup.customers.show', ['branch' => self::BRANCH, 'code' => 'TPC00001']),
            $hits->firstWhere('code', 'TPC00001')['url']
        );
        $this->assertNull($hits->firstWhere('code', 'TPC00002'), 'Payment-control accounts are never offered.');

        $exact = $this->search('APS-9990');
        $this->assertSame('APS-9990', $exact->first()['code'], 'The exact code comes first.');

        $this->actingAs($this->person(['suppliers.view']));
        $hits = $this->search('TEST-Partner');
        $this->assertSame(['supplier'], $hits->pluck('kind')->unique()->values()->all());

        $this->actingAs($this->person([]))->getJson(route('app.setup.partners.search', ['q' => 'TEST-Partner']))->assertForbidden();
    }

    public function test_the_next_supplier_code_is_group_wide_and_per_series(): void
    {
        $call = fn (string $series) => app(ProcedureService::class)->call('agora.usp_Partners_NextSupplierCode', ['Series' => $series])->first();

        $this->assertSame('APS-9991', $call('APS')->NextCode, 'The highest APS code at ANY site, plus one.');
        $this->assertSame('APE-9992', $call('APE')->NextCode);
        $this->assertNull($call('XYZ')->NextCode, 'Only the two series the customer issues.');
    }

    public function test_the_duplicate_code_guard(): void
    {
        $check = fn (array $p) => app(ProcedureService::class)->call('agora.usp_Partners_CheckCode', $p + ['Throw' => 0])->pluck('Severity', 'Code')->all();

        $this->assertSame(['CODE_TAKEN' => 'block'], $check(['Kind' => 'Supplier', 'BranchId' => self::BRANCH, 'Code' => 'aps-9990']),
            'Case-blind: aps-9990 is APS-9990.');
        $this->assertSame(['CODE_ELSEWHERE' => 'info'], $check(['Kind' => 'Supplier', 'BranchId' => self::OTHER, 'Code' => 'APE-9991', 'Name' => 'TEST-Partner creditor']));
        $this->assertSame(['CODE_MEANS_OTHER' => 'block'], $check(['Kind' => 'Supplier', 'BranchId' => self::OTHER, 'Code' => 'APE-9991', 'Name' => 'TEST-Somebody else']));
        $this->assertSame(
            ['CODE_FORMAT' => 'warn', 'ACCOUNT_TAKEN' => 'block', 'NAME_TAKEN' => 'warn'],
            $check(['Kind' => 'Supplier', 'BranchId' => self::BRANCH, 'Code' => 'X1', 'Name' => 'TEST-Partner supplier', 'AccountNo' => 'TPS01'])
        );
        $this->assertSame(['CODE_TOO_LONG' => 'block', 'CODE_FORMAT' => 'warn'], $check(['Kind' => 'Supplier', 'BranchId' => self::BRANCH, 'Code' => 'APS-99999']));
        $this->assertSame([], $check(['Kind' => 'Supplier', 'BranchId' => self::BRANCH, 'Code' => 'APS-9991', 'Name' => 'TEST-New']));
        $this->assertSame(['UNKNOWN_BRANCH' => 'block'], $check(['Kind' => 'Supplier', 'BranchId' => -7, 'Code' => 'APS-9991']));

        $this->assertSame(['CODE_TAKEN' => 'block'], $check(['Kind' => 'Customer', 'BranchId' => self::BRANCH, 'Code' => 'TPC00001']));
        $this->assertSame(
            ['ACCOUNT_TAKEN' => 'block'],
            $check(['Kind' => 'Customer', 'BranchId' => self::BRANCH, 'Code' => 'TPC00003', 'AccountNo' => 'TPCA1'])
        );
        $this->assertSame(['ACCOUNT_NOT_ON_TILL' => 'warn'], $check(['Kind' => 'Customer', 'BranchId' => self::BRANCH, 'Code' => 'TPC00003', 'AccountNo' => 'TPNONE']));

        try {
            app(ProcedureService::class)->call('agora.usp_Partners_CheckCode', ['Kind' => 'Supplier', 'BranchId' => self::BRANCH, 'Code' => 'APS-9990', 'Throw' => 1]);
            $this->fail('A blocking finding refuses when asked to.');
        } catch (AgoraProcException $e) {
            $this->assertSame('CODE_TAKEN', $e->code());
        }
    }

    public function test_no_partners_procedure_or_view_writes_anything(): void
    {
        foreach (glob(base_path('Modules/Partners/Database/Procedures/*.sql')) ?: [] as $file) {
            $sql = (string) file_get_contents($file);
            $this->assertDoesNotMatchRegularExpression('/\b(UPDATE|DELETE|MERGE|TRUNCATE)\b/i', $sql, basename($file));
            preg_match_all('/\bINSERT\s+(?:INTO\s+)?(\S+)/i', $sql, $targets);
            foreach ($targets[1] as $target) {
                $this->assertMatchesRegularExpression('/^[@#]/', $target, basename($file).' inserts only into its own table variables');
            }
        }
    }

    /**
     * The palette's hits for $q, as the JSON endpoint returns them.
     *
     * @return Collection<int, array<mixed>>
     */
    private function search(string $q): Collection
    {
        $results = $this->getJson(route('app.setup.partners.search', ['q' => $q]))->assertOk()->json('results');
        $this->assertIsArray($results);

        return (new Collection($results))->map(function (mixed $hit): array {
            $this->assertIsArray($hit);

            return $hit;
        })->values();
    }

    /**
     * @param  array<string, mixed>  $params
     * @return Collection<int, object>
     */
    private function grid(string $procedure, array $params = []): Collection
    {
        return app(ProcedureService::class)->callSets($procedure, $params + [
            'BranchIds' => self::BRANCH.','.self::OTHER, 'PageSize' => 200,
        ])[0];
    }

    /**
     * @param  array<int, string>  $actions
     * @param  array<int, int>  $branches
     */
    private function person(array $actions, array $branches = []): User
    {
        $user = User::query()->acrossBranches()->create([
            'BranchId' => 2, 'EmailAddress' => 'TEST-partners-'.Str::uuid().'@agora.invalid', 'UserName' => 'TEST-Partners',
            'IsActive' => true, 'IsLocked' => false, 'Workspace' => 'ho', 'PasswordHash' => 'TEST-only-'.Str::random(24),
        ]);
        $this->users[] = $user;
        foreach ($actions as $action) {
            $permission = $this->db()->table('agora.Permission')->where('Code', 'partners.'.$action)->value('Id');
            $this->assertNotNull($permission, "partners.{$action} is seeded");
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
        $this->db()->table('agora.DebtorContact')->whereIn('BranchId', $branches)->delete();
        $this->db()->table('PumpIT.dbo.DBF_CLDB_CUSTOMER')->whereIn('SSBranchId', $branches)->delete();
        $this->db()->table('PumpIT.dbo.BRN_Customers')->whereIn('SSBranchId', $branches)->delete();
        $this->db()->table('PumpIT.dbo.BRN_Suppliers')->whereIn('SSBranchId', $branches)->delete();
        $this->db()->table('agora.Branch')->whereIn('BranchId', $branches)->delete();
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }
}
