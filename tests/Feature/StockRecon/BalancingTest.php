<?php

namespace Tests\Feature\StockRecon;

use App\Exceptions\AgoraProcException;
use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\Permission;
use Modules\Core\Models\User;
use Modules\Core\Models\UserPermission;
use Modules\Core\Services\PermissionService;
use Modules\StockRecon\Models\StockReconAmendment;
use Modules\StockRecon\Models\StockReconRun;
use Modules\StockRecon\Models\StockReconRunLine;
use Modules\StockRecon\Services\ChainSummary;
use Modules\StockRecon\Services\StockReconService;
use Tests\Fixtures\GrantsAccess;
use Tests\TestCase;

/**
 * Shift variance balancing, against a chain built to exercise every rule.
 *
 * THE ONE ASSERTION THIS FILE EXISTS FOR is `test_the_invariant_holds`. The
 * whole method rests on a single fact: balancing REDISTRIBUTES variance and
 * cannot reduce it, because every closing count is also the next opening. So
 * once every over is zeroed, the residual short must equal |T| exactly — not
 * approximately, and not less. A change that made the residual smaller would
 * look like an improvement and would in fact be the arithmetic quietly
 * breaking. Everything else here is scaffolding for that one.
 *
 * The fixture writes to the LOCAL PumpIT stub, never to the customer's
 * instance, and only to branch 999 — the inactive branch E2eFixtureSeeder
 * creates for exactly this. tearDown() removes every row it wrote.
 *
 * The chain is the worked example from the method note: shift 1 short, shift 3
 * over by nearly the same amount, every day, ending five units short. It is
 * the pattern the admin balances by hand, so the numbers can be checked
 * against a person rather than against the code that produced them.
 */
class BalancingTest extends TestCase
{
    use GrantsAccess;

    /** The E2E fixture branch: inactive, non-trading, and nobody's real site. */
    private const BRANCH = 999;

    private const AREA = 1;

    /**
     * Five characters, not the usual TEST- prefix.
     *
     * STK_StockMaster.StockItemNo is NVARCHAR(5) in the local stub — the
     * customer's is NVARCHAR(10), though the longest item number they actually
     * use is three characters. Everything this fixture writes is on branch 999
     * and is deleted by branch in tearDown, so the prefix is not what makes it
     * findable here; the branch is.
     */
    private const ITEM = 'TBAL';

    /** A second item, dormant throughout, to prove rule 3 on its own. */
    private const DORMANT_ITEM = 'TDRM';

    /**
     * Duplicate issues need SHELF goods — the fixture's own area is hot food,
     * where rule 5 never lets one be clear — so they get an area of their own.
     */
    private const SHELF_AREA = 2;

    private const DUP_ITEM = 'TDUP';

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
     * THE INVARIANT. Every over goes to zero, and what is left short is
     * exactly |T| — the total the window fixed before anything was amended.
     *
     * Checked on the UNBLOCKED chain only, because a blocked one carries no
     * proposal at all and its counted variance stands.
     */
    public function test_the_invariant_holds(): void
    {
        $run = $this->preview();

        $lines = $run->lines->where('StockItemNo', self::ITEM);

        $overAfter = $lines->filter(fn ($l) => (float) $l->QtyVarNew > 0.005);
        $shortAfter = $lines->sum(fn ($l) => max(0.0, -(float) $l->QtyVarNew));
        $total = $lines->sum(fn ($l) => (float) $l->QtyVar);

        $this->assertTrue($overAfter->isEmpty(), 'No shift may end over: an over is written down to zero and no further.');
        $this->assertEqualsWithDelta(abs($total), $shortAfter, 0.001,
            'The residual short must equal |T| exactly — it is the floor the invariant fixes, not a target.');
        $this->assertEqualsWithDelta(-5.0, $total, 0.001, 'The worked example ends five units short.');
    }

    /** Rule 1: an over is corrected to zero and never past it into a short. */
    public function test_an_over_never_becomes_a_short(): void
    {
        $run = $this->preview();

        foreach ($run->lines->where('StockItemNo', self::ITEM) as $line) {
            if ((float) $line->QtyVar > 0.005) {
                $this->assertGreaterThanOrEqual(-0.005, (float) $line->QtyVarNew,
                    "Shift {$line->ShiftNo} on {$line->TransactionDate->toDateString()} was over and has been made short.");
            }
        }
    }

    /**
     * Rule 2: no shift is made shorter than its own count said it was.
     *
     * The floor is `min(counted variance, 0)`, not the counted variance
     * itself — an OVER being written down to zero is rule 1 doing its job and
     * is not a shift being made shorter. Asserting the stricter thing failed
     * on exactly that, which is the assertion being wrong rather than the
     * arithmetic.
     */
    public function test_no_shift_is_made_shorter_than_its_own_count(): void
    {
        $run = $this->preview();

        foreach ($run->lines->where('StockItemNo', self::ITEM) as $line) {
            $floor = min((float) $line->QtyVar, 0.0);

            $this->assertGreaterThanOrEqual($floor - 0.005, (float) $line->QtyVarNew,
                'Nobody\'s charge goes up because of a correction made to somebody else\'s shift.');
        }
    }

    /**
     * Rule 3, and the reason it is structural rather than a special case.
     *
     * A dormant shift's opening and closing stay equal TO EACH OTHER, not at
     * their original values — the opening IS the previous closing, so pinning
     * it would break the chain. What must never happen is a dormant shift
     * ending with a variance, which is a charge on somebody who was not there.
     */
    public function test_a_dormant_shift_never_carries_a_variance(): void
    {
        $run = $this->preview();

        $dormant = $run->lines->where('IsDormant', true);

        $this->assertGreaterThan(0, $dormant->count(), 'The fixture must contain dormant shifts for this to mean anything.');

        foreach ($dormant as $line) {
            $this->assertEqualsWithDelta(0.0, (float) $line->QtyVarNew, 0.005,
                'A shift that did not trade must not end carrying a variance.');
            $this->assertEqualsWithDelta((float) $line->QtyOpenNew, (float) $line->QtyCloseNew, 0.005,
                'A dormant shift\'s opening and closing must move together.');
            $this->assertFalse($line->FlagDormantMoved, 'The rule-3 guard fired.');
        }
    }

    /**
     * The last active shift is the anchor: the latest count is the one that is
     * trusted, so it is never amended.
     */
    public function test_the_last_active_shift_is_never_amended(): void
    {
        $run = $this->preview();

        $last = $run->lines
            ->where('StockItemNo', self::ITEM)
            ->where('IsDormant', false)
            ->sortByDesc('LineNo')
            ->first();

        $this->assertEqualsWithDelta(0.0, (float) $last->AmendClose, 0.005,
            'The closing count at the end of the window is the anchor and is never moved.');
    }

    /**
     * A row whose CLOSING is pinned can still have its OPENING moved, and the
     * commit has to write it. Leaving those rows out is what puts a hole in
     * the chain exactly where the anchor is.
     */
    public function test_a_row_whose_opening_alone_moves_is_still_written(): void
    {
        $run = $this->preview();

        $openingOnly = $run->lines->filter(
            fn (StockReconRunLine $l) => $l->WouldAmend && abs((float) $l->AmendClose) < 0.005
        );

        $this->assertGreaterThan(0, $openingOnly->count(),
            'The worked example has at least one shift whose opening follows an amended closing before it.');

        foreach ($openingOnly as $line) {
            $this->assertNotEqualsWithDelta((float) $line->QtyOpen, (float) $line->QtyOpenNew, 0.005,
                'A row marked as moving must actually move one of its two counts.');
        }
    }

    /**
     * The client's rule 3 (ZRP weekly pack, 23 Sep 2026): the opening must
     * equal the previous closing, and where it does not the OPENING is
     * amended — the closing stands. Until then a break blocked the chain.
     *
     * Opening ABOVE the previous closing is S10b. The row is repaired to the
     * closing before it, that closing is not moved by the balancing (the pack
     * would flag it S12 if it were), and the chain still balances.
     */
    public function test_a_broken_chain_is_repaired_by_amending_the_opening(): void
    {
        $this->setCounts('2026-07-19', 1, ['QtyOpen_Original' => 999, 'QtyOpen' => 999]);

        $run = $this->preview();
        $lines = $run->lines->where('StockItemNo', self::ITEM)->keyBy(fn ($l) => $l->TransactionDate->toDateString().'/'.$l->ShiftNo);

        $broken = $lines['2026-07-19/1'];
        $before = $lines['2026-07-18/3'];

        $this->assertTrue($broken->FlagChainBroken, 'The break was not noticed.');
        $this->assertSame('S10b', $broken->ExceptionCode, 'An opening above the previous closing is the pack\'s S10b.');
        $this->assertEqualsWithDelta(949.0, (float) $broken->ChainBreak, 0.005);
        $this->assertFalse($broken->ChainBlocked, 'A break is repaired now, not blocked.');
        $this->assertEqualsWithDelta(50.0, (float) $broken->QtyOpenNew, 0.005, 'The opening is amended to the previous closing.');
        $this->assertTrue($broken->WouldAmend, 'The repair is written.');
        $this->assertStringStartsWith('Opening repaired', $broken->Outcome);

        $this->assertEqualsWithDelta((float) $before->QtyClose, (float) $before->QtyCloseNew, 0.005,
            'The closing before a repaired opening stands — moving it is what the pack calls S12.');

        // The invariant still holds, on the repaired total: every over goes to
        // zero and the residual short is exactly the chain total.
        $this->assertTrue($lines->every(fn ($l) => (float) $l->QtyVarNew <= 0.005));
        $this->assertEqualsWithDelta(-5.0, $lines->sum(fn ($l) => (float) $l->QtyVarNew), 0.001);
        $this->assertEqualsWithDelta(-5.0, (float) $broken->ChainNetVar, 0.001,
            'T is the repaired total: the break is not a loss or a gain, it is a capture error.');
        $this->assertEqualsWithDelta(-5.0 - 949.0, $lines->sum(fn ($l) => (float) $l->QtyVar), 0.001,
            'The counted variance still shows what the staff actually wrote down.');
        $this->assertSame(1, (int) $run->fresh()->RepairedOpenings);
    }

    /**
     * Opening BELOW the previous closing: stock vanished between two shifts,
     * the pack's S10a and "the known manipulation route". The incoming shift
     * carries it — the repair makes that shift shorter, and it is reported
     * whatever the balancing then does.
     */
    public function test_an_opening_below_the_previous_closing_is_s10a_and_the_incoming_shift_carries_it(): void
    {
        $this->setCounts('2026-07-19', 1, ['QtyOpen_Original' => 40, 'QtyOpen' => 40]);

        $run = $this->preview();
        $line = $run->lines->where('StockItemNo', self::ITEM)
            ->first(fn ($l) => $l->TransactionDate->toDateString() === '2026-07-19' && $l->ShiftNo === 1);

        $this->assertSame('S10a', $line->ExceptionCode);
        $this->assertEqualsWithDelta(-10.0, (float) $line->ChainBreak, 0.005);
        $this->assertEqualsWithDelta(50.0, (float) $line->QtyOpenNew, 0.005);
        $this->assertEqualsWithDelta(0.0, (float) $line->QtyVar, 0.005, 'As counted, the shift looked balanced.');
        $this->assertEqualsWithDelta(-5.0, (float) $line->ChainNetVar, 0.001,
            'Repaired, the ten units that vanished are back in the chain total.');
    }

    /**
     * Ryan, 6 Oct 2026: "how is this chain total 1 when its cumulative ends at
     * 6?", and again that afternoon: "balance to 0 but … show a movement of 1
     * in cumulative". The Cumulative column runs on the balancing basis — each
     * shift's variance plus where its opening was set to the previous closing
     * — so it ends ON the chain total, and the line under the table says where
     * it steps without a variance. On a run totalled the old way it says that
     * instead of printing a sum that does not hold.
     */
    public function test_the_chain_panel_cumulative_column_ends_on_the_chain_total(): void
    {
        $this->setCounts('2026-07-19', 1, ['QtyOpen_Original' => 999, 'QtyOpen' => 999]);

        $run = $this->preview();
        $broken = $run->lines->where('StockItemNo', self::ITEM)
            ->first(fn ($l) => $l->TransactionDate->toDateString() === '2026-07-19' && $l->ShiftNo === 1);

        ['item' => $item, 'shifts' => $shifts] = $this->service->chain($run, $broken);
        $this->assertEqualsWithDelta(-954.0, (float) $item->CountedTotal, 0.001, 'The variance as counted.');
        $this->assertEqualsWithDelta(949.0, (float) $item->OpeningsMoved, 0.001);
        $this->assertSame(1, (int) $item->OpeningsCount);
        $this->assertSame(0, (int) $item->DuplicatesCount);
        $this->assertTrue((bool) $item->TotalReconciles);
        $this->assertEqualsWithDelta(-5.0, (float) $item->ChainNetVar, 0.001);
        $this->assertEqualsWithDelta(-5.0, (float) $shifts->last()->CumulativeVar, 0.001,
            'The Cumulative column ends on the chain total.');

        $this->actingAs($this->person('admin'));
        $panel = fn () => $this->get(route('app.stockrecon.line', [$run, $broken]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk();

        $panel()
            ->assertSee('Cumulative ends on the chain total, -5.000.', false)
            ->assertSee('+949.000 where 1 opening was', false)
            ->assertSee('set to the previous closing.', false);

        // A run totalled before the method settled: the stored T no longer
        // equals the sum of its parts, and the panel does not pretend it does.
        $this->db()->table('agora.StockReconRunLine')
            ->where('RunId', $run->Id)->where('StockItemNo', self::ITEM)
            ->update(['ChainNetVar' => 0]);

        $this->assertFalse((bool) $this->service->chain($run, $broken)['item']->TotalReconciles);
        $panel()
            ->assertSee('worked out by an earlier version of the balancing', false)
            ->assertDontSee('Cumulative ends on the chain total', false);
    }

    /**
     * ZRP, 4 Oct 2026: a break of 1, 2 or 3 units does not split the chain.
     *
     * 19 Jul shift 3 opens at 28 against a previous closing of 30. Split there,
     * the stretch before it would end on 19 Jul shift 1 with that closing
     * pinned, and the stretch after would end eight over — U4, the whole chain
     * blocked. Kept, the opening is repaired, the closing before it moves to
     * absorb the over (30 → 39), and the chain balances as if the break were
     * not there. The two units are still reported: on the row and as S10a.
     */
    public function test_a_break_of_three_or_fewer_keeps_the_chain_going_and_is_reported(): void
    {
        $this->setCounts('2026-07-19', 3, ['QtyOpen_Original' => 28, 'QtyOpen' => 28]);

        $run = $this->preview();
        $lines = $run->lines->where('StockItemNo', self::ITEM)->keyBy(fn ($l) => $l->TransactionDate->toDateString().'/'.$l->ShiftNo);

        $broken = $lines['2026-07-19/3'];

        $this->assertTrue($broken->BreakKept, 'A two-unit break is kept.');
        $this->assertEqualsWithDelta(-2.0, (float) $broken->ChainBreak, 0.005);
        $this->assertSame('S10a', $broken->ExceptionCode, 'A kept break is still on the exceptions tab.');
        $this->assertStringStartsWith('Break -2.000 kept', $broken->Outcome, 'The qty is on the row.');
        $this->assertNull($broken->AuditCode, 'S13 does not read a break that small.');

        $this->assertTrue($lines->every(fn ($l) => ! $l->ChainBlocked), 'The chain is not split, so no stretch of it ends over.');
        $this->assertEqualsWithDelta(39.0, (float) $lines['2026-07-19/1']->QtyCloseNew, 0.005,
            'The closing before a kept break moves: the chain balances through it.');
        $this->assertEqualsWithDelta(39.0, (float) $broken->QtyOpenNew, 0.005, 'The opening follows it.');
        $this->assertTrue($lines->every(fn ($l) => (float) $l->QtyVarNew <= 0.005), 'No shift ends over.');
        $this->assertEqualsWithDelta(-5.0, $lines->sum(fn ($l) => (float) $l->QtyVarNew), 0.001, 'The invariant still holds.');
        $this->assertSame(1, (int) $run->fresh()->KeptBreaks);

        $exceptions = collect(DB::connection(config('agora.connections.app'))
            ->select('EXEC agora.usp_StockRecon_GridExceptions @RunId = ?, @PageSize = 1000', [$run->Id]))
            ->firstWhere('Id', $broken->Id);

        $this->assertNotNull($exceptions, 'The kept break is on the exceptions grid.');
        $this->assertEqualsWithDelta(-2.0, (float) $exceptions->ChainBreak, 0.005, 'With its qty.');
    }

    /**
     * ZAMBUK, branch 17, 11 Sep 2026 (Ryan, 6 Oct: "chain total is 0 … but
     * held back"). A slow shelf item: the day shift counted its last 2 tins as
     * 0, the night counted the same 2 again. Nothing was sold or lost — the
     * chain total is nil. Read against the repaired opening (0), the night
     * "closed holding more than it ever received" (U2) and the whole chain was
     * held back. The break is 2, so it is kept, and the closing before it may
     * move: the day's closing goes 0 → 2, exactly what head office did by
     * hand, and every shift balances.
     */
    public function test_a_closing_counted_short_and_counted_again_next_shift_balances_rather_than_blocking(): void
    {
        $this->seedShelfChain([
            '2026-07-17' => [1 => [4, 0, 4, 0], 3 => [4, 0, 3, 1]],
            '2026-07-18' => [1 => [3, 0, 3, 0], 3 => [3, 0, 2, 1]],
            '2026-07-19' => [1 => [2, 0, 0, 0], 3 => [2, 0, 2, 0]],
            '2026-07-20' => [1 => [2, 0, 1, 1], 3 => [1, 0, 1, 0]],
            '2026-07-21' => [1 => [1, 0, 1, 0], 3 => [1, 0, 0, 1]],
        ]);

        $run = $this->previewArea(self::SHELF_AREA);
        $miscount = $this->lineAt($run, '2026-07-19', 1);
        $next = $this->lineAt($run, '2026-07-19', 3);

        $this->assertTrue((bool) $next->BreakKept, 'A break of 2 is kept.');
        $this->assertFalse((bool) $next->FlagCloseExceedsOnHand, 'The tins were counted at the start of the night: nothing came from nowhere.');
        $this->assertFalse((bool) $next->FlagSoldMoreThanOnHand);
        $this->assertFalse((bool) $next->ChainBlocked, 'A chain whose total is nil is not held back by a miscount.');
        $this->assertEqualsWithDelta(0.0, (float) $miscount->ChainNetVar, 0.001);
        $this->assertEqualsWithDelta(2.0, (float) $miscount->QtyCloseNew, 0.005, 'The miscounted closing goes 0 → 2, as head office did by hand.');
        $this->assertTrue($run->lines->every(fn ($l) => abs((float) $l->QtyVarNew) < 0.005), 'Every shift balances.');
    }

    /**
     * Only the kept break is excused. Had that night closed on 4 — 2 more than
     * it was counted holding at the start — that is still stock from nowhere,
     * and still U2.
     */
    public function test_an_over_beyond_a_kept_break_is_still_an_unrecorded_issue(): void
    {
        $this->seedShelfChain([
            '2026-07-17' => [1 => [4, 0, 4, 0], 3 => [4, 0, 3, 1]],
            '2026-07-18' => [1 => [3, 0, 3, 0], 3 => [3, 0, 2, 1]],
            '2026-07-19' => [1 => [2, 0, 0, 0], 3 => [2, 0, 4, 0]],
            '2026-07-20' => [1 => [4, 0, 3, 1], 3 => [3, 0, 3, 0]],
            '2026-07-21' => [1 => [3, 0, 3, 0], 3 => [3, 0, 2, 1]],
        ]);

        $over = $this->lineAt($this->previewArea(self::SHELF_AREA), '2026-07-19', 3);
        $this->assertTrue((bool) $over->FlagCloseExceedsOnHand, 'An over beyond the kept break is still an unrecorded issue.');
        $this->assertSame('U2', $over->ExceptionCode, 'Reported…');
        $this->assertFalse((bool) $over->ChainBlocked, '…and since 7 Oct 2026 it holds no chain back.');
    }

    /**
     * MONSTER MARIPO, branch 17, 8–9 Sep 2026 (Ryan, 6 Oct: "if a chain is 0
     * … what is it held back for"). No break at all: the night counted 1 when
     * 3 were there, the day's opening copied the 1, and the day counted 3
     * again with nothing issued. Read alone, the day "closed holding more than
     * it ever received" (U2); read with the shift before, it is one miscount —
     * the short before covers the over, the chain total is nil, and the
     * night's closing goes 1 → 3.
     */
    public function test_an_over_covered_by_the_short_before_it_balances_rather_than_blocking(): void
    {
        $this->seedShelfChain([
            '2026-07-17' => [1 => [4, 0, 4, 0], 3 => [4, 0, 3, 1]],
            '2026-07-18' => [1 => [3, 0, 3, 0], 3 => [3, 0, 1, 0]],
            '2026-07-19' => [1 => [1, 0, 3, 0], 3 => [3, 0, 2, 1]],
            '2026-07-20' => [1 => [2, 0, 2, 0], 3 => [2, 0, 1, 1]],
            '2026-07-21' => [1 => [1, 0, 1, 0], 3 => [1, 0, 0, 1]],
        ]);

        $run = $this->previewArea(self::SHELF_AREA);
        $miscount = $this->lineAt($run, '2026-07-18', 3);
        $over = $this->lineAt($run, '2026-07-19', 1);

        $this->assertFalse((bool) $over->FlagCloseExceedsOnHand, 'The short before it covers the over: nothing came from nowhere.');
        $this->assertFalse((bool) $over->ChainBlocked, 'A chain whose total is nil is not held back by one miscount.');
        $this->assertEqualsWithDelta(0.0, (float) $over->ChainNetVar, 0.001);
        $this->assertEqualsWithDelta(3.0, (float) $miscount->QtyCloseNew, 0.005, 'The miscounted closing goes 1 → 3.');
        $this->assertTrue($run->lines->every(fn ($l) => abs((float) $l->QtyVarNew) < 0.005), 'Every shift balances.');
    }

    /**
     * The mirror: 2LIT COKE, branch 17, 8 Sep 2026. A closing counted HIGH —
     * the night counted 5 with nothing issued and nothing sold — and the day
     * counted the 3 that were there. The short after covers the over; the
     * night's closing goes 5 → 3.
     */
    public function test_an_over_covered_by_the_short_after_it_balances_rather_than_blocking(): void
    {
        $this->seedShelfChain([
            '2026-07-17' => [1 => [4, 0, 4, 0], 3 => [4, 0, 3, 1]],
            '2026-07-18' => [1 => [3, 0, 3, 0], 3 => [3, 0, 5, 0]],
            '2026-07-19' => [1 => [5, 0, 3, 0], 3 => [3, 0, 2, 1]],
            '2026-07-20' => [1 => [2, 0, 2, 0], 3 => [2, 0, 1, 1]],
            '2026-07-21' => [1 => [1, 0, 1, 0], 3 => [1, 0, 0, 1]],
        ]);

        $run = $this->previewArea(self::SHELF_AREA);
        $over = $this->lineAt($run, '2026-07-18', 3);

        $this->assertFalse((bool) $over->FlagCloseExceedsOnHand, 'The short after it covers the over.');
        $this->assertFalse((bool) $over->ChainBlocked);
        $this->assertEqualsWithDelta(3.0, (float) $over->QtyCloseNew, 0.005, 'The closing counted high goes 5 → 3.');
        $this->assertTrue($run->lines->every(fn ($l) => abs((float) $l->QtyVarNew) < 0.005), 'Every shift balances.');
    }

    /**
     * Ryan, 7 Oct 2026: "if it shows out by 0 WHY DOES IT NOT BALANCE". A
     * closing counted 5 high, the next shift counting the right 3: a break of
     * -5, bigger than the 3 a chain keeps, so it used to split — one segment
     * ending +5 (U4), one -5 — and the chain was held back at nil. A nil chain
     * runs through every break, and the client-audit check does not withdraw
     * it for moving the closing at that break.
     */
    public function test_a_nil_chain_balances_through_a_break_bigger_than_the_kept_size(): void
    {
        $this->seedShelfChain([
            '2026-07-17' => [1 => [4, 0, 4, 0], 3 => [4, 0, 3, 1]],
            '2026-07-18' => [1 => [3, 0, 8, 0], 3 => [3, 0, 3, 0]],
            '2026-07-19' => [1 => [3, 0, 2, 1], 3 => [2, 0, 2, 0]],
            '2026-07-20' => [1 => [2, 0, 1, 1], 3 => [1, 0, 1, 0]],
            '2026-07-21' => [1 => [1, 0, 1, 0], 3 => [1, 0, 0, 1]],
        ]);

        $run = $this->previewArea(self::SHELF_AREA);
        $high = $this->lineAt($run, '2026-07-18', 1);

        $this->assertEqualsWithDelta(0.0, (float) $high->ChainNetVar, 0.001);
        $this->assertFalse((bool) $high->ChainBlocked, 'Nil: not held back.');
        $this->assertFalse((bool) $high->FlagNetOver);
        $this->assertFalse((bool) $high->FlagAuditConflict);
        $this->assertEqualsWithDelta(3.0, (float) $high->QtyCloseNew, 0.005, 'The closing counted high goes 8 → 3.');
        $this->assertTrue($run->lines->every(fn ($l) => abs((float) $l->QtyVarNew) < 0.005), 'Every shift balances.');
    }

    /**
     * A finding on a nil chain is reported, not a hold: here the +6 is
     * covered by the shorts on BOTH sides together (-2 before, -4 after) and
     * by neither alone, so it is still U2 — and the chain still balances,
     * its rows saying what happens to them rather than "Unrecorded issue".
     */
    public function test_a_finding_on_a_nil_chain_is_reported_and_the_chain_still_balances(): void
    {
        $this->seedShelfChain([
            '2026-07-17' => [1 => [4, 0, 4, 0], 3 => [4, 0, 3, 1]],
            '2026-07-18' => [1 => [3, 0, 1, 0], 3 => [1, 0, 7, 0]],
            '2026-07-19' => [1 => [7, 0, 3, 0], 3 => [3, 0, 2, 1]],
            '2026-07-20' => [1 => [2, 0, 2, 0], 3 => [2, 0, 1, 1]],
            '2026-07-21' => [1 => [1, 0, 1, 0], 3 => [1, 0, 0, 1]],
        ]);

        $run = $this->previewArea(self::SHELF_AREA);
        $over = $this->lineAt($run, '2026-07-18', 3);

        $this->assertTrue((bool) $over->FlagCloseExceedsOnHand, 'Neither side covers the 6 alone: still U2.');
        $this->assertSame('U2', $over->ExceptionCode, 'And reported as one.');
        $this->assertFalse((bool) $over->ChainBlocked, 'But the chain is nil, so it balances.');
        $this->assertNotSame('unrecorded', $over->outcomeKey());
        $this->assertStringStartsNotWith('Unrecorded issue', $over->Outcome);
        $this->assertTrue($run->lines->every(fn ($l) => abs((float) $l->QtyVarNew) < 0.005), 'Every shift balances.');
    }

    /**
     * Ryan, 7 Oct 2026, with head office's own script: "update the amended
     * with the original column values". Head office has balanced a miscount
     * by hand, so the preview has nothing to write and the balanceable chain
     * cannot be pressed. Reset to original takes the hand balancing off; the
     * preview after it balances from the staff counts and the press writes it.
     * Each step unwinds in order.
     */
    public function test_reset_to_original_lets_a_chain_head_office_balanced_by_hand_be_balanced_here(): void
    {
        config(['stockrecon.stamp_mode' => 'live', 'stockrecon.issue_mode' => 'live']);
        $this->seedShelfChain([
            '2026-07-17' => [1 => [4, 0, 4, 0], 3 => [4, 0, 3, 1]],
            '2026-07-18' => [1 => [3, 0, 3, 0], 3 => [3, 0, 1, 0]],
            '2026-07-19' => [1 => [1, 0, 3, 0], 3 => [3, 0, 2, 1]],
            '2026-07-20' => [1 => [2, 0, 2, 0], 3 => [2, 0, 1, 1]],
            '2026-07-21' => [1 => [1, 0, 1, 0], 3 => [1, 0, 0, 1]],
        ]);
        // Head office: the night's closing up to 3, the day's opening with it.
        $this->setRow(self::DUP_ITEM, '2026-07-18', 3, ['QtyClose' => 3]);
        $this->setRow(self::DUP_ITEM, '2026-07-19', 1, ['QtyOpen' => 3]);

        $handBalanced = $this->previewArea(self::SHELF_AREA);
        $this->assertFalse($handBalanced->lines->contains(fn ($l) => $l->ChainBlocked), 'Balanceable…');
        $this->assertFalse($handBalanced->lines->contains(fn ($l) => $l->WouldAmend), '…and nothing to write: PumpIT holds the answer.');

        $reset = $this->service->resetToOriginal($handBalanced);
        $this->assertSame('RESET', $reset['status']->Code);
        $this->assertStringContainsString('2 shift(s) put back to the original counts', $reset['status']->Message);
        $this->assertEqualsWithDelta(1.0, (float) $this->sourceRow(self::DUP_ITEM, '2026-07-18', 3)->QtyClose, 0.0005);
        $this->assertEqualsWithDelta(1.0, (float) $this->sourceRow(self::DUP_ITEM, '2026-07-19', 1)->QtyOpen, 0.0005);

        $handBalanced->refresh();
        $this->assertSame('committed', $handBalanced->Status);
        $this->assertSame('reset', $handBalanced->CommitKind);
        $ledger = StockReconAmendment::query()->acrossBranches()->where('RunId', $handBalanced->Id)->get();
        $this->assertCount(2, $ledger);
        $this->assertEqualsWithDelta(3.0, (float) $ledger->firstWhere('ShiftNo', 3)->PriorQtyClose, 0.0005, 'The hand figure is the prior.');

        // The audit trail names it.
        $this->assertSame(2, $this->db()->table(config('agora.schema').'.vw_PumpItChange')
            ->where('Ledger', 'StockReconAmendment')->where('Ref', 'Stock recon run '.$handBalanced->Id)
            ->where('Action', 'Reset to original')->count());

        // The preview after it balances from the staff counts, and writes.
        $next = $reset['run']->load('lines');
        $miscount = $this->lineAt($next, '2026-07-18', 3);
        $this->assertTrue((bool) $miscount->WouldAmend);
        $this->assertEqualsWithDelta(3.0, (float) $miscount->QtyCloseNew, 0.005);

        $commit = $this->service->commit($next);
        $this->assertSame('COMMITTED', $commit['status']->Code, 'A balancing commit stands on the reset.');
        $this->assertEqualsWithDelta(3.0, (float) $this->sourceRow(self::DUP_ITEM, '2026-07-18', 3)->QtyClose, 0.0005);

        // Unwound in order: the reset refuses while the balancing stands.
        try {
            $this->service->reverse($handBalanced->fresh(), 'TEST-out of order');
            $this->fail('The reset must not be reversed over the balancing written on it.');
        } catch (AgoraProcException $e) {
            $this->assertSame('COUNT_MOVED', $e->code());
        }

        $this->service->reverse($next->fresh(), 'TEST-balancing off');
        $this->assertEqualsWithDelta(1.0, (float) $this->sourceRow(self::DUP_ITEM, '2026-07-18', 3)->QtyClose, 0.0005);
        $this->service->reverse($handBalanced->fresh(), 'TEST-reset off');
        $this->assertEqualsWithDelta(3.0, (float) $this->sourceRow(self::DUP_ITEM, '2026-07-18', 3)->QtyClose, 0.0005, 'Back to the hand figure.');
        $this->assertEqualsWithDelta(3.0, (float) $this->sourceRow(self::DUP_ITEM, '2026-07-19', 1)->QtyOpen, 0.0005);
    }

    /**
     * Ryan, 7 Oct 2026: "we must use original". The preview reads the
     * ORIGINAL closing of the shift just before the week as the first
     * previous closing, and the ORIGINAL issue and POS — so Reset to original
     * puts that closing back too (not its opening or issue), recorded and
     * reversible like the rest.
     */
    public function test_the_calculation_is_on_the_originals_and_reset_puts_back_the_closing_before_the_week(): void
    {
        config(['stockrecon.stamp_mode' => 'live', 'stockrecon.issue_mode' => 'live']);
        $this->seedShelfChain([
            '2026-07-16' => [3 => [5, 0, 4, 1]],
            '2026-07-17' => [1 => [4, 0, 4, 0], 3 => [4, 6, 9, 1]],
            '2026-07-18' => [1 => [9, 0, 9, 0], 3 => [9, 0, 8, 1]],
            '2026-07-19' => [1 => [8, 0, 8, 0], 3 => [8, 0, 7, 1]],
            '2026-07-20' => [1 => [7, 0, 7, 0], 3 => [7, 0, 6, 1]],
            '2026-07-21' => [1 => [6, 0, 6, 0], 3 => [6, 0, 5, 1]],
        ]);
        // Head office: the closing before the week up to 6, an issue and a
        // POS changed by hand inside it.
        $this->setRow(self::DUP_ITEM, '2026-07-16', 3, ['QtyClose' => 6]);
        $this->setRow(self::DUP_ITEM, '2026-07-17', 3, ['QtyIssued' => 8]);
        $this->setRow(self::DUP_ITEM, '2026-07-18', 3, ['QtyComputer' => 3]);

        $run = $this->previewArea(self::SHELF_AREA);
        $first = $this->lineAt($run, '2026-07-17', 1);
        $this->assertEqualsWithDelta(4.0, (float) $first->PrevClose, 0.0005, 'The ORIGINAL closing before the week.');
        $this->assertEqualsWithDelta(6.0, (float) $this->lineAt($run, '2026-07-17', 3)->QtyIssued, 0.0005, 'The issue as keyed by staff.');
        $this->assertEqualsWithDelta(1.0, (float) $this->lineAt($run, '2026-07-18', 3)->QtyPOS, 0.0005, 'The POS as counted.');
        $this->assertTrue($run->lines->every(fn ($l) => abs((float) $l->QtyVar) < 0.0005), 'On the originals every shift is nil.');

        $reset = $this->service->resetToOriginal($run);
        $this->assertStringContainsString('1 of them the closing just before the week', $reset['status']->Message);
        $this->assertEqualsWithDelta(4.0, (float) $this->sourceRow(self::DUP_ITEM, '2026-07-16', 3)->QtyClose, 0.0005);
        $this->assertEqualsWithDelta(5.0, (float) $this->sourceRow(self::DUP_ITEM, '2026-07-16', 3)->QtyOpen, 0.0005, 'Its opening is not touched.');
        $this->assertEqualsWithDelta(6.0, (float) $this->sourceRow(self::DUP_ITEM, '2026-07-17', 3)->QtyIssued, 0.0005, 'The issue goes back too.');
        $this->assertSame(1, StockReconAmendment::query()->acrossBranches()->where('RunId', $run->Id)->where('RunLineId', '<', 0)->count());

        $this->service->reverse($run->fresh(), 'TEST-reset off');
        $this->assertEqualsWithDelta(6.0, (float) $this->sourceRow(self::DUP_ITEM, '2026-07-16', 3)->QtyClose, 0.0005, 'Reversed: the hand figure is back.');
        $this->assertEqualsWithDelta(8.0, (float) $this->sourceRow(self::DUP_ITEM, '2026-07-17', 3)->QtyIssued, 0.0005);
    }

    /** The press is on the run, and lands on the preview made after it. */
    public function test_the_reset_press_is_on_the_run_and_lands_on_the_new_preview(): void
    {
        config(['stockrecon.stamp_mode' => 'live', 'stockrecon.issue_mode' => 'live']);
        $this->seedShelfChain([
            '2026-07-17' => [1 => [4, 0, 4, 0], 3 => [4, 0, 3, 1]],
            '2026-07-18' => [1 => [3, 0, 3, 0], 3 => [3, 0, 1, 0]],
            '2026-07-19' => [1 => [1, 0, 3, 0], 3 => [3, 0, 2, 1]],
            '2026-07-20' => [1 => [2, 0, 2, 0], 3 => [2, 0, 1, 1]],
            '2026-07-21' => [1 => [1, 0, 1, 0], 3 => [1, 0, 0, 1]],
        ]);
        $this->setRow(self::DUP_ITEM, '2026-07-18', 3, ['QtyClose' => 3]);
        $this->setRow(self::DUP_ITEM, '2026-07-19', 1, ['QtyOpen' => 3]);
        $run = $this->previewArea(self::SHELF_AREA);

        $this->actingAs($this->person('admin'));
        $this->get(route('app.stockrecon.run', $run))
            ->assertOk()
            ->assertSee('Reset to original')
            ->assertSee('already balanced in PumpIT', false);

        $response = $this->post(route('app.stockrecon.reset', $run));
        $next = StockReconRun::query()->acrossBranches()->where('BranchId', self::BRANCH)->orderByDesc('Id')->first();

        $this->assertNotSame($run->Id, $next->Id);
        $response->assertRedirect(route('app.stockrecon.run', $next))
            ->assertSessionHas('committed', fn ($m) => str_contains($m, 'Previewed again as run '.$next->Id.': 2 shifts to amend'));
    }

    /**
     * Ryan, 7 Oct 2026: "show only positive chains or short chains". Each row
     * carries its chain's total, filtered as a second pill set in the Outcome
     * strip. The worked example ends five units short.
     */
    public function test_the_proposals_carry_the_chain_total_as_a_second_pill_set(): void
    {
        $run = $this->preview();
        $line = $run->lines->firstWhere('StockItemNo', self::ITEM);
        $this->assertSame('Short', $line->chainState());

        $this->actingAs($this->person('admin'))
            ->get(route('app.stockrecon.run', $run))
            ->assertOk()
            ->assertSee('data-tt-pills data-tt-pills-join data-tt-pill-order="Positive|Short|Nil">Chain</th>', false)
            ->assertSee('data-sort="Short"', false)
            ->assertSee('-5.000');
    }

    /**
     * Ryan, 7 Oct 2026: one row per chain that opens to its shifts, with a bar
     * under the first cell — a segment per shift in its outcome's colour. The
     * chain row carries the first opening, the last closing and the sums.
     */
    public function test_the_proposals_show_one_row_per_chain_that_opens_to_its_panel(): void
    {
        $run = $this->preview();
        $chains = ChainSummary::of($run->lines);
        $chain = $chains->first(fn (ChainSummary $c) => $c->first()->StockItemNo === self::ITEM);

        $this->assertSame($run->lines->unique(fn ($l) => $l->AreaNo.'|'.$l->StockItemNo)->count(), $chains->count());
        $this->assertSame($run->lines->where('StockItemNo', self::ITEM)->count(), $chain->count());
        $this->assertEqualsWithDelta((float) $run->lines->where('StockItemNo', self::ITEM)->sum('QtyVar'), $chain->variance(), 0.001);
        $this->assertTrue($chain->lines->first()->TransactionDate->lte($chain->lines->last()->TransactionDate), 'In date order.');
        $this->assertSame($chain->count(), $chain->segments()->count(), 'A segment per shift.');

        $html = $this->actingAs($this->person('admin'))
            ->get(route('app.stockrecon.run', $run))
            ->assertOk()
            ->assertSee('data-tt-groups', false)
            ->assertSee('data-tt-group-head="'.$chain->key.'"', false)
            ->assertSee('data-tt-group="'.$chain->key.'"', false)
            ->getContent();

        // Every shown shift sits under its chain's row; the hidden empty
        // chains have no row at all.
        $this->assertSame(substr_count($html, 'class="tt-group"'), substr_count($html, 'data-tt-group-head="'));
        $this->assertGreaterThan(0, substr_count($html, 'class="chain-bar"'));

        // No middle drill (12 Oct 2026): the chain row opens the chain panel,
        // and the shifts under it open nothing — they are never shown.
        $this->assertStringContainsString('data-tt-group-detail', $html);
        $this->assertSame(substr_count($html, 'data-tt-group-head="'), substr_count($html, 'data-detail-url="'),
            'Only the chain rows open a panel.');
        $this->assertStringContainsString('data-detail-url="'.route('app.stockrecon.line', [$run, $chain->first()]).'"', $html);
    }

    /**
     * The Amend press moved from the shift to the chain (12 Oct 2026): it
     * posts the chain's writable shifts, and those are written and no other.
     */
    public function test_a_chain_amend_writes_that_chains_shifts_and_no_other(): void
    {
        $user = User::query()->acrossBranches()->where('EmailAddress', 'ryan@revvtech.co.za')->first();

        if (! $user) {
            $this->markTestSkipped('No seeded administrator — run db:seed first.');
        }

        $run = $this->preview();
        $writable = $run->lines->filter(fn ($l) => $l->isWritable());
        $chains = $writable->groupBy(fn ($l) => $l->AreaNo.'|'.$l->StockItemNo);

        $target = $chains->first();
        $this->assertGreaterThan(1, $target->count(), 'This proves nothing unless the press carries more than one shift.');

        $this->actingAs($user)
            ->get(route('app.stockrecon.run', $run))
            ->assertSee('name="only" value="'.$target->pluck('Id')->implode(',').'"', false);

        $this->actingAs($user)
            ->post(route('app.stockrecon.commit', $run), ['only' => $target->pluck('Id')->implode(',')])
            ->assertRedirect(route('app.stockrecon.run', $run));

        $committed = StockReconRunLine::query()->acrossBranches()->where('RunId', $run->Id)
            ->where('CommitState', 'committed')->pluck('Id')->sort()->values()->all();

        $this->assertSame($target->pluck('Id')->sort()->values()->all(), $committed,
            'Exactly the chain whose button was pressed.');
    }

    /**
     * Ryan, 7 Oct 2026: "show the loader until the grid has been initialized
     * when running a stock recon preview". The run page arrives with the
     * shell's loader already showing — first in the body, held, with its own
     * words — and the proposals table names itself as what it waits for; the
     * hub's loader is hidden as everywhere else.
     */
    public function test_the_run_page_arrives_with_the_loader_held_until_its_grid_is_laid_out(): void
    {
        $run = $this->preview();
        $this->actingAs($this->person('admin'));

        $html = $this->get(route('app.stockrecon.run', $run))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-loader-overlay\s+data-loader-hold/', $html, 'Held, not hidden.');
        $this->assertStringContainsString('Laying out the chains…', $html);
        $this->assertLessThan(strpos($html, 'id="stockrecon-lines-'), strpos($html, 'data-loader-overlay'), 'It comes first, so it can paint first.');
        $this->assertMatchesRegularExpression('/id="stockrecon-lines-\d+"[^>]*data-loader-hold|data-loader-hold[^>]*id="stockrecon-lines-/s',
            substr($html, strpos($html, '<table'), 4000).' '.$html);

        $hub = $this->get(route('app.stockrecon.index'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-loader-overlay\s+hidden/', $hub);
    }

    /**
     * Ryan, 7 Oct 2026: the codes explained in a modal, in plain words for the
     * people at the sites — not "the client's weekly pack" — opened from the
     * "i" on the exceptions and from any code badge; the badges in their
     * colours, the rows washed in theirs.
     */
    public function test_the_exceptions_explain_every_code_in_a_modal_opened_from_its_badge(): void
    {
        foreach (['exceptions', 'audit'] as $set) {
            foreach ((array) config('stockrecon.'.$set) as $code => $entry) {
                foreach (['plain', 'todo', 'who'] as $field) {
                    $this->assertNotEmpty($entry[$field] ?? null, "{$code} has no {$field}.");
                    $this->assertStringNotContainsStringIgnoringCase('client', $entry[$field], "{$code} {$field} talks about the client.");
                }
            }
        }

        $this->setCounts('2026-07-19', 1, ['QtyOpen_Original' => 999, 'QtyOpen' => 999]);   // an S10b
        $run = $this->preview();
        $this->actingAs($this->person('admin'));

        $html = $this->get(route('app.stockrecon.exceptions', $run))->assertOk()
            ->assertSee('id="stockrecon-codes"', false)
            ->assertSee('What the codes mean')
            ->assertSee('class="info-btn info-btn-modal" data-modal-open="stockrecon-codes"', false)
            ->assertSee('id="stockrecon-codes-S10a"', false)
            ->assertSee('id="stockrecon-codes-S12"', false)
            ->assertDontSee("Codes are the client's weekly pack's")
            ->getContent();

        $this->assertMatchesRegularExpression('/<button type="button" class="chip \w+ tone-\w+ chip-code"\s+data-modal-open="stockrecon-codes" data-modal-anchor="S10b"/', $html,
            'A code on the grid is a coloured badge that opens the modal at itself.');
        $this->assertMatchesRegularExpression('/<tr[^>]*class="is-(crit|serious|warn|matched)"/', $html, 'Rows carry their code\'s colour.');

        // The proposals' code under the outcome is the same badge.
        $this->get(route('app.stockrecon.run', $run))->assertOk()
            ->assertSee('data-modal-anchor="S10b"', false)
            ->assertSee('id="stockrecon-codes"', false);
    }

    /**
     * Ryan, 7 Oct 2026, on ROOIBOS TEA BAG: "it says balanceable but it shows
     * -2 and i can't do anything with it". Two shorts of one and no over: a
     * real loss of 2, nothing to set it against, nothing to write. The chain
     * row and the panel say exactly that instead of "Balanceable".
     */
    public function test_a_chain_short_with_no_over_says_there_is_nothing_to_balance(): void
    {
        $this->seedShelfChain([
            '2026-07-17' => [1 => [4, 0, 4, 0], 3 => [4, 0, 3, 0]],
            '2026-07-18' => [1 => [3, 0, 3, 0], 3 => [3, 0, 2, 1]],
            '2026-07-19' => [1 => [2, 0, 2, 0], 3 => [2, 0, 1, 0]],
            '2026-07-20' => [1 => [1, 0, 1, 0], 3 => [1, 0, 1, 0]],
            '2026-07-21' => [1 => [1, 0, 1, 0], 3 => [1, 0, 0, 1]],
        ]);

        $run = $this->previewArea(self::SHELF_AREA);
        $line = $this->lineAt($run, '2026-07-17', 3);

        $this->assertFalse((bool) $line->ChainBlocked, 'Nothing holds it back…');
        $this->assertEqualsWithDelta(-2.0, (float) $line->ChainNetVar, 0.001);
        $this->assertFalse($run->lines->contains(fn ($l) => $l->WouldAmend), '…and there is nothing to write.');

        $chain = ChainSummary::of($run->lines)->first(fn ($c) => $c->first()->StockItemNo === self::DUP_ITEM);
        $this->assertSame('Short 2.000 · nothing to balance', $chain->state()['label']);
        $this->assertSame('warn', $chain->state()['tone']);

        $this->actingAs($this->person('admin'))
            ->get(route('app.stockrecon.line', [$run, $line]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSee('Short 2.000 · nothing to balance')
            ->assertSee('no over to set it against')
            ->assertDontSee('Balanceable');
    }

    /**
     * With reset_issues on, the issue goes back to the original too, and comes
     * back on reversal. Off — the default — the issue is left as it stands.
     */
    public function test_reset_to_original_puts_the_issue_back_and_reverse_restores_it(): void
    {
        config(['stockrecon.stamp_mode' => 'live', 'stockrecon.issue_mode' => 'live', 'stockrecon.reset_issues' => true]);
        $this->seedShelfChain([
            '2026-07-17' => [1 => [4, 0, 4, 0], 3 => [4, 0, 3, 1]],
            '2026-07-18' => [1 => [3, 6, 8, 1], 3 => [8, 0, 7, 1]],
            '2026-07-19' => [1 => [7, 0, 6, 1], 3 => [6, 0, 6, 0]],
            '2026-07-20' => [1 => [6, 0, 5, 1], 3 => [5, 0, 5, 0]],
            '2026-07-21' => [1 => [5, 0, 5, 0], 3 => [5, 0, 4, 1]],
        ]);
        // Head office cut the issue to 5 and the closing with it.
        $this->setRow(self::DUP_ITEM, '2026-07-18', 1, ['QtyIssued' => 5, 'QtyClose' => 7]);

        $run = $this->previewArea(self::SHELF_AREA);
        $this->service->resetToOriginal($run);

        $row = $this->sourceRow(self::DUP_ITEM, '2026-07-18', 1);
        $this->assertEqualsWithDelta(6.0, (float) $row->QtyIssued, 0.0005, 'The issue as staff keyed it.');
        $this->assertEqualsWithDelta(8.0, (float) $row->QtyClose, 0.0005);

        $amendment = StockReconAmendment::query()->acrossBranches()->where('RunId', $run->Id)->sole();
        $this->assertEqualsWithDelta(5.0, (float) $amendment->PriorQtyIssued, 0.0005);
        $this->assertEqualsWithDelta(6.0, (float) $amendment->NewQtyIssued, 0.0005);

        $this->service->reverse($run->fresh(), 'TEST-reset off');
        $row = $this->sourceRow(self::DUP_ITEM, '2026-07-18', 1);
        $this->assertEqualsWithDelta(5.0, (float) $row->QtyIssued, 0.0005, 'Head office\'s issue is back.');
        $this->assertEqualsWithDelta(7.0, (float) $row->QtyClose, 0.0005);
        config(['stockrecon.reset_issues' => false]);
        $again = $this->previewArea(self::SHELF_AREA);
        $this->service->resetToOriginal($again);
        $row = $this->sourceRow(self::DUP_ITEM, '2026-07-18', 1);
        $this->assertEqualsWithDelta(5.0, (float) $row->QtyIssued, 0.0005, 'Off: the issue stays as head office keyed it.');
        $this->assertEqualsWithDelta(8.0, (float) $row->QtyClose, 0.0005, 'The counts still go back.');
    }

    /**
     * Ryan, 8 Oct 2026: "clear any amendments". The POS goes back to
     * QtyComputer_Original with the counts, its prior kept; Reverse puts it
     * back, and refuses over a POS changed since.
     */
    public function test_reset_to_original_puts_the_pos_back_and_reverse_restores_it(): void
    {
        config(['stockrecon.stamp_mode' => 'live', 'stockrecon.issue_mode' => 'live', 'stockrecon.reset_issues' => true]);
        $this->seedShelfChain([
            '2026-07-17' => [1 => [4, 0, 4, 0], 3 => [4, 0, 3, 1]],
            '2026-07-18' => [1 => [3, 0, 2, 1], 3 => [2, 0, 1, 1]],
            '2026-07-19' => [1 => [1, 0, 1, 0], 3 => [1, 0, 0, 1]],
        ]);
        // Head office cut the POS to 0 by hand; the counts are as staff took them.
        $this->setRow(self::DUP_ITEM, '2026-07-18', 1, ['QtyComputer' => 0]);

        $run = $this->previewArea(self::SHELF_AREA);
        $this->service->resetToOriginal($run);

        $this->assertEqualsWithDelta(1.0, (float) $this->sourceRow(self::DUP_ITEM, '2026-07-18', 1)->QtyComputer, 0.0005,
            'The POS as the till recorded it.');

        $amendment = StockReconAmendment::query()->acrossBranches()->where('RunId', $run->Id)->sole();
        $this->assertEqualsWithDelta(0.0, (float) $amendment->PriorQtyPOS, 0.0005);
        $this->assertEqualsWithDelta(1.0, (float) $amendment->NewQtyPOS, 0.0005);
        $this->assertNull($amendment->NewQtyIssued, 'The issue did not change, so it is not recorded.');

        $change = $this->db()->table('agora.vw_PumpItChange')
            ->where('Ledger', 'StockReconAmendment')->where('LedgerId', $amendment->Id)->first();
        $this->assertStringContainsString('POS 0.000 → 1.000', (string) $change->Change, 'The audit trail shows it.');

        // Somebody keys the POS again: the reversal must not write over it.
        $this->setRow(self::DUP_ITEM, '2026-07-18', 1, ['QtyComputer' => 2]);
        try {
            $this->service->reverse($run->fresh(), 'TEST-reset off');
            $this->fail('A POS changed since the reset must refuse the reversal.');
        } catch (AgoraProcException $e) {
            $this->assertSame('POS_MOVED', $e->code());
        }

        $this->setRow(self::DUP_ITEM, '2026-07-18', 1, ['QtyComputer' => 1]);
        $this->service->reverse($run->fresh(), 'TEST-reset off');
        $this->assertEqualsWithDelta(0.0, (float) $this->sourceRow(self::DUP_ITEM, '2026-07-18', 1)->QtyComputer, 0.0005,
            'Head office\'s POS is back.');
    }

    /**
     * An over no short covers is still stock from nowhere: the same day
     * closing on 3 from an opening of 1, with no short before or after it.
     */
    public function test_an_over_no_short_covers_is_still_an_unrecorded_issue(): void
    {
        // The balancing envelope on its own: the over left, before stage 7g
        // captures it as the missing issue (13 Oct 2026).
        config(['stockrecon.missing_issue_mode' => 'off']);
        $this->seedShelfChain([
            '2026-07-17' => [1 => [4, 0, 4, 0], 3 => [4, 0, 3, 1]],
            '2026-07-18' => [1 => [3, 0, 3, 0], 3 => [3, 0, 1, 2]],
            '2026-07-19' => [1 => [1, 0, 3, 0], 3 => [3, 0, 2, 1]],
            '2026-07-20' => [1 => [2, 0, 2, 0], 3 => [2, 0, 1, 1]],
            '2026-07-21' => [1 => [1, 0, 1, 0], 3 => [1, 0, 0, 1]],
        ]);

        $run = $this->previewArea(self::SHELF_AREA);
        $over = $this->lineAt($run, '2026-07-19', 1);
        $this->assertTrue((bool) $over->FlagCloseExceedsOnHand, 'Two more than the shift ever held, and no short either side to cover it.');
        $this->assertSame('U2', $over->ExceptionCode);
        $this->assertFalse((bool) $over->ChainBlocked, 'Reported, and the chain balances around it (7 Oct 2026).');
        $this->assertEqualsWithDelta((float) $over->ChainNetVar,
            (float) $run->lines->where('StockItemNo', self::DUP_ITEM)->sum(fn ($l) => (float) $l->QtyVarNew), 0.005,
            'Whatever is left is the chain total — the issue to capture.');
    }

    /**
     * One shift that traded and an over of 1 — item 104, Behind Counter (Ryan,
     * 8 Oct 2026: "says held back but not held back ... which is it?"). Too
     * few active shifts is reported, never a hold: there is nothing to move
     * the over onto, so it reads as the issue to capture.
     */
    public function test_a_chain_with_one_active_shift_is_reported_not_held_back(): void
    {
        $this->seedShelfChain([
            '2026-07-17' => [1 => [5, 0, 5, 0], 3 => [5, 0, 5, 0]],
            '2026-07-18' => [1 => [5, 0, 6, 0], 3 => [6, 0, 6, 0]],
            '2026-07-19' => [1 => [6, 0, 6, 0], 3 => [6, 0, 6, 0]],
        ]);

        $run = $this->previewArea(self::SHELF_AREA);
        $line = $this->lineAt($run, '2026-07-18', 1);

        $this->assertTrue((bool) $line->FlagShortChain, 'Still reported as too few active shifts.');
        $this->assertFalse((bool) $line->ChainBlocked, 'But it does not hold the chain back.');
        $this->assertEqualsWithDelta(1.0, (float) $line->ChainNetVar, 0.001);
        $this->assertFalse($line->isEmptyChain(), 'An over of 1 is never hidden.');

        // Since 13 Oct 2026 it balances: the over is captured as the issue
        // the shift never got (Ryan: "customer wants over to balance").
        $chain = ChainSummary::of($run->lines)->first(fn ($c) => $c->first()->StockItemNo === self::DUP_ITEM);
        $this->assertSame('Balance 1 shift · issue of 1.000 captured', $chain->state()['label']);
        $this->assertTrue($line->capturesIssue() && $line->isWritable(), 'It can be ticked and amended.');

        $this->actingAs($this->person('admin'))
            ->get(route('app.stockrecon.line', [$run, $line]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSee('Balance 1 shift · issue of 1.000 captured')
            ->assertSee('missing issue 0.000 → 1.000')
            ->assertDontSee('Held back')
            ->assertDontSee('Too few active shifts to balance');
    }

    /**
     * An over balances by capturing its missing issue (Ryan, 13 Oct 2026:
     * "if a chain is Over by any amount it should be balancable" … "customer
     * wants over to balance"). The shift that closed on 6 from an opening of
     * 5 with nothing issued or sold was given 1 nobody wrote up: the run
     * proposes QtyIssued 0 → 1, the commit writes it with its prior kept and
     * the audit trail names it, a re-preview has nothing more to write, and
     * the reversal puts the 0 back.
     */
    public function test_an_over_balances_by_capturing_the_missing_issue_written_and_reversed(): void
    {
        config(['stockrecon.stamp_mode' => 'live', 'stockrecon.issue_mode' => 'live', 'stockrecon.missing_issue_mode' => 'live']);
        $this->seedShelfChain([
            '2026-07-17' => [1 => [5, 0, 5, 0], 3 => [5, 0, 5, 0]],
            '2026-07-18' => [1 => [5, 0, 6, 0], 3 => [6, 0, 6, 0]],
            '2026-07-19' => [1 => [6, 0, 6, 0], 3 => [6, 0, 6, 0]],
        ]);

        $run = $this->previewArea(self::SHELF_AREA);
        $line = $this->lineAt($run, '2026-07-18', 1);

        $this->assertEqualsWithDelta(1.0, (float) $line->QtyVar, 0.005, 'As counted, one over.');
        $this->assertEqualsWithDelta(0.0, (float) $line->QtyVarNew, 0.005, 'Captured, it balances to nil.');
        $this->assertEqualsWithDelta(1.0, (float) $line->QtyIssuedNew, 0.005, 'The issue it was missing.');
        $this->assertSame('captured', $line->outcomeKey());
        $this->assertStringStartsWith('Missing issue captured: 1.000 recorded as issued', $line->Outcome);
        $this->assertFalse($line->CountsMove, 'No count moves for it.');
        $this->assertTrue($line->WouldAmend && $line->isWritable());
        $this->assertSame(0, (int) $run->fresh()->OverRowsAfter, 'Nothing is left over.');
        $this->assertSame(1, $run->params()['CaptureMissingIssues'], 'The run records the switch it was made under.');

        // The chain panel's Amended block carries the Original block's columns
        // (Ryan, 16 Oct 2026), and its row adds up: it stands on the issue
        // captured, and its running total ends on what is left — nothing.
        ['shifts' => $shifts] = $this->service->chain($run, $line);
        $amended = $shifts->first(fn ($s) => (int) $s->Id === (int) $line->Id);
        $this->assertEqualsWithDelta(1.0, (float) $amended->QtyIssuedAmended, 0.005);
        $this->assertTrue($shifts->every(fn ($s) => (int) $s->Id === (int) $line->Id
            || abs((float) $s->QtyIssuedAmended - (float) $s->QtyIssued) < 0.0005), 'No other issue moves.');
        $this->assertEqualsWithDelta(0.0, (float) $shifts->last()->CumulativeVarNew, 0.005);
        $this->actingAs($this->person('admin'))
            ->get(route('app.stockrecon.line', [$run, $line]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSeeInOrder(['Amended — as this run proposes', '>Open<', '>Issued<', '>Close<', '>POS<',
                '>Variance<', '>Value<', '>Cumulative<', '>Amend<', '>Outcome<'], false);

        $result = $this->service->commit($run);
        $this->assertSame('COMMITTED', $result['status']->Code);
        $this->assertStringContainsString('1 missing issue(s) captured', $result['status']->Message);

        $after = $this->sourceRow(self::DUP_ITEM, '2026-07-18', 1);
        $this->assertEqualsWithDelta(1.0, (float) $after->QtyIssued, 0.005, 'QtyIssued is raised in PumpIT.');
        $this->assertEqualsWithDelta(0.0, (float) $after->QtyIssued_Original, 0.005, 'The _Original issue is never written.');
        $this->assertEqualsWithDelta(5.0, (float) $after->QtyOpen, 0.005, 'The counts are not touched.');
        $this->assertEqualsWithDelta(6.0, (float) $after->QtyClose, 0.005);

        $amendment = StockReconAmendment::query()->acrossBranches()
            ->where('RunId', $run->Id)->where('RunLineId', $line->Id)->firstOrFail();
        $this->assertEqualsWithDelta(0.0, (float) $amendment->PriorQtyIssued, 0.005);
        $this->assertEqualsWithDelta(1.0, (float) $amendment->NewQtyIssued, 0.005);

        $change = $this->db()->table('agora.vw_PumpItChange')
            ->where('Ledger', 'StockReconAmendment')->where('LedgerId', $amendment->Id)->first();
        $this->assertSame('Missing issue captured', $change->Action);
        $this->assertSame('Issue 0.000 → 1.000', $change->Change, 'The audit trail names the issue, and only the issue.');

        // Previewed again, PumpIT already holds the issue: nothing to write.
        $again = $this->previewArea(self::SHELF_AREA);
        $repeat = $this->lineAt($again, '2026-07-18', 1);
        $this->assertNull($repeat->QtyIssuedNew, 'Never captured twice.');
        $this->assertFalse((bool) $repeat->WouldAmend);
        $this->assertStringStartsWith('Missing issue already in PumpIT', $repeat->Outcome);
        ['shifts' => $shifts] = $this->service->chain($again, $repeat);
        $this->assertEqualsWithDelta(1.0, (float) $shifts->first(fn ($s) => (int) $s->Id === (int) $repeat->Id)->QtyIssuedAmended, 0.005,
            'Nothing to write, but the amended row stands on the issue PumpIT holds, so it still adds up.');

        $this->service->reverse($run->fresh(), 'TEST-capture off');
        $this->assertEqualsWithDelta(0.0, (float) $this->sourceRow(self::DUP_ITEM, '2026-07-18', 1)->QtyIssued, 0.005,
            'The reversal puts the prior issue back.');
    }

    /**
     * At 0 the option restores the split: the stretch after the break ends
     * over, as before 4 Oct — reported, and since 7 Oct balanced around.
     */
    public function test_keeping_breaks_can_be_switched_off(): void
    {
        // The balancing envelope on its own: the over left, before stage 7g
        // captures it as the missing issue (13 Oct 2026).
        config(['stockrecon.missing_issue_mode' => 'off']);
        $this->setCounts('2026-07-19', 3, ['QtyOpen_Original' => 28, 'QtyOpen' => 28]);

        $run = $this->service->preview(
            self::BRANCH, self::AREA, Carbon::parse('2026-07-17'), Carbon::parse('2026-07-21'),
            ['KeepBreaksUpTo' => 0], note: 'TEST-balancing',
        )->load('lines');

        $lines = $run->lines->where('StockItemNo', self::ITEM);

        $this->assertTrue($lines->every(fn ($l) => ! $l->BreakKept));
        $this->assertTrue($lines->contains(fn ($l) => $l->FlagNetOver), 'Split, the stretch after the break ends over.');
        $this->assertTrue($lines->every(fn ($l) => ! $l->ChainBlocked), 'An over holds no chain back.');

        // The closing before the break is pinned: the over stays on its own
        // side of it, never pushed across a count the rule says stands.
        $before = $lines->first(fn ($l) => $l->TransactionDate->toDateString() === '2026-07-19' && $l->ShiftNo === 2);
        $this->assertEqualsWithDelta(0.0, (float) $before->AmendClose, 0.005);
        $this->assertTrue($lines->contains(fn ($l) => (float) $l->QtyVarNew > 0.005), 'The over is left, on its stretch.');
    }

    /**
     * The client's rule 5: an over within 10% of a whole issue multiple is an
     * issue not written up, so the shift carries S1. Until 7 Oct 2026 it held
     * the chain back; ZRP then asked that a chain with an over within 10% be
     * balanced ("don't hold chains back"), so it is reported and the chain
     * balances.
     *
     * The multiple comes from agora.StockIssueMultiple — head office's
     * workbook — because PumpIT's own column is empty on the pack's sites.
     */
    public function test_an_over_of_a_whole_pack_is_reported_and_the_chain_still_balances(): void
    {
        $this->db()->table(config('agora.schema').'.StockIssueMultiple')->insert([
            'BranchId' => self::BRANCH, 'AreaNo' => self::AREA, 'StockItemNo' => self::ITEM,
            'IssueMultiple' => 10, 'IsWeighed' => 0, 'Workbook' => 'TEST-multiples',
        ]);

        $run = $this->preview();
        $lines = $run->lines->where('StockItemNo', self::ITEM);

        // 17 Jul shift 3 is over by exactly 10: one pack.
        $pack = $lines->first(fn ($l) => $l->TransactionDate->toDateString() === '2026-07-17' && $l->ShiftNo === 3);

        $this->assertTrue($pack->FlagPackOver);
        $this->assertSame('S1', $pack->ExceptionCode, 'Still reported on the shift.');
        $this->assertTrue($lines->every(fn ($l) => ! $l->ChainBlocked), 'But it no longer holds the chain back.');
        $this->assertTrue($lines->contains(fn ($l) => $l->isWritable()), 'The chain balances, and the press writes it.');
        $this->assertEqualsWithDelta(-5.0, (float) $lines->sum(fn ($l) => (float) $l->QtyVarNew), 0.005, 'Redistributed, never reduced.');
    }

    /**
     * The pack's E1: the stock falls and the till shows next to nothing
     * against it, so the selling PLU or recipe is not reducing this item.
     * A false short — nobody is charged, and it is not balanced.
     */
    public function test_a_chain_with_no_till_sales_linked_is_e1_and_not_balanced(): void
    {
        $this->db()->table('PumpIT.dbo.STK_StockReconLine')
            ->where('SSBranchId', self::BRANCH)->where('StockItemNo', self::ITEM)
            ->update(['QtyComputer' => 0, 'QtyComputer_Original' => 0]);

        $run = $this->preview();
        $lines = $run->lines->where('StockItemNo', self::ITEM);

        $this->assertTrue($lines->every(fn ($l) => $l->FlagNoPosLink));
        $this->assertTrue($lines->every(fn ($l) => $l->ChainBlocked));
        $this->assertTrue($lines->every(fn ($l) => $l->ExceptionCode === 'E1'),
            'E1 outranks the persistent-short class that would otherwise charge somebody.');
    }

    /**
     * The client's rule 10: a site is analysed only up to its last complete
     * recon day. Shift 3 is missing on the 21st, so the 21st is held back —
     * not balanced, not written, counted on the run.
     */
    public function test_a_day_the_recon_has_not_finished_is_held_back(): void
    {
        $this->db()->table('PumpIT.dbo.STK_StockReconLine')
            ->where('SSBranchId', self::BRANCH)->where('TransactionDate', '2026-07-21')->where('ShiftNo', 3)
            ->delete();

        $run = $this->preview()->fresh();

        $this->assertSame('2026-07-20', $run->CompleteTo?->toDateString());
        $this->assertSame(3, $run->HeldBackRows, 'Shifts 1 and 2 of the item and shift 1 of the dormant item.');
        $this->assertTrue($run->lines->every(fn ($l) => $l->TransactionDate->toDateString() <= '2026-07-20'));
    }

    /**
     * Somebody balanced by hand in PumpIT and turned an over into a short —
     * the pack's S7. The run says so, proposes the balanced answer for that
     * shift, and a live commit puts it back, leaving the chain in the source
     * intact: every opening equal to the closing before it.
     */
    public function test_a_hand_amendment_the_rules_forbid_is_flagged_and_put_back(): void
    {
        config(['stockrecon.stamp_mode' => 'live']);

        // 17 Jul shift 3 counted +10 over; by hand its closing went to 45,
        // which makes it 5 short.
        $this->setCounts('2026-07-17', 3, ['QtyClose' => 45]);

        $run = $this->preview();
        $line = $run->lines->where('StockItemNo', self::ITEM)
            ->first(fn ($l) => $l->TransactionDate->toDateString() === '2026-07-17' && $l->ShiftNo === 3);

        $this->assertSame('S7', $line->AuditCode, 'An over turned into a short by balancing is the pack\'s S7.');
        $this->assertTrue($line->WouldAmend, 'The hand figure differs from the answer, so the row is proposed.');
        $this->assertGreaterThan(0, (int) $run->fresh()->AuditRows);

        $this->service->commit($run);

        $chain = $this->db()->table('PumpIT.dbo.STK_StockReconLine')
            ->where('SSBranchId', self::BRANCH)->where('StockItemNo', self::ITEM)
            ->orderBy('TransactionDate')->orderBy('ShiftNo')->get(['QtyOpen', 'QtyClose'])->values();

        for ($i = 1; $i < $chain->count(); $i++) {
            $this->assertEqualsWithDelta((float) $chain[$i - 1]->QtyClose, (float) $chain[$i]->QtyOpen, 0.005,
                "After the commit the opening of row {$i} must equal the closing before it.");
        }

        $source = $this->db()->table('PumpIT.dbo.STK_StockReconLine')
            ->where('SSBranchId', self::BRANCH)->where('StockItemNo', self::ITEM)
            ->where('TransactionDate', '2026-07-17')->where('ShiftNo', 3)->first();

        $this->assertEqualsWithDelta((float) $line->QtyCloseNew, (float) $source->QtyClose, 0.005,
            'The hand closing is replaced by the balanced one.');
    }

    /**
     * The same breach on a chain Agora will NOT balance. It used to stay in
     * PumpIT, because a blocked chain carried no proposal at all — on real data
     * that was most chains. Now the chain is put back to the staff counts with
     * every opening repaired (the pack's own staff basis), and is still not
     * balanced: what blocks it stands, and is reported.
     *
     * Blocked by the share cap since 7 Oct 2026; a whole-pack over (S1) did
     * it until then, and an over holds no chain back now.
     */
    public function test_a_forbidden_hand_amendment_on_a_blocked_chain_goes_back_to_the_count(): void
    {
        config(['stockrecon.stamp_mode' => 'live']);

        // Somebody balanced 17 Jul shift 3 away by hand, past nil into a short (S7).
        $this->setCounts('2026-07-17', 3, ['QtyClose' => 45]);
        $this->setCounts('2026-07-18', 1, ['QtyOpen' => 45]);

        // Blocked: no correction may exceed 1% of what the shift held (F1).
        $run = $this->service->preview(
            self::BRANCH, self::AREA, Carbon::parse('2026-07-17'), Carbon::parse('2026-07-21'),
            ['MaxAmendmentPct' => 0.01], note: 'TEST-balancing',
        )->load('lines');
        $lines = $run->lines->where('StockItemNo', self::ITEM);
        $line = $lines->first(fn ($l) => $l->TransactionDate->toDateString() === '2026-07-17' && $l->ShiftNo === 3);

        $this->assertTrue($lines->every(fn ($l) => $l->ChainBlocked), 'The cap blocks the chain.');
        $this->assertSame('S7', $line->AuditCode);
        $this->assertTrue($line->RestoresHand && $line->isWritable());
        $this->assertEqualsWithDelta(60.0, (float) $line->QtyCloseNew, 0.005, 'Back to what the staff counted.');
        $this->assertStringStartsWith('Hand amendment reversed', $line->Outcome);
        $this->assertTrue($lines->every(fn ($l) => abs((float) $l->AmendClose) < 0.005), 'Reversed, not balanced.');

        $this->service->commit($run);

        $chain = $this->db()->table('PumpIT.dbo.STK_StockReconLine')
            ->where('SSBranchId', self::BRANCH)->where('StockItemNo', self::ITEM)
            ->orderBy('TransactionDate')->orderBy('ShiftNo')->get()->values();

        foreach ($chain as $i => $row) {
            $this->assertEqualsWithDelta((float) $row->QtyClose_Original, (float) $row->QtyClose, 0.005,
                "Row {$i}: every closing is the staff count again.");

            if ($i > 0) {
                $this->assertEqualsWithDelta((float) $chain[$i - 1]->QtyClose, (float) $row->QtyOpen, 0.005,
                    "Row {$i}: every opening meets the closing before it.");
            }
        }
    }

    /**
     * Agora never hands the client a new audit flag.
     *
     * At balancing somebody captured an issue of 10 on the last shift and
     * raised its closing by the same to match, which the pack accepts as it
     * stands (S4a, and S11 for the latest count). Agora writes counts, never
     * issues: putting the latest count back would turn the shift's staff over
     * into a short on the amended issue — S7, a flag that is not there today.
     * So the chain is withdrawn, F3, and nothing is written.
     */
    public function test_a_chain_whose_write_would_trip_the_clients_audit_is_held_back(): void
    {
        $this->setCounts('2026-07-21', 3, ['QtyIssued' => 10, 'QtyClose' => 60]);

        $run = $this->preview();
        $lines = $run->lines->where('StockItemNo', self::ITEM);
        $last = $lines->first(fn ($l) => $l->TransactionDate->toDateString() === '2026-07-21' && $l->ShiftNo === 3);

        $this->assertTrue($last->FlagAuditConflict);
        $this->assertSame('F3', $last->ExceptionCode);
        $this->assertTrue($lines->every(fn ($l) => $l->ChainBlocked && ! $l->WouldAmend),
            'The whole chain is held back — nothing on it is written.');
    }

    /**
     * A window that ends over still balances (ZRP and Ryan, 7 Oct 2026: "don't
     * hold chains back", "it needs to balance anyways"). No amendment removes
     * the over, so it stays on the shift that carries it and is reported; every
     * short around it is a miscount and goes to nil.
     */
    public function test_a_net_over_chain_balances_and_the_over_stays_on_its_shift(): void
    {
        // The balancing envelope on its own: the over left, before stage 7g
        // captures it as the missing issue (13 Oct 2026).
        config(['stockrecon.missing_issue_mode' => 'off']);
        // One extra unit sold on the last shift, with nothing issued to cover
        // it, tips the whole window over.
        // The sale as counted — the original column, which every preview
        // reads since 7 Oct 2026 — and the live one with it.
        $this->db()->table('PumpIT.dbo.STK_StockReconLine')
            ->where('SSBranchId', self::BRANCH)
            ->where('StockItemNo', self::ITEM)
            ->where('TransactionDate', '2026-07-21')
            ->where('ShiftNo', 3)
            ->update(['QtyComputer' => 100, 'QtyComputer_Original' => 100]);

        $run = $this->preview();
        $lines = $run->lines->where('StockItemNo', self::ITEM);

        $this->assertTrue($lines->contains(fn ($l) => $l->FlagNetOver));
        $this->assertTrue($lines->every(fn ($l) => ! $l->ChainBlocked), 'An over holds no chain back.');
        $this->assertTrue($lines->contains(fn ($l) => in_array($l->ExceptionCode, ['U1', 'U2', 'U3', 'U4'], true)),
            'A net over is an unrecorded issue and belongs in the U classes.');

        // T = 89: the counted -5 plus the 94 the last shift sold beyond its stock.
        $last = $lines->first(fn ($l) => $l->TransactionDate->toDateString() === '2026-07-21' && $l->ShiftNo === 3);
        $this->assertEqualsWithDelta(89.0, (float) $last->QtyVarNew, 0.005, 'The whole over stays on the shift that sold it.');
        $this->assertSame('unrecorded', $last->outcomeKey());
        $this->assertStringStartsWith('Unrecorded issue, over left', $last->Outcome);
        $this->assertEqualsWithDelta(0.0, (float) $last->AmendClose, 0.005, 'The latest count stands.');

        foreach ($lines->reject(fn ($l) => $l->Id === $last->Id) as $l) {
            $this->assertEqualsWithDelta(0.0, (float) $l->QtyVarNew, 0.005,
                "{$l->TransactionDate->toDateString()} shift {$l->ShiftNo}: every other shift balances to nil.");
            if ((float) $l->QtyVar < -0.005) {
                $this->assertGreaterThanOrEqual((float) $l->QtyVar - 0.005, (float) $l->QtyVarNew, 'A short never grows.');
            }
        }

        $this->assertEqualsWithDelta((float) $last->ChainNetVar, (float) $lines->sum(fn ($l) => (float) $l->QtyVarNew), 0.005,
            'Redistributed, never reduced: T is what is left.');
        $this->assertTrue($lines->contains(fn ($l) => $l->isWritable()), 'And the press writes it.');
    }

    /**
     * The customer's own chain (ZRP, 7 Oct 2026): D Pizza Box Large at
     * Debonairs FOH, 1–6 Sep, 39 over and held back whole as an unrecorded
     * issue — with -1/+1 and +7/-6/-1 sitting in it, plainly balancing. Five
     * days of it, same counts. It balances: those offsets go to nil, the -2 the
     * next closing proves was there goes into the over beside it, and the 39
     * stays on the three shifts that sold from an empty shelf.
     *
     * And the rows that balance together are marked: A, B and C.
     */
    public function test_a_chain_that_ends_over_balances_around_the_missing_issue_and_marks_what_balances_with_what(): void
    {
        // The balancing envelope on its own: the over left, before stage 7g
        // captures it as the missing issue (13 Oct 2026).
        config(['stockrecon.missing_issue_mode' => 'off']);
        $this->seedShelfChain([
            '2026-07-17' => [1 => [56, 0, 52, 3],  3 => [52, 0, 39, 14]],
            '2026-07-18' => [1 => [39, 0, 34, 12], 3 => [34, 0, 27, 1]],
            '2026-07-19' => [1 => [27, 0, 21, 5],  3 => [21, 0, 7, 14]],
            '2026-07-20' => [1 => [7, 0, 0, 5],    3 => [0, 0, 0, 20]],
            '2026-07-21' => [1 => [0, 0, 0, 7],    3 => [0, 0, 0, 14]],
        ]);

        $run = $this->previewArea(self::SHELF_AREA);
        $lines = $run->lines->where('StockItemNo', self::DUP_ITEM)->values();

        $this->assertEqualsWithDelta(39.0, (float) $lines->first()->ChainNetVar, 0.005);
        $this->assertTrue($lines->every(fn ($l) => ! $l->ChainBlocked), 'Not held back.');

        $at = fn (string $date, int $shift) => $this->lineAt($run, $date, $shift);

        // Closings: 52 → 53, 34 → 27, 27 → 26, 0 → 2; the rest stand.
        foreach ([['2026-07-17', 1, 53], ['2026-07-17', 3, 39], ['2026-07-18', 1, 27], ['2026-07-18', 3, 26],
            ['2026-07-19', 1, 21], ['2026-07-19', 3, 7], ['2026-07-20', 1, 2], ['2026-07-20', 3, 0],
            ['2026-07-21', 1, 0], ['2026-07-21', 3, 0]] as [$date, $shift, $close]) {
            $this->assertEqualsWithDelta($close, (float) $at($date, $shift)->QtyCloseNew, 0.005, "{$date} shift {$shift} closes on {$close}.");
        }

        // What is left: the over, on the shifts that sold from an empty shelf.
        foreach ([['2026-07-20', 3, 18], ['2026-07-21', 1, 7], ['2026-07-21', 3, 14]] as [$date, $shift, $over]) {
            $line = $at($date, $shift);
            $this->assertEqualsWithDelta($over, (float) $line->QtyVarNew, 0.005);
            $this->assertSame('unrecorded', $line->outcomeKey(), "{$date} shift {$shift} is the missing issue.");
        }
        $this->assertSame('U1', $at('2026-07-20', 3)->ExceptionCode, 'Still reported: it sold more than it held.');
        $this->assertEqualsWithDelta(0.0, (float) $lines->filter(fn ($l) => $l->TransactionDate->toDateString() < '2026-07-20'
            || ($l->TransactionDate->toDateString() === '2026-07-20' && $l->ShiftNo === 1))->sum(fn ($l) => abs((float) $l->QtyVarNew)), 0.005,
            'Everything before the empty shelf balances to nil.');

        // What balances with what.
        $groups = ChainSummary::of($lines)->first()->balanceGroups();
        $letter = fn (string $date, int $shift) => $groups[$at($date, $shift)->Id]['letter'] ?? null;

        $this->assertSame(['A', 'A'], [$letter('2026-07-17', 1), $letter('2026-07-17', 3)]);
        $this->assertSame(['B', 'B', 'B'], [$letter('2026-07-18', 1), $letter('2026-07-18', 3), $letter('2026-07-19', 1)]);
        $this->assertSame(['C', 'C'], [$letter('2026-07-20', 1), $letter('2026-07-20', 3)]);
        $this->assertSame([null, null, null], [$letter('2026-07-19', 3), $letter('2026-07-21', 1), $letter('2026-07-21', 3)],
            'A shift nothing balanced against carries no group.');
        $this->assertSame('18 Jul shift 3, 19 Jul shift 1', $groups[$at('2026-07-18', 1)->Id]['with']);
        $this->assertEqualsWithDelta(18.0, $groups[$at('2026-07-20', 1)->Id]['net'], 0.005, 'Group C leaves the over.');
        $this->assertStringContainsString('come to nil', ChainSummary::balanceNote($groups[$at('2026-07-17', 1)->Id]));

        $state = ChainSummary::of($lines)->first()->state();
        $this->assertStringStartsWith('Balance ', $state['label']);
        $this->assertStringContainsString('over 39', $state['label']);

        // On screen: the rail, the letter and the sentence behind it.
        $html = $this->actingAs($this->person('admin'))->get(route('app.stockrecon.run', $run))->assertOk()->getContent();
        $this->assertStringContainsString('bal-g1', (string) $html);
        $this->assertMatchesRegularExpression('/class="bal-tag" title="Group B — balances with 18 Jul shift 3, 19 Jul shift 1\. Together they come to nil\."/', (string) $html);
    }

    /** The counts are read from the _Original columns, so a re-run never compounds. */
    /**
     * The rand beside the quantity, on both sides of the chain.
     *
     * Ryan, 11 Sep 2026, reading run 7 next to the legacy Stock Recon
     * Balancing window: "any chance in the detail we can show the original
     * value and the amended value?" That screen carries Qty Var and Value Var
     * under each of its two blocks, because a variance is read as a pair —
     * 0.1 kg of a R240 cheese and 0.1 kg of sauce are the same number and not
     * the same finding.
     *
     * Derived from the SellPrice STORED on the run line, not joined to the
     * master, so a price change afterwards cannot rewrite what a run said.
     */
    public function test_the_chain_carries_the_value_beside_the_quantity_on_both_sides(): void
    {
        $run = $this->preview();
        $line = $run->lines->firstWhere('StockItemNo', self::ITEM);
        $this->assertNotNull($line);

        $shifts = $this->service->chain($run, $line)['shifts'];
        $this->assertNotEmpty($shifts);

        foreach ($shifts as $shift) {
            $this->assertEqualsWithDelta(
                round((float) $shift->QtyVar * 21.80, 2),
                (float) $shift->VarValue,
                0.005,
                'The counted variance value must be the counted quantity at the stored price.'
            );
            $this->assertEqualsWithDelta(
                round((float) $shift->QtyVarNew * 21.80, 2),
                (float) $shift->VarValueNew,
                0.005,
                'The amended variance value must follow the amended quantity, not the counted one.'
            );
        }

        $this->assertTrue(
            $shifts->contains(fn ($s) => abs((float) $s->VarValue) > 0.005),
            'A fixture built around a real variance must show a non-zero value somewhere, '
            .'or this test would pass just as well against a column of zeroes.'
        );
    }

    /**
     * The chains worth hiding, and the one that must never be hidden with them.
     *
     * Ryan, 11 Sep 2026: "shall we omit chains too short?" Only half of them.
     * "Too few active shifts to balance" covers a chain that never traded —
     * opening equal to closing throughout, a nil total, nothing for anybody to
     * decide — and it also covers a chain with ONE active shift carrying a
     * real short, which is flagged only because there is nowhere to move the
     * short TO. The second is the accountable case. Hiding on the flag would
     * take it with the noise, so the test is the substance.
     */
    public function test_only_a_chain_that_carries_nothing_counts_as_empty(): void
    {
        $run = $this->preview();

        $dormant = $run->lines->where('StockItemNo', self::DORMANT_ITEM);
        $this->assertGreaterThan(0, $dormant->count(), 'The fixture must hold a never-trading chain.');

        foreach ($dormant as $line) {
            $this->assertTrue($line->isEmptyChain(),
                'A chain that never moved and nets to nothing carries no finding and is hidden.');
        }

        $traded = $run->lines->where('StockItemNo', self::ITEM);
        foreach ($traded as $line) {
            $this->assertFalse($line->isEmptyChain(),
                'A chain with a real total must stay on the list whatever its length.');
        }
    }

    /**
     * The guard the whole distinction rests on: short AND carrying something
     * is never hidden. Asserted against the predicate directly, because the
     * fixture has no one-active-shift chain and inventing one would be testing
     * the fixture rather than the rule.
     */
    public function test_a_short_chain_that_carries_a_variance_is_never_hidden(): void
    {
        $line = new StockReconRunLine;
        $line->FlagShortChain = true;
        $line->ChainNetVar = '-5.000';

        $this->assertFalse($line->isEmptyChain(),
            'One active shift with a short nobody can balance is the accountable case, not noise.');

        $line->ChainNetVar = '0.000';
        $this->assertTrue($line->isEmptyChain());
    }

    /**
     * One shift, from its own row, without touching the ticks.
     *
     * Ryan, 11 Sep 2026: "the user cant select 1 row at a time, we need to be
     * able to amend per row." Every amendable row arrives ticked, so writing a
     * single shift meant unticking every other row first.
     *
     * The two things worth asserting are that exactly one row is written, and
     * that the screen is handed back in a usable state rather than with the
     * narrowing left behind it.
     */
    public function test_a_single_row_amend_writes_that_shift_and_no_other(): void
    {
        $user = User::query()->acrossBranches()->where('EmailAddress', 'ryan@revvtech.co.za')->first();

        if (! $user) {
            $this->markTestSkipped('No seeded administrator — run db:seed first.');
        }

        $run = $this->preview();

        $amendable = $run->lines
            ->where('WouldAmend', true)
            ->where('ChainBlocked', false)
            ->where('CommitState', 'pending');

        $this->assertGreaterThan(1, $amendable->count(),
            'This proves nothing unless there is more than one row it could have written.');

        $target = $amendable->first();

        $this->actingAs($user)
            ->post(route('app.stockrecon.commit', $run), ['only' => (string) $target->Id])
            ->assertRedirect(route('app.stockrecon.run', $run));

        $after = StockReconRunLine::query()->acrossBranches()->where('RunId', $run->Id)->get();

        $this->assertSame('committed', $after->firstWhere('Id', $target->Id)->CommitState,
            'The row whose button was pressed must be written.');

        $others = $after->where('Id', '!=', $target->Id)->where('CommitState', 'committed');
        $this->assertCount(0, $others,
            'A single-row amend that wrote anything else is the failure this exists to prevent.');

        /* And the screen comes back usable: the narrowing was a means, not an
           instruction, so what is still amendable is ticked again. */
        $stillOpen = $after->where('WouldAmend', true)->where('CommitState', 'pending');

        foreach ($stillOpen as $line) {
            $this->assertTrue((bool) $line->Selected,
                'After a single-row amend the remaining rows must not be left unticked.');
        }
    }

    public function test_the_preview_is_idempotent(): void
    {
        $first = $this->preview();
        $second = $this->preview();

        $this->assertEqualsWithDelta(
            (float) $first->UnitsAmended,
            (float) $second->UnitsAmended,
            0.001,
            'Re-running must propose the same amendment, not one on top of the last.'
        );
    }

    /**
     * A commit in journal mode records every amendment with the shift's prior
     * counts and leaves the customer's estate exactly as it was.
     *
     * `journal` is no longer the shipped setting — Ryan turned live writes on
     * 8 September 2026 — so this sets the config explicitly rather than relying
     * on the default. It is still worth having: journal is what the module
     * falls back to if the literal is ever flipped again, and "records the
     * decision without touching PumpIT" is the claim that makes falling back
     * safe.
     */
    public function test_a_journal_commit_records_the_amendment_and_touches_nothing_in_pumpit(): void
    {
        config(['stockrecon.stamp_mode' => 'journal']);

        $run = $this->preview();
        $before = $this->countsInSource();

        $result = $this->service->commit($run);

        $this->assertSame('COMMITTED', $result['status']->Code);
        $this->assertSame($before, $this->countsInSource(),
            'Journal mode must not move a single count in PumpIT.');

        $amendments = StockReconAmendment::query()->acrossBranches()->where('RunId', $run->Id)->get();

        $this->assertGreaterThan(0, $amendments->count());
        $this->assertTrue($amendments->every(fn ($a) => $a->StampMode === 'journal'));
        $this->assertTrue($amendments->every(fn ($a) => $a->State === 'active'));
    }

    /**
     * A second run cannot stack an amendment on a shift another run is already
     * holding: two live amendments mean two "prior" values and no way to know
     * which one a reversal should put back.
     */
    public function test_a_second_run_will_not_stack_an_amendment_on_the_same_shift(): void
    {
        // Journal, so PumpIT still differs from the answer and the second run
        // proposes the same rows again. In live mode it would propose nothing:
        // a row is proposed only where the answer differs from what PumpIT
        // holds, which is asserted in the live idempotency test below.
        config(['stockrecon.stamp_mode' => 'journal']);

        $first = $this->preview();
        $this->service->commit($first);

        $second = $this->preview();

        try {
            $this->service->commit($second);
            $this->fail('The second commit should have been refused.');
        } catch (AgoraProcException $e) {
            $this->assertSame('ALL_SKIPPED', $e->code());
        }

        // And the run is NOT left marked committed with nothing written, which
        // would strand it: it cannot be pressed again and has nothing to
        // reverse.
        $this->assertSame('previewed', $second->fresh()->Status);
    }

    /** A reversal puts back the prior pair the commit recorded, exactly. */
    public function test_a_reversal_restores_what_the_commit_recorded(): void
    {
        $run = $this->preview();
        $this->service->commit($run);

        $status = $this->service->reverse($run->fresh(), 'TEST-reason');

        $this->assertSame('REVERSED', $status->Code);
        $this->assertSame('reversed', $run->fresh()->Status);

        $amendments = StockReconAmendment::query()->acrossBranches()->where('RunId', $run->Id)->get();

        $this->assertTrue($amendments->every(fn ($a) => $a->State === 'reversed'));
        $this->assertTrue(
            StockReconRunLine::query()->acrossBranches()->where('RunId', $run->Id)
                ->where('CommitState', 'committed')->doesntExist(),
            'The proposals go back to pending so the run can be pressed again.'
        );
    }

    /**
     * THE LIVE PATH, END TO END: the counts move in the source, and a reversal
     * puts back exactly what was there.
     *
     * This is the test the whole `live` stamp mode rests on, and until Ryan
     * answered the build report on 8 September 2026 the write had never been
     * executed against any database in any mode. It writes to
     * `[PumpIT].dbo.STK_StockReconLine` — the local stub only, on branch 999,
     * which is why skipUnlessLocalStub() guards the whole class.
     *
     * It asserts the pair of things that make the mode safe rather than merely
     * working: BOTH columns move on a row the run said moves, and the reversal
     * restores the source byte for byte.
     */
    public function test_a_live_commit_writes_both_counts_and_a_reversal_puts_them_back(): void
    {
        config(['stockrecon.stamp_mode' => 'live']);

        $run = $this->preview();
        $before = $this->countsInSource();

        $moving = $run->lines->where('WouldAmend', true);
        $this->assertGreaterThan(0, $moving->count(), 'The fixture must propose something for this to mean anything.');

        $result = $this->service->commit($run);

        $this->assertSame('COMMITTED', $result['status']->Code);
        $this->assertNotSame($before, $this->countsInSource(), 'Live mode must actually move the counts.');

        // Every row the run said would move now holds the balanced pair in the
        // SOURCE, not merely in Agora's ledger.
        foreach ($moving as $line) {
            $source = $this->db()->table('PumpIT.dbo.STK_StockReconLine')
                ->where('SSBranchId', self::BRANCH)
                ->where('AreaNo', $line->AreaNo)
                ->where('StockItemNo', $line->StockItemNo)
                ->where('TransactionDate', $line->TransactionDate->toDateString())
                ->where('ShiftNo', $line->ShiftNo)
                ->first();

            $this->assertEqualsWithDelta((float) $line->QtyOpenNew, (float) $source->QtyOpen, 0.005,
                "Opening not written for {$line->StockItemNo} shift {$line->ShiftNo}.");
            $this->assertEqualsWithDelta((float) $line->QtyCloseNew, (float) $source->QtyClose, 0.005,
                "Closing not written for {$line->StockItemNo} shift {$line->ShiftNo}.");

            // The columns a balancing must never touch. QtyIssued because
            // amending an issue changes T rather than redistributing it; the
            // _Original pair because it is what keeps a re-preview idempotent.
            $this->assertEqualsWithDelta((float) $line->QtyIssued, (float) $source->QtyIssued, 0.005,
                'Balancing must not write QtyIssued — that is capturing a missing issue, a different act.');
            $this->assertEqualsWithDelta((float) $line->QtyOpen, (float) $source->QtyOpen_Original, 0.005,
                'QtyOpen_Original must survive a live commit, or a re-run compounds its own amendment.');
            $this->assertEqualsWithDelta((float) $line->QtyClose, (float) $source->QtyClose_Original, 0.005,
                'QtyClose_Original must survive a live commit.');
        }

        $this->service->reverse($run->fresh(), 'TEST-live round trip');

        $this->assertSame($before, $this->countsInSource(),
            'A reversal must restore the source exactly — every row, both columns.');
    }

    /**
     * A reversal never writes the old counts over a count somebody has taken
     * since. Before 5 Oct 2026 it did, and the recount was lost without a
     * trace. Refused whole, nothing moves; once the row is back to what the
     * commit left, the same reversal goes through.
     */
    public function test_a_reversal_refuses_when_a_count_it_amended_has_changed_since(): void
    {
        config(['stockrecon.stamp_mode' => 'live']);

        $before = $this->countsInSource();
        $run = $this->preview();
        $this->service->commit($run);
        $committed = $this->countsInSource();

        $a = StockReconAmendment::query()->acrossBranches()->where('RunId', $run->Id)
            ->whereColumn('PriorQtyClose', '<>', 'NewQtyClose')->orderBy('Id')->firstOrFail();
        $date = $a->TransactionDate->toDateString();

        // Somebody recounts that shift's closing in PumpIT.
        $this->setCounts($date, $a->ShiftNo, ['QtyClose' => (float) $a->NewQtyClose + 1]);

        try {
            $this->service->reverse($run->fresh(), 'TEST-count moved');
            $this->fail('The reversal should have been refused.');
        } catch (AgoraProcException $e) {
            $this->assertSame('COUNT_MOVED', $e->code());
            $this->assertStringContainsString("on {$date} shift {$a->ShiftNo} has changed", $e->getMessage());
            $this->assertStringContainsString('it now holds opening', $e->getMessage());
        }

        $this->assertEqualsWithDelta((float) $a->NewQtyClose + 1, (float) $this->sourceRow(self::ITEM, $date, $a->ShiftNo)->QtyClose, 0.005,
            'The recount is not written over.');
        $this->assertTrue(StockReconAmendment::query()->acrossBranches()->where('RunId', $run->Id)
            ->get()->every(fn ($x) => $x->State === 'active'), 'Nothing was reversed.');
        $this->assertSame('committed', $run->fresh()->Status);

        // Put back to what the commit left, the reversal goes through.
        $this->setCounts($date, $a->ShiftNo, ['QtyClose' => (float) $a->NewQtyClose]);
        $this->assertSame($committed, $this->countsInSource());

        $this->assertSame('REVERSED', $this->service->reverse($run->fresh(), 'TEST-count back')->Code);
        $this->assertSame($before, $this->countsInSource(), 'The source is as it was before the commit.');
    }

    /** A journal commit wrote nothing to PumpIT, so there is nothing to have moved: it reverses whatever PumpIT holds. */
    public function test_a_journal_reversal_is_not_checked_against_pumpit(): void
    {
        config(['stockrecon.stamp_mode' => 'journal']);

        $run = $this->preview();
        $this->service->commit($run);
        $this->setCounts('2026-07-17', 1, ['QtyClose' => 61]);

        $this->assertSame('REVERSED', $this->service->reverse($run->fresh(), 'TEST-journal')->Code);
        $this->assertEqualsWithDelta(61.0, (float) $this->sourceRow(self::ITEM, '2026-07-17', 1)->QtyClose, 0.005,
            'A journal reversal writes nothing to PumpIT.');
    }

    /**
     * A live run stays idempotent: re-previewing after a commit proposes the
     * same amendment rather than one on top of the last.
     *
     * This is the failure mode of the procedure being replaced.
     * dbo.sp_UpdateAUTOStockReconBalancing writes to the live columns while
     * balancing from them, so re-running compounds silently and nothing
     * anywhere records that it has.
     */
    public function test_a_live_commit_does_not_change_what_a_second_preview_proposes(): void
    {
        config(['stockrecon.stamp_mode' => 'live']);

        $first = $this->preview();
        $proposed = (float) $first->UnitsAmended;

        $this->service->commit($first);

        $second = $this->preview();

        $this->assertEqualsWithDelta($proposed, (float) $second->UnitsAmended, 0.001,
            'Reading the _Original columns is what makes this true; writing them would break it.');
        $this->assertSame(0, (int) $second->AmendedRows,
            'PumpIT already holds the answer, so there is nothing left to write.');
    }

    /**
     * The run records what the item is CALLED, not only its number.
     *
     * The proposals table is the one screen here not powered by a procedure —
     * its tick boxes decide what a commit writes — so it has no join to hang a
     * name on, and it showed "10" over "area 1" until Ryan said so on the live
     * screen. The label is resolved once at preview time and stored, for the
     * same reason SellPrice already is: a run is a record of what was true
     * when it was made, and an item gets renamed.
     */
    public function test_the_run_records_what_the_item_is_called(): void
    {
        $run = $this->preview();

        foreach ($run->lines as $line) {
            $this->assertNotNull($line->ItemDescription, 'Every line should carry the item name.');
            $this->assertSame('TEST-Hot Foods', $line->AreaDescription);
        }

        $this->assertSame('TEST-Chicken quarter',
            $run->lines->firstWhere('StockItemNo', self::ITEM)->ItemDescription);

        // And the fallback, for a run made before the label existed.
        $line = $run->lines->first();
        $line->ItemDescription = null;
        $this->assertSame($line->StockItemNo, $line->itemLabel(),
            'A line with no stored label falls back to the number it always had.');
    }

    /**
     * WHO WAS ON THE SHIFT — the thing docs/stock-recon.md said the algorithm
     * could not see.
     *
     * dbo.STK_StockReconEmployees is keyed on exactly the grain a recon line
     * is, and on branch 18 it covers every shift. That turns D1 — persistent
     * short, the one class that can carry a charge — from an item-level
     * observation into something that can name a person.
     */
    public function test_the_shift_carries_who_was_on_it(): void
    {
        $run = $this->preview();

        $shiftOne = $run->lines->where('ShiftNo', 1)->where('StockItemNo', self::ITEM);

        $this->assertGreaterThan(0, $shiftOne->count());

        foreach ($shiftOne as $line) {
            $this->assertSame(1, $line->EmployeeCount);
            $this->assertSame('Ndlovu Sipho', $line->EmployeeNames);
            $this->assertSame('TE01', $line->EmployeeCodes);
            $this->assertFalse($line->sharedShift());
        }
    }

    /**
     * A SHARED SHIFT NAMES BOTH PEOPLE AND SAYS IT IS SHARED.
     *
     * 90 of branch 18's 1,138 shifts have more than one person signed on, up
     * to three. A short on a shift two people worked cannot be attributed to
     * either of them, so collapsing the list to one name would manufacture an
     * accountability the data does not support — which is the whole reason
     * the count is stored beside the names rather than inferred from them.
     */
    public function test_a_shared_shift_names_everybody_on_it(): void
    {
        $run = $this->preview();

        $shiftThree = $run->lines->where('ShiftNo', 3)->where('StockItemNo', self::ITEM)->first();

        $this->assertSame(2, $shiftThree->EmployeeCount);
        $this->assertTrue($shiftThree->sharedShift());
        $this->assertStringContainsString('Khumalo Thandi', $shiftThree->EmployeeNames);
        $this->assertStringContainsString('Ndlovu Sipho', $shiftThree->EmployeeNames);

        // The codes and the names are ordered the same way, so the nth code is
        // the nth name — the only thing that makes carrying both readable.
        $this->assertSame('TE02, TE01', $shiftThree->EmployeeCodes,
            'Both aggregates order by the display name, so the pair lines up by index.');
    }

    /**
     * THE BUG RYAN SAW ON THE LIVE SCREEN, 9 September 2026.
     *
     * v1__14a stores the labels on the line at preview time, and every run
     * made before it has NULL in all six columns. That was expected. What was
     * not is that each reader was left to fall back on its own: the exceptions
     * procedure wrote ISNULL(stored, join) and named the product, the chain
     * header joined the master and named the product, and the proposals screen
     * — Eloquent, no join — showed `10` over `area 1`. One row, three answers.
     * His words: "why does it show 10 instead of the product? but
     * exceptions.... shows the product?"
     *
     * So this nulls the stored labels exactly as an old run has them, and
     * asserts the model still names everything. It reads through
     * agora.vw_StockReconRunLine, which is the single place the fallback now
     * lives.
     */
    public function test_a_run_that_stored_no_labels_still_names_the_item_and_the_shift(): void
    {
        $run = $this->preview();

        // Exactly the shape of a run previewed before v1__14a: the amounts are
        // recorded, none of the labels are.
        DB::connection('agora')->table(config('agora.schema').'.StockReconRunLine')
            ->where('BranchId', self::BRANCH)
            ->where('RunId', $run->Id)
            ->update([
                'ItemDescription' => null, 'POSCode' => null, 'StockLocation' => null,
                'AreaDescription' => null, 'EmployeeCodes' => null, 'EmployeeNames' => null,
                'EmployeeCount' => 0,
            ]);

        $run->unsetRelation('lines');
        $line = $run->lines->where('ShiftNo', 3)->where('StockItemNo', self::ITEM)->first();

        $this->assertSame('TEST-Chicken quarter', $line->ItemDescription,
            'With nothing stored, the item name comes from the stock master.');
        $this->assertSame('TEST-Chicken quarter', $line->itemLabel(),
            'And the screen shows the name, not the number Ryan was looking at.');
        $this->assertSame('TEST-Hot Foods', $line->areaLabel(),
            'The area is named too, rather than reading "area 1".');

        // The half that was blank in EVERY reader, including the one that got
        // the item right.
        $this->assertSame(2, $line->EmployeeCount);
        $this->assertTrue($line->sharedShift());
        $this->assertStringContainsString('Khumalo Thandi', $line->EmployeeNames);
        $this->assertStringContainsString('Ndlovu Sipho', $line->EmployeeNames);
    }

    /**
     * A SHIFT NOBODY WAS SIGNED ON TO STAYS EMPTY.
     *
     * The resolution keys on EmployeeCodes rather than EmployeeCount, and this
     * is why: the count is NOT NULL DEFAULT 0, so it cannot tell a run that
     * recorded nothing from a shift that recorded nobody. Keying on the count
     * would make every genuinely unmanned shift go back to the estate and
     * invent an answer for it.
     */
    public function test_a_recorded_shift_with_nobody_on_it_is_not_back_filled(): void
    {
        $run = $this->preview();

        DB::connection('agora')->table(config('agora.schema').'.StockReconRunLine')
            ->where('BranchId', self::BRANCH)
            ->where('RunId', $run->Id)
            ->update(['EmployeeCodes' => '', 'EmployeeNames' => null, 'EmployeeCount' => 0]);

        $run->unsetRelation('lines');
        $line = $run->lines->where('ShiftNo', 3)->where('StockItemNo', self::ITEM)->first();

        $this->assertSame(0, $line->EmployeeCount,
            'A run that recorded an empty list keeps it — the estate is not consulted.');
        $this->assertNull($line->EmployeeNames);
        $this->assertFalse($line->sharedShift());
    }

    /**
     * TICKING A PROPOSAL WRITES TO THE TABLE, THOUGH THE MODEL READS A VIEW.
     *
     * StockReconRunLine reads agora.vw_StockReconRunLine so an old run still
     * names its item; the view joins three masters and SQL Server cannot update
     * through it. StockReconService::select() therefore names the table. This
     * is the assertion that keeps those two facts from drifting apart — without
     * it, the tick boxes would fail only in front of an operator, on the one
     * screen that decides what gets written to the customer's database.
     */
    public function test_ticking_a_proposal_persists_and_reads_back(): void
    {
        $run = $this->preview();

        $amendable = $run->lines
            ->where('WouldAmend', true)
            ->where('ChainBlocked', false)
            ->where('CommitState', 'pending');

        $this->assertGreaterThan(1, $amendable->count(),
            'The fixture must propose more than one amendment for this to mean anything.');

        $keep = $amendable->first();

        $this->assertSame(1, $this->service->select($run, [$keep->Id]),
            'Exactly the one line asked for is ticked.');

        $run->unsetRelation('lines');

        $this->assertTrue((bool) $run->lines->firstWhere('Id', $keep->Id)->Selected);
        $this->assertSame(1, $run->lines->where('Selected', true)->count(),
            'And everything else was unticked in the same call.');

        // A row the commit could not honour cannot be ticked, whatever is asked
        // for — the tick is a promise the commit has to keep.
        $blocked = $run->lines->firstWhere('ChainBlocked', true);

        if ($blocked !== null) {
            $this->assertSame(0, $this->service->select($run, [$blocked->Id]));
        }
    }

    /** An empty window is an answer, not a failure. */
    public function test_a_window_with_no_shifts_in_it_previews_cleanly(): void
    {
        $run = $this->service->preview(
            self::BRANCH,
            self::AREA,
            Carbon::parse('2020-01-01'),
            Carbon::parse('2020-01-07'),
        );

        $this->assertSame('previewed', $run->Status);
        $this->assertSame(0, $run->TotalRows);
        $this->assertSame(0, $run->ChainCount);
    }

    /** An area that is not configured at this site is refused by name. */
    public function test_an_unknown_area_is_refused(): void
    {
        $this->expectException(AgoraProcException::class);

        $this->service->preview(
            self::BRANCH,
            987654,
            Carbon::parse('2026-07-17'),
            Carbon::parse('2026-07-21'),
        );
    }

    /**
     * ONE SHIFT IS ONE ROW, however many master rows the item has.
     *
     * STK_StockMaster is keyed by branch AND Location — the same StockItemNo
     * exists once under WINBRANCH and once under AURA on a site that runs both
     * POS families. Every reader in this module joins the master on
     * (BranchId, StockItemNo) alone, so a second master row multiplies the
     * shift rather than naming it, and stage 8 of PreviewBalancing does it on
     * an INSERT: the duplicate is WRITTEN to agora.StockReconRunLine, gets its
     * own LineNo, its own tick box, and its own place in the commit.
     *
     * The unique key (BranchId, RunId, LineNo) does not catch it, because
     * LineNo is a ROW_NUMBER over the already-fanned set.
     */
    public function test_a_second_master_row_does_not_duplicate_the_shift(): void
    {
        $shifts = $this->preview()->lines->where('StockItemNo', self::ITEM)->count();

        // The same item, counted at a second location. Nothing else changes.
        $this->db()->table('PumpIT.dbo.STK_StockMaster')->insert([
            'SSBranchId' => self::BRANCH, 'StockItemNo' => self::ITEM,
            'StockItemDescription' => 'TEST-Chicken quarter (AURA)', 'AreaNo' => self::AREA,
            'Location' => 'TEST2', 'UOMCode' => 'EA',
            'SellingPrice' => 21.80, 'POSCode' => 'TESTAURA',
            'QtyVarAllowance' => 0, 'isMonitoredItem' => 0,
        ]);

        $run = $this->preview();
        $lines = $run->lines->where('StockItemNo', self::ITEM);

        // The grid, which reads agora.vw_StockReconRunLine.
        $this->assertSame($shifts, $lines->count(),
            'A second master row for the same item multiplied the shifts: the run line is the shift, not the master row.');

        // The LEDGER underneath it. The view could be made honest while the
        // INSERT still wrote two rows per shift, and that is the half of this
        // the commit would act on — so it is asserted separately, on the table
        // and not through the view that reads it.
        $stored = $this->db()->table('agora.StockReconRunLine')
            ->where('RunId', $run->Id)->where('StockItemNo', self::ITEM)->count();

        $this->assertSame($shifts, $stored,
            'The preview WROTE a duplicate run line per master row. Each carries its own LineNo, tick box and commit path.');

        // And the chain panel's own count, which is a COUNT(*) in the header
        // block of usp_StockRecon_DrillChain — a third, independent join.
        $chain = $this->service->chain($run, $lines->first());

        $this->assertSame($shifts, (int) $chain['item']->ChainShifts,
            'The chain panel counted the master rows rather than the shifts.');
        $this->assertSame($shifts, $chain['shifts']->count(),
            'The chain panel listed a shift more than once.');
    }

    // ------------------------------------------------------- duplicate issues

    /**
     * THE CASE THE CUSTOMER ASKED ABOUT (branch 6, Tobacco, 23 Aug 2026): the
     * night shift re-keyed the day's issue. Day: 9 + 20 issued − 3 sold = 26,
     * counted 26 — it arrived. Night: 26 + 20 again − 2 sold = 44, counted 24 —
     * short exactly 20. The count is the witness that the second 20 never
     * reached the shelf.
     *
     * The preview takes it out of the basis (D1), so the night balances and
     * nobody is charged; a live commit sets QtyIssued to 0 in PumpIT with the
     * prior kept, the audit trail says so, the next preview finds nothing left
     * to do and the pack's own audit reads it as S4b; a reversal puts it back.
     */
    public function test_a_clear_duplicate_issue_is_removed_written_and_reversed(): void
    {
        config(['stockrecon.stamp_mode' => 'live', 'stockrecon.issue_mode' => 'live']);
        $this->seedShelfChain($this->duplicateChain());

        $run = $this->previewArea(self::SHELF_AREA);
        $dup = $this->lineAt($run, '2026-07-17', 3);
        $twin = $this->lineAt($run, '2026-07-17', 1);

        $this->assertSame('D1', $dup->DupTier);
        $this->assertSame('D1', $dup->ExceptionCode);
        $this->assertSame('2026-07-17', $dup->DupTwinDate?->toDateString());
        $this->assertSame(1, $dup->DupTwinShift, 'The twin is the day shift, where the stock arrived.');
        $this->assertEqualsWithDelta(20.0, (float) $dup->DupQty, 0.005);
        $this->assertEqualsWithDelta(0.0, (float) $dup->DupTwinVar, 0.005);
        $this->assertNull($dup->DupReason);
        $this->assertEqualsWithDelta(-20.0, (float) $dup->QtyVar, 0.005, 'As counted, the night was 20 short.');
        $this->assertEqualsWithDelta(0.0, (float) $dup->QtyVarNew, 0.005, 'With the duplicate out, it balances: nobody is charged.');
        $this->assertEqualsWithDelta(20.0, (float) $dup->QtyIssued, 0.005, 'The run records the issue as keyed.');
        $this->assertNotNull($dup->QtyIssuedNew);
        $this->assertEqualsWithDelta(0.0, (float) $dup->QtyIssuedNew, 0.005, 'The commit will write it as 0.');
        $this->assertTrue($dup->WouldAmend && $dup->isWritable() && $dup->removesDuplicate());
        $this->assertFalse($dup->CountsMove, 'Nothing else on the chain needs a count moved.');
        $this->assertFalse($dup->ChainBlocked);
        $this->assertStringStartsWith('Duplicate issue removed (D1)', $dup->Outcome);
        $this->assertNull($twin->DupTier, 'The twin arrived; it is not a duplicate of anything.');
        $this->assertSame(1, (int) $run->fresh()->DuplicatesClear);

        // The chain panel's account of its total: the 20 taken out is the
        // term that turns the counted total into T.
        ['item' => $item, 'shifts' => $shifts] = $this->service->chain($run, $dup);
        $this->assertSame(1, (int) $item->DuplicatesCount);
        $this->assertEqualsWithDelta(20.0, (float) $item->DuplicatesTaken, 0.005);
        $this->assertEqualsWithDelta((float) $item->ChainNetVar,
            (float) $item->CountedTotal + (float) $item->OpeningsMoved + (float) $item->DuplicatesTaken, 0.001);
        $this->assertTrue((bool) $item->TotalReconciles);
        $this->assertEqualsWithDelta((float) $item->ChainNetVar, (float) $shifts->last()->CumulativeVar, 0.001,
            'The Cumulative column counts the issue taken out, so it ends on the chain total.');

        // The day shift balanced, so the running total starts at nothing: a
        // dash, like every other figure column — never a column of 0.000
        // (Ryan, 6 Oct 2026: "should cumulative always show 0?").
        $this->actingAs($this->person('admin'))
            ->get(route('app.stockrecon.line', [$run, $dup]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSee('<td class="num muted">—</td>', false)
            ->assertDontSee('<td class="num muted">0.000</td>', false);

        $before = $this->sourceRow(self::DUP_ITEM, '2026-07-17', 3);
        $result = $this->service->commit($run);
        $this->assertSame('COMMITTED', $result['status']->Code);
        $this->assertStringContainsString('1 duplicate issue(s) set to 0', $result['status']->Message);

        $after = $this->sourceRow(self::DUP_ITEM, '2026-07-17', 3);
        $this->assertEqualsWithDelta(0.0, (float) $after->QtyIssued, 0.005, 'QtyIssued is set to 0 in PumpIT.');
        $this->assertEqualsWithDelta(20.0, (float) $after->QtyIssued_Original, 0.005, 'The _Original issue is never written.');
        $this->assertEqualsWithDelta((float) $before->QtyOpen, (float) $after->QtyOpen, 0.005, 'The counts are not touched.');
        $this->assertEqualsWithDelta((float) $before->QtyClose, (float) $after->QtyClose, 0.005);

        $amendment = StockReconAmendment::query()->acrossBranches()
            ->where('RunId', $run->Id)->where('RunLineId', $dup->Id)->firstOrFail();
        $this->assertEqualsWithDelta(20.0, (float) $amendment->PriorQtyIssued, 0.005, 'The prior is kept, so it can be put back.');
        $this->assertEqualsWithDelta(0.0, (float) $amendment->NewQtyIssued, 0.005);
        $this->assertSame($amendment->PriorQtyOpen, $amendment->NewQtyOpen);
        $this->assertSame($amendment->PriorQtyClose, $amendment->NewQtyClose);

        $trail = $this->db()->selectOne("SELECT * FROM agora.vw_PumpItChange WHERE Ledger = 'StockReconAmendment' AND LedgerId = ?", [$amendment->Id]);
        $this->assertSame('Issue 20.000 → 0.000', $trail->Change, 'The audit trail names the issue, and only the issue.');
        $this->assertSame('Duplicate issue removed', $trail->Action);
        $this->assertStringContainsString('(D1)', $trail->Reason);

        // Opened, the change reads the issue from the ledger on both sides —
        // 20 → 0 — so the shift's variance after the correction is nil, not
        // the run line's 20 twice (usp_Reports_GetAuditChange, v1__22pf).
        $shifts = app(ProcedureService::class)->callSets('usp_Reports_GetAuditChange', [
            'Ledger' => 'StockReconAmendment', 'LedgerId' => $amendment->Id,
        ])[1];
        $opened = $shifts->first(fn ($s) => (bool) $s->IsThis);
        $this->assertEqualsWithDelta(20.0, (float) $opened->IssuedBefore, 0.005);
        $this->assertEqualsWithDelta(0.0, (float) $opened->IssuedAfter, 0.005);
        $this->assertEqualsWithDelta(-20.0, (float) $opened->VarBefore, 0.005, 'Before: 20 short.');
        $this->assertEqualsWithDelta(0.0, (float) $opened->VarAfter, 0.005, 'After the correction: nil.');
        $twinShift = $shifts->first(fn ($s) => (int) $s->ShiftNo === 1 && str_starts_with((string) $s->TransactionDate, '2026-07-17'));
        $this->assertEqualsWithDelta((float) $twinShift->IssuedBefore, (float) $twinShift->IssuedAfter, 0.005,
            'A shift the commit did not write reads the same issue both sides.');

        $admin = User::query()->acrossBranches()->where('EmailAddress', config('agora.admin.email'))->first();
        if ($admin) {
            $this->actingAs($admin)
                ->get(route('app.reports.audit.change', ['StockReconAmendment', $amendment->Id]), ['X-Requested-With' => 'XMLHttpRequest'])
                ->assertOk()
                ->assertSee('<span class="ba-old ">20</span><span class="ba-new ">0</span>', false);
        }

        $again = $this->previewArea(self::SHELF_AREA);
        $line = $this->lineAt($again, '2026-07-17', 3);
        // On the original basis (7 Oct 2026) the duplicate is in what staff
        // keyed, so it is found again — but PumpIT already holds 0, so there
        // is nothing to write and it says so.
        $this->assertSame('D1', $line->DupTier, 'Found in the originals.');
        $this->assertNull($line->QtyIssuedNew, 'Nothing to write: PumpIT holds 0.');
        $this->assertSame('Already taken out: PumpIT holds no issue on this shift', $line->DupReason);
        $this->assertSame('S4b', $line->AuditCode, 'The client\'s pack reads it as "issue reduced at balancing".');
        $this->assertSame(0, (int) $again->fresh()->AmendedRows);

        $this->service->reverse($run->fresh(), 'TEST-duplicate round trip');
        $this->assertEqualsWithDelta(20.0, (float) $this->sourceRow(self::DUP_ITEM, '2026-07-17', 3)->QtyIssued, 0.005,
            'A reversal puts the issue back.');
    }

    /** A reversal never writes the old issue over one somebody has re-keyed since. */
    public function test_a_reversal_refuses_when_the_duplicate_has_changed_since(): void
    {
        config(['stockrecon.stamp_mode' => 'live', 'stockrecon.issue_mode' => 'live']);
        $this->seedShelfChain($this->duplicateChain());

        $run = $this->previewArea(self::SHELF_AREA);
        $this->service->commit($run);

        $this->setRow(self::DUP_ITEM, '2026-07-17', 3, ['QtyIssued' => 5]);

        try {
            $this->service->reverse($run->fresh(), 'TEST-moved');
            $this->fail('The reversal should have been refused.');
        } catch (AgoraProcException $e) {
            $this->assertSame('ISSUE_MOVED', $e->code());
        }

        $this->assertEqualsWithDelta(5.0, (float) $this->sourceRow(self::DUP_ITEM, '2026-07-17', 3)->QtyIssued, 0.005,
            'Nothing was put back.');
        $this->assertTrue(StockReconAmendment::query()->acrossBranches()->where('RunId', $run->Id)
            ->get()->every(fn ($a) => $a->State === 'active'), 'The amendments stay active.');
    }

    /**
     * Both shifts short by the issue: nothing says which issue was real, or
     * whether either was. D2 — listed with its twin and the reason, never
     * changed, and (since 5 Oct 2026, PM decision 01a10b21) holding its whole
     * chain back. config('stockrecon.exceptions.D2.blocks') is the one switch.
     */
    public function test_a_possible_duplicate_is_listed_and_never_changed(): void
    {
        config(['stockrecon.stamp_mode' => 'live', 'stockrecon.issue_mode' => 'live']);
        $this->seedShelfChain($this->possibleChain());

        $run = $this->previewArea(self::SHELF_AREA);
        $day = $this->lineAt($run, '2026-07-17', 1);
        $night = $this->lineAt($run, '2026-07-17', 3);

        foreach ([$day, $night] as $line) {
            $this->assertSame('D2', $line->DupTier);
            $this->assertSame('D2', $line->ExceptionCode);
            $this->assertSame('Both shifts short: which issue was real is not known', $line->DupReason);
            $this->assertNull($line->QtyIssuedNew, 'A possible duplicate is never written.');
            $this->assertFalse($line->WouldAmend);
            $this->assertEqualsWithDelta(-20.0, (float) $line->QtyVarNew, 0.005, 'Its short stands, for the site to check.');
            $this->assertNull($line->DupReleaseId);
        }

        $this->assertSame(1, $night->DupTwinShift, 'Each names the other as its twin.');
        $this->assertSame(3, $day->DupTwinShift);
        $this->assertSame('Held back, possible duplicate (D2): twin 17 Jul shift 1; check, then release', $night->Outcome,
            'The row says it is held, names the twin, and says the two ways out.');
        $this->assertSame('Held back, possible duplicate (D2)', $night->outcomeLabel());
        $this->assertTrue($run->lines->every(fn ($l) => $l->ChainBlocked && $l->DupHold), 'The whole chain is held back.');
        $this->assertSame('Held back: a possible duplicate issue (D2) on this item holds the chain',
            $this->lineAt($run, '2026-07-18', 1)->Outcome, 'And every other shift on it says why.');
        $this->assertSame(2, (int) $run->fresh()->DuplicatesPossible);
        $this->assertSame(1, $run->params()['BlockPossibleDuplicates'], 'The run records the switch it was made under.');

        config(['stockrecon.exceptions.D2.blocks' => false]);
        $listed = $this->previewArea(self::SHELF_AREA);
        $this->assertTrue($listed->lines->every(fn ($l) => ! $l->ChainBlocked && ! $l->DupHold),
            'Switched off, a possible duplicate is listed and holds nothing back.');
        $this->assertStringStartsWith('Possible duplicate issue (D2)', $this->lineAt($listed, '2026-07-17', 3)->Outcome);
    }

    /**
     * THE CASE THE HOLD EXISTS FOR (PM decision 01a10b21). Head office hid the
     * night's 20 short by moving every count after it up 20 — the short is
     * gone from PumpIT, and the latest closing is amended (the pack's S11).
     * Since 30 Sep a commit puts such moves back, which on a possible
     * duplicate puts the 20 back on the shift: on staff pay.
     *
     * With D2 not blocking, the chain balances and every hand figure is
     * proposed back to the count. With it blocking, the chain is held: no
     * proposal, the commit refuses, PumpIT does not move.
     */
    public function test_a_possible_duplicate_holds_back_a_chain_whose_short_was_hidden_and_the_commit_writes_nothing(): void
    {
        config(['stockrecon.stamp_mode' => 'live', 'stockrecon.issue_mode' => 'live']);
        $this->seedShelfChain($this->possibleChain());
        $this->hideTheNightShortByMovingCounts();

        config(['stockrecon.exceptions.D2.blocks' => false]);
        $unheld = $this->previewArea(self::SHELF_AREA);
        $this->assertSame('S11', $this->lineAt($unheld, '2026-07-21', 3)->AuditCode, 'The hidden short shows as the latest count amended.');
        $this->assertTrue($this->lineAt($unheld, '2026-07-17', 3)->WouldAmend,
            'Not held, the commit would put the night\'s closing back to its count — and the 20 back on the shift.');

        config(['stockrecon.exceptions.D2.blocks' => true]);
        $run = $this->previewArea(self::SHELF_AREA);
        $lines = $run->lines;

        $this->assertSame('D2', $this->lineAt($run, '2026-07-17', 3)->DupTier, 'Still a possible duplicate on the staff\'s own count.');
        $this->assertTrue($lines->every(fn ($l) => $l->DupHold && $l->ChainBlocked), 'Held back, whole.');
        $this->assertTrue($lines->every(fn ($l) => ! $l->WouldAmend && ! $l->RestoresHand && ! $l->isWritable()),
            'Nothing on a held chain is proposed.');
        $this->assertTrue($lines->every(fn ($l) => $l->QtyIssuedNew === null));
        $this->assertSame(0, (int) $run->fresh()->AmendedRows);

        $before = $this->countsInSource();
        $this->service->selectAllAmendable($run);

        try {
            $this->service->commit($run->fresh());
            $this->fail('A held chain has nothing to commit.');
        } catch (AgoraProcException $e) {
            $this->assertSame('NOTHING_SELECTED', $e->code());
        }

        $this->assertSame($before, $this->countsInSource(), 'PumpIT did not move.');
        $this->assertSame(0, StockReconAmendment::query()->acrossBranches()->where('RunId', $run->Id)->count());
    }

    /**
     * Stage 7d puts a blocked chain back to the staff counts where somebody's
     * hand balancing broke the client's rules. On a chain a possible duplicate
     * holds back it does not — even when something else blocks the chain too —
     * because the staff counts are exactly what puts the hidden short back.
     */
    public function test_a_held_chain_is_not_put_back_to_the_staff_counts_even_when_blocked_for_another_reason(): void
    {
        config(['stockrecon.stamp_mode' => 'live', 'stockrecon.issue_mode' => 'live']);
        $chain = $this->possibleChain();
        // Issued 5, nothing sold, count unchanged: F2. (Until 6 Oct 2026 this
        // was a closing 1 over what the shift held — U2 — but the 20 short
        // before it covers that now, and a covered over does not block.)
        $chain['2026-07-20'] = [1 => [2, 5, 2, 0], 3 => [2, 0, 1, 1]];
        $this->seedShelfChain($chain);
        $this->hideTheNightShortByMovingCounts();

        config(['stockrecon.exceptions.D2.blocks' => false]);
        $unheld = $this->previewArea(self::SHELF_AREA);
        $this->assertTrue($this->lineAt($unheld, '2026-07-20', 1)->FlagIssueWentNowhere);
        $this->assertTrue($unheld->lines->every(fn ($l) => $l->ChainBlocked), 'F2 blocks the chain.');
        $this->assertTrue($unheld->lines->contains(fn ($l) => $l->RestoresHand && $l->WouldAmend),
            'Without the hold, 7d would put the staff counts back.');

        config(['stockrecon.exceptions.D2.blocks' => true]);
        $run = $this->previewArea(self::SHELF_AREA);

        $this->assertTrue($run->lines->every(fn ($l) => $l->DupHold && ! $l->RestoresHand && ! $l->WouldAmend),
            'With it, nothing — the hold outranks the reason the chain was blocked for.');

        $before = $this->countsInSource();

        try {
            $this->service->commit($run);
            $this->fail('A held chain has nothing to commit.');
        } catch (AgoraProcException $e) {
            $this->assertSame('NOTHING_SELECTED', $e->code());
        }

        $this->assertSame($before, $this->countsInSource());
    }

    /**
     * A D1 beside a D2 on the same chain waits with it. The chain is held as a
     * whole: the two can be halves of the same keying, and which issue was real
     * is what the site is being asked to settle.
     */
    public function test_a_clear_duplicate_waits_with_its_chain_while_a_possible_one_holds_it(): void
    {
        config(['stockrecon.stamp_mode' => 'live', 'stockrecon.issue_mode' => 'live']);
        $chain = $this->duplicateChain();
        // Both 20 Jul shifts given 5 and both short exactly 5: a possible pair.
        $chain['2026-07-20'] = [1 => [15, 5, 12, 3], 3 => [12, 5, 10, 2]];
        $this->seedShelfChain($chain);

        $run = $this->previewArea(self::SHELF_AREA);
        $clear = $this->lineAt($run, '2026-07-17', 3);

        $this->assertSame('D1', $clear->DupTier);
        $this->assertSame('D2', $this->lineAt($run, '2026-07-20', 1)->DupTier);
        $this->assertTrue($clear->DupHold);
        $this->assertNull($clear->QtyIssuedNew, 'The clear one is not taken out on its own.');
        $this->assertSame('Not removed: a possible duplicate (D2) holds this chain back', $clear->DupReason);
        $this->assertTrue($run->lines->every(fn ($l) => ! $l->WouldAmend));

        config(['stockrecon.exceptions.D2.blocks' => false]);
        $this->assertNotNull($this->lineAt($this->previewArea(self::SHELF_AREA), '2026-07-17', 3)->QtyIssuedNew,
            'Without the hold it would be.');
    }

    /**
     * RELEASED WITH A REASON (PM decision 01a10b21). Somebody checked the issue
     * book and released both possible duplicates, one reason for the lot. The
     * run they pressed on still holds the chain; the next preview lists them as
     * D2 still, released, and lets the chain commit — which puts the hand
     * figures back to the count, because that is now the agreed answer.
     */
    public function test_a_released_possible_duplicate_is_still_listed_and_its_chain_commits(): void
    {
        config(['stockrecon.stamp_mode' => 'live', 'stockrecon.issue_mode' => 'live']);
        $this->seedShelfChain($this->possibleChain());
        $this->hideTheNightShortByMovingCounts();
        $this->actingAs($admin = $this->person('admin'));

        $held = $this->previewArea(self::SHELF_AREA);
        $pair = [$this->lineAt($held, '2026-07-17', 1)->Id, $this->lineAt($held, '2026-07-17', 3)->Id];

        $status = $this->service->releaseDuplicates($held, $pair, 'TEST-issue book shows both issues were delivered');
        $this->assertSame('RELEASED', $status->Code);
        $this->assertStringStartsWith('2 possible duplicate issues released', $status->Message);

        $release = $this->releases()->where('ShiftNo', 3)->sole();
        $this->assertEqualsWithDelta(20.0, (float) $release->QtyIssued, 0.005, 'Released at the issue as keyed.');
        $this->assertSame((int) $admin->Id, (int) $release->CreatedBy, 'By name.');
        $this->assertSame('TEST-issue book shows both issues were delivered', $release->Reason);
        $this->assertSame('2026-07-17', substr((string) $release->TwinDate, 0, 10));
        $this->assertSame(1, (int) $release->TwinShift);
        $this->assertSame((int) $held->Id, (int) $release->RunId);
        $this->assertNull($release->WithdrawnAt);

        $again = $this->service->releaseDuplicates($held, $pair, 'TEST-pressed a second time by mistake');
        $this->assertSame('ALREADY_RELEASED', $again->Code, 'A second press stacks nothing.');
        $this->assertSame(2, $this->releases()->count());

        $run = $this->previewArea(self::SHELF_AREA);
        $night = $this->lineAt($run, '2026-07-17', 3);

        $this->assertSame('D2', $night->DupTier, 'Still listed as a possible duplicate.');
        $this->assertSame((int) $release->Id, $night->DupReleaseId);
        $this->assertStringStartsWith('Possible duplicate (D2), released', $night->Outcome);
        $this->assertTrue($run->lines->every(fn ($l) => ! $l->DupHold && ! $l->ChainBlocked), 'No longer held back.');
        $this->assertTrue($night->WouldAmend && $night->isWritable(), 'The hand figure goes back to the count.');

        // The chain panel says who released it and why, and offers the undo.
        $this->actingAs($admin)
            ->get(route('app.stockrecon.line', [$run, $night]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSee('TEST-issue book shows both issues were delivered')
            ->assertSee('data-dup-withdraw=', false)
            ->assertDontSee('Held back: a possible duplicate issue');

        $result = $this->service->commit($run);
        $this->assertSame('COMMITTED', $result['status']->Code);
        $this->assertEqualsWithDelta(6.0, (float) $this->sourceRow(self::DUP_ITEM, '2026-07-17', 3)->QtyClose, 0.005,
            'Released, the chain is written: the night\'s closing is its count again.');
    }

    /**
     * A release is of the issue as keyed. Re-key it and the release no longer
     * matches: the hold returns until somebody looks again. Here both 17 Jul
     * issues become 22, and both shifts are short exactly 22 — still a
     * possible pair, but not the one that was released.
     */
    public function test_a_release_no_longer_matches_once_the_issue_is_re_keyed(): void
    {
        config(['stockrecon.issue_mode' => 'live']);
        $this->seedShelfChain($this->possibleChain());
        $this->actingAs($this->person('admin'));

        $held = $this->previewArea(self::SHELF_AREA);
        $pair = [$this->lineAt($held, '2026-07-17', 1)->Id, $this->lineAt($held, '2026-07-17', 3)->Id];
        $this->service->releaseDuplicates($held, $pair, 'TEST-checked against the issue book');

        $released = $this->previewArea(self::SHELF_AREA);
        $this->assertTrue($released->lines->every(fn ($l) => ! $l->DupHold));

        $this->setRow(self::DUP_ITEM, '2026-07-17', 1, ['QtyIssued' => 22]);
        $this->setRow(self::DUP_ITEM, '2026-07-17', 3, ['QtyIssued' => 22]);

        $rekeyed = $this->previewArea(self::SHELF_AREA);
        $night = $this->lineAt($rekeyed, '2026-07-17', 3);

        // On the original basis the counted issue is still 20 — the re-key
        // is in PumpIT's live column only — and the release no longer stands
        // because PumpIT does not hold the 20 it was given for.
        $this->assertSame('D2', $night->DupTier);
        $this->assertEqualsWithDelta(20.0, (float) $night->DupQty, 0.005);
        $this->assertNull($night->DupReleaseId, 'PumpIT no longer holds the 20 the release was given for.');
        $this->assertTrue($rekeyed->lines->every(fn ($l) => $l->DupHold && $l->ChainBlocked), 'Held back again.');

        // And the run that was released against cannot release the old issue
        // any more: PumpIT no longer holds it.
        try {
            $this->service->releaseDuplicates($released, [$this->lineAt($released, '2026-07-17', 3)->Id], 'TEST-from a stale preview');
            $this->fail('A release of an issue PumpIT no longer holds should be refused.');
        } catch (AgoraProcException $e) {
            $this->assertSame('ISSUE_MOVED', $e->code());
            $this->assertStringContainsString('now holds issue 22', $e->getMessage());
        }
    }

    /**
     * A withdrawn release holds the chain back again: from the next preview,
     * and — for a run previewed while it stood — at the press, where the commit
     * re-checks every possible duplicate against the ledger. The release stays
     * on the ledger, withdrawn, never deleted.
     */
    public function test_a_withdrawn_release_holds_the_chain_back_again(): void
    {
        config(['stockrecon.stamp_mode' => 'live', 'stockrecon.issue_mode' => 'live']);
        $this->seedShelfChain($this->possibleChain());
        $this->hideTheNightShortByMovingCounts();
        $this->actingAs($admin = $this->person('admin'));

        $held = $this->previewArea(self::SHELF_AREA);
        $this->service->releaseDuplicates($held,
            [$this->lineAt($held, '2026-07-17', 1)->Id, $this->lineAt($held, '2026-07-17', 3)->Id],
            'TEST-delivery notes show both');

        $run = $this->previewArea(self::SHELF_AREA);
        $this->assertTrue($this->lineAt($run, '2026-07-17', 3)->isWritable(), 'Released: the chain would commit.');

        $release = $this->releases()->where('ShiftNo', 1)->sole();
        $status = $this->service->withdrawRelease($run, (int) $release->Id, 'TEST-released against the wrong day');
        $this->assertSame('WITHDRAWN', $status->Code);

        $before = $this->countsInSource();

        try {
            $this->service->commit($run->fresh());
            $this->fail('The press should skip a chain whose release was withdrawn since the preview.');
        } catch (AgoraProcException $e) {
            $this->assertSame('ALL_SKIPPED', $e->code());
            $this->assertStringContainsString('possible duplicate issue (D2) with no release standing', $e->getMessage());
        }

        $this->assertSame($before, $this->countsInSource(), 'Nothing was written.');

        $withdrawn = $this->releases()->where('Id', $release->Id)->sole();
        $this->assertNotNull($withdrawn->WithdrawnAt, 'Withdrawn, and still on the ledger.');
        $this->assertSame((int) $admin->Id, (int) $withdrawn->WithdrawnBy);
        $this->assertSame('TEST-released against the wrong day', $withdrawn->WithdrawNote);

        $again = $this->previewArea(self::SHELF_AREA);
        $this->assertNull($this->lineAt($again, '2026-07-17', 1)->DupReleaseId);
        $this->assertNotNull($this->lineAt($again, '2026-07-17', 3)->DupReleaseId, 'The other release still stands.');
        $this->assertTrue($again->lines->every(fn ($l) => $l->DupHold && ! $l->WouldAmend), 'One unreleased D2 holds the chain.');

        try {
            $this->service->withdrawRelease($again, (int) $release->Id);
            $this->fail('A release is withdrawn once.');
        } catch (AgoraProcException $e) {
            $this->assertSame('RELEASE_WITHDRAWN', $e->code());
        }
    }

    /**
     * A release says why, is only ever of a possible duplicate, and is pressed
     * only by somebody holding the grant — the route refuses anybody else, and
     * the chain panel does not offer them the button.
     */
    public function test_a_release_is_refused_without_a_reason_or_the_grant(): void
    {
        if (! Permission::query()->acrossBranches()->where('Code', 'stockrecon.duplicates.release')->exists()) {
            $this->markTestSkipped('Run: php artisan seed:master --only=StockReconDuplicateReleasePermissionSeeder');
        }

        config(['stockrecon.issue_mode' => 'live']);
        $this->seedShelfChain($this->possibleChain());
        $admin = $this->person('admin');
        $operations = $this->person('operations');
        $this->actingAs($admin);

        $run = $this->previewArea(self::SHELF_AREA);
        $night = $this->lineAt($run, '2026-07-17', 3);

        foreach (['', '   ', 'ok fine'] as $reason) {
            try {
                $this->service->releaseDuplicates($run, [$night->Id], $reason);
                $this->fail("A release with the reason [{$reason}] should be refused.");
            } catch (AgoraProcException $e) {
                $this->assertSame('REASON_REQUIRED', $e->code());
            }
        }

        try {
            $this->service->releaseDuplicates($run, [$this->lineAt($run, '2026-07-18', 1)->Id], 'TEST-not a duplicate at all');
            $this->fail('Only a D2 is released.');
        } catch (AgoraProcException $e) {
            $this->assertSame('NOT_POSSIBLE_DUPLICATE', $e->code());
        }

        $this->assertSame(0, $this->releases()->count(), 'Nothing was released.');

        // The panel offers the press to somebody who may make it, and not to
        // somebody who may only look.
        $panel = fn ($who) => $this->actingAs($who)
            ->get(route('app.stockrecon.line', [$run, $night]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSee('Held back: a possible duplicate issue');

        $panel($admin)->assertSee('data-dup-release=', false);
        $panel($operations)->assertDontSee('data-dup-release=', false);

        $this->actingAs($operations)
            ->post(route('app.stockrecon.duplicates.release', $run), ['lines' => [$night->Id], 'reason' => 'TEST-not my call to make'])
            ->assertForbidden();

        $this->actingAs($admin)
            ->from(route('app.stockrecon.line', [$run, $night]))
            ->post(route('app.stockrecon.duplicates.release', $run), ['lines' => [$night->Id], 'reason' => ''])
            ->assertRedirect(route('app.stockrecon.line', [$run, $night]))
            ->assertSessionHas('refusal');

        $this->actingAs($admin)
            ->from(route('app.stockrecon.line', [$run, $night]))
            ->post(route('app.stockrecon.duplicates.release', $run), [
                'lines' => [$night->Id, $this->lineAt($run, '2026-07-17', 1)->Id],
                'reason' => 'TEST-issue book shows one delivery each',
            ])
            ->assertRedirect(route('app.stockrecon.line', [$run, $night]))
            ->assertSessionHas('released');

        $this->assertSame(2, $this->releases()->whereNull('WithdrawnAt')->count(), 'One reason for the lot.');

        $release = $this->releases()->where('ShiftNo', 3)->sole();

        $this->actingAs($operations)
            ->post(route('app.stockrecon.duplicates.withdraw', [$run, $release->Id]))
            ->assertForbidden();

        $this->actingAs($admin)
            ->from(route('app.stockrecon.line', [$run, $night]))
            ->post(route('app.stockrecon.duplicates.withdraw', [$run, $release->Id]))
            ->assertSessionHas('released');

        $this->assertNotNull($this->releases()->where('Id', $release->Id)->value('WithdrawnAt'));
    }

    /**
     * Rule 5: shelf goods only. In prepared food a same-size batch thrown away
     * unrecorded looks exactly like a duplicate, so a food area is never D1.
     * The flag is head office's, per area, in agora.StockAreaSetting — here it
     * overrides an area the proposal would call shelf goods.
     */
    public function test_a_prepared_food_area_is_never_a_clear_duplicate(): void
    {
        config(['stockrecon.issue_mode' => 'live']);
        $this->seedShelfChain($this->duplicateChain());

        $food = $this->db()->selectOne('SELECT * FROM agora.vw_StockAreaFood WHERE BranchId = ? AND AreaNo = ?', [self::BRANCH, self::AREA]);
        $this->assertTrue((bool) $food->IsPreparedFood, 'Area group Food Items is proposed as prepared food.');
        $this->assertFalse((bool) $food->IsConfirmed);

        $this->db()->table(config('agora.schema').'.StockAreaSetting')->insert([
            'BranchId' => self::BRANCH, 'AreaNo' => self::SHELF_AREA, 'IsPreparedFood' => 1,
            'FoodBasis' => 'TEST-head office', 'ConfirmedAt' => now(),
        ]);

        $run = $this->previewArea(self::SHELF_AREA);
        $dup = $this->lineAt($run, '2026-07-17', 3);

        $this->assertSame('D2', $dup->DupTier);
        $this->assertSame('Prepared food: a batch thrown away looks the same', $dup->DupReason);
        $this->assertNull($dup->QtyIssuedNew);
        $this->assertEqualsWithDelta(-20.0, (float) $dup->QtyVarNew, 0.005, 'Not removed: the short stands for the site to check.');
    }

    /**
     * One candidate per PAIR. The night shift's twin on the day arrived — but
     * its other twin, the next morning, is ALSO short by exactly the issue. Of
     * those two, nothing says which was the duplicate, so neither is clear.
     */
    public function test_when_both_sides_of_a_pair_qualify_neither_is_clear(): void
    {
        config(['stockrecon.issue_mode' => 'live']);
        $chain = $this->duplicateChain();
        $chain['2026-07-18'][1] = [24, 20, 20, 4];      // 24 + 20 − 4 = 40, counted 20: short 20
        $this->seedShelfChain($chain);

        $run = $this->previewArea(self::SHELF_AREA);
        $night = $this->lineAt($run, '2026-07-17', 3);
        $morning = $this->lineAt($run, '2026-07-18', 1);

        $this->assertSame('D2', $night->DupTier);
        $this->assertSame('Both sides of a pair are short by the issue', $night->DupReason);
        $this->assertSame('D2', $morning->DupTier);
        $this->assertTrue($run->lines->every(fn ($l) => $l->QtyIssuedNew === null), 'Nothing is removed.');
        $this->assertSame(0, (int) $run->fresh()->DuplicatesClear);
    }

    /**
     * A clear duplicate on a chain Agora will not balance is still a duplicate:
     * it is written on its own, the counts untouched — but only where PumpIT
     * holds the shift short by exactly the issue, so the write leaves it at
     * nil. Where somebody already moved the counts to hide the short, taking
     * the issue out alone would make an over: reported, not written.
     */
    public function test_a_duplicate_on_a_blocked_chain_is_written_alone_only_where_pumpit_still_holds_the_short(): void
    {
        config(['stockrecon.stamp_mode' => 'live', 'stockrecon.issue_mode' => 'live']);
        $chain = $this->duplicateChain();
        // Issued 5, nothing sold, count unchanged: F2 blocks the chain. (Until
        // 7 Oct 2026 this was a closing 1 over what the shift held — U2 — and
        // an unrecorded issue holds no chain back now.)
        $chain['2026-07-20'][1] = [15, 5, 15, 0];
        $chain['2026-07-20'][3] = [15, 0, 10, 5];
        $this->seedShelfChain($chain);

        $run = $this->previewArea(self::SHELF_AREA);
        $dup = $this->lineAt($run, '2026-07-17', 3);

        $this->assertTrue($dup->ChainBlocked);
        $this->assertSame('D1', $dup->DupTier);
        $this->assertTrue($dup->removesDuplicate() && $dup->isWritable(), 'The duplicate is written on its own.');
        $this->assertFalse($dup->CountsMove);
        $this->assertEqualsWithDelta(0.0, (float) $dup->QtyVarNew, 0.005);
        $this->assertTrue($run->lines->where('Id', '!=', $dup->Id)->every(fn ($l) => ! $l->WouldAmend),
            'Nothing else on a blocked chain is written.');

        $result = $this->service->commit($run);
        $this->assertSame('COMMITTED', $result['status']->Code);
        $row = $this->sourceRow(self::DUP_ITEM, '2026-07-17', 3);
        $this->assertEqualsWithDelta(0.0, (float) $row->QtyIssued, 0.005);
        $this->assertEqualsWithDelta(24.0, (float) $row->QtyClose, 0.005, 'The counts stay where they are.');
        $this->service->reverse($run->fresh(), 'TEST-blocked duplicate');

        // Head office hid the short by moving the night's closing up 20.
        $this->setRow(self::DUP_ITEM, '2026-07-17', 3, ['QtyClose' => 44]);
        $this->setRow(self::DUP_ITEM, '2026-07-18', 1, ['QtyOpen' => 44]);

        $hidden = $this->lineAt($this->previewArea(self::SHELF_AREA), '2026-07-17', 3);
        $this->assertSame('D1', $hidden->DupTier, 'Still a duplicate on the staff\'s own count.');
        $this->assertNull($hidden->QtyIssuedNew, 'Taking the issue out alone would leave an over of 20.');
        $this->assertSame('Not removed: PumpIT no longer holds this short and the chain is blocked', $hidden->DupReason);
    }

    /**
     * A chain balanced with a duplicate taken out is written WITH it. Its
     * counts were worked out with the issue gone; writing one of them alone
     * would leave the duplicate's shift short again under counts moved on the
     * assumption it was not. So a single-row amend of another shift waits.
     */
    public function test_a_chain_that_removes_a_duplicate_is_not_written_without_it(): void
    {
        config(['stockrecon.stamp_mode' => 'live', 'stockrecon.issue_mode' => 'live']);
        $chain = $this->duplicateChain();
        $chain['2026-07-19'] = [1 => [18, 0, 16, 3], 3 => [16, 0, 15, 0]];   // +1 over, then −1 short
        $this->seedShelfChain($chain);

        $run = $this->previewArea(self::SHELF_AREA);
        $count = $this->lineAt($run, '2026-07-19', 1);
        $this->assertTrue($count->CountsMove, 'The over is balanced against the short after it.');
        $this->assertSame('D1', $this->lineAt($run, '2026-07-17', 3)->DupTier);

        $this->service->select($run, [$count->Id]);

        try {
            $this->service->commit($run->fresh());
            $this->fail('The count row should have waited for the duplicate.');
        } catch (AgoraProcException $e) {
            $this->assertSame('ALL_SKIPPED', $e->code());
            $this->assertStringContainsString('removes a duplicate issue on another shift', $e->getMessage());
        }

        $this->assertEqualsWithDelta(16.0, (float) $this->sourceRow(self::DUP_ITEM, '2026-07-19', 1)->QtyClose, 0.005,
            'Nothing was written.');
    }

    /** issue_mode 'off': a clear duplicate is still found, but left in the basis and never written. */
    public function test_with_the_issue_write_off_a_duplicate_is_reported_not_removed(): void
    {
        config(['stockrecon.issue_mode' => 'off']);
        $this->seedShelfChain($this->duplicateChain());

        $run = $this->previewArea(self::SHELF_AREA);
        $dup = $this->lineAt($run, '2026-07-17', 3);

        $this->assertSame('D1', $dup->DupTier);
        $this->assertNull($dup->QtyIssuedNew);
        $this->assertSame('Not removed: removing duplicates is switched off', $dup->DupReason);
        $this->assertEqualsWithDelta(-20.0, (float) $dup->QtyVarNew, 0.005);
        $this->assertSame(0, $run->params()['RemoveDuplicates'], 'The run records the switch it was made under.');
    }

    // ---------------------------------------------------------------- helpers

    /**
     * The Dunhill 10s night of 23 Aug, moved onto the fixture's dates: day
     * shift 9 + 20 − 3 sold = 26 counted (arrived), night 26 + 20 − 2 sold = 44
     * expected, 24 counted (short exactly 20). Every other shift balances, and
     * the 19th's night is dormant.
     *
     * @return array<string, array<int, array{0: float|int, 1: float|int, 2: float|int, 3: float|int}>>
     */
    private function duplicateChain(): array
    {
        return [
            '2026-07-17' => [1 => [9, 20, 26, 3],  3 => [26, 20, 24, 2]],
            '2026-07-18' => [1 => [24, 0, 20, 4],  3 => [20, 0, 18, 2]],
            '2026-07-19' => [1 => [18, 0, 15, 3],  3 => [15, 0, 15, 0]],
            '2026-07-20' => [1 => [15, 0, 12, 3],  3 => [12, 0, 10, 2]],
            '2026-07-21' => [1 => [10, 0, 8, 2],   3 => [8, 0, 7, 1]],
        ];
    }

    /**
     * A possible pair: day 9 + 20 − 1 sold = 28, counted 8; night 8 + 20 − 2 =
     * 26, counted 6 — both 20 short, so nothing says which issue was real. The
     * rest of the chain balances.
     *
     * @return array<string, array<int, array{0: float|int, 1: float|int, 2: float|int, 3: float|int}>>
     */
    private function possibleChain(): array
    {
        return [
            '2026-07-17' => [1 => [9, 20, 8, 1], 3 => [8, 20, 6, 2]],
            '2026-07-18' => [1 => [6, 0, 4, 2],  3 => [4, 0, 3, 1]],
            '2026-07-19' => [1 => [3, 0, 2, 1],  3 => [2, 0, 2, 0]],
            '2026-07-20' => [1 => [2, 0, 1, 1],  3 => [1, 0, 1, 0]],
            '2026-07-21' => [1 => [1, 0, 1, 0],  3 => [1, 0, 0, 1]],
        ];
    }

    /**
     * Head office hides the 17 Jul night's short by moving counts, the way the
     * investigation found 59 of 134 candidates hidden: the night's closing up
     * 20, and every count after it up 20 so the chain in PumpIT stays intact.
     * The live columns only — the _Original pair is what staff counted.
     */
    private function hideTheNightShortByMovingCounts(): void
    {
        $this->db()->table('PumpIT.dbo.STK_StockReconLine')
            ->where('SSBranchId', self::BRANCH)->where('StockItemNo', self::DUP_ITEM)
            ->where('TransactionDate', '>', '2026-07-17')
            ->update(['QtyOpen' => DB::raw('QtyOpen + 20'), 'QtyClose' => DB::raw('QtyClose + 20')]);

        $this->setRow(self::DUP_ITEM, '2026-07-17', 3, ['QtyClose' => 26]);
    }

    /** The release ledger for the fixture branch. */
    private function releases(): Builder
    {
        return $this->db()->table(config('agora.schema').'.StockReconDuplicateRelease')->where('BranchId', self::BRANCH);
    }

    /** A test person holding one access profile (tests/Fixtures/GrantsAccess). */
    private function person(string $profile): User
    {
        $user = User::query()->acrossBranches()->create([
            'BranchId' => (int) config('agora.group_branch_id', 2),
            'EmailAddress' => 'TEST-dup-'.$profile.'-'.uniqid().'@agora.invalid',
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
     * A shelf-goods area (the customer's Retail Items group) beside the
     * fixture's hot-food one, with one item on the given chain.
     *
     * @param  array<string, array<int, array{0: float|int, 1: float|int, 2: float|int, 3: float|int}>>  $chain
     */
    private function seedShelfChain(array $chain): void
    {
        $db = $this->db();

        $db->table('PumpIT.dbo.STK_Area')->insert([
            'SSBranchId' => self::BRANCH, 'AreaNo' => self::SHELF_AREA,
            'AreaDescription' => 'TEST-Tobacco', 'AreaGroup' => 'Retail Items',
            'DayShift' => 1, 'AfternoonShift' => 0, 'NightShift' => 1,
            'ShowReport' => 1, 'IsCaptureWaste' => 0,
        ]);

        $db->table('PumpIT.dbo.STK_StockMaster')->insert([
            'SSBranchId' => self::BRANCH, 'StockItemNo' => self::DUP_ITEM,
            'StockItemDescription' => 'TEST-Dunhill 10s', 'AreaNo' => self::SHELF_AREA,
            'Location' => 'TEST', 'UOMCode' => 'EA',
            'SellingPrice' => 35.00, 'POSCode' => 'TEST'.self::DUP_ITEM,
            'QtyVarAllowance' => 0, 'isMonitoredItem' => 0,
        ]);

        foreach ($chain as $date => $shifts) {
            foreach ($shifts as $shift => [$open, $issued, $close, $pos]) {
                $db->table('PumpIT.dbo.STK_StockReconLine')->insert(
                    $this->line(self::DUP_ITEM, $date, $shift, $open, $issued, $close, $pos, self::SHELF_AREA)
                );
            }
        }
    }

    private function previewArea(int $area): StockReconRun
    {
        return $this->service->preview(
            self::BRANCH,
            $area,
            Carbon::parse('2026-07-17'),
            Carbon::parse('2026-07-21'),
            note: 'TEST-duplicates',
        )->load('lines');
    }

    private function lineAt(StockReconRun $run, string $date, int $shift): StockReconRunLine
    {
        return $run->lines->first(fn ($l) => $l->TransactionDate->toDateString() === $date && $l->ShiftNo === $shift)
            ?? throw new \RuntimeException("No line for {$date} shift {$shift}.");
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

    private function preview(): StockReconRun
    {
        return $this->service->preview(
            self::BRANCH,
            self::AREA,
            Carbon::parse('2026-07-17'),
            Carbon::parse('2026-07-21'),
            note: 'TEST-balancing',
        )->load('lines');
    }

    /** @param  array<string, float|int>  $counts */
    private function setCounts(string $date, int $shift, array $counts): void
    {
        $this->db()->table('PumpIT.dbo.STK_StockReconLine')
            ->where('SSBranchId', self::BRANCH)
            ->where('StockItemNo', self::ITEM)
            ->where('TransactionDate', $date)
            ->where('ShiftNo', $shift)
            ->update($counts);
    }

    /** The four counts in the source, so a journal commit can be proved inert. */
    private function countsInSource(): string
    {
        return (string) json_encode(
            $this->db()->table('PumpIT.dbo.STK_StockReconLine')
                ->where('SSBranchId', self::BRANCH)
                ->orderBy('StockItemNo')->orderBy('TransactionDate')->orderBy('ShiftNo')
                ->get(['StockItemNo', 'TransactionDate', 'ShiftNo', 'QtyOpen', 'QtyClose'])
                ->all()
        );
    }

    /**
     * The worked example from the method note: shift 1 short, shift 3 over by
     * nearly the same amount, every day, ending five units short. Shift 2 is
     * dormant throughout — it never trades and must never be charged.
     */
    private function seedFixture(): void
    {
        $db = $this->db();

        /*
         * Only the columns the local stub HAS.
         *
         * database/stubs/pumpit-reports.sql builds these three tables from the
         * column list `agora.vw_Stock*` enumerates, not from the customer's
         * full shape — so a fixture that fills in the other eighteen columns of
         * STK_StockMaster fails on the stub and passes nowhere.
         */
        $db->table('PumpIT.dbo.STK_Area')->insert([
            'SSBranchId' => self::BRANCH, 'AreaNo' => self::AREA,
            'AreaDescription' => 'TEST-Hot Foods', 'AreaGroup' => 'Food Items',
            'DayShift' => 1, 'AfternoonShift' => 0, 'NightShift' => 1,
            'ShowReport' => 1, 'IsCaptureWaste' => 0,
        ]);

        foreach ([self::ITEM => 'TEST-Chicken quarter', self::DORMANT_ITEM => 'TEST-Never trades'] as $no => $description) {
            $db->table('PumpIT.dbo.STK_StockMaster')->insert([
                'SSBranchId' => self::BRANCH, 'StockItemNo' => $no,
                'StockItemDescription' => $description, 'AreaNo' => self::AREA,
                'Location' => 'TEST', 'UOMCode' => 'EA',
                'SellingPrice' => 21.80, 'POSCode' => 'TEST'.$no,
                'QtyVarAllowance' => 0, 'isMonitoredItem' => 0,
            ]);
        }

        // date => [shift => [open, issued, close, pos]]
        $chain = [
            '2026-07-17' => [1 => [40, 50, 60, 18], 3 => [60, 0, 60, 10]],
            '2026-07-18' => [1 => [60, 0, 50, 5],   3 => [50, 0, 50, 4]],
            '2026-07-19' => [1 => [50, 0, 30, 10],  3 => [30, 0, 30, 9]],
            '2026-07-20' => [1 => [30, 0, 10, 15],  3 => [10, 0, 10, 3]],
            '2026-07-21' => [1 => [10, 50, 50, 5],  3 => [50, 0, 50, 6]],
        ];

        // Two people, so the shared-shift case is covered rather than assumed.
        foreach ([['TE01', 'Ndlovu', 'Sipho'], ['TE02', 'Khumalo', 'Thandi']] as [$code, $surname, $first]) {
            $db->table('PumpIT.dbo.BRN_Employee')->insert([
                'SSBranchId' => self::BRANCH, 'EmployeeCode' => $code,
                'FirstName' => $first, 'Surname' => $surname, 'NickName' => $first,
                'IsActive' => 1, 'IsPumpAttendant' => 0, 'PostNo' => 0,
            ]);
        }

        foreach ($chain as $date => $shifts) {
            foreach ($shifts as $shift => [$open, $issued, $close, $pos]) {
                $db->table('PumpIT.dbo.STK_StockReconLine')->insert(
                    $this->line(self::ITEM, $date, $shift, $open, $issued, $close, $pos)
                );

                // A second item that never trades at all, so rule 3 is proved
                // on a whole chain of dormant shifts and not only on the one
                // sitting between two active ones.
                $db->table('PumpIT.dbo.STK_StockReconLine')->insert(
                    $this->line(self::DORMANT_ITEM, $date, $shift, 12, 0, 12, 0)
                );
            }

            // Shift 2 never trades: a sign-off with no movement behind it,
            // between the day's two real shifts. ONE row per day — writing it
            // inside the shift loop wrote it twice, which the source's own
            // primary key would refuse and the local stub, which has no key,
            // quietly accepted.
            $db->table('PumpIT.dbo.STK_StockReconLine')->insert(
                $this->line(self::ITEM, $date, 2, $shifts[1][2], 0, $shifts[1][2], 0)
            );

            // Who was on. Shift 1 is one person; shift 3 is TWO, which is the
            // case that must never collapse to a single name.
            foreach ([[1, ['TE01']], [3, ['TE01', 'TE02']]] as [$shift, $codes]) {
                foreach ($codes as $code) {
                    $db->table('PumpIT.dbo.STK_StockReconEmployees')->insert([
                        'SSBranchId' => self::BRANCH, 'TransactionDate' => $date,
                        'ShiftNo' => $shift, 'AreaNo' => self::AREA, 'EmployeeCode' => $code,
                    ]);
                }
            }
        }
    }

    /** @return array<string, mixed> */
    private function line(string $item, string $date, int $shift, float $open, float $issued, float $close, float $pos, int $area = self::AREA): array
    {
        return [
            'SSBranchId' => self::BRANCH,
            'TransactionDate' => $date,
            'ShiftNo' => $shift,
            'AreaNo' => $area,
            'StockItemNo' => $item,
            'SellPrice' => 21.80,
            'QtyOpen' => $open, 'QtyIssued' => $issued, 'QtyClose' => $close, 'QtyComputer' => $pos,
            'QtyOpen_Original' => $open, 'QtyIssued_Original' => $issued,
            'QtyClose_Original' => $close, 'QtyComputer_Original' => $pos,
        ];
    }

    private function cleanUp(): void
    {
        $db = $this->db();

        foreach (['STK_StockReconLine', 'STK_StockReconEmployees', 'STK_StockMaster',
            'STK_Area', 'BRN_Employee'] as $table) {
            $db->table("PumpIT.dbo.{$table}")->where('SSBranchId', self::BRANCH)->delete();
        }

        $schema = config('agora.schema');

        foreach (['StockReconAmendment', 'StockReconRunLine', 'StockReconRun', 'StockIssueMultiple', 'StockAreaSetting',
            'StockReconDuplicateRelease'] as $table) {
            $db->table("{$schema}.{$table}")->where('BranchId', self::BRANCH)->delete();
        }
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }
}
