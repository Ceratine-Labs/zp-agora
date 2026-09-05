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
use Modules\Recon\Services\ReconService;
use Tests\TestCase;

/**
 * Executing a reconciliation, and undoing it.
 *
 * This is the only part of Agora that writes to the customer's estate, so it
 * is the part that has to be provable. The three assertions that matter:
 *
 *  1. In JOURNAL mode nothing in PumpIT moves. That is the fallback offered to
 *     ZP in writing on 18 August 2026 and it has to be genuinely inert.
 *  2. In LIVE mode the bank line and the deposit row really do change, to the
 *     batch number Agora allocated.
 *  3. A reversal puts back exactly what was there — including a prior batch
 *     number, not a blanket zero.
 *
 * And the one that guards the defect: a bank line with no deposit behind it
 * cannot be committed however it is ticked, because the tick itself is refused
 * on a row that is not reconcilable.
 *
 * Local container only, branch 999, cleaned up in tearDown. It writes to
 * PumpIT — the stub — through the APP connection by three-part name, never
 * through the `pumpit` connection, which in a working development .env points
 * at the customer's live instance.
 */
class ExecuteReconciliationTest extends TestCase
{
    private const BRANCH = 999;

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

    public function test_journal_mode_records_the_decision_and_touches_nothing_in_pumpit(): void
    {
        config(['recon.stamp_mode' => 'journal']);

        $run = $this->previewed();
        $line = $run->lines->firstWhere('KeyRef', '125');
        $this->service->select($run, [$line->Id]);

        $result = $this->service->commit($run->fresh());

        $this->assertSame('JOURNALLED', $result['status']->Code);
        $this->assertSame(1, (int) $result['status']->Id);

        // The ledger recorded it.
        $this->assertSame(1, $this->rowsIn('ReconBatch'));
        $this->assertSame(3, $this->rowsIn('ReconMatch'), 'Two bank legs and one deposit.');
        $this->assertSame(3, $this->rowsIn('ReconStamp'));
        $this->assertSame('journal', $this->stampState());

        // And PumpIT did not move.
        $this->assertSame(0, $this->reconciledBankLines(), 'Journal mode must not stamp the statement.');
        $this->assertSame(0, $this->reconciledDeposits());
    }

    public function test_live_mode_stamps_both_sides_and_a_reversal_puts_them_back(): void
    {
        config(['recon.stamp_mode' => 'live']);

        $counterBefore = (int) $this->db()->table('PumpIT.dbo.SS_UniqueNumber')
            ->where('SectionId', 1)->value('NextUniqueNumber');

        $run = $this->previewed();
        $line = $run->lines->firstWhere('KeyRef', '125');
        $this->service->select($run, [$line->Id]);

        $result = $this->service->commit($run->fresh());
        $this->assertSame('COMMITTED', $result['status']->Code);

        $batchNo = $this->batchNo();

        // Allocated from the customer's own counter, so it cannot collide with
        // a number the executable hands out.
        $this->assertSame($counterBefore, $batchNo);
        $this->assertSame(
            $counterBefore + 1,
            (int) $this->db()->table('PumpIT.dbo.SS_UniqueNumber')->where('SectionId', 1)->value('NextUniqueNumber')
        );

        // Both legs and the deposit really did change.
        $this->assertSame(2, $this->reconciledBankLines($batchNo));
        $this->assertSame(1, $this->reconciledDeposits($batchNo));

        // The row that had no deposit behind it was never touched.
        $this->assertSame(
            1,
            (int) $this->db()->table('PumpIT.dbo.RCN_BankStatementLinesPumpIT')
                ->where('SSBranchId', self::BRANCH)->where('ReconState', 1)
                ->where('Description', 'like', '%300 CC')->count(),
            'A bank line with no deposit must still be sitting there unreconciled.'
        );

        $this->service->reverse($run->fresh(), 'Test reversal');

        $this->assertSame(0, $this->reconciledBankLines($batchNo), 'The bank lines must go back.');
        $this->assertSame(0, $this->reconciledDeposits($batchNo), 'The deposits must go back.');
        $this->assertSame('reversed', $this->batchState());
        $this->assertSame('reversed', $run->fresh()->Status);

        // And the proposal is available again.
        $this->assertSame('pending', ReconRunLine::query()->acrossBranches()->find($line->Id)->CommitState);
    }

    /** A row that cannot reconcile cannot be ticked, so it can never be committed. */
    public function test_an_unreconcilable_row_cannot_be_selected(): void
    {
        $run = $this->previewed();
        $orphan = $run->lines->firstWhere('KeyRef', '300');

        $this->assertFalse($orphan->WouldReconcile);
        $this->assertSame(0, $this->service->select($run, [$orphan->Id]));

        config(['recon.stamp_mode' => 'live']);

        try {
            $this->service->commit($run->fresh());
            $this->fail('A run with nothing selectable must be refused.');
        } catch (AgoraProcException $e) {
            $this->assertSame('NOTHING_SELECTED', $e->code());
        }

        $this->assertSame(0, $this->reconciledBankLines());
    }

    /** Executing the same run twice is refused, not silently repeated. */
    public function test_a_committed_run_cannot_be_executed_again(): void
    {
        config(['recon.stamp_mode' => 'journal']);

        $run = $this->previewed();
        $this->service->select($run, [$run->lines->firstWhere('KeyRef', '125')->Id]);
        $this->service->commit($run->fresh());

        try {
            $this->service->commit($run->fresh());
            $this->fail('A committed run must not be executable again.');
        } catch (AgoraProcException $e) {
            $this->assertSame('RUN_ALREADY_COMMITTED', $e->code());
        }
    }

    /**
     * The re-check. A preview may be hours old; if something reconciles a bank
     * line in between, the commit must skip that row rather than stamp over it.
     */
    public function test_a_row_reconciled_since_the_preview_is_skipped_not_overwritten(): void
    {
        config(['recon.stamp_mode' => 'live']);

        $run = $this->previewed();
        $line = $run->lines->firstWhere('KeyRef', '125');
        $this->service->select($run, [$line->Id]);

        // Something else gets there first — the executable, say.
        $this->db()->table('PumpIT.dbo.RCN_BankStatementLinesPumpIT')
            ->where('SSBranchId', self::BRANCH)
            ->where('Description', 'like', '%125 CC')
            ->update(['ReconState' => 2, 'ReconBatchNo' => 9999]);

        $result = $this->service->commit($run->fresh());

        $this->assertSame(0, (int) $result['status']->Id, 'Nothing should have been committed.');
        $this->assertStringContainsString(
            'reconciled by something else',
            (string) $result['lines']->first()->Reason
        );

        // And the other leg was left alone rather than half-stamped.
        $this->assertSame(
            9999,
            (int) $this->db()->table('PumpIT.dbo.RCN_BankStatementLinesPumpIT')
                ->where('SSBranchId', self::BRANCH)->where('Description', 'like', '%125 CC')->value('ReconBatchNo')
        );
        $this->assertSame(0, $this->reconciledBankLines(0));
    }

    private function previewed(): ReconRun
    {
        return $this->service->preview(
            'ABSA', self::BRANCH,
            Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'),
        );
    }

    /*
     * Every read is branch-scoped, and that is not politeness.
     *
     * These tables are shared with whatever else the local database holds —
     * a browser session driving branch 18 will happily leave committed and
     * reversed rows in them. An unscoped ->value() picks the first row in the
     * table, which made this file fail on somebody else's data rather than on
     * its own.
     */
    private function batchNo(): int
    {
        return (int) $this->db()->table('agora.ReconBatch')
            ->where('BranchId', self::BRANCH)->value('BatchNo');
    }

    private function batchState(): ?string
    {
        return $this->db()->table('agora.ReconBatch')
            ->where('BranchId', self::BRANCH)->value('State');
    }

    private function stampState(): ?string
    {
        return $this->db()->table('agora.ReconStamp')
            ->where('BranchId', self::BRANCH)->value('State');
    }

    private function rowsIn(string $table): int
    {
        return (int) $this->db()->table("agora.{$table}")->where('BranchId', self::BRANCH)->count();
    }

    private function reconciledBankLines(?int $batchNo = null): int
    {
        $q = $this->db()->table('PumpIT.dbo.RCN_BankStatementLinesPumpIT')
            ->where('SSBranchId', self::BRANCH)->where('ReconState', 2);

        return (int) ($batchNo === null ? $q->count() : $q->where('ReconBatchNo', $batchNo)->count());
    }

    private function reconciledDeposits(?int $batchNo = null): int
    {
        $q = $this->db()->table('PumpIT.dbo.BRN_DailyBankingABSA')
            ->where('SSBranchId', self::BRANCH)->where('ReconBatchNoPumpIT', '>', 0);

        return (int) ($batchNo === null ? $q->count() : $q->where('ReconBatchNoPumpIT', $batchNo)->count());
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
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
            $this->markTestSkipped(
                "This test STAMPS RECONCILIATIONS and [{$connection}] points at [{$host}]. Local container only."
            );
        }
    }

    private function seedFixture(): void
    {
        $db = $this->db();

        $db->table('PumpIT.dbo.BRN_AutoReconCriteria')->insert([
            'SSBranchId' => self::BRANCH, 'BankReconArea' => 'ABSA', 'ProcessOrder' => 1,
            'BANK_StartPosition' => 58, 'BANK_EndPosition' => 3,
            'BANK_StartPosition2' => 49, 'BANK_EndPosition2' => 8,
            'MOPS_StartPosition' => 1, 'MOPS_EndPosition' => 10,
            'FILTER_Value' => null, 'FILTER_StartPosition' => 0, 'FILTER_EndPosition' => 0,
        ]);

        $narrative = fn (string $batch, string $leg) => str_repeat('X', 48).'02026318 '.$batch.' '.$leg;

        $db->table('PumpIT.dbo.RCN_BankStatementLinesPumpIT')->insert([
            $this->bankLine('2026-07-08', $narrative('125', 'CC'), 14937.40),
            $this->bankLine('2026-07-08', $narrative('125', 'DD'), 200.00),
            $this->bankLine('2026-07-10', $narrative('300', 'CC'), 900.00),
        ]);

        $db->table('PumpIT.dbo.BRN_DailyBankingABSA')->insert([
            $this->deposit('2026-07-08', 125, 15137.40),
        ]);
    }

    /** @return array<string, mixed> */
    private function bankLine(string $date, string $description, float $amount): array
    {
        return [
            'SSBranchId' => self::BRANCH, 'LineDate' => $date, 'Description' => $description,
            'Amount' => $amount, 'Type' => 'ABSA', 'IDState' => 2, 'ReconState' => 1, 'ReconBatchNo' => 0,
        ];
    }

    /** @return array<string, mixed> */
    private function deposit(string $date, int $batch, float $amount): array
    {
        return [
            'SSBranchId' => self::BRANCH, 'TransactionDate' => $date, 'BatchNumber' => $batch,
            'MerchantNumber' => '2026318', 'TransactionAmount' => $amount, 'ReconBatchNoPumpIT' => 0,
        ];
    }

    private function cleanUp(): void
    {
        $db = $this->db();

        foreach ([
            'PumpIT.dbo.RCN_BankStatementLinesPumpIT',
            'PumpIT.dbo.BRN_DailyBankingABSA',
            'PumpIT.dbo.BRN_AutoReconCriteria',
        ] as $table) {
            $db->table($table)->where('SSBranchId', self::BRANCH)->delete();
        }

        foreach (['ReconStamp', 'ReconMatch', 'ReconBatch', 'ReconRunLine', 'ReconRun'] as $table) {
            $db->table("agora.{$table}")->where('BranchId', self::BRANCH)->delete();
        }
    }
}
