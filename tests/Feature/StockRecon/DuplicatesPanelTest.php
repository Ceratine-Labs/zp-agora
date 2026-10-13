<?php

namespace Tests\Feature\StockRecon;

use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\Permission;
use Modules\Core\Models\User;
use Modules\Core\Models\UserPermission;
use Modules\Core\Services\PermissionService;
use Modules\StockRecon\Models\StockReconRun;
use Modules\StockRecon\Models\StockReconRunLine;
use Modules\StockRecon\Services\StockReconService;
use Tests\Fixtures\GrantsAccess;
use Tests\TestCase;

/**
 * The "Duplicate issues" panel on a stock recon run (Ryan, 1 Oct 2026: "show a
 * view like the customer report on the stock recon itself, identifying what is
 * what and what is being corrected").
 *
 * agora.usp_StockRecon_GetDuplicates is the panel: each candidate with its
 * state, the shifts that prove it — the twin where the issue arrived, the
 * shift as keyed and, for a clear one, the same shift corrected — and the
 * totals. Three chains on one shelf area, the shapes of the customer report:
 *
 *   TDUP  Nyala's Dunhill: the night re-keys the day's 20; the day's arrived.
 *         Clear (D1), the SECOND issue is the duplicate.
 *   TCHR  Ngwenya's charcoal: keyed on the day, delivered at night and keyed
 *         again. Clear (D1), the FIRST issue is the duplicate — and with it
 *         gone the day sold nothing and did not move: dormant, which is the
 *         pill a removed duplicate used to hide in.
 *   TDP2  Both shifts 20 short: possible (D2), each the other's twin, and
 *         their chain held back.
 *
 * Writes only branch 999 (the E2E fixture, nobody's site) on the LOCAL
 * container, and removes all of it in tearDown, as BalancingTest does.
 */
class DuplicatesPanelTest extends TestCase
{
    use GrantsAccess;

    private const BRANCH = 999;

    private const AREA = 2;

    /** The three items the fixture writes — five characters at most, as BalancingTest explains. */
    private const ITEMS = ['TDUP', 'TCHR', 'TDP2'];

    private StockReconService $service;

    /** @var array<int, User> people a test made, removed in tearDown */
    private array $made = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessLocalStub();
        $this->service = new StockReconService(new ProcedureService);
        $this->cleanUp();
        $this->seedChains();

        config(['stockrecon.issue_mode' => 'live', 'stockrecon.exceptions.D2.blocks' => true]);
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->cleanUp();

            foreach ($this->made as $user) {
                UserPermission::query()->acrossBranches()->where('UserId', $user->Id)->delete();
                $this->db()->table('agora.UserGridColumn')->where('UserId', $user->Id)->delete();
                User::query()->acrossBranches()->where('Id', $user->Id)->forceDelete();
            }

            if ($this->made !== []) {
                app(PermissionService::class)->forgetAll();
            }
        }

        parent::tearDown();
    }

    /**
     * Every candidate, with its twin, the shift as keyed and — for a clear
     * one — the shift corrected, in date and shift order; the state; the
     * values at the sell price the run recorded (R21.80).
     */
    public function test_the_procedure_returns_each_duplicate_with_the_shifts_that_prove_it(): void
    {
        $run = $this->preview();
        $panel = $this->service->duplicates($run);
        $cases = $panel['cases'];

        $this->assertSame(config('agora.schema').'.usp_StockRecon_GetDuplicates', $panel['procedure']);
        $this->assertSame(['D1', 'D1', 'D2', 'D2'], $cases->pluck('DupTier')->all(), 'Clear first, then possible.');

        // Nyala: the night is the duplicate, the day is where it arrived.
        $nyala = $this->case($cases, 'TDUP');
        $this->assertSame('clear', $nyala->Grp);
        $this->assertSame('will_remove', $nyala->State, 'Previewed, not committed: the commit sets it to 0.');
        $this->assertSame('TEST-Playwright', $nyala->BranchName);
        $this->assertSame('TEST-Tobacco', $nyala->AreaDescription);
        $this->assertSame('nil', $nyala->TwinResult);
        $this->assertEqualsWithDelta(-20.0, (float) $nyala->ShortQty, 0.0005);
        $this->assertEqualsWithDelta(436.00, (float) $nyala->IssueValue, 0.005, '20 at R21.80: what nobody is charged.');
        $this->assertSame(
            [['twin', '2026-07-17', 1, 'nil'], ['keyed', '2026-07-17', 3, 'short'], ['corrected', '2026-07-17', 3, 'nil']],
            $this->roles($nyala),
        );

        [$twin, $keyed, $corrected] = $nyala->shifts->all();
        $this->assertFigures($twin, open: 9, issued: 20, sold: 3, expected: 26, counted: 26, variance: 0);
        $this->assertFigures($keyed, open: 26, issued: 20, sold: 2, expected: 44, counted: 24, variance: -20);
        $this->assertEqualsWithDelta(-436.00, (float) $keyed->VarValue, 0.005);
        $this->assertFigures($corrected, open: 26, issued: 0, sold: 2, expected: 24, counted: 24, variance: 0);
        $this->assertEqualsWithDelta(20.0, (float) $corrected->IssuedBefore, 0.0005, 'The corrected row carries the issue it replaces.');

        // The charcoal: the FIRST issue never arrived, so the corrected row
        // follows the shift as keyed and the twin comes after them.
        $charcoal = $this->case($cases, 'TCHR');
        $this->assertSame('will_remove', $charcoal->State);
        $this->assertSame(
            [['keyed', '2026-07-17', 1, 'short'], ['corrected', '2026-07-17', 1, 'nil'], ['twin', '2026-07-17', 3, 'nil']],
            $this->roles($charcoal),
        );

        // The possible pair: each the other's twin, both short, the chain held.
        $night = $cases->first(fn ($c) => $c->DupTier === 'D2' && (int) $c->ShiftNo === 3);
        $this->assertSame('possible', $night->Grp);
        $this->assertSame('held', $night->State);
        $this->assertTrue((bool) $night->DupHold);
        $this->assertSame('Both shifts short: which issue was real is not known', $night->DupReason);
        $this->assertSame('short', $night->TwinResult);
        $this->assertSame(
            [['twin', '2026-07-17', 1, 'short'], ['keyed', '2026-07-17', 3, 'short']],
            $this->roles($night),
            'A possible one is never corrected: no third row.',
        );

        $totals = $panel['totals'];
        $this->assertSame(2, (int) $totals->Clear);
        $this->assertSame(2, (int) $totals->ClearCorrected);
        $this->assertEqualsWithDelta(436.00 + 21.80, (float) $totals->ClearValue, 0.005);
        $this->assertSame(2, (int) $totals->Possible);
        $this->assertSame(2, (int) $totals->PossibleHeld);
        $this->assertSame(1, (int) $totals->ChainsHeld, 'Two possible duplicates, one chain.');
        $this->assertEqualsWithDelta(-872.00, (float) $totals->PossibleValue, 0.005);

        // A run made before the labels were stored on the line still names the
        // item and the area — from the estate, through the view, as every
        // other reader of a run line does.
        $this->db()->table(config('agora.schema').'.StockReconRunLine')
            ->where('BranchId', self::BRANCH)->where('Id', $nyala->LineId)
            ->update(['ItemDescription' => null, 'AreaDescription' => null]);
        $unlabelled = $this->case($this->service->duplicates($run)['cases'], 'TDUP');
        $this->assertSame('TEST-Dunhill 10s', $unlabelled->ItemDescription);
        $this->assertSame('TEST-Tobacco', $unlabelled->AreaDescription);
    }

    /**
     * The states move with what happens to the run: committed, a D1 reads as
     * set to 0 by whom; released, a D2 reads as released since the preview;
     * switched off, a D1 is found and not taken out.
     */
    public function test_the_state_follows_the_commit_the_release_and_the_switch(): void
    {
        if (! Permission::query()->acrossBranches()->where('Code', 'stockrecon.duplicates.release')->exists()) {
            $this->markTestSkipped('Run: php artisan seed:master --only=StockReconDuplicateReleasePermissionSeeder');
        }

        config(['stockrecon.stamp_mode' => 'journal']);
        $this->actingAs($finance = $this->person('finance'));

        $run = $this->preview();
        $night = $this->lineAt($run, 'TDP2', 3);
        $this->service->releaseDuplicates($run, [$night->Id], 'TEST-issue book shows one delivery each');

        $released = $this->case($this->service->duplicates($run)['cases'], 'TDP2', 3);
        $this->assertSame('released_since', $released->State, 'Released after this run was made: preview again.');
        $this->assertSame('TEST-issue book shows one delivery each', $released->ReleaseReason);
        $this->assertSame($finance->UserName, $released->ReleasedBy);

        $this->service->commit($run->fresh());
        $nyala = $this->case($this->service->duplicates($run->fresh())['cases'], 'TDUP');
        $this->assertSame('removed', $nyala->State);
        $this->assertSame('journal', $nyala->AmendmentStampMode);
        $this->assertSame($finance->UserName, $nyala->AmendedBy);
        $this->assertNotNull($nyala->AmendedAt);
        $this->assertSame(['twin', 'keyed', 'corrected'], $nyala->shifts->pluck('Role')->all(), 'A written one still shows its correction.');

        $this->service->reverse($run->fresh(), 'TEST-panel reversal');
        $this->assertSame('reversed', $this->case($this->service->duplicates($run->fresh())['cases'], 'TDUP')->State);
        $this->assertSame(['twin', 'keyed'], $this->case($this->service->duplicates($run->fresh())['cases'], 'TDUP')
            ->shifts->pluck('Role')->all(), 'Reversed, the issue is back: no corrected row.');

        config(['stockrecon.issue_mode' => 'off']);
        $off = $this->case($this->service->duplicates($this->preview())['cases'], 'TDUP');
        $this->assertSame('not_removed', $off->State);
        $this->assertSame('Not removed: removing duplicates is switched off', $off->DupReason);
    }

    /**
     * A removed duplicate and a possible one each have their own pill, and the
     * proposals open on the removed one as well as Balanced: its chain is
     * written with it or not at all. Until 5 Oct 2026 a D1 that sold nothing
     * read as Dormant — out of the press — and its chain was skipped.
     */
    public function test_a_duplicate_has_its_own_outcome_and_opens_in_the_press(): void
    {
        $run = $this->preview();
        $charcoal = $this->lineAt($run, 'TCHR', 1);
        $nyala = $this->lineAt($run, 'TDUP', 3);
        $possible = $this->lineAt($run, 'TDP2', 3);

        $this->assertTrue($charcoal->IsDormant, 'With the duplicate out, the day sold nothing and did not move.');
        $this->assertTrue($charcoal->removesDuplicate());
        $this->assertSame('duplicate', $charcoal->outcomeKey(), 'Not dormant: a duplicate issue taken out.');
        $this->assertSame('good', $charcoal->tone());
        $this->assertSame('duplicate', $nyala->outcomeKey());
        $this->assertSame('possible', $possible->outcomeKey());
        $this->assertSame('serious', $possible->tone());
        $this->assertSame('possible', $this->lineAt($run, 'TDP2', 1)->outcomeKey());

        $admin = $this->person('admin');
        $this->actingAs($admin)->get(route('app.stockrecon.run', $run))
            ->assertOk()
            // Opens on All since 7 Oct 2026 (Ryan: "default the pills to all").
            ->assertSee('data-tt-pills
                        data-tt-pill-order="Balanced|Duplicate issue removed|', false)
            ->assertSee('data-sort="Duplicate issue removed"', false)
            ->assertSee('data-sort="Possible duplicate"', false);

        // Switched off, a clear one is found and NOT taken out, so it is not
        // the duplicate state: its row is whatever its chain is.
        config(['stockrecon.issue_mode' => 'off']);
        $kept = $this->lineAt($this->preview(), 'TDUP', 3);
        $this->assertSame('D1', $kept->DupTier);
        $this->assertNotSame('duplicate', $kept->outcomeKey());
    }

    /**
     * The panel on the run and on its exceptions, as somebody who may release
     * and somebody who may only look: the case reads the same, the press is
     * there for one and absent for the other (feature-rules §4).
     */
    public function test_the_panel_shows_each_case_and_offers_the_release_only_to_those_who_may(): void
    {
        if (! Permission::query()->acrossBranches()->where('Code', 'stockrecon.duplicates.release')->exists()) {
            $this->markTestSkipped('Run: php artisan seed:master --only=StockReconDuplicateReleasePermissionSeeder');
        }

        $run = $this->preview();
        $finance = $this->person('finance');
        $operations = $this->person('operations');
        $night = $this->lineAt($run, 'TDP2', 3);

        $panel = fn (User $who, string $route) => $this->actingAs($who)->get(route($route, $run))->assertOk()
            ->assertSee('Duplicate issues')
            ->assertSee('2 clear · 2 possible · 1 chain held back')
            ->assertSee('✓ Clear duplicate (D1)')
            ->assertSee('◆ Possible duplicate (D2)')
            ->assertSee('Will be set to 0')
            ->assertSee('Holding the chain back')
            ->assertSee('TEST-Playwright · TEST-Tobacco · TEST-Dunhill 10s · 17 Jul 2026')
            ->assertSee('17 Jul shift 3, corrected')
            ->assertSee('<span class="ba-old ">20.000</span><span class="ba-new ">0.000</span>', false)
            ->assertSee('✓ nil · not charged')
            ->assertSee('✓ arrived')
            ->assertSee('✕ short 20.000 · R436.00')
            ->assertSee('R436.00 not charged')
            ->assertSee('Both shifts short: which issue was real is not known')
            ->assertSee('role="tablist"', false)
            ->assertSee('class="tab-group"', false)
            ->assertSee('usp_StockRecon_GetDuplicates')
            ->assertSee(route('app.stockrecon.line', [$run, $night]), false);

        $panel($finance, 'app.stockrecon.run')
            ->assertSee('data-dup-release="'.route('app.stockrecon.duplicates.release', $run).'"', false)
            ->assertSee('data-dup-lines="'.$night->Id.'"', false);
        $panel($finance, 'app.stockrecon.exceptions')->assertSee('data-dup-release=', false);

        $panel($operations, 'app.stockrecon.run')->assertDontSee('data-dup-release=', false);
        $panel($operations, 'app.stockrecon.exceptions')->assertDontSee('data-dup-release=', false);

        // Released: the case says by whom and why, and offers the withdraw —
        // to the one who may, and not to the one who may not.
        $this->actingAs($finance);
        $this->service->releaseDuplicates($run, [$night->Id], 'TEST-issue book shows one delivery each');
        $release = (int) $this->db()->table('agora.StockReconDuplicateRelease')
            ->where('BranchId', self::BRANCH)->where('ShiftNo', 3)->whereNull('WithdrawnAt')->value('Id');

        $page = $this->actingAs($finance)->get(route('app.stockrecon.run', [$run, 'dup' => 'dup-'.$night->Id]))->assertOk()
            ->assertSee('Released since this preview')
            ->assertSee('TEST-issue book shows one delivery each')
            ->assertSee('preview again to let it commit')
            ->assertSee('data-dup-withdraw="'.route('app.stockrecon.duplicates.withdraw', [$run, $release]).'"', false)
            ->getContent();

        // The case asked for is the one open: its tab on, and its pane the
        // only one the server did not hide.
        $this->assertMatchesRegularExpression('/data-tab="dup-'.$night->Id.'"\s+aria-selected="true"/', $page);
        $this->assertMatchesRegularExpression('/data-tab-panel="dup-'.$night->Id.'"\s+role="tabpanel"\s*>/', $page);

        $this->actingAs($operations)->get(route('app.stockrecon.run', $run))->assertOk()
            ->assertSee('Released since this preview')
            ->assertDontSee('data-dup-withdraw=', false);
    }

    /** A run that found none says so, and the panel starts folded. */
    public function test_a_run_with_no_duplicates_says_so(): void
    {
        $run = $this->service->preview(
            self::BRANCH, self::AREA, Carbon::parse('2026-07-19'), Carbon::parse('2026-07-21'), note: 'TEST-no duplicates',
        );

        $panel = $this->service->duplicates($run);
        $this->assertTrue($panel['cases']->isEmpty());
        $this->assertSame(0, (int) $panel['totals']->Clear + (int) $panel['totals']->Possible);

        $this->actingAs($this->person('operations'))->get(route('app.stockrecon.run', $run))->assertOk()
            ->assertSee('None on this run')
            ->assertSee('No duplicate issues on this run')
            ->assertDontSee('role="tablist"', false);
    }

    /** The chain panel names a clear duplicate on its chain and links to its case. */
    public function test_the_chain_panel_shows_a_clear_duplicate(): void
    {
        $run = $this->preview();
        $charcoal = $this->lineAt($run, 'TCHR', 1);

        $this->actingAs($this->person('operations'))
            ->get(route('app.stockrecon.line', [$run, $charcoal]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSee('A duplicate issue on this chain')
            ->assertSee('D1 issue 1.000 → 0.000')
            ->assertSee('It is taken out of the balancing and set to 0 by the commit')
            ->assertSee(route('app.stockrecon.run', [$run, 'dup' => 'dup-'.$charcoal->Id]).'#duplicates', false);
    }

    // ------------------------------------------------------------------ helpers

    private function preview(): StockReconRun
    {
        return $this->service->preview(
            self::BRANCH, self::AREA, Carbon::parse('2026-07-17'), Carbon::parse('2026-07-21'), note: 'TEST-duplicates panel',
        )->load('lines');
    }

    /** @param  Collection<int, object>  $cases */
    private function case($cases, string $item, ?int $shift = null): object
    {
        return $cases->first(fn ($c) => $c->StockItemNo === $item && ($shift === null || (int) $c->ShiftNo === $shift))
            ?? throw new \RuntimeException("No case for {$item}.");
    }

    /** @return array<int, array{0: string, 1: string, 2: int, 3: string}> */
    private function roles(object $case): array
    {
        return $case->shifts->map(fn ($s) => [
            $s->Role, Carbon::parse($s->TransactionDate)->toDateString(), (int) $s->ShiftNo, $s->Result,
        ])->all();
    }

    private function assertFigures(object $s, float $open, float $issued, float $sold, float $expected, float $counted, float $variance): void
    {
        foreach (['QtyOpen' => $open, 'QtyIssued' => $issued, 'QtyPOS' => $sold, 'QtyExpected' => $expected,
            'QtyClose' => $counted, 'QtyVar' => $variance] as $column => $want) {
            $this->assertEqualsWithDelta($want, (float) $s->{$column}, 0.0005, "{$s->Role} {$column}");
        }
    }

    private function lineAt(StockReconRun $run, string $item, int $shift, string $date = '2026-07-17'): StockReconRunLine
    {
        return $run->lines()->get()->first(fn ($l) => $l->StockItemNo === $item
            && $l->TransactionDate->toDateString() === $date && $l->ShiftNo === $shift)
            ?? throw new \RuntimeException("No line for {$item} {$date} shift {$shift}.");
    }

    /** A test person holding one access profile (tests/Fixtures/GrantsAccess). */
    private function person(string $profile): User
    {
        $user = User::query()->acrossBranches()->create([
            'BranchId' => (int) config('agora.group_branch_id', 2),
            'EmailAddress' => 'TEST-duppanel-'.$profile.'-'.uniqid().'@agora.invalid',
            'UserName' => 'TEST-'.$profile,
            'IsActive' => true,
            'IsLocked' => false,
            'Workspace' => 'ho',
            'PasswordHash' => 'TEST-only-'.Str::random(24),
        ]);

        $this->grantProfile($user, $profile);
        $this->made[] = $user;

        return $user;
    }

    /**
     * A shelf area (the customer's Retail Items group, so never prepared food)
     * and the three chains. date => [shift => [open, issued, close, pos]].
     */
    private function seedChains(): void
    {
        $db = $this->db();

        $db->table('PumpIT.dbo.STK_Area')->insert([
            'SSBranchId' => self::BRANCH, 'AreaNo' => self::AREA,
            'AreaDescription' => 'TEST-Tobacco', 'AreaGroup' => 'Retail Items',
            'DayShift' => 1, 'AfternoonShift' => 0, 'NightShift' => 1,
            'ShowReport' => 1, 'IsCaptureWaste' => 0,
        ]);

        $chains = [
            'TDUP' => ['TEST-Dunhill 10s', [
                '2026-07-17' => [1 => [9, 20, 26, 3],  3 => [26, 20, 24, 2]],
                '2026-07-18' => [1 => [24, 0, 20, 4],  3 => [20, 0, 18, 2]],
                '2026-07-19' => [1 => [18, 0, 15, 3],  3 => [15, 0, 15, 0]],
                '2026-07-20' => [1 => [15, 0, 12, 3],  3 => [12, 0, 10, 2]],
                '2026-07-21' => [1 => [10, 0, 8, 2],   3 => [8, 0, 7, 1]],
            ]],
            'TCHR' => ['TEST-Charcoal bag', [
                '2026-07-17' => [1 => [8, 1, 8, 0],    3 => [8, 1, 9, 0]],
                '2026-07-18' => [1 => [9, 0, 7, 2],    3 => [7, 0, 7, 0]],
                '2026-07-19' => [1 => [7, 0, 6, 1],    3 => [6, 0, 6, 0]],
                '2026-07-20' => [1 => [6, 0, 5, 1],    3 => [5, 0, 5, 0]],
                '2026-07-21' => [1 => [5, 0, 4, 1],    3 => [4, 0, 4, 0]],
            ]],
            'TDP2' => ['TEST-Marlboro 20s', [
                '2026-07-17' => [1 => [9, 20, 8, 1],   3 => [8, 20, 6, 2]],
                '2026-07-18' => [1 => [6, 0, 4, 2],    3 => [4, 0, 3, 1]],
                '2026-07-19' => [1 => [3, 0, 2, 1],    3 => [2, 0, 2, 0]],
                '2026-07-20' => [1 => [2, 0, 1, 1],    3 => [1, 0, 1, 0]],
                '2026-07-21' => [1 => [1, 0, 1, 0],    3 => [1, 0, 0, 1]],
            ]],
        ];

        foreach ($chains as $item => [$description, $chain]) {
            $db->table('PumpIT.dbo.STK_StockMaster')->insert([
                'SSBranchId' => self::BRANCH, 'StockItemNo' => $item,
                'StockItemDescription' => $description, 'AreaNo' => self::AREA,
                'Location' => 'TEST', 'UOMCode' => 'EA',
                'SellingPrice' => 21.80, 'POSCode' => 'TEST'.$item,
                'QtyVarAllowance' => 0, 'isMonitoredItem' => 0,
            ]);

            foreach ($chain as $date => $shifts) {
                foreach ($shifts as $shift => [$open, $issued, $close, $pos]) {
                    $db->table('PumpIT.dbo.STK_StockReconLine')->insert([
                        'SSBranchId' => self::BRANCH, 'TransactionDate' => $date, 'ShiftNo' => $shift,
                        'AreaNo' => self::AREA, 'StockItemNo' => $item, 'SellPrice' => 21.80,
                        'QtyOpen' => $open, 'QtyIssued' => $issued, 'QtyClose' => $close, 'QtyComputer' => $pos,
                        'QtyOpen_Original' => $open, 'QtyIssued_Original' => $issued,
                        'QtyClose_Original' => $close, 'QtyComputer_Original' => $pos,
                    ]);
                }
            }
        }
    }

    /**
     * Only what this test writes: its three items and its area in the PumpIT
     * stub, and Agora's stock recon ledger for the fixture branch (the runs
     * it previews, their lines, amendments and releases).
     */
    private function cleanUp(): void
    {
        $db = $this->db();

        $db->table('PumpIT.dbo.STK_StockReconLine')->where('SSBranchId', self::BRANCH)
            ->whereIn('StockItemNo', self::ITEMS)->delete();
        $db->table('PumpIT.dbo.STK_StockMaster')->where('SSBranchId', self::BRANCH)
            ->whereIn('StockItemNo', self::ITEMS)->delete();
        $db->table('PumpIT.dbo.STK_Area')->where('SSBranchId', self::BRANCH)->where('AreaNo', self::AREA)->delete();

        $schema = config('agora.schema');

        foreach (['StockReconAmendment', 'StockReconRunLine', 'StockReconRun', 'StockReconDuplicateRelease'] as $table) {
            $db->table("{$schema}.{$table}")->where('BranchId', self::BRANCH)->delete();
        }
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }
}
