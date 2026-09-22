<?php

namespace Tests\Feature\Product;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\User;
use Modules\Core\Models\UserBranch;
use Modules\Core\Models\UserPermission;
use Tests\Fixtures\GrantsAccess;
use Tests\TestCase;

/**
 * The stock master screens and the boundary around them (T025).
 *
 * SaveStockItemTest covers what the procedure refuses. This covers the layer
 * above it: who may open the screens, who may write, and whether a refusal
 * from the procedure reaches the person who typed the value instead of
 * becoming a 500.
 *
 * WHO MAY WRITE is asserted here rather than left as a comment, so changing
 * it is a decision somebody makes on purpose. It shipped as admin-only and
 * Ryan widened it on 20 September 2026 to finance, operations and branch
 * manager: operations own the counts and the counting behaviour flags live on
 * this record, finance argue from cost and GP, and a branch manager is scoped
 * to their own sites by their branch grants anyway. Executive and auditor
 * keep view only — the auditor by definition.
 */
class StockMasterScreenTest extends TestCase
{
    use GrantsAccess;

    private const BRANCH = 999;

    private int $branchId;

    /** @var array<int, User> */
    private array $made = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessLocalStub('This fixture writes stock master rows.');
        $this->branchId = (int) config('agora.group_branch_id', 2);
        $this->cleanUp();
        $this->seedFixture();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->cleanUp();

            foreach ($this->made as $user) {
                UserPermission::query()->acrossBranches()->where('UserId', $user->Id)->delete();
                UserBranch::query()->acrossBranches()->where('UserId', $user->Id)->delete();
                UserPermission::query()->acrossBranches()->where('UserId', $user->Id)->delete();
                User::query()->acrossBranches()->where('Id', $user->Id)->forceDelete();
            }
        }

        parent::tearDown();
    }

    public function test_every_role_may_read_the_listing(): void
    {
        foreach (['admin', 'executive', 'finance', 'operations', 'branch-manager', 'auditor'] as $role) {
            $this->actingAs($this->person($role))
                ->get(route('app.master.stock.index'))
                ->assertOk()
                ->assertSee('Stock master');
        }
    }

    public function test_the_four_roles_that_may_write_can(): void
    {
        foreach (['admin', 'finance', 'operations', 'branch-manager'] as $role) {
            $this->actingAs($this->person($role))
                ->put($this->updateUrl(), $this->payload())
                ->assertRedirect();
        }
    }

    public function test_executive_and_auditor_may_read_but_not_write(): void
    {
        // The auditor holds *.*.view by design and must never be one misclick
        // from a change; the executive reads the estate rather than maintains
        // it.
        foreach (['executive', 'auditor'] as $role) {
            $person = $this->person($role);

            $this->actingAs($person)
                ->get(route('app.master.stock.index'))
                ->assertOk();

            $this->actingAs($person)
                ->put($this->updateUrl(), $this->payload())
                ->assertForbidden();
        }
    }

    public function test_the_detail_page_names_which_master_answered(): void
    {
        $this->actingAs($this->person('admin'))
            ->get(route('app.master.stock.show', ['branch' => self::BRANCH, 'item' => '10']))
            ->assertOk()
            ->assertSee('Held by')
            ->assertSee('Read straight from PumpIT.dbo.STK_StockMaster');
    }

    public function test_the_editor_renders_for_an_admin_with_the_fields_and_the_warning_that_matters(): void
    {
        $page = $this->actingAs($this->person('admin'))
            ->get(route('app.master.stock.show', ['branch' => self::BRANCH, 'item' => '10']))
            ->assertOk();

        // The form itself, and the area picker built from THIS site's areas.
        $page->assertSee('Change this stock line')
            ->assertSee('name="POSCode"', false)
            ->assertSee('name="PriceType"', false)
            ->assertSee('name="reason"', false)
            ->assertSee('TEST-Shop floor');

        // The sentence the person has to read before they press Save: this
        // writes an Agora override, PumpIT is untouched, and PumpIT's own
        // counts go on reading their copy until cutover. Leaving that off
        // would make the screen look like it changed the till.
        $page->assertSee('counts go on reading their copy until cutover');

        // And the warning on the price type, because 5,800 of 7,447 live
        // prices are recomputed by the customer's own procedure.
        $page->assertSee('RECOMPUTED per branch by PumpIT');
    }

    public function test_a_reader_who_cannot_write_is_not_offered_the_editor(): void
    {
        // feature-rules §4: a control the user cannot use is not rendered.
        // The auditor holds view and not edit.
        $this->actingAs($this->person('auditor'))
            ->get(route('app.master.stock.show', ['branch' => self::BRANCH, 'item' => '10']))
            ->assertOk()
            ->assertDontSee('Change this stock line')
            ->assertDontSee('data-modal-open="edit-stock-item"', false);
    }

    public function test_a_refusal_comes_back_to_the_form_rather_than_as_an_error_page(): void
    {
        // An area that does not exist at this site. The procedure refuses it;
        // the person who chose it has to be told which field to change, and a
        // 500 does not do that.
        $response = $this->actingAs($this->person('admin'))
            ->from(route('app.master.stock.show', ['branch' => self::BRANCH, 'item' => '10']))
            ->put($this->updateUrl(), $this->payload(['AreaNo' => 77]));

        $response->assertRedirect(route('app.master.stock.show', ['branch' => self::BRANCH, 'item' => '10']));
        $response->assertSessionHasErrors('refusal');

        $this->assertStringContainsString(
            'counting area',
            (string) session('errors')?->first('refusal')
        );

        // And nothing was written.
        $this->assertSame(0, $this->db()->table('agora.StockItem')
            ->where('BranchId', self::BRANCH)->count());
    }

    public function test_a_missing_reason_is_caught_by_the_form_before_the_procedure_sees_it(): void
    {
        $payload = $this->payload();
        unset($payload['reason']);

        $this->actingAs($this->person('admin'))
            ->put($this->updateUrl(), $payload)
            ->assertSessionHasErrors('reason');
    }

    public function test_a_good_save_lands_and_says_so(): void
    {
        $this->actingAs($this->person('admin'))
            ->put($this->updateUrl(), $this->payload(['StockItemDescription' => 'TEST-SAVED FROM THE FORM']))
            ->assertRedirect(route('app.master.stock.show', ['branch' => self::BRANCH, 'item' => '10']))
            ->assertSessionHas('status');

        $row = $this->db()->selectOne(
            'SELECT * FROM [agora].[vw_StockItem] WHERE BranchId = ? AND StockItemNo = ?',
            [self::BRANCH, '10']
        );

        $this->assertSame('agora', trim($row->Source));
        $this->assertSame('TEST-SAVED FROM THE FORM', trim((string) $row->StockItemDescription));
    }

    private function updateUrl(): string
    {
        return route('app.master.stock.update', ['branch' => self::BRANCH, 'item' => '10']);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function payload(array $changes = []): array
    {
        return array_merge([
            'action' => 'save',
            'StockItemNo' => '10',
            'StockItemDescription' => 'TEST-FROM THE FORM',
            'AreaNo' => 1,
            'PosSystem' => 'WINBRANCH',
            'POSCode' => '1200',
            'SellingPrice' => 12.34,
            'PriceType' => 'Set Price',
            'UOMCode' => 'Each',
            'IsActive' => 1,
            'reason' => 'TEST-screen test',
        ], $changes);
    }

    private function person(string $roleCode): User
    {
        $user = User::query()->acrossBranches()->create([
            'BranchId' => $this->branchId,
            'EmailAddress' => 'TEST-t025-'.uniqid().'@agora.invalid',
            'UserName' => 'TEST-'.$roleCode,
            'IsActive' => true,
            'IsLocked' => false,
            'Workspace' => $this->profileWorkspace($roleCode),
            'PasswordHash' => 'TEST-only-'.Str::random(24),
        ]);

        $this->grantProfile($user, $roleCode);
        $this->made[] = $user;

        return $user;
    }

    private function seedFixture(): void
    {
        $this->db()->table('PumpIT.dbo.STK_Area')->insert([
            'SSBranchId' => self::BRANCH,
            'AreaNo' => 1,
            'AreaDescription' => 'TEST-Shop floor',
            'AreaGroup' => 'Retail Items',
            'DayShift' => 1,
            'AfternoonShift' => 0,
            'NightShift' => 0,
            'ShowReport' => 1,
            'IsCaptureWaste' => 0,
        ]);

        $this->db()->table('PumpIT.dbo.STK_StockMaster')->insert([
            'SSBranchId' => self::BRANCH,
            'StockItemNo' => '10',
            'StockItemDescription' => 'TEST-PIE CHICKEN',
            'AreaNo' => 1,
            'Location' => 'WINBRANCH',
            'POSCode' => '1200',
            'SellingPrice' => 29.99,
            'PriceType' => 'Set Price',
            'Factor' => 0,
            'UOMCode' => 'Each',
            'IssueMultiple' => 1,
            'IssueMultiplePercentage' => 0,
            'QtyVarAllowance' => 0,
            'isMonitoredItem' => 0,
            'IsDoCloseQtyCalc' => 1,
            'IsAllowNegativeQtyIssued' => 0,
            'IsAllowNegativeQtyClose' => 0,
            'IsStockItemPreProduction' => 0,
            'isPreProductionItem' => 0,
            'PreProductionTypeNo' => 0,
            'Ratio' => 0,
            'ProduceLimitPercentage' => 0,
            'CreateDateTime' => '2026-01-01 00:00:00',
        ]);
    }

    private function cleanUp(): void
    {
        $this->db()->table('agora.StockItem')->where('BranchId', self::BRANCH)->delete();
        $this->db()->table('PumpIT.dbo.STK_StockMaster')->where('SSBranchId', self::BRANCH)->delete();
        $this->db()->table('PumpIT.dbo.STK_Area')->where('SSBranchId', self::BRANCH)->delete();
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }
}
