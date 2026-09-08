<?php

namespace Tests\Feature\StockRecon;

use App\Exceptions\AgoraProcException;
use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\StockRecon\Models\StockReconAmendment;
use Modules\StockRecon\Models\StockReconRun;
use Modules\StockRecon\Models\StockReconRunLine;
use Modules\StockRecon\Services\StockReconService;
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

    private StockReconService $service;

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
     * A chain the source has already broken — an opening that is not the
     * previous closing — is reported, never balanced. Redistributing along a
     * path that does not exist is meaningless, and it is what made the method
     * note's own dormant guard fire on real data.
     */
    public function test_a_broken_chain_is_reported_rather_than_balanced(): void
    {
        $this->db()->table('PumpIT.dbo.STK_StockReconLine')
            ->where('SSBranchId', self::BRANCH)
            ->where('StockItemNo', self::ITEM)
            ->where('TransactionDate', '2026-07-19')
            ->where('ShiftNo', 1)
            // The opening no longer equals the 18th's closing.
            ->update(['QtyOpen_Original' => 999, 'QtyOpen' => 999]);

        $run = $this->preview();
        $lines = $run->lines->where('StockItemNo', self::ITEM);

        $this->assertTrue($lines->contains(fn ($l) => $l->FlagChainBroken), 'The break was not noticed.');
        $this->assertTrue($lines->every(fn ($l) => $l->ChainBlocked), 'A chain is blocked WHOLE, never half balanced.');
        $this->assertTrue($lines->every(fn ($l) => ! $l->WouldAmend), 'A blocked chain carries no proposal at all.');
        $this->assertTrue($lines->every(fn ($l) => abs((float) $l->QtyVarNew - (float) $l->QtyVar) < 0.005),
            'A blocked chain\'s "after" figures are its counted figures — anything else describes a world the press cannot produce.');
    }

    /** A window that ends over cannot be balanced by any amendment, so it is reported. */
    public function test_a_net_over_chain_is_reported_rather_than_balanced(): void
    {
        // One extra unit sold on the last shift, with nothing issued to cover
        // it, tips the whole window over.
        $this->db()->table('PumpIT.dbo.STK_StockReconLine')
            ->where('SSBranchId', self::BRANCH)
            ->where('StockItemNo', self::ITEM)
            ->where('TransactionDate', '2026-07-21')
            ->where('ShiftNo', 3)
            ->update(['QtyComputer' => 100]);

        $run = $this->preview();
        $lines = $run->lines->where('StockItemNo', self::ITEM);

        $this->assertTrue($lines->contains(fn ($l) => $l->FlagNetOver));
        $this->assertTrue($lines->every(fn ($l) => $l->ChainBlocked));
        $this->assertTrue($lines->contains(fn ($l) => in_array($l->ExceptionCode, ['A1', 'A2', 'A3', 'A4'], true)),
            'A net over is an unrecorded issue and belongs in the A classes.');
    }

    /** The counts are read from the _Original columns, so a re-run never compounds. */
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

    // ---------------------------------------------------------------- helpers

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
            'SellPrice' => 21.80,
            'QtyOpen' => $open, 'QtyIssued' => $issued, 'QtyClose' => $close, 'QtyComputer' => $pos,
            'QtyOpen_Original' => $open, 'QtyIssued_Original' => $issued,
            'QtyClose_Original' => $close, 'QtyComputer_Original' => $pos,
        ];
    }

    private function cleanUp(): void
    {
        $db = $this->db();

        foreach (['STK_StockReconLine', 'STK_StockMaster', 'STK_Area'] as $table) {
            $db->table("PumpIT.dbo.{$table}")->where('SSBranchId', self::BRANCH)->delete();
        }

        $schema = config('agora.schema');

        foreach (['StockReconAmendment', 'StockReconRunLine', 'StockReconRun'] as $table) {
            $db->table("{$schema}.{$table}")->where('BranchId', self::BRANCH)->delete();
        }
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }

    private function skipUnlessLocalStub(): void
    {
        $connection = config('agora.connections.app');
        $host = config("database.connections.{$connection}.host");

        if (! in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            $this->markTestSkipped(
                "This fixture writes stock recon lines and [{$connection}] points at [{$host}]. "
                .'It runs against the local container only.'
            );
        }
    }
}
