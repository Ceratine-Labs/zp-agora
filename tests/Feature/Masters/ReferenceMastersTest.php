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
        $this->get(route('app.grids.extract', ['grid' => "app.setup.{$slug}"] + $query))->assertStatus(422);

        $this->actingAs($this->person(["{$resource}.view"]));
        $this->get(route("app.setup.{$slug}.index", $query))->assertOk()->assertSee(Str::after($procedure, 'agora.'));

        try {
            app(ProcedureService::class)->callSets($procedure, ['BranchIds' => $branch, 'Authorized' => 0]);
            $this->fail("{$procedure} must refuse an unauthorised caller.");
        } catch (AgoraProcException $e) {
            $this->assertSame('MASTERS_DENIED', $e->code());
        }
    }

    /** @return Collection<int, object> */
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
