<?php

namespace Tests\Feature\Recon;

use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Tests\TestCase;

/**
 * The manual match names the cashier on every cash bag, and only on cash bags.
 *
 * A bag belongs to the cashier who dropped it. usp_Recon_GetSides reads that
 * person off agora.vw_DailyBankingCashBags (v1__13j) and returns CashierCode
 * and CashierName on the CashBags deposit side; <x-two-pane-recon> shows them
 * in a column of their own and carries them as data on the row, for the
 * allocation step that follows.
 *
 * What is pinned: the cashier arrives on every deposit row WITHOUT changing
 * how many rows there are (the fixture holds a second drop-safe row for one
 * bag, which the stub allows and a join would multiply); an unresolved code
 * and a bag with no drop-safe row each say so rather than print a blank; and
 * the other areas return exactly the columns they did, so their screens are
 * untouched. The drop-safe amount (27 Sep 2026) rides after the cashier.
 *
 * Writes into the local PumpIT stub only (skipUnlessLocalStub), at branch 997,
 * which no other test uses, and removes only the TEST-A2 rows it made.
 */
class CashBagSidesCashierTest extends TestCase
{
    private const BRANCH = 997;

    private const FROM = '2026-09-15';

    private const TO = '2026-09-25';

    /** Result set 2 as it has always been, for every area. */
    private const DEPOSIT_COLUMNS = [
        'RowNo', 'SourceId', 'SourceKey', 'SourceRef', 'SourceRef2', 'SourceDate', 'Amount',
        'PairKey', 'ColourIndex', 'ReconBatchNoPumpIT', 'IsOutstanding',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessLocalStub();
        $this->cleanUp();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->cleanUp();
        }

        parent::tearDown();
    }

    public function test_every_cash_bag_names_its_cashier_and_no_bag_is_added(): void
    {
        $this->cashBagFixture();

        // The deposit side as it was before the cashier existed: the bags
        // themselves, at this branch, in the window, outstanding.
        $bags = $this->db()->table('PumpIT.dbo.BRN_DailyBankingCashBags')
            ->where('SSBranchId', self::BRANCH)->where('CashBagNo', 'like', 'TEST-A2-%')
            ->whereBetween('TransactionDate', [self::FROM, self::TO.' 23:59:59'])
            ->where('ReconBatchNoPumpIT', 0)
            ->count();
        $this->assertSame(4, $bags, 'The fixture: four outstanding bags in the window.');

        [$mops, $summary] = $this->sides('CashBags');

        $this->assertCount($bags, $mops, 'The cashier must not add or drop a deposit row.');
        $this->assertSame($bags, (int) $summary->MopsRows);
        $this->assertSame(1690.00, (float) $summary->MopsTotal);
        $this->assertSame($bags, $mops->pluck('SourceId')->unique()->count(), 'One row per bag.');

        $this->assertSame(
            [...self::DEPOSIT_COLUMNS, 'CashierCode', 'CashierName', 'DropSafeAmount'],
            array_keys((array) $mops->first()),
            'CashBags adds its three columns AFTER every existing one.'
        );

        $byBag = $mops->keyBy('SourceKey');

        // Resolved, and the trailing blank the drop safe carried is gone.
        $this->assertSame('TEST-A2C1', $byBag['TEST-A2-BAG1']->CashierCode);
        $this->assertSame('TEST-Champion Nokuthula', $byBag['TEST-A2-BAG1']->CashierName);

        // A code the employee list does not know: the code, and a NULL name.
        $this->assertSame('TEST-A2C9', $byBag['TEST-A2-BAG2']->CashierCode);
        $this->assertNull($byBag['TEST-A2-BAG2']->CashierName);

        // No drop-safe row: nothing names a cashier at all, or declares an amount.
        $this->assertNull($byBag['TEST-A2-BAG3']->CashierCode);
        $this->assertNull($byBag['TEST-A2-BAG3']->CashierName);
        $this->assertNull($byBag['TEST-A2-BAG3']->DropSafeAmount);

        // Amount is still what the bag was banked as; the drop-safe amount
        // rides beside it (the fixture declares 0 at the safe).
        $this->assertSame(640.00, (float) $byBag['TEST-A2-BAG1']->Amount);
        $this->assertSame(0.00, (float) $byBag['TEST-A2-BAG1']->DropSafeAmount);

        // Two drop-safe rows for one bag, which only the keyless stub can
        // hold: still one deposit, and the later drop speaks.
        $this->assertSame('TEST-A2C2', $byBag['TEST-A2-BAG4']->CashierCode);
        $this->assertSame('TEST-Later Sipho', $byBag['TEST-A2-BAG4']->CashierName);
    }

    public function test_another_area_returns_exactly_the_columns_it_did(): void
    {
        $this->absaFixture();

        [$mops] = $this->sides('ABSA');

        $this->assertCount(1, $mops);
        $this->assertSame(self::DEPOSIT_COLUMNS, array_keys((array) $mops->first()));
    }

    public function test_the_manual_match_pane_shows_the_cashier_on_cash_bags(): void
    {
        $this->cashBagFixture();

        $response = $this->actingAs($this->admin())
            ->get(route('app.recon.match', ['CashBags'] + $this->scope()), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSee('<th>Cashier</th>', false)
            // Resolved: the name, with the code beside it.
            ->assertSee('TEST-Champion Nokuthula')
            // Unresolved: the code and a visible marker, never a blank.
            ->assertSee('not on the employee list')
            ->assertSee('no drop-safe row')
            // The cashier as data on the row, for grouping the ticked bags.
            ->assertSee('data-cashier-code="TEST-A2C1"', false)
            ->assertSee('data-cashier-name="TEST-Champion Nokuthula"', false)
            ->assertSee('data-cashier-code="TEST-A2C9"', false);

        $html = (string) $response->getContent();

        // An unresolved cashier carries the code and NO name attribute — an
        // empty one would read as a cashier called nothing.
        $this->assertSame(1, preg_match('/<tr[^>]*data-cashier-code="TEST-A2C9"[^>]*>/', $html, $row));
        $this->assertStringNotContainsString('data-cashier-name', $row[0]);
        // A bag with no drop-safe row carries an empty code, so a script
        // grouping by cashier still sees it — as "nobody recorded".
        $this->assertSame(1, preg_match('/<tr[^>]*data-cashier-code=""[^>]*>/', $html));
        // One cashier column per bag row — four rows, four data attributes.
        $this->assertSame(4, substr_count($html, 'data-cashier-code="'));
    }

    public function test_the_other_areas_draw_no_cashier_column(): void
    {
        $this->absaFixture();

        $this->actingAs($this->admin())
            ->get(route('app.recon.match', ['ABSA'] + $this->scope()), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSee('TEST-A2')   // the fixture row is on screen...
            ->assertDontSee('<th>Cashier</th>', false)
            ->assertDontSee('data-cashier-code', false);
    }

    // ---- helpers -----------------------------------------------------------

    /**
     * @return array{0: Collection<int, object>, 1: object}
     */
    private function sides(string $area): array
    {
        $sets = app(ProcedureService::class)->callSets('usp_Recon_GetSides', [
            'ReconArea' => $area,
            'BranchId' => self::BRANCH,
            'FromDate' => self::FROM,
            'ToDate' => self::TO.' 23:59:59',
            'State' => 'outstanding',
            'MaxRows' => 500,
        ]);

        return [$sets[1], $sets[2]->first()];
    }

    private function cashBagFixture(): void
    {
        $this->bag('TEST-A2-BAG1', 640.00);
        $this->dropSafe('TEST-A2-BAG1', 'TEST-A2C1 ');
        $this->employee('TEST-A2C1', 'Nokuthula', 'TEST-Champion');

        $this->bag('TEST-A2-BAG2', 500.00);
        $this->dropSafe('TEST-A2-BAG2', 'TEST-A2C9');

        $this->bag('TEST-A2-BAG3', 250.00);

        $this->bag('TEST-A2-BAG4', 300.00);
        $this->dropSafe('TEST-A2-BAG4', 'TEST-A2C3', '2026-09-20 08:00');
        $this->dropSafe('TEST-A2-BAG4', 'TEST-A2C2', '2026-09-20 09:00');
        $this->employee('TEST-A2C2', 'Sipho', 'TEST-Later');

        // Outside the window, and already reconciled: neither is on the
        // outstanding deposit side, cashier or not.
        $this->bag('TEST-A2-BAG5', 999.00, '2026-09-30');
        $this->dropSafe('TEST-A2-BAG5', 'TEST-A2C1');
        $this->bag('TEST-A2-BAG6', 888.00, '2026-09-20', 77);
        $this->dropSafe('TEST-A2-BAG6', 'TEST-A2C1');
    }

    private function absaFixture(): void
    {
        $this->db()->table('PumpIT.dbo.BRN_DailyBankingABSA')->insert([
            'SSBranchId' => self::BRANCH, 'TransactionDate' => '2026-09-20', 'BatchNumber' => 424242,
            'MerchantNumber' => 'TEST-A2', 'TransactionAmount' => 1200.00, 'ReconBatchNoPumpIT' => 0,
        ]);
    }

    private function bag(string $bagNo, float $amount, string $date = '2026-09-20', int $batch = 0): void
    {
        $this->db()->table('PumpIT.dbo.BRN_DailyBankingCashBags')->insert([
            'SSBranchId' => self::BRANCH, 'TransactionDate' => $date,
            'CashBagNo' => $bagNo, 'CashBagAmount' => $amount, 'ReconBatchNoPumpIT' => $batch,
        ]);
    }

    private function dropSafe(string $bagNo, string $cashier, string $created = '2026-09-20 08:00'): void
    {
        $this->db()->table('PumpIT.dbo.BRN_DropSafe')->insert([
            'SSBranchId' => self::BRANCH, 'BagNo' => $bagNo, 'CollectionId' => 0,
            'CashierCode' => $cashier, 'Amount' => 0, 'DropDate' => '2026-09-20', 'CreateDateTime' => $created,
        ]);
    }

    private function employee(string $code, string $firstName, string $surname): void
    {
        $this->db()->table('PumpIT.dbo.BRN_Employee')->insert([
            'SSBranchId' => self::BRANCH, 'EmployeeCode' => $code, 'FirstName' => $firstName,
            'Surname' => $surname, 'IsActive' => 1, 'IsPumpAttendant' => 0, 'PostNo' => 0,
        ]);
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

        $db->table('PumpIT.dbo.BRN_DailyBankingCashBags')->where('SSBranchId', self::BRANCH)
            ->where('CashBagNo', 'like', 'TEST-A2-%')->delete();
        $db->table('PumpIT.dbo.BRN_DropSafe')->where('SSBranchId', self::BRANCH)
            ->where('BagNo', 'like', 'TEST-A2-%')->delete();
        $db->table('PumpIT.dbo.BRN_Employee')->where('SSBranchId', self::BRANCH)
            ->where('EmployeeCode', 'like', 'TEST-A2%')->delete();
        $db->table('PumpIT.dbo.BRN_DailyBankingABSA')->where('SSBranchId', self::BRANCH)
            ->where('MerchantNumber', 'TEST-A2')->delete();
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }
}
