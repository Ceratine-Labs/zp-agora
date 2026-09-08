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
