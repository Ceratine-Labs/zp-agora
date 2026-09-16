<?php

namespace Tests\Feature\Recon;

use App\Exceptions\AgoraProcException;
use App\Support\ProcedureService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Recon\Models\ReconRun;
use Modules\Recon\Models\ReconRunLine;
use Modules\Recon\Services\ReconService;
use Tests\TestCase;

/**
 * A Smart ATM proposal that previews must also COMMIT.
 *
 * Until 16 September 2026 it never could, on any site, ever — and it did not
 * fail while not doing it. Every run reported "5 of 5 sites posted" and stamped
 * nothing, telling the operator that a bank line had been "reconciled by
 * something else" or that "the two sides no longer balance". Both are
 * statements about the customer's data and both were false.
 *
 * THE SHAPE THAT BREAKS IT is the one this fixture builds, and it is the
 * ordinary shape rather than an edge case: the bank narrative names the ATM's
 * TRADING day (ATMH0279|0830) and the bank posts that line the FOLLOWING
 * calendar day (LineDate 31 August). The preview groups on the narrative date
 * and derives the deposit window from it; usp_Recon_Commit then re-found the
 * BANK lines by LineDate inside that same window, which by construction ends
 * before the line is posted. It looked for the batch in a window the batch
 * could never be in.
 *
 * So the assertion that matters here is not "the preview is right" — that had
 * a test and it passed throughout. It is that the commit STAMPS: a batch
 * allocated, ReconState moved on the bank line, ReconBatchNoPumpIT set on the
 * deposits. A commit that reports success and writes nothing is the failure
 * this file exists to catch.
 */
class SmartAtmCommitTest extends TestCase
{
    private const BRANCH = 999;

    /** The trading day in the narrative. The bank posts it on the 31st. */
    private const MMDD = '0830';

    private ReconService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessLocalStub();
        $this->service = new ReconService(new ProcedureService);
        $this->cleanUp();
        $this->seedFixture();
        $this->actingAs($this->auditor());
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->cleanUp();
        }

        parent::tearDown();
    }

    /**
     * The whole point. Preview, tick, commit — and something is actually
     * stamped at the end of it.
     */
    public function test_a_smart_atm_proposal_commits_and_stamps_both_sides(): void
    {
        $run = $this->preview();

        $line = $run->lines->firstWhere('WouldReconcile', true);

        $this->assertNotNull($line, 'The fixture must propose something, or the commit assertion below proves nothing.');
        $this->assertSame('ATMH0279', $line->KeyRef);
        $this->assertSame(self::MMDD, $line->KeyRef2,
            'The narrative date is the other half of the key. Without it the commit cannot find the bank line again.');

        $this->service->select($run->fresh(), [$line->Id]);

        $result = $this->service->commit($run->fresh());

        $this->assertGreaterThan(0, $run->fresh()->CommittedRows,
            'The commit reported success and stamped nothing — the failure this test exists for.');

        $db = DB::connection(config('agora.connections.app'));

        $this->assertSame(2, (int) $db->table('PumpIT.dbo.RCN_BankStatementLinesPumpIT')
            ->where('SSBranchId', self::BRANCH)->value('ReconState'),
            'The bank line should be reconciled.');

        $this->assertSame(0, (int) $db->table('PumpIT.dbo.BRN_DailyBankingSmartATM')
            ->where('SSBranchId', self::BRANCH)->where('ReconBatchNoPumpIT', 0)->count(),
            'Every deposit in the batch should carry the batch number.');
    }

    /**
     * The window is the DEPOSIT's, and it runs midnight to midnight on the
     * narrative date (ZP, 16 September 2026). It used to run from 19:00 the
     * previous evening to 18:59, which is what the live procedure did.
     */
    public function test_the_window_is_midnight_to_midnight_on_the_narrative_date(): void
    {
        $line = $this->preview()->lines->firstWhere('WouldReconcile', true);

        $this->assertSame('2026-08-30 00:00:00', Carbon::parse($line->WindowFrom)->toDateTimeString());
        $this->assertSame('2026-08-30 23:59:59', Carbon::parse($line->WindowTo)->toDateTimeString());
    }

    /**
     * The deposit at 21:40 is the one the boundary change moves. Under the old
     * 19:00 window it belonged to the NEXT narrative day and this batch did not
     * balance; under midnight-to-midnight it belongs to its own day.
     */
    public function test_a_late_evening_deposit_belongs_to_its_own_trading_day(): void
    {
        $line = $this->preview()->lines->firstWhere('WouldReconcile', true);

        $this->assertSame(3, (int) $line->MopsTxns,
            'All three deposits fall on 30 August and all three belong to it.');
        $this->assertEqualsWithDelta(1400.00, (float) $line->MopsTotal, 0.001);
        $this->assertEqualsWithDelta(1400.00, (float) $line->BankTotal, 0.001);
    }

    /**
     * A run previewed before the fix is refused by name, and SAYS SO on screen.
     *
     * Those runs carry no KeyRef2, so the drill correctly finds no bank lines.
     * Correctly is not the same as harmlessly: left alone the panel fell back to
     * "Nothing on the statement carries this reference in the period", which is
     * a claim about the customer's estate and is false — the line is on the
     * statement exactly where the preview found it. That is the same species of
     * untrue message this whole change set exists to remove, so it would have
     * been an unusually poor thing to reintroduce on the day. Caught on live,
     * run 1016, within the hour.
     */
    public function test_a_run_from_before_the_fix_says_so_instead_of_blaming_the_bank(): void
    {
        $run = $this->preview();
        $line = $run->lines->firstWhere('WouldReconcile', true);

        // Exactly what a pre-fix run looks like: everything else intact, the
        // narrative date absent.
        DB::connection(config('agora.connections.app'))->table('agora.ReconRunLine')
            ->where('Id', $line->Id)->update(['KeyRef2' => null]);

        $this->get(route('app.recon.line', [$run, $line]))
            ->assertOk()
            ->assertSee('previewed before the Smart ATM matching fix', false)
            ->assertSee('Preview this site again', false)
            ->assertDontSee('Nothing on the statement carries this reference', false);

        $this->service->select($run->fresh(), [$line->Id]);

        try {
            $this->service->commit($run->fresh());
            $this->fail('A run previewed before the fix must be refused, not allowed to stamp nothing.');
        } catch (AgoraProcException $e) {
            $this->assertSame('RUN_PREDATES_KEYREF2', $e->code());
        }
    }

    /**
     * The deposit column shows the DEPOSIT's time, never the bank line's.
     *
     * Ryan, 16 Sep 2026: "are you showing line date and time or the deposit
     * date and times? it must be deposit." The fixture makes the two
     * impossible to confuse — the bank line is posted on 31 August and the
     * three deposits went in on 30 August at 09:15, 17:02 and 21:40.
     */
    public function test_the_panel_shows_the_deposit_time_not_the_bank_line_date(): void
    {
        $run = $this->preview();
        $line = $run->lines->firstWhere('WouldReconcile', true);

        $panel = $this->get(route('app.recon.line', [$run, $line]))->assertOk();

        foreach (['30 Aug 2026 09:15', '30 Aug 2026 17:02', '30 Aug 2026 21:40'] as $stamp) {
            $panel->assertSee($stamp, false);
        }

        // The bank line's own date is 31 August. It must not appear as a
        // deposit date, and the deposit times must not be flattened to it.
        $panel->assertDontSee('31 Aug 2026 09:15', false);

        // And the timestamp is not printed twice on the same row.
        $panel->assertDontSee('trace TR900010 · 2026-08-30 09:15', false);
    }

    private function preview(): ReconRun
    {
        return $this->service->preview(
            'SmartATM', self::BRANCH, Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'),
        );
    }

    private function seedFixture(): void
    {
        $db = DB::connection(config('agora.connections.app'));

        /*
         * Positions matching the customer's own row: the terminal at 23 for 8
         * characters and the MM/DD immediately after it at 31 for 4. Both are
         * given as LENGTHS, which is how SmartATM's criteria are read.
         */
        $db->table('PumpIT.dbo.BRN_AutoReconCriteria')->insert([
            'SSBranchId' => self::BRANCH, 'BankReconArea' => 'SmartATM', 'ProcessOrder' => 1,
            'BANK_StartPosition' => 23, 'BANK_EndPosition' => 8,
            'BANK_StartPosition2' => 31, 'BANK_EndPosition2' => 4,
            'MOPS_StartPosition' => 1, 'MOPS_EndPosition' => 8,
            'FILTER_Value' => null, 'FILTER_StartPosition' => 0, 'FILTER_EndPosition' => 0,
        ]);

        /*
         * THE BANK LINE IS POSTED THE DAY AFTER THE DAY IT NAMES. That one
         * fact is what the old commit could not survive: its narrative says
         * 0830 and it lands on the statement on the 31st.
         *
         * Description is built so position 23 is the terminal and 31 the MM/DD,
         * exactly as 'PMNT M/M B NPF CREDIT ATMH02790830' does on the customer's
         * statement.
         */
        $db->table('PumpIT.dbo.RCN_BankStatementLinesPumpIT')->insert([
            'SSBranchId' => self::BRANCH,
            'LineDate' => '2026-08-31 00:00:00',
            'Description' => 'PMNT M/M B NPF CREDIT ATMH0279'.self::MMDD,
            'Amount' => 1400.00,
            'Type' => 'SmartATM', 'IDState' => 2, 'ReconState' => 1, 'ReconBatchNo' => 0,
        ]);

        /*
         * Three deposits, all on 30 August, totalling the bank line. The 21:40
         * one is deliberate: under the previous 19:00 -> 18:59 window it fell
         * into the NEXT trading day and this batch did not balance at all.
         */
        $deposits = [
            ['TR900010', 900010, '2026-08-30 09:15:00', 400.00],
            ['TR900011', 900011, '2026-08-30 17:02:00', 500.00],
            ['TR900012', 900012, '2026-08-30 21:40:00', 500.00],
        ];

        foreach ($deposits as [$trace, $unique, $wentIn, $amount]) {
            $db->table('PumpIT.dbo.BRN_DailyBankingSmartATM')->insert([
                'SSBranchId' => self::BRANCH, 'TerminalId' => 'ATMH0279',
                'TraceNo' => $trace, 'UniqueNo' => $unique,
                // Midnight, because this column is a day rather than a moment.
                'DepositDateTime' => '2026-08-30 00:00:00',
                'Deposited' => $amount, 'ReconBatchNoPumpIT' => 0,
            ]);

            $db->table('PumpIT.dbo.BRN_SmartATM')->insert([
                'SSBranchId' => self::BRANCH, 'TerminalId' => 'ATMH0279',
                'TraceNo' => $trace, 'UniqueNo' => $unique,
                'DepositDateTime' => $wentIn,
            ]);
        }
    }

    private function auditor(): User
    {
        $user = User::query()->acrossBranches()
            ->where('EmailAddress', config('agora.e2e.email'))->first();

        if (! $user) {
            $this->markTestSkipped('No E2E fixture user — run the E2eFixtureSeeder.');
        }

        return $user;
    }

    private function skipUnlessLocalStub(): void
    {
        $connection = config('agora.connections.app');
        $host = config("database.connections.{$connection}.host");

        if (! in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            $this->markTestSkipped("This test writes deposits and [{$connection}] points at [{$host}].");
        }
    }

    private function cleanUp(): void
    {
        $db = DB::connection(config('agora.connections.app'));

        foreach ([
            'PumpIT.dbo.BRN_DailyBankingSmartATM',
            'PumpIT.dbo.BRN_SmartATM',
            'PumpIT.dbo.RCN_BankStatementLinesPumpIT',
            'PumpIT.dbo.BRN_AutoReconCriteria',
        ] as $table) {
            $db->table($table)->where('SSBranchId', self::BRANCH)->delete();
        }

        $runs = ReconRun::query()->acrossBranches()->where('BranchId', self::BRANCH)->pluck('Id');
        ReconRunLine::query()->acrossBranches()->whereIn('RunId', $runs)->delete();
        ReconRun::query()->acrossBranches()->where('BranchId', self::BRANCH)->delete();
    }
}
