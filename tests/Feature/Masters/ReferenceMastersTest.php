<?php

namespace Tests\Feature\Masters;

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
 * The reference masters (T022): one read-only listing per PumpIT master, each
 * behind its own masters.{resource}.view, each refused by its procedure
 * without it, and each per-branch one refused without a branch in scope.
 *
 * READ-ONLY. Nothing here writes a master — there is nothing to write; the
 * only rows created are TEST- users and their grants, removed in tearDown.
 * Runs against the local container only, with every estate connection pinned
 * to it, over the real rows `php artisan agora:sync-local-masters` (and, for
 * the profit centres and category map, `agora:sync-local-branches`) copied
 * from the customer's instance — so the counts asserted are the estate's.
 */
class ReferenceMastersTest extends TestCase
{
    /** @var array<int, User> */
    private array $users = [];

    private bool $safe = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessLocalStub('This test creates TEST- users.');
        $local = config('database.connections.'.config('agora.connections.app'));
        foreach (config('agora.connections') as $name) {
            $database = config("database.connections.{$name}.database");
            config(["database.connections.{$name}" => array_replace($local, ['database' => $database])]);
            DB::purge($name);
            $this->assertContains(config("database.connections.{$name}.host"), ['127.0.0.1', 'localhost', '::1']);
        }
        $this->safe = true;
    }

    protected function tearDown(): void
    {
        if ($this->safe) {
            foreach ($this->users as $user) {
                foreach (['UserPermission', 'UserBranch', 'UserGridColumn', 'UserMenuItem'] as $table) {
                    $this->db()->table('agora.'.$table)->where('UserId', $user->Id)->delete();
                }
                User::query()->acrossBranches()->where('Id', $user->Id)->forceDelete();
            }
        }
        parent::tearDown();
    }

    public function test_profit_centres_list_all_nineteen_with_fuel_as_five_and_open_one(): void
    {
        $this->assertListingGuarded('profit-centres', 'profitcentres', 'agora.usp_Masters_GridProfitCentres');
        $this->actingAs($this->person(['profitcentres.view']));

        $rows = $this->rows('agora.usp_Masters_GridProfitCentres');
        $this->assertCount(19, $rows, 'SS_ProfitCentre holds 19 centres.');
        foreach (['ULP 95', 'ULP 93', 'Diesel 50', 'Diesel 500', 'Diesel 10'] as $fuel) {
            $this->assertContains($fuel, $rows->pluck('Name')->all(), "{$fuel} is a centre of its own.");
        }

        // CategoryCount is the active SDK_CategoryGLCode rows naming the centre.
        $shop = $rows->firstWhere('Name', 'Conv Shop');
        $this->assertSame(
            (int) $this->db()->selectOne("SELECT COUNT(*) AS n FROM agora.vw_MasterCategoryGl WHERE ProfitCentre = 'Conv Shop' AND IsActive = 1")->n,
            (int) $shop->CategoryCount
        );

        $this->get(route('app.setup.profit-centres.index'))->assertOk()
            ->assertSee('usp_Masters_GridProfitCentres')->assertSee('Fuel is five of them, not one')->assertSee('Diesel 500');

        $csv = $this->get(route('app.grids.extract', ['grid' => 'app.setup.profit-centres']))->assertOk()->streamedContent();
        $this->assertStringContainsString('EXPENSE_INVOICE', $csv);

        $page = $this->get(route('app.setup.profit-centres.show', ['branch' => 2, 'key' => $shop->RowKey]))->assertOk()
            ->assertSee('Conv Shop')->assertSee('Sites')->assertSee('dbo.SS_ProfitCentre');
        $this->get(route('app.setup.profit-centres.show', ['branch' => 2, 'key' => $shop->RowKey]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertSee('On the branch master')->assertDontSee('<!DOCTYPE', false);
        $this->get(route('app.setup.profit-centres.show', ['branch' => 2, 'key' => '999999']))->assertNotFound();
        unset($page);
    }

    public function test_standard_categories_list_all_fifty_four_with_the_shared_gl_pair(): void
    {
        $this->assertListingGuarded('standard-categories', 'standardcategories', 'agora.usp_Masters_GridStandardCategories');
        $this->actingAs($this->person(['standardcategories.view']));

        $rows = $this->rows('agora.usp_Masters_GridStandardCategories');
        $this->assertCount(54, $rows, 'SS_StandardCategory holds 54.');

        // The pair most of the shop posts to: every one of them names the others.
        $pair = $rows->where('SupplierGLCode', '51010209')->where('SalesGLCode', '41010209');
        $this->assertGreaterThan(10, $pair->count());
        $this->assertSame([$pair->count() - 1], $pair->pluck('SharedGlCount')->map(fn ($n) => (int) $n)->unique()->values()->all());

        $tobacco = $rows->firstWhere('StandardCategory', 'Tobacco');
        $this->assertSame(
            (int) $this->db()->selectOne("SELECT COUNT(*) AS n FROM agora.vw_MasterCategoryGl WHERE StandardCategory = 'Tobacco' AND IsActive = 1")->n,
            (int) $tobacco->CategoryCount
        );

        $this->get(route('app.setup.standard-categories.index', ['f' => ['SupplierGLCode' => ['q' => '51010209', 'eq' => '1']]]))
            ->assertOk()->assertSee('Grocery Foods')->assertDontSee('Diesel 50');
        $this->get(route('app.setup.standard-categories.show', ['branch' => 2, 'key' => $tobacco->RowKey]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertSee('POS categories mapped')->assertSee('51010209');
    }

    public function test_category_gl_codes_need_a_branch_and_highlight_a_missing_gp_band(): void
    {
        $this->assertListingGuarded('categories', 'categorygl', 'agora.usp_Masters_GridCategoryGlCodes', '13');
        $this->actingAs($this->person(['categorygl.view']));

        // Per branch: no branch in scope is a refusal, on the page and in the procedure.
        $this->get(route('app.setup.categories.index'))->assertOk()->assertSee('Choose a branch');
        $this->assertRefused('agora.usp_Masters_GridCategoryGlCodes', 'MASTERS_BRANCH');

        $all = $this->db()->selectOne('SELECT COUNT(*) AS n, SUM(CASE WHEN IsActive = 1 AND ISNULL(MinGP, 0) = 0 AND ISNULL(MaxGP, 0) = 0 THEN 1 ELSE 0 END) AS missing
            FROM agora.vw_MasterCategoryGl g WHERE EXISTS (SELECT 1 FROM agora.Branch b WHERE b.BranchId = g.BranchId)');
        $branches = implode(',', $this->db()->table('agora.Branch')->pluck('BranchId')->all());
        $this->assertCount((int) $all->n, $this->rows('agora.usp_Masters_GridCategoryGlCodes', $branches));
        $missing = $this->rows('agora.usp_Masters_GridCategoryGlCodes', $branches, ['FiltersJson' => json_encode([
            ['column' => 'GpBand', 'type' => 'set', 'in' => ['Missing']], ['column' => 'IsActive', 'type' => 'set', 'in' => ['Yes']],
        ])]);
        $this->assertCount((int) $all->missing, $missing, 'Every active category with MinGP = MaxGP = 0, and nothing else.');

        $baobab = $this->rows('agora.usp_Masters_GridCategoryGlCodes', '13');
        $this->assertSame([13], $baobab->pluck('BranchId')->map(fn ($id) => (int) $id)->unique()->values()->all(), 'One site in scope, one site listed.');

        $page = $this->get(route('app.setup.categories.index', ['branches' => [13]]))->assertOk()->assertSee('Baobab Convenience Centre');
        if ($baobab->contains('GpBand', 'Missing')) {
            $page->assertSee('is-warn', false);
        }
        $csv = $this->get(route('app.grids.extract', ['grid' => 'app.setup.categories', 'branches' => [13]]))->assertOk()->streamedContent();
        $this->assertStringContainsString('Baobab Convenience Centre', $csv);

        $one = $baobab->first();
        $this->get(route('app.setup.categories.show', ['branch' => 13, 'key' => $one->RowKey]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertSee($one->Category)->assertSee('The same category at your other sites');
    }

    public function test_expense_codes_need_a_branch_and_name_the_approver(): void
    {
        $this->assertPerBranchListing('expense-codes', 'expenses', 'agora.usp_Masters_GridExpenses', 'vw_Expense', 'ExpenseDescription');

        $rows = $this->rows('agora.usp_Masters_GridExpenses', $this->someBranch('vw_Expense'));
        $this->assertNotContains(null, $rows->pluck('ApproverName')->all(), 'Every code names its approver, by name or by PumpIT id.');
        $this->assertSame([], $rows->pluck('Vat')->map(fn ($v) => (int) $v)->unique()->diff([0, 15])->values()->all());
    }

    public function test_tills_are_per_site_with_their_pos(): void
    {
        $this->assertPerBranchListing('tills', 'tills', 'agora.usp_Masters_GridTills', 'vw_Till', 'TillDescription');
    }

    public function test_shifts_are_per_site_and_never_joined_on_the_number_alone(): void
    {
        $this->assertPerBranchListing('shifts', 'shifts', 'agora.usp_Masters_GridShifts', 'vw_Shift', 'ShiftDescription');

        // Three numbers estate-wide; a site lists each of its own once.
        $everywhere = $this->rows('agora.usp_Masters_GridShifts', implode(',', $this->db()->table('agora.Branch')->pluck('BranchId')->all()));
        $this->assertLessThanOrEqual(3, $everywhere->pluck('ShiftNo')->unique()->count());
        $this->assertSame($everywhere->count(), $everywhere->map(fn ($r) => $r->BranchId.':'.$r->ShiftNo)->unique()->count(), 'One row per (site, shift) — no fan-out.');
    }

    public function test_transaction_types_carry_their_document_counter(): void
    {
        $this->assertPerBranchListing('transaction-types', 'transactiontypes', 'agora.usp_Masters_GridTransactionTypes', 'vw_MasterTransactionType', 'TypeDescription');
    }

    public function test_fuel_types_tanks_and_pumps_join_at_the_same_site(): void
    {
        $this->assertPerBranchListing('fuel-types', 'fueltypes', 'agora.usp_Masters_GridFuelTypes', 'vw_MasterFuelType', 'FuelTypeDescription');
        $this->assertPerBranchListing('tanks', 'tanks', 'agora.usp_Masters_GridTanks', 'vw_MasterTank', 'TankDescription');
        $this->assertPerBranchListing('pumps', 'pumps', 'agora.usp_Masters_GridPumps', 'vw_MasterPump', 'PumpNo');

        // Every pump resolves to a grade and a tank AT ITS OWN SITE, and the
        // per-site join cannot fan out: one row per (site, pump).
        $sites = implode(',', $this->db()->table('agora.Branch')->pluck('BranchId')->all());
        $pumps = $this->rows('agora.usp_Masters_GridPumps', $sites);
        $this->assertSame((int) $this->db()->selectOne('SELECT COUNT(*) AS n FROM agora.vw_MasterPump p
            WHERE EXISTS (SELECT 1 FROM agora.Branch b WHERE b.BranchId = p.BranchId)')->n, $pumps->count());
        $this->assertSame($pumps->count(), $pumps->map(fn ($p) => $p->BranchId.':'.$p->PumpNo)->unique()->count());
        $this->assertNotContains(null, $pumps->pluck('FuelTypeDescription')->all());
        $this->assertNotContains(null, $pumps->pluck('TankDescription')->all());

        // A grade's pump count is its pumps at that site, and its record lists them.
        $branch = $this->someBranch('vw_MasterPump');
        $grade = $this->rows('agora.usp_Masters_GridFuelTypes', $branch)->sortByDesc('PumpCount')->first();
        $this->assertSame((int) $grade->PumpCount, $pumps->where('BranchId', (int) $branch)->where('FuelTypeNo', (int) $grade->FuelTypeNo)->count());
        $this->actingAs($this->person(['fueltypes.view']));
        $this->get(route('app.setup.fuel-types.show', ['branch' => $branch, 'key' => $grade->RowKey]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertSee('POS identifier')->assertSee($pumps->where('BranchId', (int) $branch)->where('FuelTypeNo', (int) $grade->FuelTypeNo)->first()->PumpNo);
    }

    public function test_utility_meters_are_per_site_with_their_last_reading(): void
    {
        $this->assertPerBranchListing('meters', 'meters', 'agora.usp_Masters_GridUtilityMeters', 'vw_UtilityMeter', 'MeterNo');

        // The last reading is the latest BRN_UtilityTransaction row for the meter.
        $branch = $this->someBranch('vw_UtilityTransaction');
        $meter = $this->rows('agora.usp_Masters_GridUtilityMeters', $branch)->sortByDesc('ReadingCount')->first();
        $latest = $this->db()->selectOne('SELECT COUNT(*) AS n, MAX(TransactionDate) AS last FROM agora.vw_UtilityTransaction
            WHERE BranchId = ? AND UtilityType = ? AND MeterNo = ?', [$branch, $meter->UtilityType, $meter->MeterNo]);
        $this->assertSame((int) $latest->n, (int) $meter->ReadingCount);
        $this->assertSame(substr((string) $latest->last, 0, 10), substr((string) $meter->LastReadingDate, 0, 10));

        $this->actingAs($this->person(['meters.view']));
        $this->get(route('app.setup.meters.show', ['branch' => $branch, 'key' => $meter->RowKey]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertSee('Last ten readings');
    }

    public function test_merchant_and_terminal_numbers_union_the_three_tables(): void
    {
        $this->assertPerBranchListing('terminals', 'terminals', 'agora.usp_Masters_GridTerminals', 'vw_MasterTerminal', 'Number');

        $all = $this->rows('agora.usp_Masters_GridTerminals', implode(',', $this->db()->table('agora.Branch')->pluck('BranchId')->all()));
        $this->assertSame(['ABSA merchant', 'Cash device terminal', 'Smart ATM terminal'], $all->pluck('Kind')->unique()->sort()->values()->all());
        $this->assertSame(['Not recorded'], $all->where('Kind', 'Cash device terminal')->pluck('Active')->unique()->values()->all(),
            'The cash-device table has no active flag; the listing does not invent one.');
    }

    public function test_statement_criteria_list_the_older_recon_rules(): void
    {
        $this->assertPerBranchListing('statement-criteria', 'reconcriteria', 'agora.usp_Masters_GridReconCriteria', 'vw_MasterReconCriteria', 'Code');
    }

    public function test_bank_accounts_list_the_customers_bankrecon_accounts(): void
    {
        $this->assertPerBranchListing('bank-accounts', 'bankaccounts', 'agora.usp_Masters_GridBankAccounts', 'vw_MasterBankAccount', 'AccountNumber');

        $all = $this->rows('agora.usp_Masters_GridBankAccounts', implode(',', $this->db()->table('agora.Branch')->pluck('BranchId')->all()));
        $this->assertSame((int) $this->db()->selectOne('SELECT COUNT(*) AS n FROM agora.vw_MasterBankAccount a
            WHERE a.IsDeleted = 0 AND EXISTS (SELECT 1 FROM agora.Branch b WHERE b.BranchId = a.BranchId)')->n, $all->count());
        $this->assertSame([false], $all->pluck('IsDeleted')->map(fn ($d) => (bool) $d)->unique()->values()->all(), 'Deleted accounts only when asked for.');
    }

    /**
     * What every per-branch master must do: refuse without a branch, list
     * exactly the chosen site's rows — the count the view holds for it — and
     * hand the same rows to the extract and one of them to the detail panel.
     */
    private function assertPerBranchListing(string $slug, string $resource, string $procedure, string $view, string $titleColumn): void
    {
        $branch = $this->someBranch($view);
        $this->assertListingGuarded($slug, $resource, $procedure, $branch);
        $this->actingAs($this->person(["{$resource}.view"]));

        $this->get(route("app.setup.{$slug}.index"))->assertOk()->assertSee('Choose a branch');
        $this->assertRefused($procedure, 'MASTERS_BRANCH');
        $this->getJson(route('app.grids.extract', ['grid' => "app.setup.{$slug}"]))->assertStatus(422);

        $rows = $this->rows($procedure, $branch);
        $this->assertSame((int) $this->db()->selectOne("SELECT COUNT(*) AS n FROM agora.{$view} WHERE BranchId = ?", [$branch])->n, $rows->count());
        $this->assertSame([(int) $branch], $rows->pluck('BranchId')->map(fn ($id) => (int) $id)->unique()->values()->all());

        $one = $rows->first();
        $this->get(route("app.setup.{$slug}.index", ['branches' => [$branch]]))->assertOk()->assertSee((string) $one->{$titleColumn});
        $csv = $this->get(route('app.grids.extract', ['grid' => "app.setup.{$slug}", 'branches' => [$branch]]))->assertOk()->streamedContent();
        $this->assertStringContainsString((string) $one->{$titleColumn}, $csv);

        $this->get(route("app.setup.{$slug}.show", ['branch' => $branch, 'key' => $one->RowKey]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()->assertSee((string) $one->{$titleColumn})->assertDontSee('<!DOCTYPE', false);
        $this->get(route("app.setup.{$slug}.show", ['branch' => $branch, 'key' => $one->RowKey]))->assertOk()->assertSee('Kept in');
        $this->get(route("app.setup.{$slug}.show", ['branch' => $branch, 'key' => 'TEST-no-such-row']))->assertNotFound();
    }

    /** The agora.Branch site carrying the most rows of a view: real, and never empty. */
    private function someBranch(string $view): string
    {
        $row = $this->db()->selectOne("SELECT TOP 1 v.BranchId FROM agora.{$view} v
            WHERE EXISTS (SELECT 1 FROM agora.Branch b WHERE b.BranchId = v.BranchId)
            GROUP BY v.BranchId ORDER BY COUNT(*) DESC, v.BranchId");
        $this->assertNotNull($row, "agora.{$view} has rows locally — run php artisan agora:sync-local-masters.");

        return (string) $row->BranchId;
    }

    private function assertRefused(string $procedure, string $code, ?string $branches = null): void
    {
        try {
            $this->rows($procedure, $branches);
            $this->fail("{$procedure} must refuse with {$code}.");
        } catch (AgoraProcException $e) {
            $this->assertSame($code, $e->code());
        }
    }

    /**
     * The three checks every listing shares: no permission is no screen and
     * no extract, the permission is the screen, and the procedure itself
     * refuses a caller the application did not authorise.
     */
    private function assertListingGuarded(string $slug, string $resource, string $procedure, ?string $branch = null): void
    {
        $query = $branch === null ? [] : ['branches' => [$branch]];

        // Somebody who may see the branches, but not this master.
        $this->actingAs($this->person(['branches.view']));
        $this->get(route("app.setup.{$slug}.index", $query))->assertForbidden();
        $this->get(route('app.grids.extract', ['grid' => "app.setup.{$slug}"] + $query))->assertForbidden();

        $this->actingAs($this->person(["{$resource}.view"]));
        $this->get(route("app.setup.{$slug}.index", $query))->assertOk()->assertSee(Str::after($procedure, 'agora.'));

        try {
            app(ProcedureService::class)->callSets($procedure, ['BranchIds' => $branch, 'Authorized' => 0]);
            $this->fail("{$procedure} must refuse an unauthorised caller.");
        } catch (AgoraProcException $e) {
            $this->assertSame('MASTERS_DENIED', $e->code());
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return Collection<int, object>
     */
    private function rows(string $procedure, ?string $branches = null, array $extra = []): Collection
    {
        return app(ProcedureService::class)->callSets($procedure, array_replace([
            'BranchIds' => $branches, 'PageSize' => 100000, 'Authorized' => 1,
        ], $extra))[0];
    }

    /** @param  array<int, string>  $actions  resource.action under masters. */
    private function person(array $actions): User
    {
        $user = User::query()->acrossBranches()->create([
            'BranchId' => 2, 'EmailAddress' => 'TEST-reference-masters-'.Str::uuid().'@agora.invalid', 'UserName' => 'TEST-Reference masters',
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

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }
}
