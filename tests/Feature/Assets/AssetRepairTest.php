<?php

namespace Tests\Feature\Assets;

use App\Exceptions\AgoraProcException;
use Tests\TestCase;

/**
 * Job cards and the purchase request that pays for them (T057).
 *
 * Ryan, 27 Sep 2026: the job card carries the purchase request number, as the
 * legacy OrderNo did, and the repair's cost is that request's APPROVED amount —
 * read from the customer's BRN_Transaction / BRN_TransactionLine, never typed.
 * These tests put requests in the LOCAL PumpIT stub at branch 999 and nowhere
 * else.
 */
class AssetRepairTest extends TestCase
{
    use AssetsFixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessLocalStub('This fixture writes job cards and purchase requests into the stub.');
        $this->cleanUpAssets();
        $this->seedAssetMasters();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->cleanUpAssets();
        }

        parent::tearDown();
    }

    public function test_the_cost_is_the_approved_requests_amount_and_nothing_until_it_is_approved(): void
    {
        $this->purchaseRequest('TSTA-CR1', self::S1, [966.00, 736.00, 1581.25]);
        $this->purchaseRequest('TSTA-CR2', self::S1, [500.00], approved: false);

        $fridge = $this->registerAsset();
        $approved = $this->raiseRepair($fridge, '2026-08-01', null, ['TransactionNo' => 'tsta-cr1']);
        $pending = $this->raiseRepair($this->registerAsset(['AssetTypeCode' => 'TSTA02']), '2026-08-02', null, ['TransactionNo' => 'TSTA-CR2']);

        $rows = $this->rows();

        $this->assertEqualsWithDelta(3283.25, (float) $rows[$approved]->RepairCost, 0.001, 'Call-out + gas + labour.');
        $this->assertSame('Approved', $rows[$approved]->RequestState);
        $this->assertSame(self::S1, $rows[$approved]->SupplierCode, 'No supplier on the card: the request\'s is taken.');
        $this->assertSame('TSTA-CR1', $rows[$approved]->TransactionNo, 'Stored as the customer writes it.');

        $this->assertNull($rows[$pending]->RepairCost, 'Awaiting approval is no cost yet — not R0.00.');
        $this->assertSame('Awaiting approval', $rows[$pending]->RequestState);
    }

    public function test_an_unknown_or_declined_request_and_a_supplier_that_is_not_the_requests_are_refused(): void
    {
        $this->purchaseRequest('TSTA-CR3', self::S1, [100.00], approved: false, declined: true);
        $this->purchaseRequest('TSTA-CR4', self::S1, [100.00]);
        $fridge = $this->registerAsset();

        $this->assertRefused('UNKNOWN_PURCHASE_REQUEST', fn () => $this->raiseRepair($fridge, '2026-08-01', null, ['TransactionNo' => 'TSTA-NONE']));
        $this->assertRefused('PR_DECLINED', fn () => $this->raiseRepair($fridge, '2026-08-01', null, ['TransactionNo' => 'TSTA-CR3']));
        $this->assertRefused('PR_SUPPLIER_MISMATCH', fn () => $this->raiseRepair($fridge, '2026-08-01', self::S2, ['TransactionNo' => 'TSTA-CR4']));
        $this->assertRefused('UNKNOWN_SUPPLIER', fn () => $this->raiseRepair($fridge, '2026-08-01', 'TSTA-NO'));
        $this->assertRefused('PROBLEM_REQUIRED', fn () => $this->raiseRepair($fridge, '2026-08-01', self::S1, ['Problem' => '  ']));
        $this->assertRefused('RETURN_BEFORE_CALL', fn () => $this->raiseRepair($fridge, '2026-08-05', self::S1, ['DateExpectedReturn' => '2026-08-04']));
    }

    public function test_one_request_paying_for_several_cards_is_shown_on_each_and_counted_once(): void
    {
        // One call-out, two fridges: the request is on both cards.
        $this->purchaseRequest('TSTA-CR5', self::S1, [4725.00]);
        $a = $this->raiseRepair($this->registerAsset(), '2026-08-01', null, ['TransactionNo' => 'TSTA-CR5']);
        $b = $this->raiseRepair($this->registerAsset(['AssetTypeCode' => 'TSTA02']), '2026-08-01', null, ['TransactionNo' => 'TSTA-CR5']);

        $sets = $this->procs()->callSets('agora.usp_Assets_GridRepairs', [
            'BranchIds' => (string) self::BRANCH, 'DateFrom' => '2026-01-01', 'DateTo' => '2026-12-31',
        ]);
        $rows = $sets[0]->keyBy(fn ($r) => (int) $r->RepairId);

        $this->assertSame(2, (int) $rows[$a]->SharedBy);
        $this->assertEqualsWithDelta(4725.00, (float) $rows[$a]->RepairCost, 0.001);
        $this->assertEqualsWithDelta(4725.00, (float) $rows[$b]->RepairCost, 0.001);
        $this->assertEqualsWithDelta(4725.00, (float) $sets[2]->first()->RepairCost, 0.001,
            'The footer counts the request once, not R9 450.');

        $tally = $this->procs()->callSets('agora.usp_Assets_Comebacks', [
            'BranchIds' => (string) self::BRANCH, 'DateFrom' => '2026-01-01', 'DateTo' => '2026-12-31',
        ])[1]->firstWhere('SupplierCode', self::S1);
        $this->assertEqualsWithDelta(4725.00, (float) $tally->RepairCost, 0.001, 'And so does the supplier tally.');
    }

    public function test_updating_a_card_needs_the_edit_permission_and_a_cancelled_card_cannot_be_updated(): void
    {
        $this->purchaseRequest('TSTA-CR6', self::S1, [800.00]);
        $card = $this->raiseRepair($this->registerAsset(), '2026-08-01');
        $update = ['BranchId' => self::BRANCH, 'RepairId' => $card, 'CallDate' => '2026-08-01', 'SupplierCode' => self::S1,
            'Problem' => 'TEST-Not cooling', 'TransactionNo' => 'TSTA-CR6', 'DateActualReturn' => '2026-08-03'];

        $this->assertRefused('EDIT_NOT_ALLOWED', fn () => $this->procs()->write('agora.usp_Assets_SaveRepair', $update + ['AllowEdit' => 0]));

        $this->procs()->write('agora.usp_Assets_SaveRepair', $update + ['AllowEdit' => 1]);
        $this->assertEqualsWithDelta(800.00, (float) $this->rows()[$card]->RepairCost, 0.001, 'The request attached after the call.');
        $this->assertSame('Done', $this->rows()[$card]->Result);

        $this->procs()->write('agora.usp_Assets_CancelRepair', [
            'BranchId' => self::BRANCH, 'RepairId' => $card, 'Reason' => 'TEST', 'AllowEdit' => 1,
        ]);
        $this->assertRefused('CANCELLED', fn () => $this->procs()->write('agora.usp_Assets_SaveRepair', $update + ['AllowEdit' => 1]));
        $this->assertRefused('ALREADY_CANCELLED', fn () => $this->procs()->write('agora.usp_Assets_CancelRepair', [
            'BranchId' => self::BRANCH, 'RepairId' => $card, 'Reason' => 'TEST', 'AllowEdit' => 1,
        ]));
        $this->assertNotNull($this->db()->table('agora.AssetRepair')->where('BranchId', self::BRANCH)->where('Id', $card)->value('Id'),
            'A cancelled card is still there.');
    }

    public function test_a_card_not_back_after_its_promised_date_is_overdue_and_one_back_after_it_is_late(): void
    {
        $overdue = $this->raiseRepair($this->registerAsset(), '2026-08-01', self::S1, ['DateExpectedReturn' => '2026-08-05']);
        $late = $this->raiseRepair($this->registerAsset(['AssetTypeCode' => 'TSTA02']), '2026-08-01', self::S1,
            ['DateExpectedReturn' => '2026-08-05', 'DateActualReturn' => '2026-08-09']);

        $rows = $this->rows();
        $this->assertSame('Overdue', $rows[$overdue]->Result);
        $this->assertSame('Late', $rows[$late]->Result);
        $this->assertSame(8, (int) $rows[$late]->DaysOut);
    }

    /** @return array<int, object> */
    private function rows(): array
    {
        return $this->procs()->callSets('agora.usp_Assets_GridRepairs', [
            'BranchIds' => (string) self::BRANCH, 'DateFrom' => '2026-01-01', 'DateTo' => '2026-12-31', 'PageSize' => 500,
        ])[0]->keyBy(fn ($r) => (int) $r->RepairId)->all();
    }

    private function assertRefused(string $code, callable $call): void
    {
        try {
            $call();
        } catch (AgoraProcException $e) {
            $this->assertSame($code, $e->code(), $e->getMessage());

            return;
        }

        $this->fail("Expected the procedure to refuse with {$code}; it accepted.");
    }
}
