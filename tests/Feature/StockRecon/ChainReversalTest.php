<?php

namespace Tests\Feature\StockRecon;

use App\Exceptions\AgoraProcException;
use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\User;
use Modules\Core\Models\UserPermission;
use Modules\Core\Services\PermissionService;
use Modules\StockRecon\Models\StockReconAmendment;
use Modules\StockRecon\Models\StockReconRun;
use Modules\StockRecon\Models\StockReconRunLine;
use Modules\StockRecon\Services\StockReconService;
use Tests\Fixtures\GrantsAccess;
use Tests\TestCase;

/**
 * Reversing ONE item's chain inside a committed run (12 Nov 2026,
 * agora.usp_StockRecon_ReverseChain).
 *
 * One shelf-goods area, two items, both amended by one live commit: item A has
 * a missing issue captured (QtyIssued written), item B has a short and the over
 * after it balanced (a closing and the next opening written). Reversing A must
 * put A's rows back to their priors, leave B's rows and amendments alone and
 * leave the run committed; reversing B then leaves the run reversed.
 *
 * The fixture writes to the LOCAL PumpIT stub on branch 999 only, and is
 * removed by branch in tearDown — the helpers are copies of BalancingTest's.
 */
class ChainReversalTest extends TestCase
{
    use GrantsAccess;

    /** The E2E fixture branch: inactive, non-trading, and nobody's real site. */
    private const BRANCH = 999;

    /** Shelf goods, so a missing issue can be captured. */
    private const AREA = 2;

    /** Five characters at most: STK_StockMaster.StockItemNo is NVARCHAR(5) in the stub. */
    private const ISSUE_ITEM = 'TCHA';

    private const COUNT_ITEM = 'TCHB';

    private StockReconService $service;

    /** @var array<int, User> people a test made, removed in tearDown */
    private array $made = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessLocalStub();
        $this->service = new StockReconService(new ProcedureService);
        $this->cleanUp();
        $this->seedFixture();

        config(['stockrecon.stamp_mode' => 'live', 'stockrecon.issue_mode' => 'live', 'stockrecon.missing_issue_mode' => 'live']);
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

    public function test_reversing_one_chain_restores_it_and_leaves_the_other_and_the_run_committed(): void
    {
        $before = ['A' => $this->rowsOf(self::ISSUE_ITEM), 'B' => $this->rowsOf(self::COUNT_ITEM)];
        $run = $this->commitBoth();
        $committedB = $this->rowsOf(self::COUNT_ITEM);

        $this->assertNotSame($before['A'], $this->rowsOf(self::ISSUE_ITEM), 'The commit must write A for this to prove anything.');
        $this->assertNotSame($before['B'], $committedB, 'The commit must write B for this to prove anything.');
        $this->assertEqualsWithDelta(2.0, (float) $this->sourceRow(self::ISSUE_ITEM, '2026-07-18', 1)->QtyIssued, 0.005, 'A: the missing issue is in PumpIT.');
        $this->assertEqualsWithDelta(24.0, (float) $this->sourceRow(self::COUNT_ITEM, '2026-07-18', 1)->QtyClose, 0.005, 'B: the short closing is amended.');
        $this->assertEqualsWithDelta(24.0, (float) $this->sourceRow(self::COUNT_ITEM, '2026-07-18', 3)->QtyOpen, 0.005, 'B: the next opening moves with it.');
        $amendmentsB = $this->amendments($run, self::COUNT_ITEM)->pluck('Id')->sort()->values()->all();

        $status = $this->service->reverseChain($run, self::AREA, self::ISSUE_ITEM, 'TEST-wrong issue on A');

        $this->assertSame('REVERSED', $status->Code);
        $this->assertStringContainsString('stay posted', $status->Message);
        $this->assertSame($before['A'], $this->rowsOf(self::ISSUE_ITEM), 'A is back to its priors: counts and the issue.');
        $this->assertSame($committedB, $this->rowsOf(self::COUNT_ITEM), 'B is untouched in PumpIT.');

        $a = $this->amendments($run, self::ISSUE_ITEM);
        $this->assertTrue($a->isNotEmpty() && $a->every(fn ($x) => $x->State === 'reversed' && $x->ReversedAt !== null
            && $x->ReversalReason === 'TEST-wrong issue on A'), 'A\'s amendments are reversed, with when and why.');
        $b = $this->amendments($run, self::COUNT_ITEM);
        $this->assertTrue($b->every(fn ($x) => $x->State === 'active' && $x->ReversedAt === null && $x->ReversalReason === null), 'B\'s amendments stand.');
        $this->assertSame($amendmentsB, $b->pluck('Id')->sort()->values()->all());

        $fresh = $run->fresh();
        $this->assertSame('committed', $fresh->Status, 'The run stays committed while B stands.');
        $this->assertNull($fresh->ReversalReason);
        $this->assertSame(0, $this->lines($run, self::ISSUE_ITEM)->where('CommitState', 'committed')->count(), 'A\'s proposals are pending again.');
        $this->assertGreaterThan(0, $this->lines($run, self::COUNT_ITEM)->where('CommitState', 'committed')->count(), 'B\'s stay committed.');

        // The audit trail reads the same ledger: A's rows read reversed, B's active.
        $states = $this->db()->table('agora.vw_PumpItChange')->where('Ledger', 'StockReconAmendment')
            ->whereIn('LedgerId', $a->pluck('Id')->merge($b->pluck('Id'))->all())->pluck('State', 'LedgerId');
        $this->assertTrue($a->every(fn ($x) => $states[$x->Id] === 'reversed'));
        $this->assertTrue($b->every(fn ($x) => $states[$x->Id] === 'active'));

        // And it says WHY, while the run is still committed and has no reason
        // of its own (v1__22r): the reason is the amendment's.
        $reasons = fn () => $this->db()->table('agora.vw_PumpItChange')->where('Ledger', 'StockReconAmendment')
            ->whereIn('LedgerId', $a->pluck('Id')->merge($b->pluck('Id'))->all())->pluck('ReversalReason', 'LedgerId');
        $why = $reasons();
        $this->assertTrue($a->every(fn ($x) => $why[$x->Id] === 'TEST-wrong issue on A'), 'The trail carries the chain reversal\'s reason.');
        $this->assertTrue($b->every(fn ($x) => $why[$x->Id] === null), 'An amendment still standing has none.');

        // The last chain standing: the run is reversed, as the run-level reversal leaves it.
        $status = $this->service->reverseChain($run->fresh(), self::AREA, self::COUNT_ITEM, 'TEST-B too');

        $this->assertStringContainsString('the run is reversed', $status->Message);
        $this->assertSame($before['B'], $this->rowsOf(self::COUNT_ITEM), 'B is back to its priors.');
        $this->assertSame($before['A'], $this->rowsOf(self::ISSUE_ITEM));
        $fresh = $run->fresh();
        $this->assertSame('reversed', $fresh->Status);
        $this->assertNotNull($fresh->ReversedAt);
        $this->assertSame('TEST-B too', $fresh->ReversalReason);
        $this->assertSame(0, StockReconRunLine::query()->acrossBranches()->where('RunId', $run->Id)->where('CommitState', 'committed')->count());

        // Each chain keeps its OWN reason in the trail once the run is reversed:
        // the run's reason is the last chain's, and must not be painted over A.
        $why = $reasons();
        $this->assertTrue($a->every(fn ($x) => $why[$x->Id] === 'TEST-wrong issue on A'));
        $this->assertTrue($b->every(fn ($x) => $why[$x->Id] === 'TEST-B too'));
    }

    /** A row changed in PumpIT since the commit refuses the whole chain, and nothing is written. */
    public function test_a_row_moved_since_the_commit_refuses_the_whole_chain(): void
    {
        $run = $this->commitBoth();
        $committed = ['A' => $this->rowsOf(self::ISSUE_ITEM), 'B' => $this->rowsOf(self::COUNT_ITEM)];
        $pending = fn () => StockReconRunLine::query()->acrossBranches()->where('RunId', $run->Id)->where('CommitState', 'pending')->count();
        $pendingBefore = $pending();

        // Somebody recounts B's night opening, the second row of the chain.
        $this->setRow(self::COUNT_ITEM, '2026-07-18', 3, ['QtyOpen' => 25]);

        try {
            $this->service->reverseChain($run, self::AREA, self::COUNT_ITEM, 'TEST-count moved');
            $this->fail('The chain reversal should have been refused.');
        } catch (AgoraProcException $e) {
            $this->assertSame('COUNT_MOVED', $e->code());
            $this->assertStringContainsString('on 2026-07-18 shift 3 has changed', $e->getMessage());
        }

        $this->assertEqualsWithDelta(24.0, (float) $this->sourceRow(self::COUNT_ITEM, '2026-07-18', 1)->QtyClose, 0.005,
            'The day closing, which had not moved, is not put back on its own.');
        $this->assertEqualsWithDelta(25.0, (float) $this->sourceRow(self::COUNT_ITEM, '2026-07-18', 3)->QtyOpen, 0.005, 'The recount stands.');
        $this->assertTrue($this->amendments($run, self::COUNT_ITEM)->every(fn ($x) => $x->State === 'active'), 'Nothing on B was reversed.');

        // A re-keyed issue on A refuses A the same way.
        $this->setRow(self::ISSUE_ITEM, '2026-07-18', 1, ['QtyIssued' => 4]);

        try {
            $this->service->reverseChain($run, self::AREA, self::ISSUE_ITEM, 'TEST-issue moved');
            $this->fail('The chain reversal should have been refused.');
        } catch (AgoraProcException $e) {
            $this->assertSame('ISSUE_MOVED', $e->code());
        }

        $this->assertEqualsWithDelta(4.0, (float) $this->sourceRow(self::ISSUE_ITEM, '2026-07-18', 1)->QtyIssued, 0.005);
        $this->assertTrue($this->amendments($run, self::ISSUE_ITEM)->every(fn ($x) => $x->State === 'active'), 'Nothing on A was reversed.');
        $this->assertSame('committed', $run->fresh()->Status);
        $this->assertSame($pendingBefore, $pending(), 'No proposal went back to pending.');

        // Put back to what the commit left, the chain goes through.
        $this->setRow(self::COUNT_ITEM, '2026-07-18', 3, ['QtyOpen' => 24]);
        $this->assertSame($committed['B'], $this->rowsOf(self::COUNT_ITEM));
        $this->assertSame('REVERSED', $this->service->reverseChain($run->fresh(), self::AREA, self::COUNT_ITEM, 'TEST-count back')->Code);
    }

    /** A reason is required; a chain with nothing standing, and a run that is not there, are refused. */
    public function test_a_missing_reason_and_a_chain_with_nothing_active_are_refused(): void
    {
        foreach (['', '   '] as $reason) {
            $this->assertRefused('REASON_REQUIRED', fn () => $this->service->reverseChain(
                (new StockReconRun)->forceFill(['Id' => 0, 'BranchId' => self::BRANCH]), self::AREA, self::ISSUE_ITEM, $reason));
        }

        $run = $this->previewArea();
        $this->assertRefused('NO_RUN', fn () => $this->service->reverseChain(
            (new StockReconRun)->forceFill(['Id' => -1, 'BranchId' => self::BRANCH]), self::AREA, self::ISSUE_ITEM, 'TEST-no run'));
        $this->assertRefused('NOTHING_TO_REVERSE', fn () => $this->service->reverseChain($run, self::AREA, self::ISSUE_ITEM, 'TEST-not committed'),
            'A preview has no amendment to reverse.');

        $this->service->commit($run);
        $this->assertRefused('REASON_REQUIRED', fn () => $this->service->reverseChain($run->fresh(), self::AREA, self::ISSUE_ITEM, ''));
        $this->assertTrue($this->amendments($run, self::ISSUE_ITEM)->every(fn ($x) => $x->State === 'active'));
        $this->assertRefused('NOTHING_TO_REVERSE', fn () => $this->service->reverseChain($run->fresh(), self::AREA, 'TNONE', 'TEST-not on the run'));
        $this->assertRefused('NOTHING_TO_REVERSE', fn () => $this->service->reverseChain($run->fresh(), 1, self::ISSUE_ITEM, 'TEST-wrong area'));

        $this->service->reverseChain($run->fresh(), self::AREA, self::ISSUE_ITEM, 'TEST-once');
        $this->assertRefused('NOTHING_TO_REVERSE', fn () => $this->service->reverseChain($run->fresh(), self::AREA, self::ISSUE_ITEM, 'TEST-twice'),
            'A chain already reversed has nothing left.');
    }

    /** A journal amendment is reversed in Agora only: nothing in PumpIT is checked or written. */
    public function test_a_journal_chain_reversal_writes_nothing_to_pumpit(): void
    {
        config(['stockrecon.stamp_mode' => 'journal']);
        $before = $this->rowsOf(self::COUNT_ITEM);
        $run = $this->commitBoth();
        $this->assertSame($before, $this->rowsOf(self::COUNT_ITEM), 'A journal commit writes nothing.');

        $this->setRow(self::COUNT_ITEM, '2026-07-18', 3, ['QtyOpen' => 25]);
        $moved = $this->rowsOf(self::COUNT_ITEM);

        $this->assertSame('REVERSED', $this->service->reverseChain($run, self::AREA, self::COUNT_ITEM, 'TEST-journal')->Code);
        $this->assertSame($moved, $this->rowsOf(self::COUNT_ITEM), 'A journal reversal writes nothing to PumpIT.');
        $this->assertTrue($this->amendments($run, self::COUNT_ITEM)->every(fn ($x) => $x->State === 'reversed'));
    }

    /**
     * The press: on the chain page of a committed run, one form whose
     * confirmation names the PumpIT table, each row and column, old → new; in
     * the fragment (inside the commit form) a link to that page. Behind
     * stockrecon.runs.reverse.
     */
    public function test_the_chain_page_carries_the_press_and_it_reverses_the_chain(): void
    {
        $run = $this->commitBoth();
        $lineB = $this->lines($run, self::COUNT_ITEM)->firstOrFail();
        $admin = $this->person('admin');

        $this->actingAs($admin)->get(route('app.stockrecon.line', [$run, $lineB]))
            ->assertOk()
            ->assertSee('id="reverse-chain"', false)
            ->assertSee('action="'.route('app.stockrecon.lines.reverse', [$run, $lineB]).'"', false)
            ->assertSee('data-diff="STK_StockReconLine · TEST-Item B, item '.self::COUNT_ITEM.', area '.self::AREA.', site '.self::BRANCH.'"', false)
            ->assertSee('data-diff-field="18 Jul shift 1 (Day) · QtyClose" data-diff-old="24" value="21"', false)
            ->assertSee('data-diff-field="18 Jul shift 3 (Night) · QtyOpen" data-diff-old="24" value="21"', false)
            ->assertSee('name="reason" required', false);

        $this->actingAs($admin)->get(route('app.stockrecon.line', [$run, $lineB]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSee('Reverse this item…')
            ->assertDontSee('id="reverse-chain"', false);

        $operations = $this->person('operations');
        $this->actingAs($operations)->get(route('app.stockrecon.line', [$run, $lineB]))
            ->assertOk()->assertDontSee('id="reverse-chain"', false);
        $this->actingAs($operations)->post(route('app.stockrecon.lines.reverse', [$run, $lineB]), ['reason' => 'TEST-not mine'])
            ->assertForbidden();

        $this->actingAs($admin)->from(route('app.stockrecon.line', [$run, $lineB]))
            ->post(route('app.stockrecon.lines.reverse', [$run, $lineB]), ['reason' => ''])
            ->assertRedirect(route('app.stockrecon.line', [$run, $lineB]))
            ->assertSessionHas('refusal');

        $this->actingAs($admin)->post(route('app.stockrecon.lines.reverse', [$run, $lineB]), ['reason' => 'TEST-pressed'])
            ->assertRedirect(route('app.stockrecon.run', $run))
            ->assertSessionHas('committed')
            ->assertSessionHas('committedCode', 'REVERSED');

        // The run page says what a reversal did, not that ticked shifts went through.
        $this->actingAs($admin)->withSession(['committed' => 'TEST-put back', 'committedCode' => 'REVERSED'])
            ->get(route('app.stockrecon.run', $run))
            ->assertOk()
            ->assertSee('PumpIT holds what it held before this run wrote it.')
            ->assertDontSee('Every ticked shift went through.');

        $this->assertTrue($this->amendments($run, self::COUNT_ITEM)->every(fn ($x) => $x->State === 'reversed'
            && $x->ReversalReason === 'TEST-pressed' && (int) $x->ReversedBy === (int) $admin->Id));
        $this->assertTrue($this->amendments($run, self::ISSUE_ITEM)->every(fn ($x) => $x->State === 'active'));

        // Nothing left standing on B: the page no longer offers the press.
        $this->actingAs($admin)->get(route('app.stockrecon.line', [$run, $lineB]))
            ->assertOk()->assertDontSee('id="reverse-chain"', false);
    }

    // ---------------------------------------------------------------- helpers

    /** Preview the shelf area and commit it live: both chains written. */
    private function commitBoth(): StockReconRun
    {
        $run = $this->previewArea();

        foreach ([self::ISSUE_ITEM, self::COUNT_ITEM] as $item) {
            $this->assertGreaterThan(0, $run->lines->where('StockItemNo', $item)->where('WouldAmend', true)->count(),
                "{$item} must be amended for this to prove anything: ".$run->lines->where('StockItemNo', $item)->pluck('Outcome')->unique()->implode('; '));
        }

        $this->assertSame('COMMITTED', $this->service->commit($run)['status']->Code);

        return $run->fresh();
    }

    private function assertRefused(string $code, callable $call, string $message = ''): void
    {
        try {
            $call();
            $this->fail("Expected {$code}. {$message}");
        } catch (AgoraProcException $e) {
            $this->assertSame($code, $e->code(), $message.' '.$e->getMessage());
        }
    }

    /** @return Collection<int, StockReconAmendment> */
    private function amendments(StockReconRun $run, string $item): Collection
    {
        return StockReconAmendment::query()->acrossBranches()
            ->where('BranchId', self::BRANCH)->where('RunId', $run->Id)->where('StockItemNo', $item)->get();
    }

    /** @return Collection<int, StockReconRunLine> */
    private function lines(StockReconRun $run, string $item): Collection
    {
        return StockReconRunLine::query()->acrossBranches()
            ->where('BranchId', self::BRANCH)->where('RunId', $run->Id)->where('StockItemNo', $item)
            ->orderBy('LineNo')->get();
    }

    /** Every column a commit or a reversal may write, for one item, as it stands in PumpIT. */
    private function rowsOf(string $item): string
    {
        return (string) json_encode(
            $this->db()->table('PumpIT.dbo.STK_StockReconLine')
                ->where('SSBranchId', self::BRANCH)->where('StockItemNo', $item)
                ->orderBy('TransactionDate')->orderBy('ShiftNo')
                ->get(['TransactionDate', 'ShiftNo', 'QtyOpen', 'QtyIssued', 'QtyClose', 'QtyComputer'])
                ->all()
        );
    }

    private function previewArea(): StockReconRun
    {
        return $this->service->preview(
            self::BRANCH,
            self::AREA,
            Carbon::parse('2026-07-17'),
            Carbon::parse('2026-07-21'),
            note: 'TEST-chain reversal',
        )->load('lines');
    }

    private function sourceRow(string $item, string $date, int $shift): object
    {
        return $this->db()->table('PumpIT.dbo.STK_StockReconLine')
            ->where('SSBranchId', self::BRANCH)->where('StockItemNo', $item)
            ->where('TransactionDate', $date)->where('ShiftNo', $shift)->first();
    }

    /** @param  array<string, float|int>  $values */
    private function setRow(string $item, string $date, int $shift, array $values): void
    {
        $this->db()->table('PumpIT.dbo.STK_StockReconLine')
            ->where('SSBranchId', self::BRANCH)->where('StockItemNo', $item)
            ->where('TransactionDate', $date)->where('ShiftNo', $shift)
            ->update($values);
    }

    /** A test person holding one access profile (tests/Fixtures/GrantsAccess). */
    private function person(string $profile): User
    {
        $user = User::query()->acrossBranches()->create([
            'BranchId' => (int) config('agora.group_branch_id', 2),
            'EmailAddress' => 'TEST-chain-'.$profile.'-'.uniqid().'@agora.invalid',
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
     * A shelf-goods area with two items:
     *   A — the 18th's day counts 7 from an opening of 5 with nothing issued:
     *       one over of exactly the issue multiple (2), captured as a missing
     *       issue (BalancingTest's missing-issue chain);
     *   B — the 18th's day closes 21 where 24 was due (short 3) and the night
     *       counts 22 where 19 was due (over 3): the closing and the next
     *       opening move to 24 together. Its multiple (10) keeps the over a
     *       count, not an issue.
     */
    private function seedFixture(): void
    {
        $db = $this->db();

        $db->table('PumpIT.dbo.STK_Area')->insert([
            'SSBranchId' => self::BRANCH, 'AreaNo' => self::AREA,
            'AreaDescription' => 'TEST-Tobacco', 'AreaGroup' => 'Retail Items',
            'DayShift' => 1, 'AfternoonShift' => 0, 'NightShift' => 1,
            'ShowReport' => 1, 'IsCaptureWaste' => 0,
        ]);

        $chains = [
            self::ISSUE_ITEM => ['TEST-Item A', 2, [
                '2026-07-17' => [1 => [5, 0, 5, 0], 3 => [5, 0, 5, 0]],
                '2026-07-18' => [1 => [5, 0, 7, 0], 3 => [7, 0, 7, 0]],
                '2026-07-19' => [1 => [7, 0, 7, 0], 3 => [7, 0, 7, 0]],
            ]],
            self::COUNT_ITEM => ['TEST-Item B', 10, [
                '2026-07-17' => [1 => [30, 0, 28, 2], 3 => [28, 0, 26, 2]],
                '2026-07-18' => [1 => [26, 0, 21, 2], 3 => [21, 0, 22, 2]],
                '2026-07-19' => [1 => [22, 0, 20, 2], 3 => [20, 0, 18, 2]],
            ]],
        ];

        foreach ($chains as $no => [$description, $multiple, $chain]) {
            $db->table('PumpIT.dbo.STK_StockMaster')->insert([
                'SSBranchId' => self::BRANCH, 'StockItemNo' => $no,
                'StockItemDescription' => $description, 'AreaNo' => self::AREA,
                'Location' => 'TEST', 'UOMCode' => 'EA',
                'SellingPrice' => 35.00, 'POSCode' => 'TEST'.$no,
                'QtyVarAllowance' => 0, 'isMonitoredItem' => 0, 'IssueMultiple' => $multiple,
            ]);

            foreach ($chain as $date => $shifts) {
                foreach ($shifts as $shift => [$open, $issued, $close, $pos]) {
                    $db->table('PumpIT.dbo.STK_StockReconLine')->insert($this->line($no, $date, $shift, $open, $issued, $close, $pos));
                }
            }
        }
    }

    /** @return array<string, mixed> */
    private function line(string $item, string $date, int $shift, float $open, float $issued, float $close, float $pos): array
    {
        return [
            'SSBranchId' => self::BRANCH,
            'TransactionDate' => $date,
            'ShiftNo' => $shift,
            'AreaNo' => self::AREA,
            'StockItemNo' => $item,
            'SellPrice' => 35.00,
            'QtyOpen' => $open, 'QtyIssued' => $issued, 'QtyClose' => $close, 'QtyComputer' => $pos,
            'QtyOpen_Original' => $open, 'QtyIssued_Original' => $issued,
            'QtyClose_Original' => $close, 'QtyComputer_Original' => $pos,
        ];
    }

    /** As BalancingTest::cleanUp(): everything on branch 999, in PumpIT's stub and in Agora. */
    private function cleanUp(): void
    {
        $db = $this->db();

        foreach (['STK_StockReconLine', 'STK_StockReconEmployees', 'STK_StockWasteLine', 'STK_StockMaster',
            'STK_Area', 'BRN_Employee'] as $table) {
            $db->table("PumpIT.dbo.{$table}")->where('SSBranchId', self::BRANCH)->delete();
        }

        $schema = config('agora.schema');

        $db->table('agora.StockReconItemProfile')->where('BranchId', self::BRANCH)->delete();
        $db->table('agora.StockReconProfileEvent')->where('CreatedBy', -999)->delete();
        $db->table('agora.StockReconBalancingProfile')->where('CreatedBy', -999)->where('Name', 'TEST item profile')->delete();

        foreach (['StockReconAmendment', 'StockReconRunLine', 'StockReconRun', 'StockIssueMultiple', 'StockAreaSetting',
            'StockReconDuplicateRelease', 'StockWasteAuthorization', 'StockReconItemRemoval', 'StockReconAreaShiftCompletion'] as $table) {
            $db->table("{$schema}.{$table}")->where('BranchId', self::BRANCH)->delete();
        }
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }
}
