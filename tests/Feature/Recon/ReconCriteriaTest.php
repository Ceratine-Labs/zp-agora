<?php

namespace Tests\Feature\Recon;

use App\Exceptions\AgoraProcException;
use App\Support\ProcedureService;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Branch;
use Modules\Core\Models\User;
use Modules\Recon\Models\ReconCriteria;
use Tests\TestCase;

/**
 * The extraction configuration, edited as an Agora-side override.
 *
 * THE ASSERTION THAT MATTERS MOST is the first one: with no override in place,
 * agora.vw_AutoReconCriteria returns exactly what it returned before this
 * table existed. Seven procedures read that view — the five previews and both
 * drills — and none of them changed, so if the view ever stopped being
 * equivalent the whole module would quietly start reconciling differently.
 *
 * This test WRITES, to agora.ReconCriteria only. It never touches the
 * customer's BRN_AutoReconCriteria, and there is no code path from here to it.
 * Everything it creates carries a TEST- reason and is removed in tearDown.
 */
class ReconCriteriaTest extends TestCase
{
    private const REASON = 'TEST-criteria override';

    private ProcedureService $procedures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->procedures = app(ProcedureService::class);
    }

    protected function tearDown(): void
    {
        ReconCriteria::query()->acrossBranches()->where('Reason', 'like', 'TEST-%')->delete();

        parent::tearDown();
    }

    private function admin(): User
    {
        $user = User::query()->acrossBranches()->where('EmailAddress', 'ryan@revvtech.co.za')->first();

        if (! $user) {
            $this->markTestSkipped('No seeded administrator — run db:seed first.');
        }

        return $user;
    }

    /** @return array<int, object> */
    private function rowsOf(string $name = 'vw_AutoReconCriteria'): array
    {
        return DB::connection(config('agora.connections.app'))
            ->table(config('agora.schema').'.'.$name)
            ->orderBy('BranchId')->orderBy('BankReconArea')->orderBy('ProcessOrder')
            ->get()->all();
    }

    /**
     * With nothing overridden, the view is the customer's table and nothing
     * else — which is what makes redefining it safe for the seven procedures
     * that read it.
     */
    public function test_with_no_override_the_view_is_exactly_the_customers_table(): void
    {
        $this->assertSame(0, ReconCriteria::query()->acrossBranches()->count(),
            'This test needs a clean override table to mean anything.');

        $effective = collect($this->rowsOf())->map(fn (object $r) => (array) $r)->all();
        $legacy = collect($this->rowsOf('vw_LegacyReconCriteria'))->map(fn (object $r) => (array) $r)->all();

        $this->assertEquals($legacy, $effective);
    }

    /**
     * An override REPLACES the customer's rule, and the previews resolve to it
     * — which is the whole point, and is asserted through the view the
     * procedures actually read rather than through the table.
     */
    public function test_an_override_replaces_the_customers_rule_in_the_view(): void
    {
        $legacy = collect($this->rowsOf('vw_LegacyReconCriteria'))->first();

        if ($legacy === null) {
            $this->markTestSkipped('The stub holds no criteria row to override.');
        }

        $this->save((int) $legacy->BranchId, $legacy->BankReconArea, (int) $legacy->ProcessOrder, [
            'BankStartPosition' => 11,
            'BankEndPosition' => 4,
        ]);

        $row = collect($this->rowsOf())->firstWhere('BranchId', $legacy->BranchId);

        $this->assertSame(11, (int) $row->BANK_StartPosition);
        $this->assertSame(4, (int) $row->BANK_EndPosition);
        // Ours are negative so they can never collide with a legacy identity,
        // and so it is obvious on sight which rows Agora put there.
        $this->assertLessThan(0, (int) $row->AutoReconId);

        // And the customer's own table is untouched, which is the rule the
        // whole design exists to keep.
        $stillTheirs = collect($this->rowsOf('vw_LegacyReconCriteria'))->firstWhere('BranchId', $legacy->BranchId);
        $this->assertSame((int) $legacy->BANK_StartPosition, (int) $stillTheirs->BANK_StartPosition);
    }

    /**
     * Parking is not deleting: the row and its reason stay, and what is back in
     * force is the customer's rule.
     */
    public function test_parking_an_override_puts_the_customers_rule_back(): void
    {
        $legacy = collect($this->rowsOf('vw_LegacyReconCriteria'))->first();

        if ($legacy === null) {
            $this->markTestSkipped('The stub holds no criteria row to override.');
        }

        $this->save((int) $legacy->BranchId, $legacy->BankReconArea, (int) $legacy->ProcessOrder, [
            'BankStartPosition' => 11, 'BankEndPosition' => 4,
        ]);

        $this->procedures->write('usp_Recon_SaveCriteria', [
            'BranchId' => (int) $legacy->BranchId,
            'ReconArea' => $legacy->BankReconArea,
            'ProcessOrder' => (int) $legacy->ProcessOrder,
            'Action' => 'park',
            'Reason' => self::REASON.' parked',
            'UserId' => 1,
        ]);

        $row = collect($this->rowsOf())->firstWhere('BranchId', $legacy->BranchId);
        $this->assertSame((int) $legacy->BANK_StartPosition, (int) $row->BANK_StartPosition);

        // The row is still there, with why it was made.
        $this->assertSame(1, ReconCriteria::query()->acrossBranches()->where('Reason', 'like', 'TEST-%')->count());
    }

    /**
     * A branch with NO rule of its own can be given one — which is finding 1,
     * and the larger half of what this screen is for.
     */
    public function test_an_override_can_add_a_rule_a_branch_never_had(): void
    {
        $legacy = collect($this->rowsOf('vw_LegacyReconCriteria'))->first();

        if ($legacy === null) {
            $this->markTestSkipped('The stub holds no criteria row at all.');
        }

        $branch = (int) $legacy->BranchId;
        $area = collect(array_keys((array) config('recon.areas')))
            ->first(fn (string $a) => collect($this->rowsOf('vw_LegacyReconCriteria'))
                ->where('BranchId', $branch)->where('BankReconArea', $a)->isEmpty());

        if ($area === null) {
            $this->markTestSkipped('That branch already has a rule in every area.');
        }

        // Before: the preview can only refuse.
        $before = $this->procedures->call(config("recon.areas.{$area}.procedure"), [
            'BranchId' => $branch, 'FromDate' => '2026-07-01', 'ToDate' => '2026-07-31 23:59:59',
        ])->first();

        $this->assertTrue(property_exists($before, 'Error'), 'Expected a refusal before the override.');

        $this->save($branch, $area, 1, ['BankStartPosition' => 1, 'BankEndPosition' => 6]);

        $after = $this->procedures->call(config("recon.areas.{$area}.procedure"), [
            'BranchId' => $branch, 'FromDate' => '2026-07-01', 'ToDate' => '2026-07-31 23:59:59',
        ])->first();

        $this->assertFalse($after !== null && property_exists($after, 'Error'),
            'The override should have given the branch a usable rule.');

        $override = ReconCriteria::query()->acrossBranches()->where('Reason', 'like', 'TEST-%')->firstOrFail();
        $this->assertTrue($override->isAddition(), 'This override adds a rule rather than replacing one.');
    }

    /** Every refusal the save procedure owns, and it owns all of them. */
    public function test_the_procedure_refuses_what_a_preview_would_refuse(): void
    {
        $base = [
            'BranchId' => 18, 'ReconArea' => 'FNB', 'ProcessOrder' => 1, 'Action' => 'save',
            'BankStartPosition' => 28, 'BankEndPosition' => 5,
            'Reason' => self::REASON, 'UserId' => 1,
        ];

        $cases = [
            'REASON_REQUIRED' => ['Reason' => null],
            'UNKNOWN_AREA' => ['ReconArea' => 'Yumbi'],
            'BAD_PROCESS_ORDER' => ['ProcessOrder' => 0],
            'BAD_START' => ['BankStartPosition' => 0],
            // A start and end that resolve to nothing is what every Preview*
            // refuses at run time; refusing it here means the person finds out
            // while looking at the form.
            'BAD_LENGTH' => ['BankEndPosition' => 0],
            'NOTHING_TO_PARK' => ['Action' => 'park'],
        ];

        foreach ($cases as $code => $override) {
            try {
                $this->procedures->write('usp_Recon_SaveCriteria', array_merge($base, $override));
                $this->fail("Expected {$code} and the procedure accepted it.");
            } catch (AgoraProcException $e) {
                $this->assertSame($code, $e->code());
            }
        }
    }

    /** A copy previews by default, and leaves a deliberate rule alone. */
    public function test_copy_previews_before_it_writes_and_keeps_what_is_already_set(): void
    {
        $legacy = collect($this->rowsOf('vw_LegacyReconCriteria'))->first();

        if ($legacy === null) {
            $this->markTestSkipped('The stub holds no rule to copy.');
        }

        $from = (int) $legacy->BranchId;
        $to = collect($this->rowsOf('vw_LegacyReconCriteria'))
            ->pluck('BranchId')->map(fn ($id) => (int) $id)->unique()
            ->first(fn (int $id) => $id !== $from);

        if ($to === null) {
            $this->markTestSkipped('The stub has only one branch with configuration.');
        }

        $preview = $this->procedures->callSets('usp_Recon_CopyCriteria', [
            'FromBranchId' => $from, 'ToBranchId' => $to, 'ReconArea' => null,
            'Overwrite' => 0, 'Apply' => 0, 'Reason' => null, 'UserId' => 1,
        ]);

        $this->assertSame('PREVIEWED', $preview[1]->first()->Code);
        $this->assertSame(0, ReconCriteria::query()->acrossBranches()->count(),
            'A preview must write nothing at all.');

        $applied = $this->procedures->callSets('usp_Recon_CopyCriteria', [
            'FromBranchId' => $from, 'ToBranchId' => $to, 'ReconArea' => null,
            'Overwrite' => 0, 'Apply' => 1, 'Reason' => self::REASON.' copy', 'UserId' => 1,
        ]);

        $this->assertSame('COPIED', $applied[1]->first()->Code);
        $this->assertGreaterThan(0, ReconCriteria::query()->acrossBranches()->count());

        // Asked again without overwrite, it leaves what it just made alone.
        $again = $this->procedures->callSets('usp_Recon_CopyCriteria', [
            'FromBranchId' => $from, 'ToBranchId' => $to, 'ReconArea' => null,
            'Overwrite' => 0, 'Apply' => 0, 'Reason' => null, 'UserId' => 1,
        ]);

        $this->assertNotEmpty($again[0]->where('Verdict', 'kept'),
            'An override the target already has is kept unless overwrite is asked for.');
    }

    /** The screen: the grid, its procedure, and the modal fragment behind a row. */
    public function test_the_configuration_tab_renders_over_its_procedure(): void
    {
        $this->actingAs($this->admin())->get('/app/recon/auto/ABSA/config')
            ->assertOk()
            ->assertSee('agora.usp_Recon_GridCriteria')
            ->assertSee('In force')
            // The narrative check is a button, not something the page does on
            // every load — it reads the customer's 249 GB statement table.
            ->assertSee('Check the narratives');
    }

    /** The edit fragment shows the customer's row beside the override, always. */
    public function test_the_edit_fragment_shows_both_sides(): void
    {
        $legacy = collect($this->rowsOf('vw_LegacyReconCriteria'))->first();

        if ($legacy === null) {
            $this->markTestSkipped('The stub holds no criteria row.');
        }

        $this->actingAs($this->admin())
            ->get("/app/recon/auto/{$legacy->BankReconArea}/config/{$legacy->BranchId}/{$legacy->ProcessOrder}")
            ->assertOk()
            ->assertSee("The customer's row")
            ->assertSee("Agora's override")
            ->assertSee('replaces');
    }

    /** @param array<string, int|string|null> $values */
    private function save(int $branchId, string $area, int $order, array $values): void
    {
        $this->procedures->write('usp_Recon_SaveCriteria', array_merge([
            'BranchId' => $branchId,
            'ReconArea' => $area,
            'ProcessOrder' => $order,
            'Action' => 'save',
            'Reason' => self::REASON,
            'UserId' => 1,
        ], $values));
    }

    /**
     * The rule address is a PAGE for a person and a FRAGMENT for the dialog.
     *
     * It answered both with the fragment until Ryan opened one on live and got
     * unstyled text with no navigation. The grid links a site name straight at
     * this address, so it has to stand on its own.
     */
    public function test_the_rule_address_is_a_page_for_a_person_and_a_fragment_for_the_dialog(): void
    {
        $legacy = collect($this->rowsOf('vw_LegacyReconCriteria'))->first();

        if ($legacy === null) {
            $this->markTestSkipped('The stub holds no criteria row.');
        }

        $url = "/app/recon/auto/{$legacy->BankReconArea}/config/{$legacy->BranchId}/{$legacy->ProcessOrder}";

        $page = $this->actingAs($this->admin())->get($url)->assertOk();

        // The shell, and a way back to the list it was reached from.
        $page->assertSee('<html', false)
            ->assertSee('class="appbar"', false)
            ->assertSee(route('app.recon.config', $legacy->BankReconArea), false)
            ->assertSee("The customer's row");

        // The dialog asks the same address and must NOT get a whole document
        // back — modal.js drops it straight into the dialog body.
        $fragment = $this->actingAs($this->admin())
            ->get($url, ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk();

        $fragment->assertSee("The customer's row")
            ->assertDontSee('<html', false)
            ->assertDontSee('class="appbar"', false);
    }

    /**
     * A configured site that does not trade still gets its name.
     *
     * Five branches on the live estate have extraction rules and keep no
     * trading day — AJLG Properties, Zululand Petroleum itself, and three
     * trusts and property companies. The rule page used to look them up in the
     * trading-sites list, find nothing, and render "Site 1".
     */
    public function test_a_configured_site_that_does_not_trade_is_still_named(): void
    {
        $branch = Branch::query()->acrossBranches()
            ->where('IsActive', true)->where('IsTrading', false)->first();

        if ($branch === null) {
            $this->markTestSkipped('This instance has no non-trading branch to check.');
        }

        $rule = collect($this->rowsOf('vw_LegacyReconCriteria'))
            ->firstWhere('BranchId', $branch->BranchId);

        if ($rule === null) {
            $this->markTestSkipped('No non-trading branch here carries a criteria row.');
        }

        $this->actingAs($this->admin())
            ->get("/app/recon/auto/{$rule->BankReconArea}/config/{$rule->BranchId}/{$rule->ProcessOrder}")
            ->assertOk()
            ->assertSee($branch->Name)
            ->assertDontSee('Site '.$branch->BranchId);
    }
}
