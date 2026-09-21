<?php

namespace Tests\Feature\Product;

use App\Exceptions\AgoraProcException;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Modules\Product\Services\CriticalLineService;
use Tests\TestCase;

/**
 * The critical list — `agora.vw_StockItemCritical` and its save procedure.
 *
 * THE FACT THAT SHAPES ALL OF THIS: the list is keyed to the POS file, not to
 * the stock master. Measured live on 20 September 2026 — 1,895 lines, every
 * one with a row in DBF_STDB, and 602 of them (32%) with NO stock master row
 * at that site and POS system. So a line with no item number is normal, and
 * the two tests that pin that are the ones worth keeping if any are cut.
 *
 * Writes to the LOCAL PumpIT stub and to Agora's own tables, branch 999 only.
 */
class CriticalLineTest extends TestCase
{
    private const BRANCH = 999;

    private CriticalLineService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessLocalStub('This fixture writes critical lines and POS rows.');
        $this->service = new CriticalLineService;
        $this->cleanUp();
        $this->posRow('1200', 'TEST-PIE CHICKEN', 4.0);
        $this->posRow('9999', 'TEST-UNCOUNTED LINE', 0.0);
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->cleanUp();
        }

        parent::tearDown();
    }

    public function test_a_legacy_critical_line_comes_through_with_its_source_named(): void
    {
        $this->legacyCritical('1200', 'TEST-PIE CHICKEN', 1);

        $row = $this->resolved('1200');
        $this->assertSame('legacy', trim($row->Source));
        $this->assertSame(1, (int) $row->IsActive);
    }

    public function test_an_override_replaces_the_customers_row_and_parking_puts_it_back(): void
    {
        $this->legacyCritical('1200', 'TEST-PIE CHICKEN', 1);

        $this->service->save(self::BRANCH, 'WINBRANCH', '1200', 'save',
            ['Description' => 'TEST-RENAMED', 'IsActive' => true, 'reason' => 'TEST-rename'], null);

        $this->assertSame('agora', trim($this->resolved('1200')->Source));
        $this->assertSame('TEST-RENAMED', trim((string) $this->resolved('1200')->Description));
        $this->assertSame(1, $this->countResolved('1200'), 'The UNION must yield one row, not two.');

        $this->service->save(self::BRANCH, 'WINBRANCH', '1200', 'park', ['reason' => 'TEST-undo'], null);

        $this->assertSame('legacy', trim($this->resolved('1200')->Source));
        $this->assertSame('TEST-PIE CHICKEN', trim((string) $this->resolved('1200')->Description));
    }

    public function test_removing_a_line_takes_it_off_the_list_without_touching_the_customers(): void
    {
        $this->legacyCritical('1200', 'TEST-PIE CHICKEN', 1);

        $this->service->save(self::BRANCH, 'WINBRANCH', '1200', 'remove', ['reason' => 'TEST-discontinued'], null);

        $this->assertSame(0, (int) $this->resolved('1200')->IsActive);

        // PumpIT's own row is untouched. This is the assertion the whole
        // design exists for.
        $legacy = $this->db()->table('PumpIT.dbo.STK_StockMasterCritical')
            ->where('SSBranchId', self::BRANCH)->first();
        $this->assertSame(1, (int) $legacy->IsActive);
    }

    public function test_a_code_the_site_does_not_sell_is_refused(): void
    {
        // A critical line for a code the till does not know is an alert that
        // can never clear.
        $this->expectRefusal('UNKNOWN_POS_CODE', fn () => $this->service->save(
            self::BRANCH, 'WINBRANCH', 'NOSUCHCODE', 'save',
            ['Description' => 'TEST-GHOST', 'IsActive' => true, 'reason' => 'TEST'], null
        ));
    }

    public function test_a_line_with_no_stock_master_row_is_allowed(): void
    {
        // 602 of the 1,895 live lines are exactly this. Requiring a stock
        // master row would refuse a third of the customer's own list.
        $this->service->save(self::BRANCH, 'WINBRANCH', '9999', 'save',
            ['Description' => 'TEST-UNCOUNTED LINE', 'IsActive' => true, 'reason' => 'TEST-add'], null);

        $this->assertSame('agora', trim($this->resolved('9999')->Source));
    }

    public function test_the_grid_shows_an_uncounted_line_as_not_counted_rather_than_hiding_it(): void
    {
        $this->legacyCritical('9999', 'TEST-UNCOUNTED LINE', 1);

        $row = $this->gridRow('9999');
        $this->assertNotNull($row, 'A critical line with no stock master row must still be listed.');
        $this->assertSame(0, (int) $row->IsCounted);
        $this->assertNull($row->StockItemNo);
    }

    public function test_out_of_stock_is_the_status_and_it_leads_the_default_order(): void
    {
        // 1200 has 4 on hand, 9999 has none. The one that is out must be the
        // first row without anybody sorting.
        $this->legacyCritical('1200', 'TEST-PIE CHICKEN', 1);
        $this->legacyCritical('9999', 'TEST-UNCOUNTED LINE', 1);

        $rows = $this->grid();

        $this->assertSame('Out of stock', trim($rows[0]->Status));
        $this->assertSame('9999', trim($rows[0]->PosCode));
    }

    public function test_low_means_one_pack_or_less_rather_than_a_number_somebody_picked(): void
    {
        // 4 on hand against a pack of 6 is Low; against a pack of 2 it is not.
        $this->db()->table('PumpIT.dbo.DBF_STDB')
            ->where('SSBranchId', self::BRANCH)->where('CODE', '1200')->update(['PACKSIZE' => 6]);
        $this->legacyCritical('1200', 'TEST-PIE CHICKEN', 1);
        $this->assertSame('Low', trim($this->gridRow('1200')->Status));

        $this->db()->table('PumpIT.dbo.DBF_STDB')
            ->where('SSBranchId', self::BRANCH)->where('CODE', '1200')->update(['PACKSIZE' => 2]);
        $this->assertSame('In stock', trim($this->gridRow('1200')->Status));
    }

    public function test_a_line_taken_off_the_list_reads_as_off_the_list_not_as_out_of_stock(): void
    {
        $this->legacyCritical('9999', 'TEST-UNCOUNTED LINE', 0);

        // It has nothing on hand, but it is not on the list, so "Out of stock"
        // would be a false alarm.
        $this->assertSame('Off the list', trim($this->gridRow('9999')->Status));
    }

    public function test_a_change_with_no_reason_is_refused(): void
    {
        $this->legacyCritical('1200', 'TEST-PIE CHICKEN', 1);

        $this->expectRefusal('REASON_REQUIRED', fn () => $this->service->save(
            self::BRANCH, 'WINBRANCH', '1200', 'save',
            ['Description' => 'TEST', 'IsActive' => true, 'reason' => '  '], null
        ));
    }

    public function test_parking_when_there_is_no_override_says_so(): void
    {
        $this->legacyCritical('1200', 'TEST-PIE CHICKEN', 1);

        $this->expectRefusal('NOTHING_TO_PARK', fn () => $this->service->save(
            self::BRANCH, 'WINBRANCH', '1200', 'park', ['reason' => 'TEST'], null
        ));
    }

    public function test_a_save_that_omits_the_description_takes_the_pos_files_rather_than_blanking_it(): void
    {
        // An override replaces its legacy row WHOLE, so a null here would
        // erase what the customer had.
        $this->service->save(self::BRANCH, 'WINBRANCH', '1200', 'save',
            ['IsActive' => true, 'reason' => 'TEST-no description sent'], null);

        $this->assertSame('TEST-PIE CHICKEN', trim((string) $this->resolved('1200')->Description));
    }

    private function expectRefusal(string $code, callable $act): void
    {
        try {
            $act();
        } catch (AgoraProcException $e) {
            $this->assertSame($code, $e->code(), "Refused, but with {$e->code()}: {$e->getMessage()}");

            return;
        }

        $this->fail("Expected AGORA:{$code} and the write was allowed.");
    }

    private function resolved(string $posCode): object
    {
        $rows = $this->db()->select(
            'SELECT * FROM [agora].[vw_StockItemCritical] WHERE BranchId = ? AND PosCode = ?',
            [self::BRANCH, $posCode]
        );

        $this->assertCount(1, $rows, "Expected exactly one resolved row for {$posCode}.");

        return $rows[0];
    }

    private function countResolved(string $posCode): int
    {
        return count($this->db()->select(
            'SELECT 1 AS x FROM [agora].[vw_StockItemCritical] WHERE BranchId = ? AND PosCode = ?',
            [self::BRANCH, $posCode]
        ));
    }

    /** @return array<int, object> */
    private function grid(): array
    {
        return $this->db()->select(
            'EXEC agora.usp_Product_GridCriticalLines @BranchIds = ?, @PageSize = 50',
            [(string) self::BRANCH]
        );
    }

    private function gridRow(string $posCode): ?object
    {
        foreach ($this->grid() as $row) {
            if (trim((string) $row->PosCode) === $posCode) {
                return $row;
            }
        }

        return null;
    }

    private function legacyCritical(string $code, string $description, int $active): void
    {
        $this->db()->table('PumpIT.dbo.STK_StockMasterCritical')->insert([
            'SSBranchId' => self::BRANCH,
            'Code' => $code,
            'Location' => 'WINBRANCH',
            'Desc' => $description,
            'CAT' => 'TEST',
            'IsActive' => $active,
        ]);
    }

    private function posRow(string $code, string $description, float $qty): void
    {
        $this->db()->table('PumpIT.dbo.DBF_STDB')->insert([
            'SSBranchId' => self::BRANCH,
            'CODE' => $code,
            'LOCATION' => 'WINBRANCH',
            'DESC' => $description,
            'CAT' => 'TEST',
            'STDCOST' => 10,
            'STDSELL' => 15,
            'QTY' => $qty,
            'M_SOLD' => 0,
            'L_SOLD' => null,
            'PACKSIZE' => 1,
            'VATCODE' => 'A',
        ]);
    }

    private function cleanUp(): void
    {
        $this->db()->table('agora.StockItemCritical')->where('BranchId', self::BRANCH)->delete();
        $this->db()->table('PumpIT.dbo.STK_StockMasterCritical')->where('SSBranchId', self::BRANCH)->delete();
        $this->db()->table('PumpIT.dbo.DBF_STDB')->where('SSBranchId', self::BRANCH)->delete();
        $this->db()->table('PumpIT.dbo.STK_StockMaster')->where('SSBranchId', self::BRANCH)->delete();
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }
}
