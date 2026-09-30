<?php

namespace Tests\Feature\Cash;

use App\Exceptions\AgoraProcException;
use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Cash\Services\DeductionService;
use Modules\Core\Models\User;
use Modules\Core\Models\UserPermission;
use Tests\Fixtures\GrantsAccess;
use Tests\TestCase;

/**
 * Deductions and the payroll file (T041).
 *
 * The tabs are a port of PumpIT's payroll reports and were proved row for row
 * against the legacy procedures on real data (Engen Bethlehem, August 2026 —
 * docs/cash-deductions.md). This file proves the RULES on a fixture built so
 * every one of them has something to bite on:
 *
 *   a cashier R10 short          deducted — worse than the R3 leniency
 *   a cashier R2 short           written off — at or inside it, never listed
 *   a pump attendant R5 short    deducted in full
 *   a cash-bag short of R20      deducted in full, though it is on no cashup
 *   R20 of stock loss in a group with R31 of allowance      written off
 *   R20 of stock loss in a group with R3.10 of allowance    deducted in full
 *   R5 lost on the Lotto area by two people                 R2.50 each
 *
 * The fixture is branch 999 in May 2026, on its own tills (61, 62) and areas
 * (61–63), so it shares nothing with the other Cash fixtures on the branch.
 * tearDown() removes exactly what it wrote.
 */
class DeductionsTest extends TestCase
{
    use GrantsAccess;

    private const BRANCH = 999;

    private const FROM = '2026-05-01';

    private const TO = '2026-05-31';

    private const SHIFT = 7;

    private const EMPLOYEES = ['TDD01' => 'Cashier', 'TDD02' => 'Attendant', 'TDD03' => 'Shopfloor'];

    private const SHORT_NO = 990001;

    /** @var array<int, User> */
    private array $made = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessLocalStub();
        $this->cleanUp();
        $this->seedFixture();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            foreach ($this->made as $user) {
                UserPermission::query()->acrossBranches()->where('UserId', $user->Id)->delete();
                $this->db()->table('agora.UserGridColumn')->where('UserId', $user->Id)->delete();
                User::query()->acrossBranches()->where('Id', $user->Id)->forceDelete();
            }
            $this->cleanUp();
        }

        parent::tearDown();
    }

    public function test_the_cash_tab_lists_what_is_deducted_and_counts_what_is_written_off(): void
    {
        [$rows, $totals] = $this->grid('usp_Cash_GridDeductionCashShorts', ['Leniency' => 3.00]);

        $bySource = $rows->keyBy('Source');

        $this->assertCount(3, $rows, 'A cashier short over the leniency, a pump short and a cash-bag short — not the R2.');
        $this->assertEqualsWithDelta(-10.00, (float) $bySource['cashier_short']->Deduction, 0.001);
        $this->assertEqualsWithDelta(-3.00, (float) $bySource['cashier_short']->LeniencyAllowance, 0.001);
        $this->assertEqualsWithDelta(-5.00, (float) $bySource['pump_short']->Deduction, 0.001);
        $this->assertSame('TDD02', trim($bySource['pump_short']->EmployeeCode));
        $this->assertEqualsWithDelta(-20.00, (float) $bySource['cashbag_short']->Deduction, 0.001);
        $this->assertSame(self::SHORT_NO, (int) $bySource['cashbag_short']->StaffShortsNo);

        $this->assertEqualsWithDelta(-12.00, (float) $totals->AllCashierShorts, 0.001, 'Every cashier short, before leniency.');
        $this->assertSame(1, (int) $totals->WrittenOffShifts);
        $this->assertEqualsWithDelta(-2.00, (float) $totals->WrittenOffAmount, 0.001);
        $this->assertEqualsWithDelta(-35.00, (float) $totals->Deduction, 0.001);
    }

    public function test_a_short_exactly_at_the_leniency_is_written_off(): void
    {
        // Move the R2 short to exactly R3: "at or inside" is written off.
        $this->db()->table('PumpIT.dbo.BRN_DailyBankingCashBags')
            ->where('SSBranchId', self::BRANCH)->where('CashBagNo', 'TEST-DD2')->update(['CashBagAmount' => 97]);

        [$rows, $totals] = $this->grid('usp_Cash_GridDeductionCashShorts', ['Leniency' => 3.00]);

        $this->assertCount(3, $rows);
        $this->assertEqualsWithDelta(-3.00, (float) $totals->WrittenOffAmount, 0.001);
    }

    public function test_an_approved_cash_bag_short_on_a_cashup_is_not_counted_twice(): void
    {
        // Were it posted (it never is — PM decision 01a0e5b5), the pump-shortages
        // line would carry it, and the cash-bag source must not add it again.
        $this->db()->table('PumpIT.dbo.BRN_DailyBankingEmployees')->insert([
            'SSBranchId' => self::BRANCH, 'TillNo' => 62, 'ShiftNo' => self::SHIFT, 'TransactionDate' => '2026-05-06 00:00:00',
            'EmployeeCode' => 'TDD01', 'AmountShort' => 20, 'StaffShortsNo' => self::SHORT_NO,
        ]);

        [$rows] = $this->grid('usp_Cash_GridDeductionCashShorts', ['Leniency' => 3.00]);

        $this->assertFalse($rows->contains('Source', 'cashbag_short'));
        $this->assertTrue($rows->contains(fn ($r) => $r->Source === 'pump_short' && (int) $r->StaffShortsNo === self::SHORT_NO));
    }

    public function test_the_stock_and_non_integrated_tabs_share_the_loss_among_the_people_on_the_area(): void
    {
        [$summary] = $this->grid('usp_Cash_GridStockLossSummary');
        [$detail] = $this->grid('usp_Cash_GridStockLossDetail');
        [$nonint] = $this->grid('usp_Cash_GridNonIntPosSummary');
        [$nonintDetail] = $this->grid('usp_Cash_GridNonIntPosDetail');

        $this->assertCount(2, $summary, 'TDD03 on each of the two stock areas; the Lotto area is not stock.');
        $this->assertTrue($summary->every(fn ($r) => trim($r->EmployeeCode) === 'TDD03' && abs((float) $r->EmployeeTotalLoss + 20) < 0.001));
        $this->assertCount(2, $detail);
        $this->assertEqualsWithDelta(1.00, (float) $detail->firstWhere('AreaNo', 61)->LossGraceDaily, 0.001);

        $this->assertCount(2, $nonint, 'Two people on the Lotto area share its R5 loss.');
        $this->assertTrue($nonint->every(fn ($r) => abs((float) $r->EmployeeTotalLoss + 2.50) < 0.001));
        $this->assertCount(2, $nonintDetail);
    }

    public function test_a_person_with_no_post_drops_out_of_the_stock_tabs_as_in_the_legacy(): void
    {
        $this->db()->table('PumpIT.dbo.BRN_Employee')->where('SSBranchId', self::BRANCH)->where('EmployeeCode', 'TDD03')
            ->update(['PostNo' => 92]);

        [$summary] = $this->grid('usp_Cash_GridStockLossSummary');

        $this->assertCount(0, $summary, 'The legacy INNER JOINs BRN_EmployeePost; post 92 does not exist.');
    }

    public function test_the_build_records_one_row_per_source_and_applies_each_allowance(): void
    {
        $run = $this->build();
        $lines = $this->lines($run->Id);

        $this->assertSame(8, $lines->count());
        $this->assertSame(
            ['cashbag_short' => 1, 'cashier_short' => 2, 'nonint_pos' => 2, 'pump_short' => 1, 'stock_loss' => 2],
            $lines->countBy('Source')->sortKeys()->all(),
        );

        $loose = $lines->first(fn ($l) => $l->Source === 'stock_loss' && $l->AreaGroup === 'TEST-DD Loose');
        $tight = $lines->first(fn ($l) => $l->Source === 'stock_loss' && $l->AreaGroup === 'TEST-DD Tight');

        $this->assertEqualsWithDelta(-31.00, (float) $loose->Allowance, 0.001, '31 days x R1.00 a day.');
        $this->assertTrue((bool) $loose->IsWrittenOff, 'R20 inside R31 of allowance is written off…');
        $this->assertEqualsWithDelta(0.00, (float) $loose->Deducted, 0.001);
        $this->assertEqualsWithDelta(-3.10, (float) $tight->Allowance, 0.001);
        $this->assertEqualsWithDelta(-20.00, (float) $tight->Deducted, 0.001, '…and R20 beyond R3.10 is deducted IN FULL, not the excess.');

        $this->assertEqualsWithDelta(-60.00, (float) $run->Deducted, 0.001);
        $this->assertEqualsWithDelta(-22.00, (float) $run->WrittenOff, 0.001);
        $this->assertEqualsWithDelta(-35.00, (float) $run->CashDeducted, 0.001);
        $this->assertEqualsWithDelta(-20.00, (float) $run->StockDeducted, 0.001);
        $this->assertEqualsWithDelta(-5.00, (float) $run->NonIntDeducted, 0.001);
    }

    public function test_the_payroll_file_adds_up_to_what_the_schedule_deducts(): void
    {
        $run = $this->build();
        $file = $this->service()->exportPayroll([self::BRANCH], Carbon::parse(self::FROM), Carbon::parse(self::TO), 1);

        $byPerson = $file['lines']->keyBy(fn ($l) => trim($l->EmployeeCode));

        $this->assertCount(3, $byPerson);
        $this->assertEqualsWithDelta(32.50, (float) $byPerson['TDD01']->Total, 0.001, 'R10 + R20 cash, R2.50 Lotto.');
        $this->assertEqualsWithDelta(30.00, (float) $byPerson['TDD01']->CashShort, 0.001);
        $this->assertEqualsWithDelta(5.00, (float) $byPerson['TDD02']->Total, 0.001);
        $this->assertEqualsWithDelta(22.50, (float) $byPerson['TDD03']->Total, 0.001, 'R20 stock (the tight group only), R2.50 Lotto.');
        $this->assertEqualsWithDelta(-(float) $run->Deducted, $file['lines']->sum(fn ($l) => (float) $l->Total), 0.001,
            'The file totals equal the Deducted stat — the acceptance check.');

        $this->assertSame('exported', $this->db()->table('agora.DeductionRun')->where('BranchId', self::BRANCH)
            ->where('Id', $run->Id)->value('Status'));
    }

    public function test_a_rebuild_supersedes_and_an_exported_period_is_not_rebuilt(): void
    {
        $first = $this->build();
        $second = $this->build();

        $this->assertSame('superseded', $this->db()->table('agora.DeductionRun')->where('BranchId', self::BRANCH)
            ->where('Id', $first->Id)->value('Status'));
        $this->assertSame((int) $second->Id, (int) $this->db()->table('agora.DeductionRun')->where('BranchId', self::BRANCH)
            ->where('Id', $first->Id)->value('SupersededBy'));
        $this->assertSame(8, $this->lines($first->Id)->count(), 'A superseded run keeps its rows.');

        $this->service()->exportPayroll([self::BRANCH], Carbon::parse(self::FROM), Carbon::parse(self::TO), 1);

        $result = $this->service()->build([self::BRANCH], Carbon::parse('2026-05-10'), Carbon::parse('2026-05-20'), 1);
        $this->assertArrayHasKey(self::BRANCH, $result['refused']);
        $this->assertStringContainsString('not rebuilt', $result['refused'][self::BRANCH]);
    }

    public function test_the_six_tabs_render_and_the_build_and_export_presses_work(): void
    {
        $user = $this->person('admin');
        $scope = ['branches' => (string) self::BRANCH, 'from' => self::FROM, 'to' => self::TO];

        foreach (['app.cash.deductions', 'app.cash.deductions.stockloss', 'app.cash.deductions.stocklossdetail',
            'app.cash.deductions.nonint', 'app.cash.deductions.nonintdetail', 'app.cash.deductions.payroll'] as $route) {
            $this->actingAs($user)->get(route($route, $scope))->assertOk();
        }

        $this->actingAs($user)->get(route('app.cash.deductions', $scope))
            ->assertSee('Leniency allowed')->assertSee('usp_Cash_GridDeductionCashShorts');

        $this->actingAs($user)
            ->post(route('app.cash.deductions.build'), ['branches' => [self::BRANCH], 'from' => self::FROM, 'to' => self::TO])
            ->assertRedirect()->assertSessionHas('deductionBuilt');

        $csv = $this->actingAs($user)
            ->post(route('app.cash.deductions.export'), ['branches' => [self::BRANCH], 'from' => self::FROM, 'to' => self::TO]);
        $csv->assertOk();
        $this->assertStringContainsString('EmployeeCode', $csv->streamedContent());
        $this->assertStringContainsString('TDD01', $csv->streamedContent());
    }

    public function test_a_period_that_has_not_ended_is_refused(): void
    {
        try {
            app(ProcedureService::class)->callSets('usp_Cash_BuildDeductions', [
                'BranchId' => self::BRANCH, 'FromDate' => Carbon::today()->startOfMonth()->toDateString(),
                'ToDate' => Carbon::today()->toDateString(), 'UserId' => 1,
            ]);
            $this->fail('A period that has not ended was built.');
        } catch (AgoraProcException $e) {
            $this->assertSame('DEDUCTION_PERIOD', $e->code());
        }

        $this->assertSame(0, $this->db()->table('agora.DeductionRun')->where('BranchId', self::BRANCH)->count());
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param  array<string, mixed>  $extra
     * @return array{0: Collection<int, object>, 1: object}
     */
    private function grid(string $procedure, array $extra = []): array
    {
        $sets = app(ProcedureService::class)->callSets($procedure, [
            'BranchIds' => (string) self::BRANCH, 'DateFrom' => self::FROM, 'DateTo' => self::TO, 'PageSize' => 1000,
        ] + $extra);

        return [$sets[0] ?? collect(), ($sets[2] ?? collect())->first() ?? (object) []];
    }

    private function build(): object
    {
        $result = $this->service()->build([self::BRANCH], Carbon::parse(self::FROM), Carbon::parse(self::TO), 1, 'TEST-deductions');
        $this->assertSame([], $result['refused']);

        return $this->db()->table('agora.DeductionRun')->where('BranchId', self::BRANCH)
            ->where('Id', (int) $result['built'][self::BRANCH]->Id)->first();
    }

    /** @return Collection<int, \stdClass> */
    private function lines(int|string $runId): Collection
    {
        return $this->db()->table('agora.Deduction')->where('BranchId', self::BRANCH)->where('RunId', (int) $runId)->get();
    }

    private function seedFixture(): void
    {
        $db = $this->db();
        $pumpit = fn (string $table) => $db->table("PumpIT.dbo.{$table}");

        $pumpit('BRN_EmployeePost')->insert(['SSBranchId' => self::BRANCH, 'PostNo' => 91, 'PostDescription' => 'TEST-DD Post', 'StockControl' => 0]);
        foreach (self::EMPLOYEES as $code => $first) {
            $pumpit('BRN_Employee')->insert(['SSBranchId' => self::BRANCH, 'EmployeeCode' => $code, 'FirstName' => 'TEST-'.$first,
                'Surname' => 'Deduction', 'NickName' => $first, 'IsActive' => 1, 'IsPumpAttendant' => $code === 'TDD02' ? 1 : 0, 'PostNo' => 91]);
        }
        foreach ([61 => 'TEST-DD Till A', 62 => 'TEST-DD Till B'] as $no => $name) {
            $pumpit('BRN_Tills')->insert(['SSBranchId' => self::BRANCH, 'TillNo' => $no, 'TillDescription' => $name, 'Location' => 'WINBRANCH']);
        }
        $pumpit('BRN_Shifts')->insert(['SSBranchId' => self::BRANCH, 'ShiftNo' => self::SHIFT, 'ShiftDescription' => 'TEST-DD Day', 'IsActive' => 1]);

        // Three cashups by TDD01: R10 short, R2 short, and one whose only short is the pump attendant's.
        foreach ([['2026-05-05', 61, 'TEST-DD1', 90], ['2026-05-06', 61, 'TEST-DD2', 98], ['2026-05-06', 62, 'TEST-DD3', 95]] as [$day, $till, $bag, $banked]) {
            $at = $day.' 00:00:00';
            $pumpit('BRN_DailyBanking')->insert([
                'SSBranchId' => self::BRANCH, 'TillNo' => $till, 'ShiftNo' => self::SHIFT, 'TransactionDate' => $at, 'EmployeeCode' => 'TDD01',
                'ClosingBalance' => 100, 'CreditCardAmount' => 0, 'CardAmount1' => 0, 'CardAmount2' => 0, 'EFuelAmount' => 0,
                'DirectDepositAmount' => 0, 'EmployeeAmount' => 0, 'Note' => 'TEST-DD', 'DayEndNo' => '901', 'Posted' => 0,
                'Imported' => 0, 'CreateDateTime' => $at,
            ]);
            $pumpit('BRN_DailyBankingCashBags')->insert(['SSBranchId' => self::BRANCH, 'TillNo' => $till, 'ShiftNo' => self::SHIFT,
                'TransactionDate' => $at, 'CashBagNo' => $bag, 'CashBagAmount' => $banked, 'ReconBatchNoPumpIT' => 0, 'Imported' => 0]);
        }
        $pumpit('BRN_DailyBankingEmployees')->insert(['SSBranchId' => self::BRANCH, 'TillNo' => 62, 'ShiftNo' => self::SHIFT,
            'TransactionDate' => '2026-05-06 00:00:00', 'EmployeeCode' => 'TDD02', 'AmountShort' => 5, 'StaffShortsNo' => 0]);

        // A cash-bag short a recon match raised, approved, on no cashup.
        $pumpit('BRN_StaffShorts')->insert([
            'SSBranchId' => self::BRANCH, 'StaffShortsNo' => self::SHORT_NO, 'EmployeeCode' => 'TDD01', 'AmountShort' => 20,
            'Note' => 'TEST-DD bag short', 'Reason' => 'Cashier short', 'TransactionDate' => '2026-05-07 00:00:00', 'CreatedByUserId' => 1,
            'Approved' => 1, 'ApprovedDate' => '2026-05-08 00:00:00', 'ApprovedByUserId' => 1, 'IsDeclined' => 0, 'DeclinedByUserId' => 0,
            'IsSelectedForDailyBanking' => 0, 'CreateDateTime' => '2026-05-07 00:00:00',
        ]);
        $db->table('agora.StaffShort')->insert([
            'BranchId' => self::BRANCH, 'StaffShortsNo' => self::SHORT_NO, 'EmployeeCode' => 'TDD01', 'AmountShort' => 20,
            'TransactionDate' => '2026-05-07', 'ReasonCode' => 'cashier_short', 'TillNo' => 61, 'ShiftNo' => self::SHIFT,
            'Source' => 'recon', 'SourceId' => self::SHORT_NO, 'SourceRef' => 'TEST-DD recon', 'CreatedAt' => now(),
        ]);

        // Stock: two area groups with different allowances, and a Lotto area.
        $pumpit('STK_AreaGroup')->insert([
            ['AreaGroupId' => 9901, 'AreaGroupDescription' => 'TEST-DD Loose', 'LossGraceMonthly' => 31, 'LossGraceDaily' => 1.00],
            ['AreaGroupId' => 9902, 'AreaGroupDescription' => 'TEST-DD Tight', 'LossGraceMonthly' => 3, 'LossGraceDaily' => 0.10],
        ]);
        foreach ([61 => ['TEST-DD Food', 'TEST-DD Loose'], 62 => ['Lotto/Electricity/Airtime', 'TEST-DD Loose'], 63 => ['TEST-DD Shop', 'TEST-DD Tight']] as $no => [$name, $group]) {
            $pumpit('STK_Area')->insert(['SSBranchId' => self::BRANCH, 'AreaNo' => $no, 'AreaDescription' => $name, 'AreaGroup' => $group,
                'DayShift' => 1, 'AfternoonShift' => 0, 'NightShift' => 0, 'ShowReport' => 1, 'IsCaptureWaste' => 0]);
        }
        foreach (['TDI1' => [61, 10], 'TDI2' => [62, 1], 'TDI3' => [63, 10]] as $item => [$area, $price]) {
            $pumpit('STK_StockMaster')->insert(['SSBranchId' => self::BRANCH, 'StockItemNo' => $item, 'StockItemDescription' => 'TEST-DD '.$item,
                'Location' => 'TEST', 'UOMCode' => 'EA', 'SellingPrice' => $price, 'AreaNo' => $area, 'QtyVarAllowance' => 0, 'isMonitoredItem' => 0, 'IsDoCloseQtyCalc' => 0]);
        }

        // [area, item, price, open, close, pos, people]: loss = (-open + close + pos) x price, shared.
        foreach ([[61, 'TDI1', 10, 10, 5, 3, ['TDD03']], [63, 'TDI3', 10, 10, 5, 3, ['TDD03']], [62, 'TDI2', 1, 100, 90, 5, ['TDD01', 'TDD03']]] as [$area, $item, $price, $open, $close, $pos, $people]) {
            $pumpit('STK_StockReconLine')->insert([
                'SSBranchId' => self::BRANCH, 'TransactionDate' => '2026-05-05', 'ShiftNo' => self::SHIFT, 'AreaNo' => $area, 'StockItemNo' => $item,
                'SellPrice' => $price, 'QtyOpen' => $open, 'QtyIssued' => 0, 'QtyClose' => $close, 'QtyComputer' => $pos,
                'QtyOpen_Original' => $open, 'QtyIssued_Original' => 0, 'QtyClose_Original' => $close, 'QtyComputer_Original' => $pos,
            ]);
            foreach ($people as $code) {
                $pumpit('STK_StockReconEmployees')->insert(['SSBranchId' => self::BRANCH, 'TransactionDate' => '2026-05-05',
                    'ShiftNo' => self::SHIFT, 'AreaNo' => $area, 'EmployeeCode' => $code]);
            }
        }
    }

    private function cleanUp(): void
    {
        $db = $this->db();
        $inMay = fn ($q, string $column = 'TransactionDate') => $q->where('SSBranchId', self::BRANCH)
            ->where($column, '>=', self::FROM)->where($column, '<', '2026-06-01');

        $db->table('agora.Deduction')->where('BranchId', self::BRANCH)->delete();
        $db->table('agora.DeductionRun')->where('BranchId', self::BRANCH)->delete();
        $db->table('agora.StaffShort')->where('BranchId', self::BRANCH)->where('StaffShortsNo', self::SHORT_NO)->delete();

        $inMay($db->table('PumpIT.dbo.BRN_DailyBanking'))->whereIn('TillNo', [61, 62])->delete();
        $inMay($db->table('PumpIT.dbo.BRN_DailyBankingCashBags'))->whereIn('TillNo', [61, 62])->delete();
        $inMay($db->table('PumpIT.dbo.BRN_DailyBankingEmployees'))->whereIn('TillNo', [61, 62])->delete();
        $db->table('PumpIT.dbo.BRN_StaffShorts')->where('SSBranchId', self::BRANCH)->where('StaffShortsNo', self::SHORT_NO)->delete();
        $inMay($db->table('PumpIT.dbo.STK_StockReconLine'))->whereIn('AreaNo', [61, 62, 63])->delete();
        $inMay($db->table('PumpIT.dbo.STK_StockReconEmployees'))->whereIn('AreaNo', [61, 62, 63])->delete();
        $db->table('PumpIT.dbo.STK_StockMaster')->where('SSBranchId', self::BRANCH)->whereIn('StockItemNo', ['TDI1', 'TDI2', 'TDI3'])->delete();
        $db->table('PumpIT.dbo.STK_Area')->where('SSBranchId', self::BRANCH)->whereIn('AreaNo', [61, 62, 63])->delete();
        $db->table('PumpIT.dbo.STK_AreaGroup')->whereIn('AreaGroupDescription', ['TEST-DD Loose', 'TEST-DD Tight'])->delete();
        $db->table('PumpIT.dbo.BRN_Shifts')->where('SSBranchId', self::BRANCH)->where('ShiftNo', self::SHIFT)->where('ShiftDescription', 'TEST-DD Day')->delete();
        $db->table('PumpIT.dbo.BRN_Tills')->where('SSBranchId', self::BRANCH)->whereIn('TillNo', [61, 62])->delete();
        $db->table('PumpIT.dbo.BRN_Employee')->where('SSBranchId', self::BRANCH)->whereIn('EmployeeCode', array_keys(self::EMPLOYEES))->delete();
        $db->table('PumpIT.dbo.BRN_EmployeePost')->where('SSBranchId', self::BRANCH)->where('PostNo', 91)->delete();
    }

    private function person(string $profile): User
    {
        $user = User::query()->acrossBranches()->create([
            'BranchId' => (int) config('agora.group_branch_id', 2),
            'EmailAddress' => 'TEST-t041-'.uniqid().'@agora.invalid',
            'UserName' => 'TEST-'.$profile,
            'IsActive' => true,
            'IsLocked' => false,
            'Workspace' => 'ho',
            'PasswordHash' => 'TEST-only-'.Str::random(24),
        ]);

        $this->grantProfile($user, $profile);
        $this->made[] = $user;

        return $user;
    }

    private function service(): DeductionService
    {
        return app(DeductionService::class);
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }
}
