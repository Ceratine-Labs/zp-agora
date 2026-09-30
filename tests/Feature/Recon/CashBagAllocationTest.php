<?php

namespace Tests\Feature\Recon;

use App\Exceptions\AgoraProcException;
use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Tests\TestCase;

/**
 * A cash-bag match says who the cash is accounted to (task 01a084fd-0674).
 *
 * A bag belongs to the cashier who dropped it, so usp_Recon_ManualMatch now
 * takes, on CashBags and nowhere else, the split by cashier the clerk
 * confirmed — and refuses one that is not the split these bags give or does
 * not add up. Ryan's rules of 9 Sep 2026: the split is on CashBagAmount with
 * the drop-safe amount shown beside it (q1); a bank-vs-bags variance is shared
 * pro rata by what each banked (q2); CashBags only (q4); a split that does not
 * add up is ALLOCATION_INCOMPLETE (q5).
 *
 * The split is proved here through what the procedure ACCEPTS (what it
 * KEEPS, agora.ReconAllocation, is ReconAllocationTest's):
 * the exact derived shares go through as "Split between N cashiers." and a
 * split one cent away from them, still adding up, goes through as "changed by
 * hand" — so the procedure's own derivation is pinned to the cent, remainder
 * included.
 *
 * Writes into the local PumpIT stub only (skipUnlessLocalStub), at branch 996,
 * which no other test uses, and removes only the TEST-A3 rows it made and the
 * ledger rows at that branch. Journal mode throughout, except the refusal
 * that proves the customer's batch counter does not move.
 */
class CashBagAllocationTest extends TestCase
{
    private const BRANCH = 996;

    private const FROM = '2026-09-15';

    private const TO = '2026-09-25';

    private ProcedureService $procedures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessLocalStub();
        $this->procedures = app(ProcedureService::class);
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->cleanUp();
        }

        parent::tearDown();
    }

    /** One cashier: named, and carrying the whole variance. */
    public function test_one_cashier_is_named_and_carries_the_whole_variance(): void
    {
        $this->employee('TEST-A3C1', 'Nokuthula', 'TEST-A3 Champion');
        $bags = [
            $this->bag('TEST-A3-B1', 640.00, 'TEST-A3C1'),
            $this->bag('TEST-A3-B2', 360.00, 'TEST-A3C1'),
        ];
        $bank = $this->bankLine(990.00);

        $status = $this->match([$bank], $bags, [
            ['code' => 'TEST-A3C1', 'banked' => '1000.00', 'share' => '-10.00'],
        ], 'TEST-A3 bank short by R10');

        $this->assertSame('MATCHED_FORCED', $status->Code);
        $this->assertStringEndsWith(
            'Accounted to TEST-A3 Champion Nokuthula (TEST-A3C1).',
            $status->Message,
            'One cashier is named in what the clerk reads next.'
        );
    }

    /**
     * Three cashiers and a variance that does not divide: R500, R300 and R200
     * banked, the bank R9.99 short. Pro rata in whole cents, half away from
     * zero, gives -5.00, -3.00, -2.00 = -10.00 — a cent too many — so the cent
     * goes back on the line that banked most: -4.99, -3.00, -2.00.
     */
    public function test_three_cashiers_share_the_variance_pro_rata_with_the_remainder_on_the_largest(): void
    {
        $this->employee('TEST-A3C1', 'Ayanda', 'TEST-A3 Largest');
        $this->employee('TEST-A3C2', 'Bongani', 'TEST-A3 Middle');
        $this->employee('TEST-A3C3', 'Cebo', 'TEST-A3 Smallest');
        $bags = [
            // Declared R520 at the safe and banked as R500: the split is on
            // the R500 (q1), whatever the safe said.
            $this->bag('TEST-A3-B1', 500.00, 'TEST-A3C1', dropSafe: 520.00),
            $this->bag('TEST-A3-B2', 300.00, 'TEST-A3C2'),
            $this->bag('TEST-A3-B3', 120.00, 'TEST-A3C3'),
            $this->bag('TEST-A3-B4', 80.00, 'TEST-A3C3'),
        ];
        $bank = $this->bankLine(990.01);
        $reason = 'TEST-A3 short banking';

        // Rounded without the remainder: adds to -10.00, the variance is -9.99.
        $this->assertRefused('ALLOCATION_INCOMPLETE', 'does not add up', fn () => $this->match([$bank], $bags, [
            ['code' => 'TEST-A3C1', 'banked' => '500.00', 'share' => '-5.00'],
            ['code' => 'TEST-A3C2', 'banked' => '300.00', 'share' => '-3.00'],
            ['code' => 'TEST-A3C3', 'banked' => '200.00', 'share' => '-2.00'],
        ], $reason));

        // A banked total the bags do not give — the drop-safe R520 in place of
        // the banked R500 — is a split of different bags.
        $this->assertRefused('ALLOCATION_INCOMPLETE', 'not the split these bags give', fn () => $this->match([$bank], $bags, [
            ['code' => 'TEST-A3C1', 'banked' => '520.00', 'share' => '-4.99'],
            ['code' => 'TEST-A3C2', 'banked' => '300.00', 'share' => '-3.00'],
            ['code' => 'TEST-A3C3', 'banked' => '200.00', 'share' => '-2.00'],
        ], $reason));

        $this->assertNothingWritten();

        // The derived split, exactly: accepted, and not "changed by hand".
        $derived = $this->match([$bank], $bags, [
            ['code' => 'TEST-A3C3', 'banked' => '200.00', 'share' => '-2.00'],
            ['code' => 'TEST-A3C1', 'banked' => '500.00', 'share' => '-4.99'],
            ['code' => 'TEST-A3C2', 'banked' => '300.00', 'share' => '-3.00'],
        ], $reason);

        $this->assertSame('MATCHED_FORCED', $derived->Code);
        $this->assertStringEndsWith('Split between 3 cashiers.', $derived->Message);

        // A cent moved between two lines still adds up, so it is accepted —
        // and the procedure knows it is not its own split.
        $byHand = $this->match([$bank], $bags, [
            ['code' => 'TEST-A3C1', 'banked' => '500.00', 'share' => '-5.00'],
            ['code' => 'TEST-A3C2', 'banked' => '300.00', 'share' => '-2.99'],
            ['code' => 'TEST-A3C3', 'banked' => '200.00', 'share' => '-2.00'],
        ], $reason);

        $this->assertStringEndsWith('Split between 3 cashiers, as changed by hand.', $byHand->Message);
    }

    /** A bag with no drop-safe row is a line of its own, never dropped. */
    public function test_bags_with_no_cashier_recorded_are_a_line_of_their_own(): void
    {
        $this->employee('TEST-A3C1', 'Nokuthula', 'TEST-A3 Champion');
        $bags = [
            $this->bag('TEST-A3-B1', 600.00, 'TEST-A3C1'),
            $this->bag('TEST-A3-B2', 400.00, null),
        ];
        $bank = $this->bankLine(1010.00);
        $reason = 'TEST-A3 bank over';

        // Leaving the unrecorded bag out of the split is refused.
        $this->assertRefused('ALLOCATION_INCOMPLETE', 'not the split these bags give', fn () => $this->match([$bank], $bags, [
            ['code' => 'TEST-A3C1', 'banked' => '600.00', 'share' => '10.00'],
        ], $reason));

        $status = $this->match([$bank], $bags, [
            ['code' => 'TEST-A3C1', 'banked' => '600.00', 'share' => '6.00'],
            ['code' => null, 'banked' => '400.00', 'share' => '4.00'],
        ], $reason);

        $this->assertStringEndsWith('Split between 1 cashier and the bags with no cashier recorded.', $status->Message);
    }

    /**
     * No split at all is refused — required on cash bags — and the refusal
     * comes before anything is written: no run, no stamp, and the customer's
     * batch counter where it was. Live mode, so the counter is the real test.
     */
    public function test_a_cash_bag_match_without_a_split_is_refused_and_writes_nothing(): void
    {
        $bags = [$this->bag('TEST-A3-B1', 500.00, 'TEST-A3C1')];
        $bank = $this->bankLine(500.00);
        $counter = $this->counter();

        $this->assertRefused('ALLOCATION_INCOMPLETE', 'who the cash is accounted to',
            fn () => $this->match([$bank], $bags, null, null, 'live'));

        $this->assertRefused('ALLOCATION_INCOMPLETE', 'who the cash is accounted to',
            fn () => $this->match([$bank], $bags, 'not json', null, 'live'));

        $this->assertNothingWritten();
        $this->assertSame($counter, $this->counter(), 'A refused match takes no batch number.');
        $this->assertSame(0, (int) $this->db()->table('PumpIT.dbo.BRN_DailyBankingCashBags')
            ->where('SSBranchId', self::BRANCH)->where('ReconBatchNoPumpIT', '<>', 0)->count());
        $this->assertSame(1, (int) $this->db()->table('PumpIT.dbo.RCN_BankStatementLinesPumpIT')
            ->where('BankStatementLineID', $bank)->value('ReconState'));
    }

    /** A balanced match still confirms whose it was: every share is zero. */
    public function test_a_balanced_cash_bag_match_still_names_the_cashier(): void
    {
        $bags = [$this->bag('TEST-A3-B1', 500.00, 'TEST-A3C9')];
        $bank = $this->bankLine(500.00);

        $status = $this->match([$bank], $bags, [
            ['code' => 'TEST-A3C9', 'banked' => '500.00', 'share' => '0.00'],
        ]);

        $this->assertSame('MATCHED', $status->Code);
        // Not on the employee list: named by the code alone.
        $this->assertStringEndsWith('Accounted to TEST-A3C9.', $status->Message);
    }

    /** CashBags only (q4). Every other area matches exactly as it did. */
    public function test_another_area_takes_no_split_and_matches_as_before(): void
    {
        $bank = (int) $this->db()->table('PumpIT.dbo.RCN_BankStatementLinesPumpIT')->insertGetId([
            'SSBranchId' => self::BRANCH, 'LineDate' => '2026-09-20', 'Description' => 'TEST-A3 ABSA 424242',
            'Amount' => 1200.00, 'Type' => 'ABSA', 'IDState' => 2, 'ReconState' => 1, 'ReconBatchNo' => 0,
        ]);
        $this->db()->table('PumpIT.dbo.BRN_DailyBankingABSA')->insert([
            'SSBranchId' => self::BRANCH, 'TransactionDate' => '2026-09-20', 'BatchNumber' => 424242,
            'MerchantNumber' => 'TEST-A3', 'TransactionAmount' => 1200.00, 'ReconBatchNoPumpIT' => 0,
        ]);
        $deposit = ['id' => null, 'key' => '424242', 'dt' => '2026-09-20', 'amt' => 1200.00];

        $this->assertRefused('ALLOCATION_NOT_APPLICABLE', 'Only a cash-bag match', fn () => $this->write('ABSA', [$bank], [$deposit], [
            ['code' => 'X', 'banked' => '1200.00', 'share' => '0.00'],
        ]));

        $status = $this->write('ABSA', [$bank], [$deposit], null);

        $this->assertSame('MATCHED', $status->Code);
        $this->assertStringEndsWith('.', $status->Message);
        $this->assertStringNotContainsString('ccounted', $status->Message);
        $this->assertStringNotContainsString('Split', $status->Message);
    }

    /**
     * The screen: the split panel and the drop-safe column on cash bags, and
     * neither anywhere else. What the panel draws is the browser's (see
     * recon-match.js); what is pinned here is that it is there to draw.
     */
    public function test_the_pane_offers_the_split_and_the_drop_safe_on_cash_bags_only(): void
    {
        $this->employee('TEST-A3C1', 'Nokuthula', 'TEST-A3 Champion');
        $this->bag('TEST-A3-B1', 500.00, 'TEST-A3C1', dropSafe: 520.00);
        $this->bag('TEST-A3-B2', 300.00, 'TEST-A3C1');
        $this->bag('TEST-A3-B3', 250.00, null);

        $html = (string) $this->actingAs($this->admin())
            ->get(route('app.recon.match', ['CashBags'] + $this->scope()), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSee('data-match-allocation', false)
            ->assertSee('name="allocation"', false)
            ->assertSee('Who the cash is accounted to')
            ->assertSee('<th class="num">Drop safe</th>', false)
            ->assertDontSee('<th>Second</th>', false)
            // Declared R520, banked R500: marked, not reconciled away.
            ->assertSee('adjusted')
            ->assertSee('data-drop-safe="520.0000"', false)
            ->getContent();

        // A bag with no drop-safe row carries an empty drop-safe amount.
        $this->assertSame(1, preg_match('/<tr[^>]*data-cashier-code=""[^>]*data-drop-safe=""[^>]*>/', $html));
        // Exactly one bag is adjusted, and its chip says both figures.
        $this->assertSame(1, substr_count($html, 'title="Declared R520.00 at the drop safe; the bag was banked as R500.00"'));

        $this->db()->table('PumpIT.dbo.BRN_DailyBankingABSA')->insert([
            'SSBranchId' => self::BRANCH, 'TransactionDate' => '2026-09-20', 'BatchNumber' => 424242,
            'MerchantNumber' => 'TEST-A3', 'TransactionAmount' => 1200.00, 'ReconBatchNoPumpIT' => 0,
        ]);

        $this->actingAs($this->admin())
            ->get(route('app.recon.match', ['ABSA'] + $this->scope()), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSee('<th>Second</th>', false)
            ->assertDontSee('data-match-allocation', false)
            ->assertDontSee('name="allocation"', false)
            ->assertDontSee('Drop safe');
    }

    /** Through the controller, as the screen posts it: one hidden JSON field. */
    public function test_the_screen_posts_the_split_and_is_told_whose_cash_it_was(): void
    {
        config(['recon.stamp_mode' => 'journal']);

        $this->employee('TEST-A3C1', 'Nokuthula', 'TEST-A3 Champion');
        $bag = $this->bag('TEST-A3-B1', 800.00, 'TEST-A3C1');
        $bank = $this->bankLine(800.00);

        $post = fn (?string $allocation) => $this->actingAs($this->admin())
            ->from(route('app.recon.match', ['CashBags'] + $this->scope()))
            ->post(route('app.recon.match.save', 'CashBags'), array_filter([
                'branch_id' => self::BRANCH, 'from' => self::FROM, 'to' => self::TO,
                'bank' => [$bank],
                'mops' => [json_encode($bag)],
                'allocation' => $allocation,
            ], fn ($v) => $v !== null));

        $post(null)->assertSessionHas('refusal', fn (string $m) => str_contains($m, 'who the cash is accounted to'));
        $this->assertNothingWritten();

        $post(json_encode([['code' => 'TEST-A3C1', 'banked' => '800.00', 'share' => '0.00']]))
            ->assertSessionHas('executed', fn (string $m) => str_ends_with($m, 'Accounted to TEST-A3 Champion Nokuthula (TEST-A3C1).'));
    }

    // ---- helpers -----------------------------------------------------------

    /**
     * @param  array<int, int>  $bankIds
     * @param  array<int, array<string, mixed>>  $deposits
     * @param  array<int, array<string, mixed>>|string|null  $allocation
     */
    private function match(array $bankIds, array $deposits, array|string|null $allocation, ?string $reason = null, string $mode = 'journal'): object
    {
        return $this->write('CashBags', $bankIds, $deposits, $allocation, $reason, $mode);
    }

    /**
     * @param  array<int, int>  $bankIds
     * @param  array<int, array<string, mixed>>  $deposits
     * @param  array<int, array<string, mixed>>|string|null  $allocation
     */
    private function write(string $area, array $bankIds, array $deposits, array|string|null $allocation, ?string $reason = null, string $mode = 'journal'): object
    {
        return $this->procedures->write('usp_Recon_ManualMatch', [
            'BranchId' => self::BRANCH,
            'ReconArea' => $area,
            'FromDate' => self::FROM,
            'ToDate' => self::TO.' 23:59:59',
            'BankLineIds' => implode(',', $bankIds),
            'MopsJson' => json_encode($deposits),
            'Reason' => $reason,
            'StampMode' => $mode,
            'UserId' => 1,
            'AllocationJson' => is_array($allocation) ? json_encode($allocation) : $allocation,
        ]);
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

    private function assertNothingWritten(): void
    {
        $this->assertSame(0, $this->db()->table('agora.ReconRun')->where('BranchId', self::BRANCH)->count(),
            'A refused match writes no run.');
        $this->assertSame(0, $this->db()->table('agora.ReconBatch')->where('BranchId', self::BRANCH)->count());
        $this->assertSame(0, $this->db()->table('agora.ReconAllocation')->where('BranchId', self::BRANCH)->count(),
            'A refused match keeps no split.');
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

    private function employee(string $code, string $firstName, string $surname): void
    {
        $this->db()->table('PumpIT.dbo.BRN_Employee')->insert([
            'SSBranchId' => self::BRANCH, 'EmployeeCode' => $code, 'FirstName' => $firstName,
            'Surname' => $surname, 'IsActive' => 1, 'IsPumpAttendant' => 0, 'PostNo' => 0,
        ]);
    }

    private function counter(): int
    {
        return (int) $this->db()->table('PumpIT.dbo.SS_UniqueNumber')->where('SectionId', 1)->value('NextUniqueNumber');
    }

    /** @return array{branch_id: int, from: string, to: string} */
    private function scope(): array
    {
        return ['branch_id' => self::BRANCH, 'from' => self::FROM, 'to' => self::TO];
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
