<?php

namespace Tests\Feature\Recon;

use App\Support\ProcedureService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Recon\Models\ReconRun;
use Modules\Recon\Models\ReconRunLine;
use Modules\Recon\Services\ReconService;
use Tests\TestCase;

/**
 * SmartATM is dated by the deposit, not by the cashup.
 *
 * `BRN_DailyBankingSmartATM.DepositDateTime` is the trading day a deposit was
 * FILED under — midnight on every row, because it is a day rather than a
 * moment. `BRN_SmartATM.DepositDateTime` is when the money actually went into
 * the machine: 17:28, 19:17, 07:49.
 *
 * On the customer's instance those disagree on 17.5% of rows over three
 * months, by anything from 366 hours before to 212 hours after. Scoping a
 * preview on the filing date therefore pulled in a different set of deposits
 * from the one the operator asked for, and the bank settles against when the
 * money went in.
 *
 * This is the ONE place the module's logic deliberately differs from the
 * procedure ZP validated (Ryan, 4 September 2026), which is why it has a test
 * to itself: a silent revert would look like a rounding difference.
 */
class SmartAtmDepositDateTest extends TestCase
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

    /**
     * The deposit is FILED on 31 August and went in at 07:49 on 1 September.
     * A September preview must see it; an August one must not.
     */
    public function test_a_deposit_is_in_scope_by_when_it_went_in_not_when_it_was_filed(): void
    {
        $september = $this->preview('2026-09-01', '2026-09-30');

        $this->assertSame(
            1,
            $september->lines->sum('MopsTxns'),
            'It went in on 1 September, so September is where it belongs.'
        );

        $august = $this->preview('2026-08-01', '2026-08-31');

        $this->assertSame(
            0,
            $august->lines->sum('MopsTxns'),
            'Its cashup row says 31 August. Scoping on that is the behaviour this change removed.'
        );
    }

    /**
     * A deposit with no device row must fall back to the filing date rather
     * than disappear. There are none on the customer's instance today — and a
     * row that silently vanished would be the worst way to find the first one.
     */
    public function test_a_deposit_with_no_device_row_falls_back_rather_than_vanishing(): void
    {
        DB::connection(config('agora.connections.app'))
            ->table('PumpIT.dbo.BRN_SmartATM')->where('SSBranchId', self::BRANCH)->delete();

        $run = $this->preview('2026-08-01', '2026-08-31');

        $this->assertSame(
            1,
            $run->lines->sum('MopsTxns'),
            'With no device timestamp it is dated by its cashup row, and that says 31 August.'
        );
    }

    private function preview(string $from, string $to): ReconRun
    {
        return $this->service->preview(
            'SmartATM', self::BRANCH, Carbon::parse($from), Carbon::parse($to),
        );
    }

    private function seedFixture(): void
    {
        $db = DB::connection(config('agora.connections.app'));

        $db->table('PumpIT.dbo.BRN_AutoReconCriteria')->insert([
            'SSBranchId' => self::BRANCH, 'BankReconArea' => 'SmartATM', 'ProcessOrder' => 1,
            'BANK_StartPosition' => 23, 'BANK_EndPosition' => 8,
            'BANK_StartPosition2' => 0, 'BANK_EndPosition2' => 0,
            'MOPS_StartPosition' => 1, 'MOPS_EndPosition' => 8,
            'FILTER_Value' => null, 'FILTER_StartPosition' => 0, 'FILTER_EndPosition' => 0,
        ]);

        // Filed to the 31st at midnight; actually deposited at 07:49 the next
        // morning. This is the real shape — see the class docblock.
        $db->table('PumpIT.dbo.BRN_DailyBankingSmartATM')->insert([
            'SSBranchId' => self::BRANCH, 'TerminalId' => 'ATMH0279',
            'TraceNo' => 'TR900001', 'UniqueNo' => 900001,
            'DepositDateTime' => '2026-08-31 00:00:00',
            'Deposited' => 1400.00, 'ReconBatchNoPumpIT' => 0,
        ]);

        $db->table('PumpIT.dbo.BRN_SmartATM')->insert([
            'SSBranchId' => self::BRANCH, 'TerminalId' => 'ATMH0279',
            'TraceNo' => 'TR900001', 'UniqueNo' => 900001,
            'DepositDateTime' => '2026-09-01 07:49:00',
        ]);
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
