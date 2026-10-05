<?php

namespace Tests\Feature\Recon;

use App\Exceptions\AgoraProcException;
use App\Support\ProcedureService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Recon\Models\ReconRun;
use Modules\Recon\Models\ReconRunLine;
use Tests\TestCase;

/**
 * Pairing by hand — the half of the job no rule can do.
 *
 * This test WRITES, including in live mode, and there is no way to prove the
 * feature without doing so: the whole claim is that a hand-made match stamps
 * the estate and can be put back exactly. Every run it makes is REVERSED and
 * then deleted in tearDown, and the assertions check the restoration rather
 * than assuming it.
 *
 * It runs against the local container's PumpIT stub. It must never be pointed
 * at the customer's instance — `stamp_mode` is live and the branch used here
 * is whichever one the stub has data for.
 */
class ManualMatchTest extends TestCase
{
    /**
     * Runs this test made, cleaned up whatever happens.
     *
     * @var array<int, int>
     */
    private array $made = [];

    private ProcedureService $procedures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->procedures = app(ProcedureService::class);
    }

    protected function tearDown(): void
    {
        if ($this->made !== []) {
            $connection = DB::connection(config('agora.connections.app'));
            $schema = config('agora.schema');

            foreach ($this->made as $runId) {
                // Put the estate back before removing the record of what was
                // done to it — the reversal reads agora.ReconMatch, so
                // deleting first would strand the stub with stamped rows and
                // no way to work out what they were.
                try {
                    $this->procedures->write('usp_Recon_Reverse', [
                        'BranchId' => $this->branchId(),
                        'BatchId' => null,
                        'RunId' => $runId,
                        'Reason' => 'TEST-teardown',
                        'UserId' => 1,
                    ]);
                } catch (AgoraProcException) {
                    // Already reversed by the test itself. Nothing to undo.
                }

                foreach (['ReconStamp', 'ReconMatch', 'ReconBatch', 'ReconRunLine'] as $table) {
                    $connection->table("{$schema}.{$table}")->where('RunId', $runId)->delete();
                }

                $connection->table("{$schema}.ReconRun")->where('Id', $runId)->delete();
            }
        }

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

    /** The branch the stub actually has ABSA statement lines for. */
    private function branchId(): int
    {
        return 18;
    }

    /** @return array{bank: Collection<int, object>, mops: Collection<int, object>, summary: object} */
    private function sides(string $state = 'outstanding'): array
    {
        $sets = $this->procedures->callSets('usp_Recon_GetSides', [
            'ReconArea' => 'ABSA',
            'BranchId' => $this->branchId(),
            'FromDate' => '2026-07-01',
            'ToDate' => '2026-07-31 23:59:59',
            'State' => $state,
            'MaxRows' => 200,
        ]);

        return ['bank' => $sets[0], 'mops' => $sets[1], 'summary' => $sets[2]->first()];
    }

    /**
     * @param  array<int, int>  $bankIds
     * @param  array<int, array<string, mixed>>  $deposits
     */
    private function match(array $bankIds, array $deposits, ?string $reason = null, string $mode = 'journal'): object
    {
        $status = $this->procedures->write('usp_Recon_ManualMatch', [
            'BranchId' => $this->branchId(),
            'ReconArea' => 'ABSA',
            'FromDate' => '2026-07-01',
            'ToDate' => '2026-07-31 23:59:59',
            'BankLineIds' => implode(',', $bankIds),
            'MopsJson' => json_encode($deposits),
            'Reason' => $reason,
            'StampMode' => $mode,
            'UserId' => 1,
        ]);

        $this->made[] = (int) $status->Id;

        return $status;
    }

    /**
     * A colour means the reference is on BOTH sides — never merely that the
     * row has one.
     *
     * This is the assertion the whole screen rests on. A colour per distinct
     * key would look identical on a screenshot and would be useless.
     */
    public function test_only_references_present_on_both_sides_are_coloured(): void
    {
        ['bank' => $bank, 'mops' => $mops] = $this->sides();

        if ($bank->isEmpty() || $mops->isEmpty()) {
            $this->markTestSkipped('The stub holds no outstanding ABSA rows for this period.');
        }

        $mopsKeys = $mops->pluck('PairKey')->filter()->unique();
        $bankKeys = $bank->pluck('PairKey')->filter()->unique();

        foreach ($bank as $row) {
            $shared = $row->PairKey !== null && $mopsKeys->contains($row->PairKey);

            $this->assertSame(
                $shared,
                (int) $row->ColourIndex > 0,
                "Bank line {$row->BankStatementLineID} (key {$row->PairKey}) is coloured "
                .'if and only if that reference is on the deposit side too.'
            );
        }

        foreach ($mops as $row) {
            $shared = $row->PairKey !== null && $bankKeys->contains($row->PairKey);

            $this->assertSame($shared, (int) $row->ColourIndex > 0);
        }
    }

    /** Both sides, or nothing. A one-sided "match" is not a match. */
    public function test_a_match_needs_both_sides(): void
    {
        $this->expectException(AgoraProcException::class);
        $this->expectExceptionMessage('at least one row on each side');

        $this->match([1], []);
    }

    /**
     * A variance is allowed — sometimes it is the truth — but never silently.
     */
    public function test_a_variance_without_a_reason_is_refused(): void
    {
        ['bank' => $bank, 'mops' => $mops] = $this->sides();

        $line = $bank->first();
        $deposit = $mops->first(fn (object $row) => (float) $row->Amount !== (float) $line->Amount);

        if ($line === null || $deposit === null) {
            $this->markTestSkipped('The stub holds no unbalanced pair to force.');
        }

        try {
            $this->match([$line->BankStatementLineID], [$this->deposit($deposit)]);
            $this->fail('A match whose sides do not balance was accepted without a reason.');
        } catch (AgoraProcException $e) {
            $this->assertSame('FORCE_REASON_REQUIRED', $e->code());
        }
    }

    /**
     * The whole loop: match in LIVE mode, see both sides stamped, see a second
     * attempt on a claimed line refused, reverse, and see both sides restored
     * to exactly what they held.
     */
    public function test_a_live_match_stamps_both_sides_and_reverses_exactly(): void
    {
        ['bank' => $bank, 'mops' => $mops] = $this->sides();

        // A balanced pair: every bank line carrying one shared reference, and
        // the deposit rows carrying it.
        $key = $mops->pluck('PairKey')->filter()
            ->first(fn (string $k) => $bank->where('PairKey', $k)->isNotEmpty());

        if ($key === null) {
            $this->markTestSkipped('The stub holds no reference present on both sides.');
        }

        $lines = $bank->where('PairKey', $key);
        $deposits = $mops->where('PairKey', $key);

        $before = $this->bankState($lines->pluck('BankStatementLineID')->all());

        $status = $this->match(
            $lines->pluck('BankStatementLineID')->map(fn (mixed $id) => (int) $id)->all(),
            $deposits->map(fn (object $row) => $this->deposit($row))->values()->all(),
            null,
            'live',
        );

        $this->assertSame('MATCHED', $status->Code);
        $this->assertGreaterThan(0, (int) $status->BatchNo, 'A live match takes a real batch number.');

        // Both sides stamped, with the batch the procedure reported.
        foreach ($this->bankState($lines->pluck('BankStatementLineID')->all()) as $id => $row) {
            $this->assertSame(2, (int) $row->ReconState, "Bank line {$id} should be reconciled.");
            $this->assertSame((int) $status->BatchNo, (int) $row->ReconBatchNo);
        }

        // A manual match IS a run — that is what makes everything downstream
        // work on it unchanged.
        $run = ReconRun::query()->acrossBranches()->findOrFail($status->Id);
        $this->assertSame('committed', $run->Status);
        $this->assertSame('agora.usp_Recon_ManualMatch', $run->ProcedureName);

        $line = ReconRunLine::query()->acrossBranches()->where('RunId', $run->Id)->firstOrFail();
        $this->assertSame('Matched by hand', $line->Outcome);
        $this->assertSame((int) $status->BatchNo, (int) $line->ReconBatchNo);

        // The estate has moved, so a second attempt on the same line must be
        // refused rather than stamped over.
        try {
            $this->match(
                [(int) $lines->first()->BankStatementLineID],
                [$this->deposit($deposits->first())],
                'TEST-should not be reached',
                'live',
            );
            $this->fail('A bank line already reconciled was matched again.');
        } catch (AgoraProcException $e) {
            $this->assertSame('BANK_ROW_MOVED', $e->code());
        }

        // And it goes back to exactly what it was — not to zero, but to what
        // each row actually held.
        $this->procedures->write('usp_Recon_Reverse', [
            'BranchId' => $this->branchId(),
            'BatchId' => null,
            'RunId' => $run->Id,
            'Reason' => 'TEST-proving the reversal restores the prior state',
            'UserId' => 1,
        ]);

        foreach ($this->bankState($lines->pluck('BankStatementLineID')->all()) as $id => $row) {
            $this->assertSame((int) $before[$id]->ReconState, (int) $row->ReconState, "Bank line {$id} restored.");
            $this->assertSame((int) $before[$id]->ReconBatchNo, (int) $row->ReconBatchNo);
        }
    }

    /**
     * Chantal Peens (ZP), 1 Oct 2026: a forced FNB match must write its
     * variance to the DEPOSIT side, as PumpIT's own forced batch does — the
     * deposits' Amount moves to meet the bank, the change is recorded in
     * AdjustedBy, Adjusted is set, and the bank line never moves.
     *
     * Two deposit rows of its own (TST batch numbers, full primary key, as
     * every live row has) R26.00 over a real outstanding bank line, all inside
     * a transaction that is rolled back: the procedure joins it, so nothing it
     * writes to the stub survives the test.
     */
    public function test_a_forced_fnb_match_writes_its_variance_to_the_deposits_and_not_the_bank(): void
    {
        $bankLine = $this->fnbBankLine();
        $db = DB::connection(config('agora.connections.app'));
        $bankTotal = round((float) $bankLine->Amount, 2);

        $db->beginTransaction();

        try {
            $rows = $this->insertFnbDeposits([round($bankTotal * 0.6, 2), round($bankTotal - round($bankTotal * 0.6, 2) + 26.00, 2)]);
            $before = $this->fnbRows($rows);

            $status = $this->forceFnb($bankLine, $rows, 'live');

            $this->assertSame('MATCHED_FORCED', $status->Code);
            $this->assertStringContainsString('written to the deposits', $status->Message);

            $after = $this->fnbRows($rows);

            // The deposits now meet the bank to the cent, and the bank did not move.
            $this->assertEqualsWithDelta($bankTotal, array_sum(array_map(fn ($r) => (float) $r->Amount, $after)), 0.001);
            $this->assertEqualsWithDelta($bankTotal, (float) $this->bankState([$bankLine->BankStatementLineID])[$bankLine->BankStatementLineID]->Amount, 0.001);

            $moved = 0.0;

            foreach ($after as $key => $row) {
                $share = round((float) $row->Amount - (float) $before[$key]->Amount, 2);
                $moved += $share;

                $this->assertSame((int) $status->BatchNo, (int) $row->ReconBatchNoPumpIT);
                $this->assertTrue((bool) $row->Adjusted, 'Every adjusted row says so.');
                $this->assertEqualsWithDelta((float) $before[$key]->AdjustedBy + $share, (float) $row->AdjustedBy, 0.001,
                    'AdjustedBy carries exactly what Amount moved by.');
            }

            $this->assertEqualsWithDelta(-26.00, $moved, 0.001, 'Every cent of the variance went to the deposits.');

            // Pro rata by what each row carried: share = variance x row / deposits.
            $amounts = array_values(array_map(fn ($r) => (float) $r->Amount, $before));
            $shares = array_values(array_map(fn ($k) => round((float) $after[$k]->Amount - (float) $before[$k]->Amount, 2), array_keys($after)));
            $this->assertEqualsWithDelta(-26.00 * $amounts[0] / array_sum($amounts), $shares[0], 0.011);

            // The ledger keeps what each row held, which is what a reversal puts back.
            $matches = $db->table(config('agora.schema').'.ReconMatch')
                ->where('RunId', $status->Id)->where('Side', 'mops')->get();

            $this->assertCount(2, $matches);
            $this->assertTrue($matches->every(fn ($m) => $m->Adjustment !== null && $m->PriorAmount !== null));

            $detail = $db->table(config('agora.schema').'.ReconStamp')
                ->where('RunId', $status->Id)->whereNotNull('Detail')->value('Detail');

            $this->assertStringContainsString('AdjustedBy', (string) $detail, 'The audit trail can say what changed.');

            // And it does: the trail's line for each deposit names the amounts (v1__22c).
            $trail = $db->table(config('agora.schema').'.vw_PumpItChange')
                ->where('Ledger', 'ReconStamp')
                ->whereIn('LedgerId', $db->table(config('agora.schema').'.ReconStamp')->where('RunId', $status->Id)->pluck('Id'))
                ->where('PumpItTable', 'BRN_DailyBankingFNB')
                ->pluck('Change');

            $this->assertCount(2, $trail);
            $this->assertTrue($trail->every(fn ($c) => str_contains($c, 'Amount ') && str_contains($c, 'AdjustedBy')));
        } finally {
            $db->rollBack();
        }

        $this->assertSame(0, $db->table('PumpIT.dbo.BRN_DailyBankingFNB')->where('BatchNo', 'like', 'TST%')->count(),
            'Rolled back: the stub is as it was.');
    }

    /** A reversal puts every adjusted deposit back to what it held, and leaves the bank as it was. */
    public function test_reversing_a_forced_fnb_match_puts_the_deposits_back(): void
    {
        $bankLine = $this->fnbBankLine();
        $db = DB::connection(config('agora.connections.app'));
        $bankTotal = round((float) $bankLine->Amount, 2);

        $db->beginTransaction();

        try {
            $rows = $this->insertFnbDeposits([round($bankTotal * 0.6, 2), round($bankTotal - round($bankTotal * 0.6, 2) + 26.00, 2)]);
            $before = $this->fnbRows($rows);
            $bankBefore = $this->bankState([$bankLine->BankStatementLineID])[$bankLine->BankStatementLineID];

            $status = $this->forceFnb($bankLine, $rows, 'live');

            $this->procedures->write('usp_Recon_Reverse', [
                'BranchId' => $this->branchId(), 'BatchId' => null, 'RunId' => $status->Id,
                'Reason' => 'TEST-reverse the FNB adjustment', 'UserId' => 1,
            ]);

            $this->assertEquals($before, $this->fnbRows($rows), 'Amount, AdjustedBy, Adjusted and the stamp are exactly as they were.');

            $bankAfter = $this->bankState([$bankLine->BankStatementLineID])[$bankLine->BankStatementLineID];
            $this->assertSame((int) $bankBefore->ReconState, (int) $bankAfter->ReconState);
            $this->assertEqualsWithDelta((float) $bankBefore->Amount, (float) $bankAfter->Amount, 0.001);
        } finally {
            $db->rollBack();
        }
    }

    /** A deposit edited after the match is not written over: the reversal is refused whole. */
    public function test_a_reversal_refuses_when_an_adjusted_deposit_has_changed_since(): void
    {
        $bankLine = $this->fnbBankLine();
        $db = DB::connection(config('agora.connections.app'));
        $bankTotal = round((float) $bankLine->Amount, 2);

        $db->beginTransaction();

        try {
            $rows = $this->insertFnbDeposits([round($bankTotal * 0.6, 2), round($bankTotal - round($bankTotal * 0.6, 2) + 26.00, 2)]);
            $status = $this->forceFnb($bankLine, $rows, 'live');

            // Somebody corrects one of the rows in PumpIT afterwards.
            $db->table('PumpIT.dbo.BRN_DailyBankingFNB')->where('SSBranchId', $this->branchId())
                ->where('BatchNo', $rows[0]->SourceKey)->update(['Amount' => DB::raw('Amount + 1')]);

            $refused = null;

            try {
                $this->procedures->write('usp_Recon_Reverse', [
                    'BranchId' => $this->branchId(), 'BatchId' => null, 'RunId' => $status->Id,
                    'Reason' => 'TEST-should be refused', 'UserId' => 1,
                ]);
            } catch (AgoraProcException $e) {
                $refused = $e;
            }

            $this->assertNotNull($refused, 'The reversal must not write over a deposit changed since the match.');
            $this->assertSame('ADJUST_MOVED', $refused->code());
        } finally {
            if ($db->transactionLevel() > 0) {
                $db->rollBack();
            }
        }
    }

    /**
     * Two settlements can share batch, date and amount and differ only by
     * merchant. The clerk ticked merchant B's: B's row is stamped, A's is not
     * (task 01a0ce85, 5 Oct 2026). FNB and ABSA, rolled back.
     */
    public function test_a_deposit_is_claimed_by_its_merchant_not_just_its_batch_date_and_amount(): void
    {
        $db = DB::connection(config('agora.connections.app'));

        foreach (['FNB', 'ABSA'] as $area) {
            $sides = $this->procedures->callSets('usp_Recon_GetSides', [
                'ReconArea' => $area, 'BranchId' => $this->branchId(),
                'FromDate' => '2026-07-01', 'ToDate' => '2026-07-31 23:59:59', 'State' => 'outstanding', 'MaxRows' => 200,
            ]);
            $bankLine = $sides[0]->first();

            if ($bankLine === null) {
                $this->markTestSkipped("The stub holds no outstanding {$area} bank line in July 2026.");
            }

            $db->beginTransaction();

            try {
                [$table, $merchantCol] = $area === 'FNB'
                    ? ['PumpIT.dbo.BRN_DailyBankingFNB', 'MerchantNo']
                    : ['PumpIT.dbo.BRN_DailyBankingABSA', 'MerchantNumber'];

                foreach (['TSTA01', 'TSTB01'] as $i => $merchant) {
                    $db->table($table)->insert($area === 'FNB'
                        ? ['SSBranchId' => $this->branchId(), 'TillNo' => 91 + $i, 'ShiftNo' => 1,
                            'TransactionDate' => '2026-07-15 00:00:00', 'BatchNo' => 'TST0599', 'MerchantNo' => $merchant,
                            'Amount' => 123.45, 'ReconBatchNo' => 0, 'ReconBatchNoPumpIT' => 0, 'Adjusted' => 0, 'AdjustedBy' => 0]
                        : ['SSBranchId' => $this->branchId(), 'TillNo' => 91 + $i, 'ShiftNo' => 1,
                            'TransactionDate' => '2026-07-15 00:00:00', 'RetrievalRefNumber' => 'TST'.$i, 'TransactionAuthCode' => 'TST',
                            'ABSA_TransactionDate' => '2026-07-15 00:00:00', 'ABSA_TransactionTime' => '2026-07-15 10:00:00',
                            'TransactionAmount' => 123.45, 'BatchNumber' => 9905099, 'MerchantNumber' => $merchant,
                            'ReconBatchNo' => 0, 'ReconBatchNoPumpIT' => 0]);
                }

                $status = $this->procedures->write('usp_Recon_ManualMatch', [
                    'BranchId' => $this->branchId(), 'ReconArea' => $area,
                    'FromDate' => '2026-07-01', 'ToDate' => '2026-07-31 23:59:59',
                    'BankLineIds' => (string) $bankLine->BankStatementLineID,
                    'MopsJson' => json_encode([[
                        'id' => null, 'key' => $area === 'FNB' ? 'TST0599' : '9905099', 'dt' => '2026-07-15',
                        'amt' => 123.45, 'ref2' => 'TSTB01',
                    ]]),
                    'Reason' => 'TEST-merchant', 'StampMode' => 'live', 'UserId' => 1, 'AdjustMode' => 'off',
                ]);

                $stamped = $db->table($table)->where('SSBranchId', $this->branchId())
                    ->whereIn($merchantCol, ['TSTA01', 'TSTB01'])->pluck('ReconBatchNoPumpIT', $merchantCol);

                $this->assertSame((int) $status->BatchNo, (int) $stamped['TSTB01'], "{$area}: the ticked merchant's row is stamped.");
                $this->assertSame(0, (int) $stamped['TSTA01'], "{$area}: the other merchant's row is left alone.");
            } finally {
                $db->rollBack();
            }
        }
    }

    /** With the switch off a forced FNB match stamps both sides and moves no amount — as before 5 Oct 2026. */
    public function test_with_the_fnb_adjustment_off_a_forced_match_moves_no_amount(): void
    {
        $bankLine = $this->fnbBankLine();
        $db = DB::connection(config('agora.connections.app'));
        $bankTotal = round((float) $bankLine->Amount, 2);

        $db->beginTransaction();

        try {
            $rows = $this->insertFnbDeposits([round($bankTotal * 0.6, 2), round($bankTotal - round($bankTotal * 0.6, 2) + 26.00, 2)]);
            $before = $this->fnbRows($rows);

            $status = $this->forceFnb($bankLine, $rows, 'off');

            $this->assertSame('MATCHED_FORCED', $status->Code);
            $this->assertStringNotContainsString('written to the deposits', $status->Message);

            foreach ($this->fnbRows($rows) as $key => $row) {
                $this->assertSame((int) $status->BatchNo, (int) $row->ReconBatchNoPumpIT, 'Still stamped.');
                $this->assertEqualsWithDelta((float) $before[$key]->Amount, (float) $row->Amount, 0.001, 'No amount moved.');
                $this->assertEqualsWithDelta(0.0, (float) $row->AdjustedBy, 0.001);
            }
        } finally {
            $db->rollBack();
        }
    }

    /** An outstanding FNB bank line on the branch and month the stub holds them for. */
    private function fnbBankLine(): object
    {
        $sets = $this->procedures->callSets('usp_Recon_GetSides', [
            'ReconArea' => 'FNB',
            'BranchId' => $this->branchId(),
            'FromDate' => '2026-07-01',
            'ToDate' => '2026-07-31 23:59:59',
            'State' => 'outstanding',
            'MaxRows' => 200,
        ]);

        $bank = $sets[0]->first(fn ($l) => (float) $l->Amount > 1000);

        if ($bank === null) {
            $this->markTestSkipped('The stub holds no outstanding FNB bank line in July 2026.');
        }

        return $bank;
    }

    /**
     * Deposit rows of the test's own, with a whole primary key. Call inside
     * the test's transaction: they go when it is rolled back.
     *
     * @param  array<int, float>  $amounts
     * @return array<int, object>
     */
    private function insertFnbDeposits(array $amounts): array
    {
        $rows = [];

        foreach ($amounts as $i => $amount) {
            $batch = 'TST05'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT);

            DB::connection(config('agora.connections.app'))->table('PumpIT.dbo.BRN_DailyBankingFNB')->insert([
                'SSBranchId' => $this->branchId(), 'TillNo' => 91 + $i, 'ShiftNo' => 1,
                'TransactionDate' => '2026-07-15 00:00:00', 'BatchNo' => $batch, 'MerchantNo' => 'TST001',
                'Amount' => $amount, 'ReconBatchNo' => 0, 'ReconBatchNoPumpIT' => 0, 'Adjusted' => 0, 'AdjustedBy' => 0,
            ]);

            $rows[] = (object) ['SourceId' => null, 'SourceKey' => $batch, 'SourceDate' => '2026-07-15', 'Amount' => $amount];
        }

        return $rows;
    }

    /** @param  array<int, object>  $rows */
    private function forceFnb(object $bankLine, array $rows, string $adjust): object
    {
        return $this->procedures->write('usp_Recon_ManualMatch', [
            'BranchId' => $this->branchId(),
            'ReconArea' => 'FNB',
            'FromDate' => '2026-07-01',
            'ToDate' => '2026-07-31 23:59:59',
            'BankLineIds' => (string) $bankLine->BankStatementLineID,
            'MopsJson' => json_encode(array_map(fn ($r) => $this->deposit($r), $rows)),
            'Reason' => 'TEST-forced FNB',
            'StampMode' => 'live',
            'UserId' => 1,
            'AdjustMode' => $adjust,
        ]);
    }

    /**
     * The test's deposit rows as PumpIT holds them, keyed by batch number.
     *
     * @param  array<int, object>  $rows
     * @return array<string, object>
     */
    private function fnbRows(array $rows): array
    {
        $out = [];

        foreach ($rows as $r) {
            $out[$r->SourceKey] = DB::connection(config('agora.connections.app'))
                ->table('PumpIT.dbo.BRN_DailyBankingFNB')
                ->where('SSBranchId', $this->branchId())
                ->where('BatchNo', $r->SourceKey)
                ->first(['Amount', 'AdjustedBy', 'Adjusted', 'ReconBatchNoPumpIT']);
        }

        return $out;
    }

    /** The screen itself: a site has to be chosen before there are two sides. */
    public function test_the_pane_asks_for_a_site_before_it_shows_anything(): void
    {
        $this->actingAs($this->admin())->get('/app/recon/auto/ABSA/match')
            ->assertOk()
            ->assertSee('Choose a site first');

        $this->actingAs($this->admin())
            ->get('/app/recon/auto/ABSA/match?branch_id='.$this->branchId().'&from=2026-07-01&to=2026-07-31')
            ->assertOk()
            ->assertSee('Bank statement')
            ->assertSee('Deposits')
            ->assertDontSee('Choose a site first');
    }

    /** @return array<string, mixed> */
    private function deposit(object $row): array
    {
        return [
            'id' => $row->SourceId,
            'key' => $row->SourceKey,
            'dt' => substr((string) $row->SourceDate, 0, 10),
            'amt' => (float) $row->Amount,
        ];
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, object>
     */
    private function bankState(array $ids): array
    {
        return DB::connection(config('agora.connections.app'))
            ->table(config('agora.schema').'.vw_BankStatementLine')
            ->whereIn('BankStatementLineID', $ids)
            ->get()
            ->keyBy('BankStatementLineID')
            ->all();
    }
}
