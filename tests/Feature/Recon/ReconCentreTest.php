<?php

namespace Tests\Feature\Recon;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Recon\Models\ReconRun;
use Tests\TestCase;

/**
 * The recon centre — one area, one site, one period, three tabs.
 *
 * ZP's ask through Ryan, 23 Sep 2026: pick the site and the dates once, the
 * filters fold away, the automatic balancing runs, and the suggestions and
 * the manual match sit beside it with the scope already passed. What is
 * guarded here is the part that breaks quietly: the scope travelling. Every
 * tab is a fragment asked for WITH the scope, and every press inside one comes
 * back to the centre, on the same tab, with the same scope — a centre whose
 * Match button dropped the clerk on another page would be three screens again.
 *
 * The fixture is the LOCAL PumpIT stub, branch 999, FNB only. Every write is
 * journal mode, so the stub is never stamped; the Agora ledger rows a preview
 * or a match writes are removed in tearDown().
 */
class ReconCentreTest extends TestCase
{
    private const BRANCH = 999;

    private const FROM = '2026-08-04';

    private const TO = '2026-08-12';

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessLocalStub();
        config(['recon.stamp_mode' => 'journal']);
        $this->cleanUp();
        $this->seedFixture();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->cleanUp();
        }

        parent::tearDown();
    }

    public function test_choosing_the_scope_runs_the_balancing_and_lands_on_the_centre(): void
    {
        $response = $this->actingAs($this->admin())->post('/app/recon/auto', $this->scope() + ['area' => 'FNB', 'centre' => 1]);

        $run = $this->latestRun();
        $response->assertRedirect(route('app.recon.area', ['FNB'] + $this->scope() + ['run' => $run->Id]));

        $this->actingAs($this->admin())->get(route('app.recon.area', ['FNB'] + $this->scope() + ['run' => $run->Id]))
            ->assertOk()
            // The scope folded to one line, and the three tabs over it.
            ->assertSee('class="scope-line"', false)
            ->assertSee('Run #'.$run->Id)
            ->assertSee('data-tabs-query="tab"', false)
            ->assertSee('data-tab-src="'.e(route('app.recon.run.panel', [$run] + $this->scope() + ['centre' => 1, 'run' => $run->Id])).'"', false)
            ->assertSee('data-tab-src="'.e(route('app.recon.suggest', ['FNB'] + $this->scope() + ['centre' => 1, 'run' => $run->Id])).'"', false)
            ->assertSee('data-tab-src="'.e(route('app.recon.match', ['FNB'] + $this->scope() + ['centre' => 1, 'run' => $run->Id])).'"', false);
    }

    /** A reload, or a link carrying only the scope, must not mint a second run. */
    public function test_the_open_run_of_the_same_scope_is_picked_up_rather_than_run_again(): void
    {
        $this->actingAs($this->admin())->post('/app/recon/auto', $this->scope() + ['area' => 'FNB', 'centre' => 1]);
        $run = $this->latestRun();

        $this->actingAs($this->admin())->get(route('app.recon.area', ['FNB'] + $this->scope()))
            ->assertOk()
            ->assertSee('Run #'.$run->Id);

        $this->assertSame(1, ReconRun::query()->acrossBranches()->where('BranchId', self::BRANCH)->where('ReconArea', 'FNB')->count());
    }

    public function test_a_scope_with_no_run_offers_the_balancing_as_a_press(): void
    {
        $this->actingAs($this->admin())->get(route('app.recon.area', ['FNB'] + $this->scope()))
            ->assertOk()
            ->assertSee('Not balanced yet')
            ->assertSee('Run auto balancing')
            ->assertSee('Not previewed yet');

        $this->assertSame(0, ReconRun::query()->acrossBranches()->where('BranchId', self::BRANCH)->where('ReconArea', 'FNB')->count(),
            'Opening the centre wrote a run.');
    }

    public function test_the_auto_tab_is_the_run_and_its_presses_come_back_to_the_centre(): void
    {
        $this->actingAs($this->admin())->post('/app/recon/auto', $this->scope() + ['area' => 'FNB', 'centre' => 1]);
        $run = $this->latestRun();

        $this->actingAs($this->admin())
            ->get(route('app.recon.run.panel', [$run] + $this->scope() + ['centre' => 1, 'run' => $run->Id]),
                ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertDontSee('<html', false)
            ->assertSee('id="execute-'.$run->Id.'"', false)
            ->assertSee('name="back" value="centre"', false)
            ->assertSee('name="tab" value="auto"', false)
            ->assertSee('Balance again');
    }

    public function test_executing_from_the_centre_returns_to_the_auto_tab(): void
    {
        $this->actingAs($this->admin())->post('/app/recon/auto', $this->scope() + ['area' => 'FNB', 'centre' => 1]);
        $run = $this->latestRun()->load('lines');
        $line = $run->lines->firstWhere('WouldReconcile', true);
        $this->assertNotNull($line, 'The fixture has one batch that settles on its number.');

        $this->actingAs($this->admin())
            ->post(route('app.recon.execute', $run), [
                'lines' => [$line->Id],
                'back' => 'centre', 'tab' => 'auto', 'run' => $run->Id,
                'branch_id' => self::BRANCH, 'period_from' => self::FROM, 'period_to' => self::TO,
            ])
            ->assertRedirect(route('app.recon.area', ['FNB'] + $this->scope() + ['run' => $run->Id, 'tab' => 'auto']))
            ->assertSessionHas('executed');
    }

    public function test_the_suggestions_tab_is_a_fragment_that_posts_back_to_the_centre(): void
    {
        $this->actingAs($this->admin())
            ->get(route('app.recon.suggest', ['FNB'] + $this->scope() + ['centre' => 1]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertDontSee('<html', false)
            ->assertDontSee('What to suggest for')
            ->assertSee('Suggested matches')
            ->assertSee('name="back" value="centre"', false)
            ->assertSee('name="tab" value="suggest"', false);
    }

    public function test_the_manual_tab_is_a_fragment_that_posts_back_to_the_centre(): void
    {
        $this->actingAs($this->admin())
            ->get(route('app.recon.match', ['FNB'] + $this->scope() + ['centre' => 1]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertDontSee('<html', false)
            ->assertDontSee('What to pair')
            ->assertSee('Pair by hand')
            ->assertSee('name="back" value="centre"', false)
            ->assertSee('name="tab" value="match"', false)
            ->assertSee('name="period_from" value="'.self::FROM.'"', false);
    }

    /** "Manual in the same load as the run" — and the match lands back there. */
    public function test_a_match_made_in_the_centre_returns_to_its_tab_with_its_scope(): void
    {
        $line = (int) $this->db()->table('PumpIT.dbo.RCN_BankStatementLinesPumpIT')
            ->where('SSBranchId', self::BRANCH)->where('Amount', 3689.76)->value('BankStatementLineID');

        $this->actingAs($this->admin())
            ->post(route('app.recon.match.save', 'FNB'), [
                // The window the rows sit in, as the screen sends it; the
                // period the clerk chose travels separately, for the way back.
                'branch_id' => self::BRANCH, 'from' => '2026-08-01', 'to' => self::TO,
                'back' => 'centre', 'tab' => 'match', 'period_from' => self::FROM, 'period_to' => self::TO,
                'bank' => [$line],
                'mops' => [json_encode(['id' => null, 'key' => '1', 'dt' => '2026-08-03', 'amt' => 3689.76])],
            ])
            ->assertSessionHas('centreDone')
            ->assertRedirect(route('app.recon.area', ['FNB'] + $this->scope() + ['tab' => 'match']));
    }

    /**
     * A refusal is said on the centre too, not on a page the clerk never
     * asked for. The deposit is dated outside the window, so it is not found.
     */
    public function test_a_refused_match_in_the_centre_says_so_there(): void
    {
        $this->actingAs($this->admin())
            ->post(route('app.recon.match.save', 'FNB'), [
                'branch_id' => self::BRANCH, 'from' => self::FROM, 'to' => self::TO,
                'back' => 'centre', 'tab' => 'match', 'period_from' => self::FROM, 'period_to' => self::TO,
            ])
            ->assertSessionHas('centreRefusal')
            ->assertRedirect(route('app.recon.area', ['FNB'] + $this->scope() + ['tab' => 'match']));
    }

    /** The old pages still answer, and each offers the way back with the scope. */
    public function test_the_standalone_pages_offer_the_way_back(): void
    {
        $this->actingAs($this->admin())->get(route('app.recon.match', ['FNB'] + $this->scope()))
            ->assertOk()
            ->assertSee('What to pair')
            ->assertSee('Back to the recon centre')
            ->assertSee('href="'.e(route('app.recon.area', ['FNB'] + $this->scope() + ['tab' => 'match'])).'"', false);
    }

    // ---- helpers -----------------------------------------------------------

    /** @return array{branch_id: int, from: string, to: string} */
    private function scope(): array
    {
        return ['branch_id' => self::BRANCH, 'from' => self::FROM, 'to' => self::TO];
    }

    private function latestRun(): ReconRun
    {
        return ReconRun::query()->acrossBranches()->where('BranchId', self::BRANCH)->where('ReconArea', 'FNB')->latest('Id')->firstOrFail();
    }

    private function admin(): User
    {
        $user = User::query()->acrossBranches()->where('EmailAddress', 'ryan@revvtech.co.za')->first();

        if (! $user) {
            $this->markTestSkipped('No seeded administrator — run db:seed first.');
        }

        return $user;
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }

    private function seedFixture(): void
    {
        $db = $this->db();

        // The real FNB configuration: batch trailing, merchant at (33, len 6).
        $db->table('PumpIT.dbo.BRN_AutoReconCriteria')->insert([
            'SSBranchId' => self::BRANCH, 'BankReconArea' => 'FNB', 'ProcessOrder' => 1,
            'BANK_StartPosition' => 40, 'BANK_EndPosition' => 3,
            'BANK_StartPosition2' => 33, 'BANK_EndPosition2' => 6,
            'MOPS_StartPosition' => 8, 'MOPS_EndPosition' => 3,
            'FILTER_Value' => null, 'FILTER_StartPosition' => 0, 'FILTER_EndPosition' => 0,
        ]);

        $line = fn (string $date, string $narrative, float $amount) => [
            'SSBranchId' => self::BRANCH, 'LineDate' => $date, 'Description' => $narrative,
            'Amount' => $amount, 'Type' => 'FNB', 'IDState' => 2, 'ReconState' => 1, 'ReconBatchNo' => 0,
        ];

        $db->table('PumpIT.dbo.RCN_BankStatementLinesPumpIT')->insert([
            // Settles on its batch number: the Auto tab's.
            $line('2026-08-06', 'SETTLEMENT ACB CREDIT SPEEDPOINT850250 203', 500.00),
            // An 'FN' line: the Suggestions tab's, and the manual match's.
            $line('2026-08-04', 'SETTLEMENT ACB CREDIT SPEEDPOINT00707875FN', 3689.76),
        ]);

        $deposit = fn (string $date, string $batch, string $merchant, float $amount) => [
            'SSBranchId' => self::BRANCH, 'TransactionDate' => $date, 'BatchNo' => $batch,
            'MerchantNo' => $merchant, 'Amount' => $amount, 'ReconBatchNoPumpIT' => 0,
        ];

        $db->table('PumpIT.dbo.BRN_DailyBankingFNB')->insert([
            $deposit('2026-08-05', '0000000203', '850250', 500.00),
            $deposit('2026-08-03', '1', '999999', 3689.76),
        ]);
    }

    private function cleanUp(): void
    {
        $db = $this->db();

        $db->table('PumpIT.dbo.RCN_BankStatementLinesPumpIT')->where('SSBranchId', self::BRANCH)->where('Type', 'FNB')->delete();
        $db->table('PumpIT.dbo.BRN_DailyBankingFNB')->where('SSBranchId', self::BRANCH)->delete();
        $db->table('PumpIT.dbo.BRN_AutoReconCriteria')->where('SSBranchId', self::BRANCH)->where('BankReconArea', 'FNB')->delete();

        $schema = config('agora.schema');
        $runs = ReconRun::query()->acrossBranches()->where('BranchId', self::BRANCH)->where('ReconArea', 'FNB')->pluck('Id');

        foreach (['ReconStamp', 'ReconMatch', 'ReconBatch', 'ReconRunLine'] as $table) {
            $db->table("{$schema}.{$table}")->whereIn('RunId', $runs)->delete();
        }

        ReconRun::query()->acrossBranches()->whereIn('Id', $runs)->delete();
    }
}
