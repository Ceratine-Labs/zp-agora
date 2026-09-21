<?php

namespace Tests\Feature\Recon;

use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Recon\Services\ReconService;
use Tests\TestCase;

/**
 * Every area's commit actually stamps.
 *
 * WHY THIS FILE EXISTS. On 16 September 2026 Smart ATM was found never to have
 * committed a batch — not once, on any site. It did not fail: it allocated no
 * batch, wrote nothing, reported "posted", and blamed the customer's data. The
 * whole module's test suite passed throughout, because every test asked whether
 * the PREVIEW was right and none asked whether the COMMIT had written anything.
 *
 * The defect class is general: **the commit re-finds a batch by something other
 * than the key the preview grouped it by.** Smart ATM's instance of it was a
 * window that described the deposit side being used to re-find the bank side.
 * Ryan asked for the same question put to the rest (16 Sep 2026), so this is
 * one test per remaining area, each asserting the same three things — a batch
 * exists, the statement moved, the deposit moved.
 *
 * ABSA already has this in ExecuteReconciliationTest and SmartATM in
 * SmartAtmCommitTest, so neither is repeated here.
 *
 * Every fixture is deliberately the SIMPLEST shape that reconciles, because the
 * question is not "does the matching logic work" — that is covered elsewhere —
 * but "when it says it wrote something, did it".
 */
class CommitStampsEveryAreaTest extends TestCase
{
    private const BRANCH = 999;

    private ReconService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessLocalStub();
        $this->service = new ReconService(new ProcedureService);
        config(['recon.stamp_mode' => 'live']);
        $this->cleanUp();
        $this->actingAs($this->auditor());
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->cleanUp();
        }

        parent::tearDown();
    }

    /** FNB, batched population — matched on batch number and merchant. */
    public function test_fnb_commits_and_stamps_both_sides(): void
    {
        $db = $this->db();

        // The documented batched shape — merchant at (33,6), batch as the
        // trailing token at (40,3). The suffix is not 'FN', so the line is
        // batched rather than standalone.
        $this->criteria('FNB', bankStart: 40, bankLen: 3, bankStart2: 33, bankLen2: 6, mopsStart: 1, mopsLen: 10);

        $db->table('PumpIT.dbo.RCN_BankStatementLinesPumpIT')->insert([
            'SSBranchId' => self::BRANCH, 'LineDate' => '2026-07-08',
            'Description' => 'SETTLEMENT ACB CREDIT SPEEDPOINT850250 203',
            'Amount' => 5200.00, 'Type' => 'FNB', 'IDState' => 2, 'ReconState' => 1, 'ReconBatchNo' => 0,
        ]);

        $db->table('PumpIT.dbo.BRN_DailyBankingFNB')->insert([
            'SSBranchId' => self::BRANCH, 'TransactionDate' => '2026-07-08',
            'BatchNo' => '203', 'MerchantNo' => '850250',
            'Amount' => 5200.00, 'ReconBatchNoPumpIT' => 0,
        ]);

        $this->assertStamps('FNB', 'PumpIT.dbo.BRN_DailyBankingFNB');
    }

    /** CashMachine — the one area whose two sides are named differently. */
    public function test_cash_machine_commits_and_stamps_both_sides(): void
    {
        $db = $this->db();

        // The slice matched on is SlipNo characters 2..6; the STAMP has to
        // address the whole slip, which is the distinction that would break if
        // the commit addressed the slice instead.
        $this->criteria('CashMachine', bankStart: 21, bankLen: 5, bankStart2: 0, bankLen2: 0, mopsStart: 2, mopsLen: 5);

        $db->table('PumpIT.dbo.RCN_BankStatementLinesPumpIT')->insert([
            'SSBranchId' => self::BRANCH, 'LineDate' => '2026-07-08',
            'Description' => 'CASH MACHINE DEP    83104',
            'Amount' => 3100.00, 'Type' => 'CashMachine', 'IDState' => 2, 'ReconState' => 1, 'ReconBatchNo' => 0,
        ]);

        $db->table('PumpIT.dbo.BRN_DailyBankingDeposita')->insert([
            'SSBranchId' => self::BRANCH, 'TransactionDate' => '2026-07-08',
            'SlipNo' => 'D831041234', 'DepositaAmount' => 3100.00, 'ReconBatchNoPumpIT' => 0,
        ]);

        $this->assertStamps('CashMachine', 'PumpIT.dbo.BRN_DailyBankingDeposita');
    }

    /** CashBags — stamped by its own id, the tightest of the five. */
    public function test_cash_bags_commits_and_stamps_both_sides(): void
    {
        $db = $this->db();

        // A bag number is a LONG DIGIT RUN — @MinBagKeyLen defaults to 11, and
        // 'contains' refuses anything shorter rather than matching a stray
        // four-digit fragment of a narrative. A seven-character fixture is
        // silently ignored, which is worth knowing before writing one.
        $this->criteria('CashBags', bankStart: 18, bankLen: 11, bankStart2: 0, bankLen2: 0, mopsStart: 1, mopsLen: 20);

        $db->table('PumpIT.dbo.RCN_BankStatementLinesPumpIT')->insert([
            'SSBranchId' => self::BRANCH, 'LineDate' => '2026-07-08',
            'Description' => 'CASH BAG COLLECT 10000044170',
            'Amount' => 7400.00, 'Type' => 'CashDeposit', 'IDState' => 2, 'ReconState' => 1, 'ReconBatchNo' => 0,
        ]);

        // No drop-safe collection behind it, so the bag falls back to its own
        // number as the reference — the live procedure's own fallback.
        $db->table('PumpIT.dbo.BRN_DailyBankingCashBags')->insert([
            'SSBranchId' => self::BRANCH, 'TransactionDate' => '2026-07-08',
            'CashBagNo' => '10000044170', 'CashBagAmount' => 7400.00, 'ReconBatchNoPumpIT' => 0,
        ]);

        $this->assertStamps('CashBags', 'PumpIT.dbo.BRN_DailyBankingCashBags');
    }

    /**
     * Preview, tick, commit — then look at the estate rather than at the
     * procedure's own account of itself. The Smart ATM defect was invisible
     * precisely because the status said COMMITTED either way.
     */
    private function assertStamps(string $area, string $depositTable): void
    {
        $run = $this->service->preview(
            $area, self::BRANCH, Carbon::parse('2026-07-01'), Carbon::parse('2026-07-31'),
        );

        $line = $run->lines->firstWhere('WouldReconcile', true);

        $this->assertNotNull($line,
            "{$area}: the fixture proposed nothing, so the commit assertions below would prove nothing.");

        $this->assertSame(1, $this->service->select($run->fresh(), [$line->Id]),
            "{$area}: the row would not tick.");

        $this->service->commit($run->fresh());

        $this->assertGreaterThan(0, (int) $run->fresh()->CommittedRows,
            "{$area}: the commit reported success and stamped nothing — the Smart ATM defect, in another area.");

        $this->assertSame(1, (int) $this->db()->table('agora.ReconBatch')
            ->where('BranchId', self::BRANCH)->count(),
            "{$area}: no batch was allocated.");

        $this->assertSame(0, (int) $this->db()->table('PumpIT.dbo.RCN_BankStatementLinesPumpIT')
            ->where('SSBranchId', self::BRANCH)->where('ReconState', 1)->count(),
            "{$area}: the bank line was not reconciled.");

        $this->assertSame(0, (int) $this->db()->table($depositTable)
            ->where('SSBranchId', self::BRANCH)->where('ReconBatchNoPumpIT', 0)->count(),
            "{$area}: the deposit was not stamped with the batch number.");
    }

    private function criteria(string $area, int $bankStart, int $bankLen, int $bankStart2,
        int $bankLen2, int $mopsStart, int $mopsLen): void
    {
        $this->db()->table('PumpIT.dbo.BRN_AutoReconCriteria')->insert([
            'SSBranchId' => self::BRANCH, 'BankReconArea' => $area, 'ProcessOrder' => 1,
            'BANK_StartPosition' => $bankStart, 'BANK_EndPosition' => $bankLen,
            'BANK_StartPosition2' => $bankStart2, 'BANK_EndPosition2' => $bankLen2,
            'MOPS_StartPosition' => $mopsStart, 'MOPS_EndPosition' => $mopsLen,
            'FILTER_Value' => null, 'FILTER_StartPosition' => 0, 'FILTER_EndPosition' => 0,
        ]);
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

    private function cleanUp(): void
    {
        $db = $this->db();

        foreach ([
            'PumpIT.dbo.RCN_BankStatementLinesPumpIT',
            'PumpIT.dbo.BRN_DailyBankingFNB',
            'PumpIT.dbo.BRN_DailyBankingDeposita',
            'PumpIT.dbo.BRN_DailyBankingCashBags',
            'PumpIT.dbo.BRN_AutoReconCriteria',
        ] as $table) {
            $db->table($table)->where('SSBranchId', self::BRANCH)->delete();
        }

        foreach (['ReconStamp', 'ReconMatch', 'ReconBatch', 'ReconRunLine', 'ReconRun'] as $table) {
            $db->table("agora.{$table}")->where('BranchId', self::BRANCH)->delete();
        }
    }
}
