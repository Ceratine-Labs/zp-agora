<?php

namespace Tests\Feature\Cash;

use App\Exceptions\AgoraProcException;
use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Cash\Services\DeductionService;
use Modules\Cash\Services\StaffShortService;
use Modules\Core\Models\Permission;
use Modules\Core\Models\User;
use Modules\Core\Models\UserPermission;
use Modules\Core\Services\PermissionService;
use Tests\Fixtures\GrantsAccess;
use Tests\TestCase;

/**
 * Write once, flow everywhere (T042): a short captured once reaches the
 * cashup, the deduction schedule and the payroll file, once each, and is
 * never keyed again.
 *
 * Nothing copies the short along the way. The cashup reads the pump-shortages
 * line the post wrote; the schedule reads the cashup; the file reads the
 * schedule. So "once each" is a property of the reads, and this test counts
 * them.
 *
 * THE FIXTURE (branch 999, April 2026, tills 71/72, shift 7, all TEST-):
 *
 *   TWO01  the cashier on till 71: Z-read R1 000, R880 banked, R120 short
 *   TWO02  a pump attendant, whose R120 pump short is what was missing
 *   TWO03  a cashier whose R50 cash-bag short a recon match raised
 *
 * Writes the LOCAL stub and Agora's database only; tearDown() removes it all.
 */
class WriteOnceTest extends TestCase
{
    use GrantsAccess;

    private const BRANCH = 999;

    private const DAY = '2026-04-14';

    private const FROM = '2026-04-01';

    private const TO = '2026-04-30';

    private const BAG_SHORT = 990101;

    /** @var array<int, User> */
    private array $made = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessLocalStub('This fixture writes a cashup and staff shorts into the PumpIT stub.');

        if (! Permission::query()->withoutGlobalScopes()->where('Code', 'cash.staffshort.approve')->exists()) {
            $this->markTestSkipped('Run: php artisan seed:master --only=StaffShortPermissionSeeder');
        }

        config(['cash.staffshort.write_mode' => 'live', 'cash.cashup.write_mode' => 'live']);
        Mail::fake();

        $this->cleanUp();
        $this->seedFixture();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->cleanUp();

            foreach ($this->made as $user) {
                UserPermission::query()->acrossBranches()->where('UserId', $user->Id)->delete();
                $this->db()->table('agora.UserGridColumn')->where('UserId', $user->Id)->delete();
                User::query()->acrossBranches()->where('Id', $user->Id)->forceDelete();
            }

            app(PermissionService::class)->forgetAll();
        }

        parent::tearDown();
    }

    public function test_one_pump_short_makes_one_cashup_effect_one_deduction_and_one_payroll_line(): void
    {
        $admin = $this->person();
        $shorts = app(StaffShortService::class);
        $unpostedBefore = $this->unposted();

        $this->assertEqualsWithDelta(-120.00, (float) $this->cashup()->Variance, 0.001, 'The cashier is R120 short before anything is captured.');

        // Capture: raised against the cashup it belongs to, approved.
        $no = (int) $shorts->raise(self::BRANCH, ['employee' => 'TWO02', 'amount' => '120.00', 'day' => self::DAY,
            'reason' => 'pump_short', 'note' => 'TEST-WO pump 3', 'till' => 71, 'shift' => 7], (int) $admin->Id)->StaffShortsNo;
        $shorts->approve(self::BRANCH, $no, (int) $admin->Id);

        // Approved but on no cashup: on no deduction, and the screen says so.
        $this->assertSame((int) $unpostedBefore->Shorts + 1, (int) $this->unposted()->Shorts);
        $this->assertEqualsWithDelta((float) $unpostedBefore->Amount + 120.00, (float) $this->unposted()->Amount, 0.001);
        $this->actingAs($admin)->get(route('app.cash.deductions', $this->scope()))
            ->assertOk()->assertSee('approved staff short is on no cashup yet');
        $this->assertSame(0, $this->build()->where('SourceRef', "Staff short {$no}")->count());

        // Posted once — and a second post is refused.
        $shorts->post(self::BRANCH, $no, null, null, null, (int) $admin->Id);
        try {
            $shorts->post(self::BRANCH, $no, self::DAY, 71, 7, (int) $admin->Id);
            $this->fail('A short was posted twice.');
        } catch (AgoraProcException $e) {
            $this->assertSame('CASH_SHORT_POSTED', $e->code());
        }

        // ONE cashup effect: one pump-shortages line, and the variance moved by exactly the short.
        $this->assertSame(1, $this->db()->table('PumpIT.dbo.BRN_DailyBankingEmployees')
            ->where('SSBranchId', self::BRANCH)->where('StaffShortsNo', $no)->count());
        $this->assertEqualsWithDelta(0.00, (float) $this->cashup()->Variance, 0.001, 'The cashier is no longer short: the attendant is.');
        $this->assertSame((int) $unpostedBefore->Shorts, (int) $this->unposted()->Shorts);
        $this->actingAs($admin)->get(route('app.cash.deductions', $this->scope()))
            ->assertOk()->assertDontSee('approved staff short is on no cashup yet');

        // ONE deduction row, on the attendant; none left on the cashier.
        $lines = $this->build();
        $mine = $lines->where('SourceRef', "Staff short {$no}");
        $this->assertCount(1, $mine);
        $this->assertSame(['pump_short', 'TWO02'], [$mine->first()->Source, trim($mine->first()->EmployeeCode)]);
        $this->assertEqualsWithDelta(-120.00, (float) $mine->first()->Deducted, 0.001);
        $this->assertCount(0, $lines->filter(fn ($l) => trim($l->EmployeeCode) === 'TWO01'), 'The cashier is not deducted for the same R120.');

        // ONE payroll line.
        $file = $this->payroll();
        $this->assertCount(1, $file->filter(fn ($l) => trim($l->EmployeeCode) === 'TWO02'));
        $this->assertEqualsWithDelta(120.00, (float) $file->first(fn ($l) => trim($l->EmployeeCode) === 'TWO02')->CashShort, 0.001);
        $this->assertCount(0, $file->filter(fn ($l) => trim($l->EmployeeCode) === 'TWO01'));
    }

    public function test_a_short_left_unposted_leaves_the_cashier_deducted_instead_never_both(): void
    {
        $admin = $this->person();
        $shorts = app(StaffShortService::class);

        $no = (int) $shorts->raise(self::BRANCH, ['employee' => 'TWO02', 'amount' => '120.00', 'day' => self::DAY,
            'reason' => 'pump_short', 'note' => 'TEST-WO pump 3', 'till' => 71, 'shift' => 7], (int) $admin->Id)->StaffShortsNo;
        $shorts->approve(self::BRANCH, $no, (int) $admin->Id);

        $lines = $this->build();

        $this->assertCount(1, $lines->filter(fn ($l) => trim($l->EmployeeCode) === 'TWO01' && $l->Source === 'cashier_short'));
        $this->assertCount(0, $lines->filter(fn ($l) => trim($l->EmployeeCode) === 'TWO02'), 'The R120 is deducted once, from whoever the cashup says.');
    }

    public function test_a_cash_bag_short_reaches_payroll_once_without_touching_a_cashup(): void
    {
        $before = (float) $this->cashup()->Variance;
        $unpostedBefore = (int) $this->unposted()->Shorts;

        $this->bagShort();

        $this->assertEqualsWithDelta($before, (float) $this->cashup()->Variance, 0.001, 'No cashup effect: it was found at the bank.');
        $this->assertSame(0, $this->db()->table('PumpIT.dbo.BRN_DailyBankingEmployees')
            ->where('SSBranchId', self::BRANCH)->where('StaffShortsNo', self::BAG_SHORT)->count());
        $this->assertSame($unpostedBefore, (int) $this->unposted()->Shorts, 'Not reported as unposted: it reaches payroll directly.');

        $mine = $this->build()->where('SourceKey', 'short|'.self::BAG_SHORT);
        $this->assertCount(1, $mine);
        $this->assertSame(['cashbag_short', 'TWO03'], [$mine->first()->Source, trim($mine->first()->EmployeeCode)]);

        $file = $this->payroll()->filter(fn ($l) => trim($l->EmployeeCode) === 'TWO03');
        $this->assertCount(1, $file);
        $this->assertEqualsWithDelta(50.00, (float) $file->first()->CashShort, 0.001);
    }

    // ---------------------------------------------------------------- helpers

    /** @return Collection<int, \stdClass> the schedule lines of a fresh build */
    private function build(): Collection
    {
        $result = app(DeductionService::class)->build([self::BRANCH], Carbon::parse(self::FROM), Carbon::parse(self::TO), 1, 'TEST-write-once');
        $this->assertSame([], $result['refused']);

        return $this->db()->table('agora.Deduction')->where('BranchId', self::BRANCH)
            ->where('RunId', (int) $result['built'][self::BRANCH]->Id)->get();
    }

    /** @return Collection<int, object> */
    private function payroll(): Collection
    {
        return app(DeductionService::class)->exportPayroll([self::BRANCH], Carbon::parse(self::FROM), Carbon::parse(self::TO), 1)['lines'];
    }

    private function unposted(): object
    {
        return app(DeductionService::class)->unposted([self::BRANCH], [], Carbon::parse(self::FROM), Carbon::parse(self::TO));
    }

    private function cashup(): object
    {
        return app(ProcedureService::class)->callSets('usp_Cash_GetCashup', [
            'BranchId' => self::BRANCH, 'DateFrom' => self::DAY, 'DateTo' => self::DAY, 'TillNo' => 71, 'ShiftNo' => 7,
        ])[0]->sole();
    }

    /** @return array<string, string> */
    private function scope(): array
    {
        return ['branches' => (string) self::BRANCH, 'from' => self::FROM, 'to' => self::TO];
    }

    private function bagShort(): void
    {
        $at = '2026-04-15 00:00:00';

        $this->db()->table('PumpIT.dbo.BRN_StaffShorts')->insert([
            'SSBranchId' => self::BRANCH, 'StaffShortsNo' => self::BAG_SHORT, 'EmployeeCode' => 'TWO03', 'AmountShort' => 50,
            'Note' => 'TEST-WO bag short', 'Reason' => 'Cashier short', 'TransactionDate' => $at, 'CreatedByUserId' => 1,
            'Approved' => 1, 'ApprovedDate' => $at, 'ApprovedByUserId' => 1, 'IsDeclined' => 0, 'DeclinedByUserId' => 0,
            'IsSelectedForDailyBanking' => 0, 'CreateDateTime' => $at,
        ]);
        $this->db()->table('agora.StaffShort')->insert([
            'BranchId' => self::BRANCH, 'StaffShortsNo' => self::BAG_SHORT, 'EmployeeCode' => 'TWO03', 'AmountShort' => 50,
            'TransactionDate' => '2026-04-15', 'ReasonCode' => 'cashier_short', 'TillNo' => 72, 'ShiftNo' => 7,
            'Source' => 'recon', 'SourceId' => self::BAG_SHORT, 'SourceRef' => 'TEST-WO recon', 'RaisedMailAt' => now(),
            'DecidedMailAt' => now(), 'CreatedAt' => now(),
        ]);
    }

    private function seedFixture(): void
    {
        $pumpit = fn (string $table) => $this->db()->table("PumpIT.dbo.{$table}");

        foreach ([['TWO01', 'Cashier', 0], ['TWO02', 'Attendant', 1], ['TWO03', 'Bagger', 0]] as [$code, $first, $pump]) {
            $pumpit('BRN_Employee')->insert(['SSBranchId' => self::BRANCH, 'EmployeeCode' => $code, 'FirstName' => 'TEST-'.$first,
                'Surname' => 'WriteOnce', 'IsActive' => 1, 'IsPumpAttendant' => $pump, 'PostNo' => 0]);
        }
        foreach ([71 => 'TEST-WO Till A', 72 => 'TEST-WO Till B'] as $no => $name) {
            $pumpit('BRN_Tills')->insert(['SSBranchId' => self::BRANCH, 'TillNo' => $no, 'TillDescription' => $name, 'Location' => 'WINBRANCH']);
        }
        $pumpit('BRN_Shifts')->insert(['SSBranchId' => self::BRANCH, 'ShiftNo' => 7, 'ShiftDescription' => 'TEST-WO Day', 'IsActive' => 1]);

        $at = self::DAY.' 00:00:00';
        $pumpit('BRN_DailyBanking')->insert([
            'SSBranchId' => self::BRANCH, 'TillNo' => 71, 'ShiftNo' => 7, 'TransactionDate' => $at, 'EmployeeCode' => 'TWO01',
            'ClosingBalance' => 1000, 'CreditCardAmount' => 0, 'CardAmount1' => 0, 'CardAmount2' => 0, 'EFuelAmount' => 0,
            'DirectDepositAmount' => 0, 'EmployeeAmount' => -120, 'Note' => 'TEST-WO', 'DayEndNo' => '902', 'Posted' => 0,
            'Imported' => 0, 'CreateDateTime' => $at,
        ]);
        $pumpit('BRN_DailyBankingCashBags')->insert(['SSBranchId' => self::BRANCH, 'TillNo' => 71, 'ShiftNo' => 7, 'TransactionDate' => $at,
            'CashBagNo' => 'TEST-WO1', 'CashBagAmount' => 880, 'ReconBatchNoPumpIT' => 0, 'Imported' => 0]);
    }

    private function cleanUp(): void
    {
        $db = $this->db();
        $numbers = $db->table('PumpIT.dbo.BRN_StaffShorts')->where('SSBranchId', self::BRANCH)
            ->where('EmployeeCode', 'like', 'TWO%')->pluck('StaffShortsNo')->all();

        $db->table('agora.Deduction')->where('BranchId', self::BRANCH)
            ->whereIn('RunId', $db->table('agora.DeductionRun')->where('BranchId', self::BRANCH)->where('FromDate', self::FROM)->select('Id'))->delete();
        $db->table('agora.DeductionRun')->where('BranchId', self::BRANCH)->where('FromDate', self::FROM)->delete();
        $db->table('agora.StaffShortEvent')->where('BranchId', self::BRANCH)
            ->where(fn ($q) => $q->where('EmployeeCode', 'like', 'TWO%')->orWhereIn('StaffShortsNo', $numbers ?: [0]))->delete();
        $db->table('agora.StaffShort')->where('BranchId', self::BRANCH)->where('EmployeeCode', 'like', 'TWO%')->delete();
        $db->table('PumpIT.dbo.BRN_StaffShorts')->where('SSBranchId', self::BRANCH)->where('EmployeeCode', 'like', 'TWO%')->delete();
        $db->table('PumpIT.dbo.BRN_DailyBankingEmployees')->where('SSBranchId', self::BRANCH)->whereIn('TillNo', [71, 72])->delete();
        $db->table('PumpIT.dbo.BRN_DailyBankingCashBags')->where('SSBranchId', self::BRANCH)->whereIn('TillNo', [71, 72])->delete();

        $cashups = $db->table('agora.Cashup')->where('BranchId', self::BRANCH)->whereIn('TillNo', [71, 72])->pluck('Id')->all();
        $db->table('agora.CashupEvent')->where('BranchId', self::BRANCH)->whereIn('CashupId', $cashups ?: [0])->delete();
        $db->table('agora.Cashup')->where('BranchId', self::BRANCH)->whereIn('TillNo', [71, 72])->delete();
        $db->table('PumpIT.dbo.BRN_DailyBanking')->where('SSBranchId', self::BRANCH)->whereIn('TillNo', [71, 72])->delete();

        $db->table('PumpIT.dbo.BRN_Employee')->where('SSBranchId', self::BRANCH)->whereIn('EmployeeCode', ['TWO01', 'TWO02', 'TWO03'])->delete();
        $db->table('PumpIT.dbo.BRN_Tills')->where('SSBranchId', self::BRANCH)->whereIn('TillNo', [71, 72])->delete();
        $db->table('PumpIT.dbo.BRN_Shifts')->where('SSBranchId', self::BRANCH)->where('ShiftNo', 7)->where('ShiftDescription', 'TEST-WO Day')->delete();
    }

    private function person(): User
    {
        $user = User::query()->acrossBranches()->create([
            'BranchId' => (int) config('agora.group_branch_id', 2),
            'EmailAddress' => 'TEST-t042-'.uniqid().'@agora.invalid',
            'UserName' => 'TEST-write-once',
            'IsActive' => true,
            'IsLocked' => false,
            'Workspace' => 'ho',
            'PasswordHash' => 'TEST-only-'.Str::random(24),
        ]);

        $this->grantProfile($user, 'admin');
        $this->made[] = $user;

        return $user;
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }
}
