<?php

namespace Tests\Feature\Recon;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * agora.vw_DailyBankingCashBags names the bag's cashier and its collection.
 *
 * The bank line settles a security COLLECTION, but the cash is accounted for
 * per BAG, and a bag belongs to the cashier who dropped it
 * (BRN_DropSafe.CashierCode). v1__13j put that person, their name and the
 * collection's DBagNo on the view every CashBags procedure already reads.
 *
 * What is pinned here is the contract the later work leans on — ONE ROW PER
 * BAG — and the branch on every lookup. DropSafeAmount (27 Sep 2026) is the
 * amount declared at the safe, off the SAME drop-safe row that names the
 * cashier, carried beside CashBagAmount and never in place of it. On the customer's instance each lookup
 * is on its table's declared primary key, so neither can go wrong there; the
 * local stub declares no keys at all, which is exactly what lets these tests
 * put a duplicate in front of the view and see that it does not multiply.
 *
 * Writes into the local PumpIT stub only (skipUnlessLocalStub), at branch 999
 * and a second fake branch 998, and removes only the TEST-A1 rows it made.
 */
class CashBagCashierViewTest extends TestCase
{
    private const BRANCH = 999;

    private const OTHER_BRANCH = 998;

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

    public function test_a_bag_names_its_cashier_and_its_collection(): void
    {
        $this->bag(self::BRANCH, 'TEST-A1-BAG1', 640.00);
        // A trailing blank, as branch 21's AM261117 carries on live. The view
        // hands the code over trimmed, so a split grouped in PHP sees one
        // cashier, not two.
        // Declared R600 at the safe, banked as R640: an adjusted bag.
        $this->dropSafe(self::BRANCH, 'TEST-A1-BAG1', 99_900_001, 'TEST-A1C1 ', amount: 600.00);
        $this->collection(self::BRANCH, 99_900_001, 'TEST-A1-DBAG1');
        $this->employee(self::BRANCH, 'TEST-A1C1', 'Nokuthula', 'TEST-Champion');

        $rows = $this->viewRows(self::BRANCH);

        $this->assertCount(1, $rows);
        $this->assertSame('TEST-A1C1', $rows[0]->CashierCode);
        $this->assertSame('TEST-Champion Nokuthula', $rows[0]->CashierName);
        $this->assertSame('TEST-A1-DBAG1', $rows[0]->DBagNo);
        $this->assertSame(99_900_001, (int) $rows[0]->CollectionId);
        // Both figures, each in its own column: the view reconciles nothing.
        $this->assertSame(600.00, (float) $rows[0]->DropSafeAmount);
        $this->assertSame(640.00, (float) $rows[0]->CashBagAmount);
    }

    /**
     * The same bag number, collection id and cashier code at another branch
     * describe somebody else's cash. A lookup on the code alone would take
     * them, or fan out over them.
     */
    public function test_every_lookup_carries_the_branch(): void
    {
        $this->bag(self::BRANCH, 'TEST-A1-BAG2', 500.00);
        $this->dropSafe(self::BRANCH, 'TEST-A1-BAG2', 99_900_002, 'TEST-A1C2');
        $this->collection(self::BRANCH, 99_900_002, 'TEST-A1-DBAG2');
        $this->employee(self::BRANCH, 'TEST-A1C2', 'Right', 'TEST-Here');

        $this->dropSafe(self::OTHER_BRANCH, 'TEST-A1-BAG2', 99_900_002, 'TEST-A1C9');
        $this->collection(self::OTHER_BRANCH, 99_900_002, 'TEST-A1-WRONG');
        $this->employee(self::OTHER_BRANCH, 'TEST-A1C2', 'Wrong', 'TEST-Elsewhere');

        // A cashier who exists only at the other branch does not resolve here.
        $this->bag(self::BRANCH, 'TEST-A1-BAG3', 300.00);
        $this->dropSafe(self::BRANCH, 'TEST-A1-BAG3', 0, 'TEST-A1C9');
        $this->employee(self::OTHER_BRANCH, 'TEST-A1C9', 'Only', 'TEST-There');

        $rows = collect($this->viewRows(self::BRANCH))->keyBy('CashBagNo');

        $this->assertCount(2, $rows);
        $this->assertSame('TEST-Here Right', $rows['TEST-A1-BAG2']->CashierName);
        $this->assertSame('TEST-A1-DBAG2', $rows['TEST-A1-BAG2']->DBagNo);

        $this->assertSame('TEST-A1C9', $rows['TEST-A1-BAG3']->CashierCode);
        $this->assertNull($rows['TEST-A1-BAG3']->CashierName, 'An unresolved cashier is NULL, never a blank name.');
        $this->assertNull($rows['TEST-A1-BAG3']->DBagNo);
        $this->assertSame(0, (int) $rows['TEST-A1-BAG3']->CollectionId, 'Dropped and not collected keeps the legacy 0.');
    }

    /**
     * The stub has no keys, so it can hold what live refuses: two drop-safe
     * rows for one bag and two employee rows for one code. Still one row, and
     * the later drop-safe row is the one that speaks.
     */
    public function test_a_duplicate_lookup_row_does_not_duplicate_the_bag(): void
    {
        $this->bag(self::BRANCH, 'TEST-A1-BAG4', 1840.00);
        $this->dropSafe(self::BRANCH, 'TEST-A1-BAG4', 99_900_004, 'TEST-A1C3', '2026-09-20 08:00', 1800.00);
        $this->dropSafe(self::BRANCH, 'TEST-A1-BAG4', 99_900_004, 'TEST-A1C4', '2026-09-20 09:00', 1840.00);
        $this->collection(self::BRANCH, 99_900_004, 'TEST-A1-DBAG4');
        $this->collection(self::BRANCH, 99_900_004, 'TEST-A1-DBAG4');
        $this->employee(self::BRANCH, 'TEST-A1C4', 'Later', 'TEST-Cashier');
        $this->employee(self::BRANCH, 'TEST-A1C4', 'Later', 'TEST-Cashier');

        $rows = $this->viewRows(self::BRANCH);

        $this->assertCount(1, $rows);
        $this->assertSame('TEST-A1C4', $rows[0]->CashierCode);
        $this->assertSame('TEST-Cashier Later', $rows[0]->CashierName);
        // The amount comes off the same drop-safe row as the cashier.
        $this->assertSame(1840.00, (float) $rows[0]->DropSafeAmount);
    }

    public function test_a_bag_with_no_drop_safe_row_is_still_one_row(): void
    {
        $this->bag(self::BRANCH, 'TEST-A1-BAG5', 250.00);

        $rows = $this->viewRows(self::BRANCH);

        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]->CollectionId);
        $this->assertNull($rows[0]->DBagNo);
        $this->assertNull($rows[0]->CashierCode);
        $this->assertNull($rows[0]->CashierName);
        $this->assertNull($rows[0]->DropSafeAmount, 'No drop-safe row, no declared amount — never a zero.');
    }

    /**
     * @return list<object>
     */
    private function viewRows(int $branchId): array
    {
        $schema = config('agora.schema');

        return $this->db()->table("{$schema}.vw_DailyBankingCashBags")
            ->where('BranchId', $branchId)
            ->where('CashBagNo', 'like', 'TEST-A1-%')
            ->orderBy('CashBagNo')
            ->get()
            ->all();
    }

    private function bag(int $branchId, string $bagNo, float $amount): void
    {
        $this->db()->table('PumpIT.dbo.BRN_DailyBankingCashBags')->insert([
            'SSBranchId' => $branchId, 'TransactionDate' => '2026-09-20',
            'CashBagNo' => $bagNo, 'CashBagAmount' => $amount, 'ReconBatchNoPumpIT' => 0,
        ]);
    }

    private function dropSafe(int $branchId, string $bagNo, int $collectionId, string $cashier, string $created = '2026-09-20 08:00', float $amount = 0): void
    {
        $this->db()->table('PumpIT.dbo.BRN_DropSafe')->insert([
            'SSBranchId' => $branchId, 'BagNo' => $bagNo, 'CollectionId' => $collectionId,
            'CashierCode' => $cashier, 'Amount' => $amount, 'DropDate' => '2026-09-20', 'CreateDateTime' => $created,
        ]);
    }

    private function collection(int $branchId, int $collectionId, string $dBagNo): void
    {
        $this->db()->table('PumpIT.dbo.BRN_DropSafe_Collection')->insert([
            'SSBranchId' => $branchId, 'CollectionId' => $collectionId, 'DBagNo' => $dBagNo,
            'CollectionDate' => '2026-09-21', 'CreateDateTime' => '2026-09-21 10:00',
        ]);
    }

    private function employee(int $branchId, string $code, string $firstName, string $surname): void
    {
        $this->db()->table('PumpIT.dbo.BRN_Employee')->insert([
            'SSBranchId' => $branchId, 'EmployeeCode' => $code, 'FirstName' => $firstName,
            'Surname' => $surname, 'IsActive' => 1, 'IsPumpAttendant' => 0, 'PostNo' => 0,
        ]);
    }

    private function cleanUp(): void
    {
        $db = $this->db();
        $branches = [self::BRANCH, self::OTHER_BRANCH];

        $db->table('PumpIT.dbo.BRN_DailyBankingCashBags')->whereIn('SSBranchId', $branches)
            ->where('CashBagNo', 'like', 'TEST-A1-%')->delete();
        $db->table('PumpIT.dbo.BRN_DropSafe')->whereIn('SSBranchId', $branches)
            ->where('BagNo', 'like', 'TEST-A1-%')->delete();
        $db->table('PumpIT.dbo.BRN_DropSafe_Collection')->whereIn('SSBranchId', $branches)
            ->where('DBagNo', 'like', 'TEST-A1-%')->delete();
        $db->table('PumpIT.dbo.BRN_Employee')->whereIn('SSBranchId', $branches)
            ->where('EmployeeCode', 'like', 'TEST-A1%')->delete();
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }
}
