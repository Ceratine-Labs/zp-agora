<?php

namespace Tests\Feature\Recon;

use App\Exceptions\AgoraProcException;
use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Recon\Models\ReconRun;
use Modules\Recon\Models\ReconRunLine;
use Modules\Recon\Services\ReconPreviewRefused;
use Modules\Recon\Services\ReconService;
use Tests\TestCase;

/**
 * The ported AUTO RECON preview, against rows that reproduce the findings.
 *
 * The one assertion this file exists for is `test_a_bank_line_with_no_deposit_never_reconciles`.
 * Finding 2 of docs/pumpit-auto-recon-findings.md is that the live procedures
 * decide with `IF @CurrAmount <> @MOPSAmount`, which is UNKNOWN when there is
 * no deposit row and therefore falls to the ELSE — the matched branch — so
 * under Execute the bank line is stamped reconciled against nothing. Every
 * other test here is scaffolding for that one.
 *
 * The fixture writes to the LOCAL PumpIT stub, never to the customer's
 * instance, and only to branch 999 — the inactive branch E2eFixtureSeeder
 * creates for exactly this. tearDown() removes every row it wrote.
 */
class AutoReconPreviewTest extends TestCase
{
    /** The E2E fixture branch: inactive, non-trading, and nobody's real site. */
    private const BRANCH = 999;

    private ReconService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessLocalStub();
        $this->service = new ReconService(new ProcedureService);
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

    public function test_the_four_outcomes_come_back_named(): void
    {
        $run = $this->preview();

        $this->assertSame(
            ['Matched', 'Amount mismatch', 'Bank only - no deposit', 'Deposit only - no bank line'],
            $run->lines->pluck('Outcome')->all(),
            'The procedure orders matched first and orphans last, and that order is part of the answer.'
        );
    }

    /**
     * The critical one.
     *
     * Batch 300 has a bank line and no deposit at all. The live procedure
     * calls that matched. Here it must be reported, must not be reconcilable,
     * and must never reach a state anything could stamp.
     */
    public function test_a_bank_line_with_no_deposit_never_reconciles(): void
    {
        $run = $this->preview();

        $orphan = $run->lines->firstWhere('KeyRef', '300');

        $this->assertNotNull($orphan, 'A bank line with no deposit must still be reported.');
        $this->assertSame('Bank only - no deposit', $orphan->Outcome);
        $this->assertFalse($orphan->WouldReconcile);
        $this->assertSame(1, $run->BankOnlyRows);

        $this->assertSame(
            1,
            $run->MatchedRows,
            'Only the genuinely matched batch counts. If this is 2, the NULL comparison has come back.'
        );
    }

    /**
     * Finding 4: the live procedure iterates bank lines only, so a deposit
     * batch with no bank line never reaches the screen at all.
     */
    public function test_a_deposit_with_no_bank_line_is_reported(): void
    {
        $run = $this->preview();
        $orphan = $run->lines->firstWhere('KeyRef', '400');

        $this->assertNotNull($orphan);
        $this->assertSame(0, $orphan->BankLines);
        $this->assertEqualsWithDelta(750.00, (float) $orphan->MopsTotal, 0.001);
        $this->assertFalse($orphan->WouldReconcile);
    }

    /**
     * Confirmed by ZP on 14 August 2026: an ABSA batch settles on the SUM of
     * its credit and debit legs, not leg by leg. Batch 125 is the decisive
     * case from their own data — the credit leg alone does not equal the
     * deposit, credit plus debit does, to the cent.
     */
    public function test_a_batch_settles_on_the_sum_of_its_credit_and_debit_legs(): void
    {
        $matched = $this->preview()->lines->firstWhere('KeyRef', '125');

        $this->assertTrue($matched->WouldReconcile);
        $this->assertSame(2, $matched->BankLines);
        $this->assertEqualsWithDelta(14937.40, (float) $matched->BankCC, 0.001);
        $this->assertEqualsWithDelta(200.00, (float) $matched->BankDD, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $matched->DiffAmount, 0.001);
    }

    /**
     * Finding 1: on the live system twenty-four of twenty-six branches produce
     * nothing and the screen does not say why. A branch with no criteria row
     * must produce a refusal, not an empty result set — those are opposite
     * answers.
     */
    public function test_a_branch_with_no_criteria_row_refuses_rather_than_returning_nothing(): void
    {
        $this->db()->table('PumpIT.dbo.BRN_AutoReconCriteria')
            ->where('SSBranchId', self::BRANCH)->delete();

        $this->expectException(ReconPreviewRefused::class);
        $this->preview();
    }

    /** The run is kept, with the arguments it was given, so it is reproducible. */
    public function test_the_run_records_the_procedure_and_its_arguments(): void
    {
        $run = $this->preview();

        $this->assertSame('agora.usp_Recon_PreviewABSA', $run->ProcedureName);
        // Whatever the deployment is configured for — the point is that the
        // run RECORDS it, so a figure read back later says whether the stamp
        // it describes actually reached PumpIT.
        $this->assertSame(config('recon.stamp_mode'), $run->StampMode);
        $this->assertSame(self::BRANCH, $run->BranchId);
        $this->assertSame('specific', $run->params()['RuleOrder']);
        $this->assertSame(4, $run->TotalRows);
    }

    /**
     * The drill has to agree with the aggregate it expands.
     *
     * It is a separate procedure resolving the criteria itself, so the risk it
     * carries is divergence: an expanded row showing lines the total above it
     * never counted. Batch 125 is two bank lines against one deposit, and both
     * sides have to come back with the same count and the same money.
     */
    public function test_the_drill_returns_the_rows_the_aggregate_counted(): void
    {
        $run = $this->preview();
        $matched = $run->lines->firstWhere('KeyRef', '125');

        $detail = $this->service->drill($run, $matched);

        $this->assertCount(2, $detail['bank'], 'The aggregate counted two bank lines.');
        $this->assertCount(1, $detail['mops']);
        $this->assertEqualsWithDelta(
            (float) $matched->BankTotal,
            (float) $detail['bank']->sum('Amount'),
            0.001,
            'The lines behind a proposal must add up to the proposal.'
        );
        $this->assertEqualsWithDelta(
            (float) $matched->MopsTotal,
            (float) $detail['mops']->sum('Amount'),
            0.001
        );

        // The credit and debit legs, which is what makes the total right.
        $this->assertSame(['CC', 'DD'], $detail['bank']->pluck('Leg')->map('trim')->sort()->values()->all());
    }

    /**
     * On the row that matters most, the drill has to show an empty deposit
     * side — that emptiness IS the finding, and a drill that quietly returned
     * a nearby deposit would undo the whole point of the rebuild.
     */
    public function test_the_drill_shows_nothing_behind_a_bank_only_row(): void
    {
        $run = $this->preview();
        $detail = $this->service->drill($run, $run->lines->firstWhere('KeyRef', '300'));

        $this->assertCount(1, $detail['bank']);
        $this->assertCount(0, $detail['mops']);
    }

    /** And the mirror image: a deposit nobody banked has no statement line. */
    public function test_the_drill_shows_nothing_behind_a_deposit_only_row(): void
    {
        $run = $this->preview();
        $detail = $this->service->drill($run, $run->lines->firstWhere('KeyRef', '400'));

        $this->assertCount(0, $detail['bank']);
        $this->assertCount(1, $detail['mops']);
        $this->assertEqualsWithDelta(750.00, (float) $detail['mops']->sum('Amount'), 0.001);
    }

    /** A preview is a record of a read, so it can simply go. */
    public function test_a_preview_can_be_discarded_with_its_lines(): void
    {
        $run = $this->preview();

        $this->assertSame(1, $this->service->discard(self::BRANCH, null, $run->Id));

        $this->assertNull(ReconRun::query()->acrossBranches()->find($run->Id));
        $this->assertSame(
            0,
            ReconRunLine::query()->acrossBranches()->where('RunId', $run->Id)->count(),
            'Discarding a run must take its proposals with it, not orphan them.'
        );
    }

    /**
     * The rule the procedure exists to hold.
     *
     * A committed run is the only record of what was stamped, on whose
     * authority and under which rules — the thing PumpIT has never had, and
     * why question 3.7 of the findings could only be guessed at. Deleting one
     * would recreate the problem the ledger exists to solve.
     */
    public function test_a_committed_run_is_refused(): void
    {
        $run = $this->preview();
        $run->forceFill(['Status' => 'committed'])->save();

        try {
            $this->service->discard(self::BRANCH, null, $run->Id);
            $this->fail('Discarding a committed run must be refused.');
        } catch (AgoraProcException $e) {
            $this->assertSame('RUN_COMMITTED', $e->code());
        }

        $this->assertNotNull(ReconRun::query()->acrossBranches()->find($run->Id));
    }

    /**
     * A sweep skips a committed run rather than stopping on it. The clerk
     * asked to clear their previews, not to be blocked by history.
     */
    public function test_a_sweep_clears_previews_and_keeps_committed_runs(): void
    {
        $keep = $this->preview();
        $keep->forceFill(['Status' => 'committed'])->save();
        $this->preview();
        $this->preview();

        $this->assertSame(2, $this->service->discard(self::BRANCH, 'ABSA'));

        $this->assertNotNull(ReconRun::query()->acrossBranches()->find($keep->Id));
        $this->assertSame(
            1,
            ReconRun::query()->acrossBranches()->where('BranchId', self::BRANCH)->count()
        );
    }

    /**
     * Ryan's case, 4 September 2026, from real data at branch 9.
     *
     * The bank narrative yields `69744` and the deposit slip yields `697440` —
     * the same reconciliation, read one character too long on the deposit side.
     * Without the flag it is reported as two unrelated orphans at opposite ends
     * of the screen; with it, both rows point at each other and say why.
     */
    public function test_a_reference_read_a_character_too_long_becomes_one_matched_row(): void
    {
        $this->seedNearReferenceCase(33320.00);

        $run = $this->service->preview(
            'CashMachine', self::BRANCH,
            Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'),
        );

        // ONE row, not two orphans. That is the whole change.
        $this->assertCount(1, $run->lines);

        $line = $run->lines->first();
        $this->assertSame('69744', $line->KeyRef, 'The bank reference leads.');
        $this->assertSame('697440', $line->pairedReference(), 'And the deposit keeps its own.');

        // Both sides are on the row, and it is a real match.
        $this->assertSame(1, $line->BankLines);
        $this->assertSame(1, $line->MopsTxns);
        $this->assertTrue($line->WouldReconcile, 'Equal totals make it reconcilable, flagged.');
        $this->assertSame('Matched - reference read a character apart', $line->Outcome);
        $this->assertTrue($line->hasNearReference());
        $this->assertStringContainsString('an inference, not the configured rule', (string) $line->NearRefNote);

        // The run's own counts were recomputed around the pairing.
        $this->assertSame(1, $run->TotalRows);
        $this->assertSame(1, $run->MatchedRows);
        $this->assertSame(0, $run->BankOnlyRows);
        $this->assertSame(0, $run->DepositOnlyRows);
    }

    /** Paired, but the two sides disagree — a mismatch, not a match. */
    public function test_a_paired_row_whose_totals_differ_is_a_mismatch(): void
    {
        $this->seedNearReferenceCase(33320.00, 4020.00);

        $run = $this->service->preview(
            'CashMachine', self::BRANCH,
            Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'),
        );

        $line = $run->lines->first();

        $this->assertSame('Amount mismatch - reference read a character apart', $line->Outcome);
        $this->assertFalse($line->WouldReconcile);
        $this->assertEqualsWithDelta(-29300.00, (float) $line->DiffAmount, 0.001);
        $this->assertSame(1, $run->MismatchRows);
    }

    /** And the drill on a paired row finds the deposits under THEIR reference. */
    public function test_the_drill_on_a_paired_row_finds_both_sides(): void
    {
        $this->seedNearReferenceCase(33320.00);

        $run = $this->service->preview(
            'CashMachine', self::BRANCH,
            Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'),
        );

        $detail = $this->service->drill($run, $run->lines->first());

        $this->assertCount(1, $detail['bank']);
        $this->assertCount(
            1,
            $detail['mops'],
            'The deposits are under 697440; looking under 69744 would return nothing.'
        );
    }

    /** And reading the deposit side the other way makes them meet properly. */
    public function test_the_other_reading_pairs_them_into_one_row(): void
    {
        $this->seedNearReferenceCase(33320.00);

        $run = $this->service->preview(
            'CashMachine', self::BRANCH,
            Carbon::parse('2026-08-01'), Carbon::parse('2026-08-31'),
            ['MopsConvention' => 'endpos'],
        );

        $this->assertCount(1, $run->lines, 'Two orphans become one row.');
        $this->assertSame('69744', $run->lines->first()->KeyRef);
        $this->assertNull(
            $run->lines->first()->pairedReference(),
            'Under the correct reading the two sides agree, so there is nothing to pair.'
        );
        $this->assertSame(0, $run->DepositOnlyRows);
        $this->assertSame(0, $run->BankOnlyRows);
    }

    /** A short or unrelated reference must not be paired — a false link sends
     *  somebody looking for a relationship that is not there. */
    public function test_unrelated_references_are_not_paired(): void
    {
        $run = $this->preview();

        $this->assertNull($run->lines->firstWhere('KeyRef', '300')->NearRefLineId);
        $this->assertNull($run->lines->firstWhere('KeyRef', '400')->NearRefLineId);
    }

    /**
     * CashMachine at this branch: bank reads five characters from position 28,
     * the deposit slip reads from position 2 with MOPS_EndPosition = 6 — which
     * as a LENGTH gives six characters and as an END POSITION gives five.
     */
    private function seedNearReferenceCase(float $bank = 33320.00, float $deposit = 33320.00): void
    {
        $db = $this->db();

        $db->table('PumpIT.dbo.BRN_AutoReconCriteria')->insert([
            'SSBranchId' => self::BRANCH, 'BankReconArea' => 'CashMachine', 'ProcessOrder' => 1,
            'BANK_StartPosition' => 28, 'BANK_EndPosition' => 32,
            'BANK_StartPosition2' => 0, 'BANK_EndPosition2' => 0,
            'MOPS_StartPosition' => 2, 'MOPS_EndPosition' => 6,
            'FILTER_Value' => null, 'FILTER_StartPosition' => 0, 'FILTER_EndPosition' => 0,
        ]);

        $db->table('PumpIT.dbo.RCN_BankStatementLinesPumpIT')->insert([
            'SSBranchId' => self::BRANCH, 'LineDate' => '2026-08-05',
            'Description' => 'CF NPF CREDIT ABSA BANK CCB69744',
            'Amount' => $bank, 'Type' => 'CashMachine',
            'IDState' => 2, 'ReconState' => 1, 'ReconBatchNo' => 0,
        ]);

        $db->table('PumpIT.dbo.BRN_DailyBankingDeposita')->insert([
            'SSBranchId' => self::BRANCH, 'TransactionDate' => '2026-08-03',
            'SlipNo' => 'D6974400001', 'DepositaAmount' => $deposit, 'ReconBatchNoPumpIT' => 0,
        ]);
    }

    private function preview(): ReconRun
    {
        $this->actingAs($this->auditor());

        return $this->service->preview(
            'ABSA',
            self::BRANCH,
            Carbon::parse('2026-07-01'),
            Carbon::parse('2026-07-31'),
        );
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

    /** The app connection, which is where the views read PumpIT from. */
    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }

    /**
     * Writing bank lines and deposits is only safe against the local stub.
     *
     * The app connection is the one this test writes through, so it is the one
     * that has to be local. Belt and braces on the rule that matters most in
     * this repository: nothing in the customer's databases is ever written.
     */
    private function skipUnlessLocalStub(): void
    {
        $connection = config('agora.connections.app');
        $host = config("database.connections.{$connection}.host");

        if (! in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            $this->markTestSkipped(
                "The recon fixture writes bank lines and deposits, and [{$connection}] points at [{$host}]. "
                .'It runs against the local container only.'
            );
        }
    }

    private function seedFixture(): void
    {
        $pumpit = $this->db();

        $pumpit->table('PumpIT.dbo.BRN_AutoReconCriteria')->insert([
            'SSBranchId' => self::BRANCH,
            'BankReconArea' => 'ABSA',
            'ProcessOrder' => 1,
            // The real branch-18 configuration: batch at (58, len 3),
            // merchant at (49, len 8).
            'BANK_StartPosition' => 58, 'BANK_EndPosition' => 3,
            'BANK_StartPosition2' => 49, 'BANK_EndPosition2' => 8,
            'MOPS_StartPosition' => 1, 'MOPS_EndPosition' => 10,
            'FILTER_Value' => null, 'FILTER_StartPosition' => 0, 'FILTER_EndPosition' => 0,
        ]);

        // 48 characters of filler, merchant at 49..56, batch at 58..60, then
        // the CC/DD leg suffix the procedure reads with RIGHT(...,2).
        $narrative = fn (string $batch, string $leg) => str_repeat('X', 48).'02026318 '.$batch.' '.$leg;

        $pumpit->table('PumpIT.dbo.RCN_BankStatementLinesPumpIT')->insert([
            $this->bankLine('2026-07-08', $narrative('125', 'CC'), 14937.40),
            $this->bankLine('2026-07-08', $narrative('125', 'DD'), 200.00),
            $this->bankLine('2026-07-09', $narrative('200', 'CC'), 500.00),
            // No deposit behind this one anywhere.
            $this->bankLine('2026-07-10', $narrative('300', 'CC'), 900.00),
        ]);

        $pumpit->table('PumpIT.dbo.BRN_DailyBankingABSA')->insert([
            $this->deposit('2026-07-08', 125, 15137.40),
            $this->deposit('2026-07-09', 200, 450.00),
            // No bank line behind this one.
            $this->deposit('2026-07-11', 400, 750.00),
        ]);
    }

    /** @return array<string, mixed> */
    private function bankLine(string $date, string $description, float $amount): array
    {
        return [
            'SSBranchId' => self::BRANCH,
            'LineDate' => $date,
            'Description' => $description,
            'Amount' => $amount,
            'Type' => 'ABSA',
            'IDState' => 2,
            'ReconState' => 1,
            'ReconBatchNo' => 0,
        ];
    }

    /** @return array<string, mixed> */
    private function deposit(string $date, int $batch, float $amount): array
    {
        return [
            'SSBranchId' => self::BRANCH,
            'TransactionDate' => $date,
            'BatchNumber' => $batch,
            // The bank narrative carries a leading zero that this column does
            // not; the procedure compares them numerically.
            'MerchantNumber' => '2026318',
            'TransactionAmount' => $amount,
            'ReconBatchNoPumpIT' => 0,
        ];
    }

    private function cleanUp(): void
    {
        $pumpit = $this->db();

        foreach ([
            'PumpIT.dbo.RCN_BankStatementLinesPumpIT',
            'PumpIT.dbo.BRN_DailyBankingABSA',
            'PumpIT.dbo.BRN_DailyBankingDeposita',
            'PumpIT.dbo.BRN_AutoReconCriteria',
        ] as $table) {
            $pumpit->table($table)->where('SSBranchId', self::BRANCH)->delete();
        }

        // Eloquent, not the procedure: usp_Recon_DiscardRuns deliberately
        // refuses a committed run, and a test that marks one committed still
        // has to leave the branch clean behind it.
        $runs = ReconRun::query()->acrossBranches()->where('BranchId', self::BRANCH)->pluck('Id');

        ReconRunLine::query()->acrossBranches()->whereIn('RunId', $runs)->delete();
        ReconRun::query()->acrossBranches()->where('BranchId', self::BRANCH)->delete();
    }
}
