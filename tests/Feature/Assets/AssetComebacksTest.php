<?php

namespace Tests\Feature\Assets;

use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * THE ACCEPTANCE TEST FOR T057: the comeback rule, pinned.
 *
 * "Same asset, second repair call within 30 days" (Ryan, 27 Sep 2026, PM
 * decision 01a0e309 — Q2 = A). The 1,385 reviewed job cards the plan named
 * exist in no database on the customer's instance; the supplier forensic file
 * is being asked of ZP separately. Until it arrives this is the fixture: every
 * edge of the rule, against the local stub.
 *
 * The rule lives in agora.vw_AssetRepairCall. What is asserted here is what
 * the two readers of it return — agora.usp_Assets_GridRepairs (the screen) and
 * agora.usp_Assets_Comebacks (the by-supplier tally) — so a reader that drifted
 * from the view would fail too.
 */
class AssetComebacksTest extends TestCase
{
    use AssetsFixture;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessLocalStub('This fixture writes assets and job cards.');
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

    public function test_a_second_call_on_the_same_asset_within_30_days_is_a_comeback_and_the_boundary_is_inclusive(): void
    {
        $fridge = $this->registerAsset(['DatePurchased' => '2025-01-01']);

        $first = $this->raiseRepair($fridge, '2026-01-01', self::S1);
        $twelve = $this->raiseRepair($fridge, '2026-01-13', self::S1);   // 12 days: comeback
        $thirtyOne = $this->raiseRepair($fridge, '2026-02-13', self::S2); // 31 days: not
        $thirty = $this->raiseRepair($fridge, '2026-03-15', self::S2);   // exactly 30: comeback
        $sameDay = $this->raiseRepair($fridge, '2026-03-15', self::S1);  // 0 days: comeback

        $rows = $this->repairRows();

        $this->assertSame('No', $rows[$first]->IsComeback, 'The first call on an asset is never a comeback.');
        $this->assertSame('Yes', $rows[$twelve]->IsComeback);
        $this->assertSame(12, (int) $rows[$twelve]->DaysSincePrevious);
        $this->assertSame('No', $rows[$thirtyOne]->IsComeback, '31 days is outside the window.');
        $this->assertSame(31, (int) $rows[$thirtyOne]->DaysSincePrevious);
        $this->assertSame('Yes', $rows[$thirty]->IsComeback, 'Within 30 days includes the thirtieth.');
        $this->assertSame('Yes', $rows[$sameDay]->IsComeback, 'A second call the same day is 0 days after the first.');
        $this->assertSame(0, (int) $rows[$sameDay]->DaysSincePrevious);
        $this->assertSame('Comeback', $rows[$twelve]->Result);
    }

    public function test_calls_on_different_assets_are_never_each_others_comebacks(): void
    {
        $fridge = $this->registerAsset();
        $freezer = $this->registerAsset(['AssetTypeCode' => 'TSTA02']);

        $this->raiseRepair($fridge, '2026-08-01');
        $other = $this->raiseRepair($freezer, '2026-08-03');

        $this->assertSame('No', $this->repairRows()[$other]->IsComeback,
            'Two days apart, same site, same supplier — but a different asset, so not a comeback.');
    }

    public function test_a_cancelled_job_card_is_not_a_call_and_cannot_make_the_next_one_a_comeback(): void
    {
        $fridge = $this->registerAsset();

        $this->raiseRepair($fridge, '2026-05-01');
        $mistake = $this->raiseRepair($fridge, '2026-05-21');
        $genuine = $this->raiseRepair($fridge, '2026-06-10');

        $this->assertSame('Yes', $this->repairRows()[$genuine]->IsComeback, '20 days after the mistaken card, before it is cancelled.');

        $this->procs()->write('agora.usp_Assets_CancelRepair', [
            'BranchId' => self::BRANCH, 'RepairId' => $mistake, 'Reason' => 'TEST-raised twice', 'AllowEdit' => 1,
        ]);

        $rows = $this->repairRows();
        $this->assertSame('No', $rows[$genuine]->IsComeback, '40 days after the last REAL call.');
        $this->assertSame(40, (int) $rows[$genuine]->DaysSincePrevious);
        $this->assertSame('Cancelled', $rows[$mistake]->Result);
    }

    public function test_a_comeback_counts_against_the_supplier_whose_repair_did_not_hold(): void
    {
        $fridge = $this->registerAsset();
        $printer = $this->registerAsset(['AssetTypeCode' => 'TSTA02']);

        // S1 repairs the fridge; it fails 10 days later and the site calls S2.
        $this->raiseRepair($fridge, '2026-07-01', self::S1);
        $this->raiseRepair($fridge, '2026-07-11', self::S2);
        // S2 repairs the printer twice, 60 days apart: no comeback.
        $this->raiseRepair($printer, '2026-06-01', self::S2);
        $this->raiseRepair($printer, '2026-07-31', self::S2);

        $sets = $this->procs()->callSets('agora.usp_Assets_Comebacks', [
            'BranchIds' => (string) self::BRANCH, 'DateFrom' => '2026-01-01', 'DateTo' => '2026-09-30',
        ]);

        $comebacks = $sets[0];
        $this->assertCount(1, $comebacks);
        $this->assertSame(self::S1, $comebacks->first()->PrevSupplierCode);
        $this->assertSame(self::S2, $comebacks->first()->SupplierCode);

        $bySupplier = $sets[1]->keyBy('SupplierCode');
        $this->assertSame(1, (int) $bySupplier[self::S1]->Calls);
        $this->assertSame(1, (int) $bySupplier[self::S1]->Comebacks, 'The comeback is S1\'s: their repair did not hold.');
        $this->assertEqualsWithDelta(100.0, (float) $bySupplier[self::S1]->ComebackPct, 0.01);
        $this->assertSame(3, (int) $bySupplier[self::S2]->Calls);
        $this->assertSame(0, (int) $bySupplier[self::S2]->Comebacks);
        $this->assertSame(self::S1, $sets[1]->first()->SupplierCode, 'Worst rate first.');
    }

    public function test_the_previous_call_counts_even_when_it_falls_outside_the_date_range_asked_for(): void
    {
        $fridge = $this->registerAsset();

        $this->raiseRepair($fridge, '2026-06-25');
        $inWindow = $this->raiseRepair($fridge, '2026-07-05');

        // Asked for July only: the June call is not listed, but it still makes
        // the July call a comeback. A window must not hide the call before it.
        $rows = $this->repairRows('2026-07-01', '2026-07-31');

        $this->assertCount(1, $rows);
        $this->assertSame('Yes', $rows[$inWindow]->IsComeback);
    }

    public function test_the_comebacks_only_filter_narrows_to_comebacks_and_a_filter_matching_nothing_returns_nothing(): void
    {
        $fridge = $this->registerAsset();
        $this->raiseRepair($fridge, '2026-08-01');
        $comeback = $this->raiseRepair($fridge, '2026-08-05');

        $only = $this->repairRows(filters: [['column' => 'IsComeback', 'type' => 'set', 'in' => ['Yes']]]);
        $this->assertSame([$comeback], array_keys($only));

        $none = $this->repairRows(filters: [['column' => 'Problem', 'type' => 'text', 'op' => 'contains', 'value' => 'TEST-no-such-problem']]);
        $this->assertSame([], $none, 'A filter that matches nothing must narrow to nothing — the Users-grid bug.');
    }

    /**
     * The repair grid's rows for branch 999, keyed by the job card's id.
     *
     * @param  array<int, array<string, mixed>>  $filters
     * @return array<int, object>
     */
    private function repairRows(string $from = '2025-01-01', string $to = '2026-12-31', array $filters = []): array
    {
        $sets = $this->procs()->callSets('agora.usp_Assets_GridRepairs', [
            'BranchIds' => (string) self::BRANCH,
            'DateFrom' => $from,
            'DateTo' => $to,
            'PageSize' => 500,
            'FiltersJson' => $filters === [] ? null : json_encode($filters),
        ]);

        /** @var Collection<int, object> $rows */
        $rows = $sets[0];

        $this->assertSame($rows->count(), (int) $sets[1]->first()->TotalRows, 'The count is of the same set as the page.');

        return $rows->keyBy(fn (object $r) => (int) $r->RepairId)->all();
    }
}
