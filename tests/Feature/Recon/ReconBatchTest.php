<?php

namespace Tests\Feature\Recon;

use App\Exceptions\AgoraProcException;
use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Modules\Cash\Services\StaffShortService;
use Modules\Core\Models\User;
use Tests\TestCase;

class ReconBatchTest extends TestCase
{
    private const BRANCH = 994;

    private const FROM = '2026-09-15';

    private const TO = '2026-09-25';

    private ProcedureService $procedures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessLocalStub();
        $this->procedures = app(ProcedureService::class);
        $this->cleanUp();
        $this->mock(StaffShortService::class)->shouldReceive('sendPending')->andReturnNull();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->cleanUp();
        }
        parent::tearDown();
    }

    /** @return array{object, object, int, int} */
    private function twoBatches(): array
    {
        $first = $this->makeBatch('TEST-A3-B1', 100);
        $second = $this->makeBatch('TEST-A3-B2', 200);
        // One automatic run may own many batches. Model that ledger shape.
        $batch = $this->db()->table('agora.ReconBatch')->where('RunId', $second->Id)->value('Id');
        $this->db()->table('agora.ReconBatch')->where('Id', $batch)->update(['RunId' => $first->Id]);
        $this->db()->table('agora.ReconMatch')->where('BatchId', $batch)->update(['RunId' => $first->Id]);
        $this->db()->table('agora.ReconStamp')->where('RunId', $second->Id)->update(['RunId' => $first->Id]);

        return [$first, $second, (int) $this->db()->table('agora.ReconBatch')->where('BatchNo', $first->BatchNo)->where('BranchId', self::BRANCH)->value('Id'), (int) $batch];
    }

    private function makeBatch(string $key, float $amount): object
    {
        $bag = $this->bag($key, $amount, null);
        $bank = $this->bankLine($amount);

        return $this->procedures->write('usp_Recon_ManualMatch', [
            'BranchId' => self::BRANCH, 'ReconArea' => 'CashBags', 'FromDate' => self::FROM, 'ToDate' => self::TO,
            'BankLineIds' => (string) $bank, 'MopsJson' => json_encode([$bag]), 'StampMode' => 'live',
            'AllocationJson' => json_encode([['code' => null, 'banked' => (string) $amount, 'share' => '0']]),
        ]);
    }

    public function test_preview_then_signed_confirmation_reverses_only_the_named_batch_in_pumpit(): void
    {
        [$first, $second, $firstId, $secondId] = $this->twoBatches();
        $url = route('app.recon.batch', ['CashBags', 'branch_id' => self::BRANCH, 'batch_no' => $first->BatchNo]);
        $response = $this->actingAs($this->admin())->get($url)->assertOk()
            ->assertSee('Batch '.$first->BatchNo)->assertSee('TEST-A3-B1')->assertDontSee('TEST-A3-B2')
            ->assertSee('data-confirm=', false);
        $this->assertSame('committed', $this->db()->table('agora.ReconBatch')->where('Id', $firstId)->value('State'));
        $signed = URL::temporarySignedRoute('app.recon.batch.reverse', now()->addMinutes(30),
            ['CashBags', 'branch_id' => self::BRANCH, 'batch_id' => $firstId, 'batch_no' => $first->BatchNo]);
        $this->post($signed, ['reason' => 'TEST-A3 one batch only', 'batch_id' => $secondId])->assertRedirect($url);
        $this->assertSame('reversed', $this->db()->table('agora.ReconBatch')->where('Id', $firstId)->value('State'));
        $this->assertSame('committed', $this->db()->table('agora.ReconBatch')->where('Id', $secondId)->value('State'));
        $this->assertEquals(0, $this->db()->table('PumpIT.dbo.BRN_DailyBankingCashBags')->where('SSBranchId', self::BRANCH)->where('CashBagNo', 'TEST-A3-B1')->value('ReconBatchNoPumpIT'));
        $this->assertEquals($second->BatchNo, $this->db()->table('PumpIT.dbo.BRN_DailyBankingCashBags')->where('SSBranchId', self::BRANCH)->where('CashBagNo', 'TEST-A3-B2')->value('ReconBatchNoPumpIT'));
        $this->assertEquals(1, $this->db()->table('PumpIT.dbo.RCN_BankStatementLinesPumpIT')->where('SSBranchId', self::BRANCH)->where('ReconState', 2)->count());
        $this->assertSame(['applied'], $this->db()->table('agora.ReconStamp')->where('BranchId', self::BRANCH)->where('BatchNo', $second->BatchNo)->pluck('State')->unique()->values()->all());
    }

    public function test_unknown_wrong_area_and_unsigned_batch_reversal_are_refused(): void
    {
        [$first, $second, $firstId] = $this->twoBatches();
        $this->assertRefused('BATCH_NOT_FOUND', 'PumpIT-only', fn () => $this->procedures->callSets('usp_Recon_GetBatch', ['BranchId' => self::BRANCH, 'ReconArea' => 'FNB', 'BatchNo' => $first->BatchNo]));
        $this->assertRefused('BATCH_NOT_FOUND', '', fn () => $this->procedures->write('usp_Recon_Reverse', ['BranchId' => self::BRANCH, 'BatchId' => $firstId, 'ExpectedBatchNo' => $second->BatchNo, 'Reason' => 'TEST-A3 wrong target']));
        $this->actingAs($this->admin())->post(route('app.recon.batch.reverse', ['CashBags', 'branch_id' => self::BRANCH, 'batch_id' => $firstId, 'batch_no' => $first->BatchNo]), ['reason' => 'TEST-A3 no preview'])->assertForbidden();
        $this->assertSame('committed', $this->db()->table('agora.ReconBatch')->where('Id', $firstId)->value('State'));
    }

    public function test_run_number_and_status_filters_limit_the_returned_rows(): void
    {
        [$first, $second] = $this->twoBatches();
        $this->db()->table('agora.ReconRun')->where('Id', $second->Id)->update(['Status' => 'previewed']);
        $base = ['BranchIds' => (string) self::BRANCH, 'MineOnly' => 0, 'OpenOnly' => 0];
        $sets = $this->procedures->callSets('usp_Recon_GridRuns', $base + ['FiltersJson' => json_encode([['column' => 'Id', 'op' => 'eq', 'value' => (string) $first->Id]])]);
        $this->assertSame([(int) $first->Id], $sets[0]->pluck('Id')->map(fn ($id) => (int) $id)->all());
        $sets = $this->procedures->callSets('usp_Recon_GridRuns', $base + ['FiltersJson' => json_encode([['column' => 'Status', 'type' => 'set', 'in' => ['committed']]])]);
        $this->assertSame([(int) $first->Id], $sets[0]->pluck('Id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame(['committed'], $sets[0]->pluck('Status')->unique()->all());
    }

    private function assertRefused(string $code, string $says, callable $attempt): void
    {
        try {
            $attempt();
            $this->fail("Expected {$code}; the match went through.");
        } catch (AgoraProcException $e) {
            $this->assertSame($code, $e->code());
            $this->assertStringContainsString($says, $e->getMessage());
        }
    }

    /**
     * A bag, its drop-safe row when it has a cashier, and the deposit as the
     * screen sends it.
     *
     * @return array{id: int, key: string, dt: string, amt: float}
     */
    private function bag(string $bagNo, float $amount, ?string $cashier, ?float $dropSafe = null): array
    {
        $id = (int) $this->db()->table('PumpIT.dbo.BRN_DailyBankingCashBags')->insertGetId([
            'SSBranchId' => self::BRANCH, 'TransactionDate' => '2026-09-20',
            'CashBagNo' => $bagNo, 'CashBagAmount' => $amount, 'ReconBatchNoPumpIT' => 0,
        ]);

        if ($cashier !== null) {
            $this->db()->table('PumpIT.dbo.BRN_DropSafe')->insert([
                'SSBranchId' => self::BRANCH, 'BagNo' => $bagNo, 'CollectionId' => 0,
                'CashierCode' => $cashier, 'Amount' => $dropSafe ?? $amount,
                'DropDate' => '2026-09-20', 'CreateDateTime' => '2026-09-20 08:00',
            ]);
        }

        return ['id' => $id, 'key' => $bagNo, 'dt' => '2026-09-20', 'amt' => $amount];
    }

    private function bankLine(float $amount): int
    {
        return (int) $this->db()->table('PumpIT.dbo.RCN_BankStatementLinesPumpIT')->insertGetId([
            'SSBranchId' => self::BRANCH, 'LineDate' => '2026-09-21', 'Description' => 'TEST-A3 CASH DEPOSIT',
            'Amount' => $amount, 'Type' => 'CashDeposit', 'IDState' => 2, 'ReconState' => 1, 'ReconBatchNo' => 0,
        ]);
    }

    private function admin(): User
    {
        $user = User::query()->acrossBranches()->where('EmailAddress', 'ryan@revvtech.co.za')->first();

        if (! $user) {
            $this->markTestSkipped('No seeded administrator — run db:seed first.');
        }

        return $user;
    }

    private function cleanUp(): void
    {
        $db = $this->db();

        foreach (['ReconAllocation', 'ReconStamp', 'ReconMatch', 'ReconBatch', 'ReconRunLine', 'ReconRun'] as $table) {
            $db->table("agora.{$table}")->where('BranchId', self::BRANCH)->delete();
        }

        $db->table('PumpIT.dbo.RCN_BankStatementLinesPumpIT')->where('SSBranchId', self::BRANCH)
            ->where('Description', 'like', 'TEST-A3%')->delete();
        $db->table('PumpIT.dbo.BRN_DailyBankingCashBags')->where('SSBranchId', self::BRANCH)
            ->where('CashBagNo', 'like', 'TEST-A3-%')->delete();
        $db->table('PumpIT.dbo.BRN_DropSafe')->where('SSBranchId', self::BRANCH)
            ->where('BagNo', 'like', 'TEST-A3-%')->delete();
        $db->table('PumpIT.dbo.BRN_Employee')->where('SSBranchId', self::BRANCH)
            ->where('EmployeeCode', 'like', 'TEST-A3%')->delete();
        $db->table('PumpIT.dbo.BRN_DailyBankingABSA')->where('SSBranchId', self::BRANCH)
            ->where('MerchantNumber', 'TEST-A3')->delete();
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }
}
