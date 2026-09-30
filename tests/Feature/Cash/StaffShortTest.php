<?php

namespace Tests\Feature\Cash;

use App\Exceptions\AgoraProcException;
use App\Grid\GridRegistry;
use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Cash\Grids\StaffShortGrid;
use Modules\Cash\Mail\StaffShortDecidedMail;
use Modules\Cash\Mail\StaffShortRaisedMail;
use Modules\Cash\Services\CashupService;
use Modules\Cash\Services\StaffShortService;
use Modules\Cash\Support\CashupScope;
use Modules\Core\Models\MenuItem;
use Modules\Core\Models\Permission;
use Modules\Core\Models\User;
use Modules\Core\Models\UserBranch;
use Modules\Core\Models\UserPermission;
use Modules\Core\Services\PermissionService;
use Tests\Fixtures\GrantsAccess;
use Tests\TestCase;

/**
 * Staff shorts (T040): a short raised into PumpIT as its own client writes it,
 * under PumpIT's own counter and one of four reason codes; the approvers
 * mailed; approved or declined with a reason, the raiser mailed; posted to a
 * cashup's pump shortages — from the staff-shorts screen or the Daily Banking
 * leg, which is the same save — and counted there; the legacy's free-text
 * reasons read as the codes with the text kept; the screens and the list.
 *
 * THE FIXTURE (branch 999, all TEST- data in the LOCAL PumpIT stub):
 *
 *   employees   TSS01 Sipho (a pump attendant), TSS02 Lindiwe, TSS03 Zanele
 *   tills 81, 82 and shifts 7, 8 ("TEST-SS …"), numbered clear of every other
 *               suite's
 *   a cashup    till 81, shift 7, 18 Sep 2026: a header in PumpIT with a Z-read
 *               of R1 000.00 and nothing on it, so the variance is -R1 000.00
 *               and a posted short moves it by exactly its amount
 *
 * Every short raised is TSS…; the legacy ones are inserted at numbers 99 0501+.
 * Writes the LOCAL stub and Agora's database only, on branch 999, and removes
 * every row it wrote (the stub's section 2 counter moves on, as the recon
 * counter does in its tests). skipUnlessLocalStub guarantees it never runs
 * against the customer's instance.
 */
class StaffShortTest extends TestCase
{
    use GrantsAccess;

    private const BRANCH = 999;

    private const DAY = '2026-09-18';

    private const EMPLOYEES = [['TSS01', 'Sipho', 1], ['TSS02', 'Lindiwe', 0], ['TSS03', 'Zanele', 0]];

    /** @var array<int, User> */
    private array $made = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessLocalStub('This fixture writes staff shorts and a cashup into the PumpIT stub.');

        if (! Permission::query()->withoutGlobalScopes()->where('Code', 'cash.staffshort.approve')->exists()) {
            $this->markTestSkipped('Run: php artisan seed:master --only=StaffShortPermissionSeeder');
        }

        if (! $this->db()->table('agora.StaffShortReason')->where('Code', 'pump_short')->exists()) {
            $this->markTestSkipped('Run: php artisan seed:master --only=StaffShortReasonSeeder');
        }

        if ($this->db()->selectOne('SELECT COUNT(*) AS n FROM PumpIT.dbo.SS_UniqueNumber WHERE SectionId = 2')->n != 1) {
            $this->markTestSkipped('The stub has no staff-short counter: apply database/stubs/pumpit-cash.sql (section 7).');
        }

        // The line config/grids.php carries once this lane is merged; the
        // registration itself is asserted by test_the_list_grid_is_registered,
        // which does NOT add it.
        if (! app(GridRegistry::class)->has('app.cash.staffshorts.list')) {
            config(['grids.grids' => config('grids.grids') + ['app.cash.staffshorts.list' => StaffShortGrid::class]]);
        }

        config(['cash.staffshort.write_mode' => 'live', 'cash.cashup.write_mode' => 'live']);

        $this->cleanUp();
        $this->seedFixture();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->cleanUp();

            foreach ($this->made as $user) {
                UserPermission::query()->acrossBranches()->where('UserId', $user->Id)->delete();
                UserBranch::query()->acrossBranches()->where('UserId', $user->Id)->delete();
                $this->db()->table('agora.UserGridColumn')->where('UserId', $user->Id)->delete();
                User::query()->acrossBranches()->where('Id', $user->Id)->forceDelete();
            }

            app(PermissionService::class)->forgetAll();
        }

        parent::tearDown();
    }

    /* ----------------------------------------------------------------- raise */

    public function test_a_short_is_raised_into_pumpit_as_its_own_client_writes_it(): void
    {
        Mail::fake();
        $admin = $this->person('admin');
        $counter = $this->counter();

        $status = $this->raise($admin, ['note' => 'TEST-pump 4, customer drove off', 'till' => 81, 'shift' => 7]);

        $this->assertSame('CASH_SHORT_RAISED', $status->Code);
        $this->assertSame($counter, (int) $status->StaffShortsNo, 'The number is SS_UniqueNumber section 2 as it stood.');
        $this->assertSame($counter + 1, $this->counter(), 'And the counter moved on by one.');
        $this->assertStringContainsString("Staff short {$counter} raised in PumpIT: R120.50 against TEST-Shorts Sipho (TSS01), pump short", (string) $status->Message);

        $row = $this->pumpit($counter);
        $this->assertSame(
            ['TSS01', '120.50', 'TEST-pump 4, customer drove off', 'Pump short', self::DAY.' 00:00:00', 0, null, 0, 0, 0],
            [$row->EmployeeCode, number_format((float) $row->AmountShort, 2, '.', ''), $row->Note, $row->Reason,
                substr((string) $row->TransactionDate, 0, 19), (int) $row->Approved, $row->ApprovedByUserId,
                (int) $row->IsDeclined, (int) $row->DeclinedByUserId, (int) $row->IsSelectedForDailyBanking],
            'A waiting short, as PumpIT\'s client leaves one: the code\'s label as Reason, the note as Note.'
        );
        $this->assertNull($row->CreatedByUserId, 'An Agora user PumpIT never knew raises as NULL; Agora keeps who.');

        $agora = $this->db()->table('agora.StaffShort')->where('BranchId', self::BRANCH)->where('StaffShortsNo', $counter)->sole();
        $this->assertSame(['pump_short', 'screen', 81, 7, (int) $admin->Id], [$agora->ReasonCode, $agora->Source, (int) $agora->TillNo, (int) $agora->ShiftNo, (int) $agora->CreatedBy]);

        $event = $this->events($counter)->sole();
        $writes = json_decode((string) $event->WritesJson, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($writes);
        $this->assertSame(['raised', 2, $counter, $counter + 1, 'BRN_StaffShorts'], [
            $event->Event, $writes['counter']['sectionId'], $writes['counter']['prior'], $writes['counter']['new'], $writes['rows'][0]['table'],
        ]);

        $view = $this->shortRow($counter);
        $this->assertSame(['pump_short', 'Pump short', 'agora', 'Waiting', 'Agora', 'TEST-pump 4, customer drove off'],
            [$view->ReasonCode, $view->ReasonLabel, $view->ReasonFrom, $view->Status, $view->RaisedIn, $view->Note]);
    }

    public function test_raising_mails_the_approvers_and_nobody_else(): void
    {
        Mail::fake();
        $admin = $this->person('admin');
        $approver = $this->grant($this->person('auditor', 'approver'), ['cash.staffshort.approve', 'cash.staffshort.view']);
        $elsewhere = $this->grant($this->person('auditor', 'elsewhere'), ['cash.staffshort.approve']);
        UserBranch::query()->acrossBranches()->create(['BranchId' => 6, 'UserId' => $elsewhere->Id]);
        $raiserOnly = $this->grant($this->person('auditor', 'raiser'), ['cash.staffshort.raise', 'cash.staffshort.view']);

        $no = (int) $this->raise($admin, [])->StaffShortsNo;

        Mail::assertSent(StaffShortRaisedMail::class, fn (StaffShortRaisedMail $m) => $m->hasTo($approver->EmailAddress)
            && $m->short['no'] === $no && $m->short['amount'] === 'R120.50' && $m->short['reason'] === 'Pump short'
            && $m->short['employee'] === 'TEST-Shorts Sipho' && str_contains($m->url, 'short='.$no));
        // An approver whose sites do not include this one is not told, nor is anybody who cannot approve.
        Mail::assertNotSent(StaffShortRaisedMail::class, fn (StaffShortRaisedMail $m) => $m->hasTo($elsewhere->EmailAddress));
        Mail::assertNotSent(StaffShortRaisedMail::class, fn (StaffShortRaisedMail $m) => $m->hasTo($raiserOnly->EmailAddress));
        $this->assertSame(StaffShortRaisedMail::TEMPLATE_CODE, 'cash.staff_short_raised');

        $sent = Mail::sent(StaffShortRaisedMail::class)->count();
        $agora = $this->db()->table('agora.StaffShort')->where('BranchId', self::BRANCH)->where('StaffShortsNo', $no)->sole();
        $this->assertNotNull($agora->RaisedMailAt);
        $this->assertSame($sent, (int) $agora->RaisedMailTo);

        // Told once: the pending-mail run sends nothing more about it.
        app(StaffShortService::class)->sendPending();
        $this->assertSame($sent, Mail::sent(StaffShortRaisedMail::class, fn (StaffShortRaisedMail $m) => $m->short['no'] === $no)->count());
    }

    public function test_what_a_raise_refuses_and_that_nothing_is_written(): void
    {
        Mail::fake();
        $admin = $this->person('admin');
        $counter = $this->counter();

        foreach ([
            'CASH_SHORT_EMPLOYEE' => ['employee' => 'TSSNOPE'],
            'CASH_SHORT_AMOUNT' => ['amount' => '0'],
            'CASH_SHORT_REASON' => ['reason' => 'TEST-nope'],
            'CASH_SHORT_NOTE' => ['reason' => 'other', 'note' => null],
            'CASH_SHORT_DATE' => ['day' => now()->addDays(2)->toDateString()],
            'CASH_SHORT_CASHUP' => ['till' => 81, 'shift' => null],
        ] as $code => $change) {
            $this->assertRefused($code, fn () => $this->raise($admin, $change));
        }

        $this->assertRefused('CASH_SHORT_AMOUNT', fn () => $this->procedures()->write('usp_Cash_RaiseStaffShort', [
            'BranchId' => self::BRANCH, 'EmployeeCode' => 'TSS01', 'AmountShort' => '1.005', 'TransactionDate' => self::DAY,
            'ReasonCode' => 'pump_short', 'WriteMode' => 'live', 'UserId' => (int) $admin->Id,
        ]));
        $this->assertRefused('CASH_WRITES_OFF', fn () => $this->procedures()->write('usp_Cash_RaiseStaffShort', [
            'BranchId' => self::BRANCH, 'EmployeeCode' => 'TSS01', 'AmountShort' => 10, 'TransactionDate' => self::DAY,
            'ReasonCode' => 'pump_short', 'WriteMode' => 'off', 'UserId' => (int) $admin->Id,
        ]));

        $this->assertSame($counter, $this->counter(), 'No refusal burns a number.');
        $this->assertCount(0, $this->shorts());

        // The same short twice is a second press, not a second short.
        $first = (int) $this->raise($admin, [])->StaffShortsNo;
        $this->assertRefused('CASH_SHORT_DUPLICATE', fn () => $this->raise($admin, []), "Staff short {$first} already charges");
        $this->assertCount(1, $this->shorts());
    }

    /* ------------------------------------------------------------- decisions */

    public function test_approving_and_declining_write_pumpits_columns_and_tell_the_raiser(): void
    {
        Mail::fake();
        $admin = $this->person('admin');
        $raiser = $this->grant($this->person('auditor', 'raiser'), ['cash.staffshort.raise', 'cash.staffshort.view']);
        $service = app(StaffShortService::class);

        $a = (int) $service->raise(self::BRANCH, $this->short([]), (int) $raiser->Id)->StaffShortsNo;
        $b = (int) $service->raise(self::BRANCH, $this->short(['employee' => 'TSS02', 'amount' => '33.00', 'reason' => 'cashier_short']), (int) $raiser->Id)->StaffShortsNo;

        $approved = $service->approve(self::BRANCH, $a, (int) $admin->Id, 'TEST-slips seen');
        $this->assertSame('CASH_SHORT_APPROVED', $approved->Code);
        $row = $this->pumpit($a);
        $this->assertSame([1, 0], [(int) $row->Approved, (int) $row->IsDeclined]);
        $this->assertNotNull($row->ApprovedDate);
        $this->assertNull($row->ApprovedByUserId, 'The approver\'s PumpIT user id — NULL for somebody PumpIT never knew.');
        Mail::assertSent(StaffShortDecidedMail::class, fn (StaffShortDecidedMail $m) => $m->hasTo($raiser->EmailAddress)
            && $m->short['no'] === $a && $m->short['decision'] === 'approved' && $m->short['why'] === 'TEST-slips seen');

        $this->assertRefused('CASH_SHORT_DECIDED', fn () => $service->approve(self::BRANCH, $a, (int) $admin->Id), 'approved');
        $this->assertRefused('CASH_SHORT_DECIDED', fn () => $service->decline(self::BRANCH, $a, 'TEST-too late', (int) $admin->Id));

        $this->assertRefused('CASH_SHORT_DECLINE_REASON', fn () => $service->decline(self::BRANCH, $b, '  ', (int) $admin->Id));

        $why = 'TEST-No slips were sent for approval, and the till reading does not show the drive-off at all';
        $declined = $service->decline(self::BRANCH, $b, $why, (int) $admin->Id);
        $this->assertSame('CASH_SHORT_DECLINED', $declined->Code);
        $row = $this->pumpit($b);
        $this->assertSame([0, 1, mb_substr($why, 0, 50), 0], [(int) $row->Approved, (int) $row->IsDeclined, $row->DeclinedReason, (int) $row->DeclinedByUserId],
            'The column keeps 50 characters, as PumpIT\'s does; DeclinedByUserId is its 0 default for an unknown user.');
        $this->assertSame($why, $this->events($b)->firstWhere('Event', 'declined')->Note, 'Agora keeps the whole reason.');
        Mail::assertSent(StaffShortDecidedMail::class, fn (StaffShortDecidedMail $m) => $m->hasTo($raiser->EmailAddress)
            && $m->short['no'] === $b && $m->short['decision'] === 'declined' && $m->short['why'] === $why);

        $this->assertSame(['Approved', 'Declined'], [$this->shortRow($a)->Status, $this->shortRow($b)->Status]);
        $this->assertRefused('CASH_SHORT_DECLINED', fn () => $service->post(self::BRANCH, $b, self::DAY, 81, 7, (int) $admin->Id));
    }

    /* --------------------------------------------------------------- posting */

    public function test_posting_puts_the_short_on_the_cashups_pump_shortages_and_the_cashup_counts_it(): void
    {
        Mail::fake();
        $admin = $this->person('admin');
        $service = app(StaffShortService::class);
        $before = $this->cashup();

        $no = (int) $this->raise($admin, ['till' => 81, 'shift' => 7])->StaffShortsNo;
        $this->assertRefused('CASH_SHORT_NOT_APPROVED', fn () => $service->post(self::BRANCH, $no, null, null, null, (int) $admin->Id));

        $service->approve(self::BRANCH, $no, (int) $admin->Id);

        // No cashup named: the one it was raised for.
        $status = $service->post(self::BRANCH, $no, null, null, null, (int) $admin->Id);
        $this->assertSame('CASH_CASHUP_SAVED', $status->Code);
        $this->assertStringContainsString("1 staff short(s) posted to this cashup's pump shortages ({$no})", (string) $status->Message);

        $line = $this->db()->table('PumpIT.dbo.BRN_DailyBankingEmployees')->where('SSBranchId', self::BRANCH)->where('StaffShortsNo', $no)->sole();
        $this->assertSame([81, 7, self::DAY, 'TSS01', '120.50', 'Pump short', 0], [
            (int) $line->TillNo, (int) $line->ShiftNo, substr((string) $line->TransactionDate, 0, 10), $line->EmployeeCode,
            number_format((float) $line->AmountShort, 2, '.', ''), $line->Reason, (int) $line->Imported,
        ], 'The line carries the short as the legacy copies it.');
        $this->assertSame(1, (int) $this->pumpit($no)->IsSelectedForDailyBanking);

        $after = $this->cashup();
        $this->assertEqualsWithDelta((float) $before->StaffShorts + 120.50, (float) $after->StaffShorts, 0.0001, 'The cashup\'s pump shortages count it.');
        $this->assertEqualsWithDelta((float) $before->Variance + 120.50, (float) $after->Variance, 0.0001);
        $this->assertEqualsWithDelta((float) $after->Variance, (float) $this->header()->EmployeeAmount, 0.0001, 'And PumpIT\'s EmployeeAmount with it.');

        $view = $this->shortRow($no);
        $this->assertSame(['Posted', 81, 7, self::DAY], [$view->Status, (int) $view->PostedTillNo, (int) $view->PostedShiftNo, substr((string) $view->PostedDate, 0, 10)]);
        $posted = $this->events($no)->firstWhere('Event', 'posted');
        $this->assertSame([81, 7], [(int) $posted->TillNo, (int) $posted->ShiftNo]);

        $this->assertRefused('CASH_SHORT_POSTED', fn () => $service->post(self::BRANCH, $no, self::DAY, 81, 7, (int) $admin->Id), 'till 81, shift 7');

        // One pump-shortage line per person per cashup — PumpIT's own key.
        $second = (int) $this->raise($admin, ['amount' => '10.00', 'note' => 'TEST-a second one'])->StaffShortsNo;
        $service->approve(self::BRANCH, $second, (int) $admin->Id);
        $this->assertRefused('CASH_POOL_MASTER', fn () => $service->post(self::BRANCH, $second, self::DAY, 81, 7, (int) $admin->Id),
            'one per person per cashup');
        $this->assertSame(0, (int) $this->pumpit($second)->IsSelectedForDailyBanking);
    }

    public function test_the_daily_banking_leg_offers_the_approved_shorts_and_saving_one_posts_it(): void
    {
        Mail::fake();
        $admin = $this->person('admin');
        $service = app(StaffShortService::class);
        $cashups = app(CashupService::class);
        $scope = new CashupScope(self::BRANCH, self::DAY, 81, 7);

        $mine = (int) $this->raise($admin, ['till' => 81, 'shift' => 7])->StaffShortsNo;
        $other = (int) $this->raise($admin, ['employee' => 'TSS02', 'amount' => '44.00'])->StaffShortsNo;
        $waiting = (int) $this->raise($admin, ['employee' => 'TSS03', 'amount' => '5.00'])->StaffShortsNo;
        $service->approve(self::BRANCH, $mine, (int) $admin->Id);
        $service->approve(self::BRANCH, $other, (int) $admin->Id);

        $this->assertContains('StaffShorts', $cashups->savableChannels(), 'The leg is no longer held.');
        $this->assertArrayNotHasKey('StaffShorts', $cashups->heldChannels());

        $pool = $cashups->pool($scope, 'StaffShorts')['rows']->filter(fn ($r) => str_starts_with((string) $r->CashierCode, 'TSS'));
        $this->assertSame(["Short {$mine}", "Short {$other}"], $pool->pluck('Reference')->values()->all(),
            'The approved ones — the one raised for this cashup first — never the one still waiting.');
        $this->assertSame('raised for this cashup', $pool->first()->Hint);
        $this->assertNotContains("Short {$waiting}", $pool->pluck('Reference')->all());

        $status = $cashups->savePool($scope, 'StaffShorts', [(string) $pool->last()->PoolKey], (int) $admin->Id);
        $this->assertSame([1, 'CASH_CASHUP_SAVED'], [(int) $status->Inserted, $status->Code]);
        $this->assertSame('Posted', $this->shortRow($other)->Status);

        $this->assertRefused('CASH_POOL_MASTER', fn () => $cashups->savePool($scope, 'StaffShorts', ['{"StaffShortsNo":'.$waiting.'}'], (int) $admin->Id),
            'waiting for approval');
    }

    /* ----------------------------------------------------- the legacy reasons */

    public function test_the_legacy_spellings_read_as_one_code_with_the_text_kept(): void
    {
        $spellings = [
            990501 => ['Pumpshort', 'pump_short'],
            990502 => ['PUMP SHORT 07.07.2024', 'pump_short'],
            990503 => ['punp short', 'pump_short'],
            990504 => ['Palesa 23.07.2024 Keletso Pump Short', 'pump_short'],
            990505 => ['short lubes', 'stock_loss'],
            990506 => ['Till Short between 2 Z-Reads', 'cashier_short'],
            990507 => ['Driver Drinks', 'driver_drinks'],
            990508 => ['short', 'pump_short'],          // its note says "Pump short 23 may"
            990509 => ['Coke Promo', 'driver_drinks'],
            990510 => ['tirah busses', 'driver_drinks'], // its note says "nombuso drivers drink"
            990511 => ['Staff Shortage', 'driver_drinks'], // said by nothing but the "Driver Drinks" employee it is booked to
            990512 => ['Customer drove of without paying', 'other'],
        ];

        $this->db()->table('PumpIT.dbo.BRN_Employee')->insert(['SSBranchId' => self::BRANCH, 'EmployeeCode' => 'TSSD1',
            'FirstName' => 'Driver', 'Surname' => 'Drinks', 'IsActive' => 1, 'IsPumpAttendant' => 0, 'PostNo' => 0]);

        foreach ($spellings as $no => [$reason]) {
            $this->db()->table('PumpIT.dbo.BRN_StaffShorts')->insert([
                'SSBranchId' => self::BRANCH, 'StaffShortsNo' => $no, 'EmployeeCode' => $no === 990511 ? 'TSSD1' : 'TSS01', 'AmountShort' => 12.34,
                'Note' => match ($no) {
                    990507 => 'TEST-Nokthula x5 (23/09/2026)',
                    990508 => 'Pump short 23 may',
                    990510 => 'nombuso drivers drink 09/12/2025',
                    990511 => 'Staff Shortage Paballo 01.10.2025',
                    default => $reason,
                }, 'Reason' => $reason,
                'TransactionDate' => self::DAY.' 16:00:32', 'CreatedByUserId' => 104, 'Approved' => 0, 'IsDeclined' => 0,
                'DeclinedByUserId' => 0, 'CreateDateTime' => self::DAY.' 16:00:32',
            ]);
        }

        $rows = $this->db()->table('agora.vw_CashStaffShort')->where('BranchId', self::BRANCH)
            ->whereIn('StaffShortsNo', array_keys($spellings))->orderBy('StaffShortsNo')->get()->keyBy('StaffShortsNo');

        foreach ($spellings as $no => [$reason, $code]) {
            $this->assertSame($code, $rows[$no]->ReasonCode, $reason);
            $this->assertSame('PumpIT', $rows[$no]->RaisedIn);
        }

        $this->assertSame('alias', $rows[990501]->ReasonFrom);
        $this->assertSame(['alias', 'Driver drinks / promotion'], [$rows[990507]->ReasonFrom, $rows[990507]->ReasonLabel],
            'The fifth code (PM decision 01a0e5b5).');
        $this->assertSame('note', $rows[990508]->ReasonFrom, 'A reason that says nothing is read from the note.');
        $this->assertSame('note', $rows[990510]->ReasonFrom);
        $this->assertSame('employee', $rows[990511]->ReasonFrom, 'Neither text says; the pseudo-employee does.');
        $this->assertSame('none', $rows[990512]->ReasonFrom, 'No pattern matched; it reads as other.');
        $this->assertSame('short — Pump short 23 may', $rows[990508]->Note);
        $this->assertSame('Pumpshort', $rows[990501]->Note, 'The text as typed is the note.');
        $this->assertSame('Driver Drinks — TEST-Nokthula x5 (23/09/2026)', $rows[990507]->Note, 'And the legacy note after it when it says more.');

        // The grid filters them as one reason.
        $grid = $this->procedures()->callSets('usp_Cash_GridStaffShorts', [
            'BranchIds' => (string) self::BRANCH, 'DateFrom' => self::DAY, 'DateTo' => self::DAY, 'PageSize' => 100,
            'FiltersJson' => json_encode([['column' => 'ReasonLabel', 'type' => 'set', 'in' => ['Pump short']]]),
        ]);
        $this->assertSame([990508, 990504, 990503, 990502, 990501],
            $grid[0]->filter(fn ($r) => (int) $r->StaffShortsNo >= 990501)->pluck('StaffShortsNo')->map(fn ($n) => (int) $n)->values()->all());
    }

    /* ------------------------------------------------------------ withdrawal */

    /**
     * An approved short not yet on a cashup is taken back (PM decision
     * 01a0e5b5): the approval undone to the legacy's declined shape, declined
     * with the reason, what it replaced kept, and the raiser told it was
     * withdrawn. Nothing else can be withdrawn.
     */
    public function test_an_approved_short_not_on_a_cashup_is_withdrawn_and_nothing_else_is(): void
    {
        Mail::fake();
        $admin = $this->person('admin');
        $raiser = $this->grant($this->person('auditor', 'raiser'), ['cash.staffshort.raise', 'cash.staffshort.view']);
        $service = app(StaffShortService::class);

        $no = (int) $service->raise(self::BRANCH, $this->short(['till' => 81, 'shift' => 7]), (int) $raiser->Id)->StaffShortsNo;
        $this->assertRefused('CASH_SHORT_NOT_APPROVED', fn () => $service->withdraw(self::BRANCH, $no, 'TEST-too soon', (int) $admin->Id),
            'decline it instead');

        $service->approve(self::BRANCH, $no, (int) $admin->Id);
        $this->assertRefused('CASH_SHORT_WITHDRAW_REASON', fn () => $service->withdraw(self::BRANCH, $no, ' ', (int) $admin->Id));

        $why = 'TEST-Recon batch reversed: the bags were ticked against the wrong bank line';
        $status = $service->withdraw(self::BRANCH, $no, $why, (int) $admin->Id);
        $this->assertSame('CASH_SHORT_WITHDRAWN', $status->Code);

        $row = $this->pumpit($no);
        $this->assertSame([0, null, 0, 1, mb_substr($why, 0, 50), 0, 0], [
            (int) $row->Approved, $row->ApprovedDate, (int) $row->ApprovedByUserId, (int) $row->IsDeclined,
            $row->DeclinedReason, (int) $row->DeclinedByUserId, (int) $row->IsSelectedForDailyBanking,
        ], 'The legacy\'s own declined shape.');
        $this->assertNotNull($row->DeclinedDate);

        $event = $this->events($no)->firstWhere('Event', 'withdrawn');
        $this->assertSame($why, $event->Note, 'The whole reason, in Agora.');
        $writes = json_decode((string) $event->WritesJson, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($writes);
        $this->assertSame([true, false], [(bool) $writes['prior']['Approved'], (bool) $writes['prior']['IsDeclined']], 'The approval it replaced.');
        $this->assertNotNull($writes['prior']['ApprovedDate']);

        $this->assertSame('Declined', $this->shortRow($no)->Status);
        Mail::assertSent(StaffShortDecidedMail::class, fn (StaffShortDecidedMail $m) => $m->hasTo($raiser->EmailAddress)
            && $m->short['no'] === $no && $m->short['decision'] === 'withdrawn' && $m->short['why'] === $why);

        $this->assertRefused('CASH_SHORT_DECIDED', fn () => $service->withdraw(self::BRANCH, $no, 'TEST-again', (int) $admin->Id));
        $this->assertRefused('CASH_SHORT_DECLINED', fn () => $service->post(self::BRANCH, $no, self::DAY, 81, 7, (int) $admin->Id));

        // A posted short is on a cashup, which counts it: it is not withdrawn.
        $posted = (int) $this->raise($admin, ['employee' => 'TSS02', 'amount' => '9.00', 'till' => 81, 'shift' => 7])->StaffShortsNo;
        $service->approve(self::BRANCH, $posted, (int) $admin->Id);
        $service->post(self::BRANCH, $posted, null, null, null, (int) $admin->Id);
        $this->assertRefused('CASH_SHORT_POSTED', fn () => $service->withdraw(self::BRANCH, $posted, 'TEST-too late', (int) $admin->Id),
            'till 81, shift 7');
        $this->assertSame(1, (int) $this->pumpit($posted)->Approved);
    }

    /**
     * A short a cash-bag match raised is never a cashup line (PM decision
     * 01a0e5b5): the pool does not offer it, posting it is refused with its own
     * code wherever it is tried, and the screen says why — and offers the
     * withdrawal instead.
     */
    public function test_a_short_a_cash_bag_match_raised_is_never_posted_to_a_cashup(): void
    {
        Mail::fake();
        $admin = $this->person('admin');
        $service = app(StaffShortService::class);

        $no = (int) $this->procedures()->write('usp_Cash_RaiseStaffShort', [
            'BranchId' => self::BRANCH, 'EmployeeCode' => 'TSS02', 'AmountShort' => 4.99, 'TransactionDate' => self::DAY,
            'ReasonCode' => 'cashier_short', 'Note' => 'TEST-cash-bag match', 'Source' => 'recon', 'SourceId' => 990001,
            'SourceRef' => 'Recon batch 9901 (cash bags)', 'WriteMode' => 'live', 'UserId' => (int) $admin->Id,
        ])->StaffShortsNo;
        $service->approve(self::BRANCH, $no, (int) $admin->Id);

        $pool = app(CashupService::class)->pool(new CashupScope(self::BRANCH, self::DAY, 81, 7), 'StaffShorts')['rows'];
        $this->assertNull($pool->first(fn ($r) => $r->Reference === "Short {$no}"), 'The pool never offers it.');

        $this->assertRefused('CASH_SHORT_FROM_RECON', fn () => $service->post(self::BRANCH, $no, self::DAY, 81, 7, (int) $admin->Id),
            'deductions read the short directly');
        $this->assertRefused('CASH_SHORT_FROM_RECON', fn () => app(CashupService::class)->savePool(
            new CashupScope(self::BRANCH, self::DAY, 81, 7), 'StaffShorts', ['{"StaffShortsNo":'.$no.'}'], (int) $admin->Id,
        ), 'Recon batch 9901');
        $this->assertSame(0, (int) $this->pumpit($no)->IsSelectedForDailyBanking);
        $this->assertSame(0, $this->db()->table('PumpIT.dbo.BRN_DailyBankingEmployees')->where('SSBranchId', self::BRANCH)
            ->where('StaffShortsNo', $no)->count());

        $this->actingAs($admin)->get(route('app.cash.staffshorts', ['branch_id' => self::BRANCH, 'date_from' => self::DAY, 'date_to' => self::DAY, 'short' => $no]))
            ->assertOk()
            ->assertSee('Raised by a cash-bag match — never put on a cashup')
            ->assertDontSee('id="staffshort-post"', false)
            ->assertSee('id="staffshort-withdraw"', false);
    }

    /* -------------------------------------------------------------- screens */

    public function test_the_screens_raise_decide_and_post_and_say_so(): void
    {
        Mail::fake();
        $admin = $this->person('admin');
        $scope = ['branch_id' => self::BRANCH, 'date_from' => self::DAY, 'date_to' => self::DAY];

        $this->actingAs($admin)->get(route('app.cash.staffshorts', $scope))
            ->assertOk()
            ->assertSee('agora.usp_Cash_GetStaffShorts')
            ->assertSee('id="staffshort-raise-desk"', false)
            ->assertSee('TEST-Shorts Sipho');

        $response = $this->actingAs($admin)->post(route('app.cash.staffshorts.store'), $scope + [
            'employee' => 'TSS01', 'amount' => '75.25', 'day' => self::DAY, 'reason' => 'pump_short', 'note' => 'TEST-by the form',
            'till' => 81, 'shift' => 7,
        ]);
        $no = (int) $this->shorts()->max('StaffShortsNo');
        $response->assertRedirect(route('app.cash.staffshorts', $scope + ['short' => $no]));

        $this->actingAs($admin)->get((string) $response->headers->get('Location'))
            ->assertOk()
            ->assertSee("Staff short {$no} raised in PumpIT")
            ->assertSee('id="staffshort-approve"', false)
            ->assertSee('id="staffshort-decline"', false)
            ->assertDontSee('id="staffshort-post"', false);

        $this->actingAs($admin)->post(route('app.cash.staffshorts.decline'), $scope + ['short' => $no, 'decline_reason' => ''])
            ->assertRedirect();
        $this->actingAs($admin)->get(route('app.cash.staffshorts', $scope + ['short' => $no]))->assertSee('CASH_SHORT_DECLINE_REASON');

        $this->actingAs($admin)->post(route('app.cash.staffshorts.approve'), $scope + ['short' => $no])->assertRedirect();
        $this->actingAs($admin)->get(route('app.cash.staffshorts', $scope + ['short' => $no]))
            ->assertSee('id="staffshort-post"', false)
            ->assertSee('id="staffshort-withdraw"', false);

        $this->actingAs($admin)->post(route('app.cash.staffshorts.post'), $scope + ['short' => $no, 'post_date' => self::DAY, 'post_till' => 81, 'post_shift' => 7])
            ->assertRedirect();
        $this->assertSame('Posted', $this->shortRow($no)->Status);

        $this->actingAs($admin)->get(route('app.cash.staffshorts.phone', $scope))
            ->assertOk()->assertSee('id="staffshort-raise-phone"', false);

        $this->actingAs($admin)->get(route('app.cash.staffshorts.list', ['branches' => self::BRANCH, 'from' => self::DAY, 'to' => self::DAY]))
            ->assertOk()->assertSee('agora.usp_Cash_GridStaffShorts')->assertSee((string) $no);

        // Read-only: no press is rendered, and none is reachable.
        $auditor = $this->person('auditor');
        $this->actingAs($auditor)->get(route('app.cash.staffshorts', $scope + ['short' => $no]))
            ->assertOk()->assertDontSee('id="staffshort-raise-desk"', false)->assertDontSee('id="staffshort-approve"', false);
        $this->actingAs($auditor)->post(route('app.cash.staffshorts.store'), $scope + [
            'employee' => 'TSS01', 'amount' => '1', 'day' => self::DAY, 'reason' => 'pump_short',
        ])->assertForbidden();
        $this->actingAs($auditor)->post(route('app.cash.staffshorts.approve'), $scope + ['short' => $no])->assertForbidden();
        $this->actingAs($auditor)->post(route('app.cash.staffshorts.post'), $scope + ['short' => $no])->assertForbidden();
        $this->actingAs($auditor)->post(route('app.cash.staffshorts.withdraw'), $scope + ['short' => $no, 'withdraw_reason' => 'TEST'])->assertForbidden();

        // Switched off: the screen says so and no press is reachable.
        config(['cash.staffshort.write_mode' => 'off']);
        $this->actingAs($admin)->get(route('app.cash.staffshorts', $scope))
            ->assertOk()->assertSee('Writing to PumpIT is off')->assertDontSee('id="staffshort-raise-desk"', false);
        $this->actingAs($admin)->post(route('app.cash.staffshorts.store'), $scope + [
            'employee' => 'TSS01', 'amount' => '1', 'day' => self::DAY, 'reason' => 'pump_short',
        ])->assertForbidden();
    }

    public function test_the_pending_mail_run_tells_the_approvers_of_a_short_nobody_was_told_about(): void
    {
        Mail::fake();
        $admin = $this->person('admin');
        $approver = $this->grant($this->person('auditor', 'approver'), ['cash.staffshort.approve']);

        // As a cash-bag match raises one: inside a procedure, which cannot mail.
        $no = (int) $this->procedures()->write('usp_Cash_RaiseStaffShort', [
            'BranchId' => self::BRANCH, 'EmployeeCode' => 'TSS02', 'AmountShort' => 4.99, 'TransactionDate' => self::DAY,
            'ReasonCode' => 'cashier_short', 'Note' => 'TEST-cash-bag match', 'WriteMode' => 'live', 'UserId' => (int) $admin->Id,
        ])->StaffShortsNo;
        Mail::assertNothingSent();

        $this->artisan('cash:staff-short-mail')->assertSuccessful();

        Mail::assertSent(StaffShortRaisedMail::class, fn (StaffShortRaisedMail $m) => $m->hasTo($approver->EmailAddress) && $m->short['no'] === $no);
        $this->assertNotNull($this->db()->table('agora.StaffShort')->where('BranchId', self::BRANCH)->where('StaffShortsNo', $no)->value('RaisedMailAt'));
    }

    public function test_the_permissions_and_the_menu_are_seeded(): void
    {
        foreach (['view', 'raise', 'approve', 'withdraw', 'post'] as $action) {
            $this->assertTrue(Permission::query()->withoutGlobalScopes()->where('Code', "cash.staffshort.{$action}")->exists(), $action);
        }

        $routes = MenuItem::query()->acrossBranches()->whereIn('Path', ['raise/staff-short', 'capture/staff-shorts', 'decisions/staff-shorts', 'money/staff-shorts'])
            ->pluck('RouteName', 'Path')->all();
        $this->assertSame('app.cash.staffshorts', $routes['raise/staff-short'] ?? null);
        $this->assertSame('app.cash.staffshorts', $routes['capture/staff-shorts'] ?? null);
        $this->assertSame('app.cash.staffshorts.list', $routes['decisions/staff-shorts'] ?? null);
        $this->assertSame('app.cash.staffshorts.list', $routes['money/staff-shorts'] ?? null);
    }

    public function test_the_list_grid_is_registered(): void
    {
        // Fails until config/grids.php carries
        //   'app.cash.staffshorts.list' => Modules\Cash\Grids\StaffShortGrid::class,
        // — the extract and the saved column layout both find a grid by key.
        $grids = (require base_path('config/grids.php'))['grids'];

        $this->assertSame(StaffShortGrid::class, $grids['app.cash.staffshorts.list'] ?? null);
    }

    /* ---------------------------------------------------------------- fixture */

    private function seedFixture(): void
    {
        $pumpit = fn (string $table) => $this->db()->table("PumpIT.dbo.{$table}");

        foreach (self::EMPLOYEES as [$code, $first, $pump]) {
            $pumpit('BRN_Employee')->insert(['SSBranchId' => self::BRANCH, 'EmployeeCode' => $code, 'FirstName' => $first,
                'Surname' => 'TEST-Shorts', 'IsActive' => 1, 'IsPumpAttendant' => $pump, 'PostNo' => 0]);
        }
        foreach ([81 => 'TEST-SS Till A', 82 => 'TEST-SS Till B'] as $no => $name) {
            $pumpit('BRN_Tills')->insert(['SSBranchId' => self::BRANCH, 'TillNo' => $no, 'TillDescription' => $name, 'Location' => 'WINBRANCH']);
        }
        foreach ([7 => 'TEST-SS Day', 8 => 'TEST-SS Night'] as $no => $name) {
            $pumpit('BRN_Shifts')->insert(['SSBranchId' => self::BRANCH, 'ShiftNo' => $no, 'ShiftDescription' => $name, 'IsActive' => 1]);
        }

        $pumpit('BRN_DailyBanking')->insert([
            'SSBranchId' => self::BRANCH, 'TillNo' => 81, 'ShiftNo' => 7, 'TransactionDate' => self::DAY.' 00:00:00', 'EmployeeCode' => 'TSS02',
            'ClosingBalance' => 1000, 'CreditCardAmount' => 0, 'CardAmount1' => 0, 'CardAmount2' => 0, 'EFuelAmount' => 0,
            'DirectDepositAmount' => 0, 'EmployeeAmount' => -1000, 'Note' => null, 'DayEndNo' => '1', 'Posted' => 0,
            'Imported' => 0, 'CreateDateTime' => self::DAY.' 00:00:00',
        ]);
    }

    private function cleanUp(): void
    {
        $db = $this->db();
        $numbers = $db->table('PumpIT.dbo.BRN_StaffShorts')->where('SSBranchId', self::BRANCH)
            ->where('EmployeeCode', 'like', 'TSS%')->pluck('StaffShortsNo')->all();

        $db->table('agora.StaffShortEvent')->where('BranchId', self::BRANCH)
            ->where(fn ($q) => $q->where('EmployeeCode', 'like', 'TSS%')->orWhereIn('StaffShortsNo', $numbers ?: [0]))->delete();
        $db->table('agora.StaffShort')->where('BranchId', self::BRANCH)->where('EmployeeCode', 'like', 'TSS%')->delete();
        $db->table('PumpIT.dbo.BRN_StaffShorts')->where('SSBranchId', self::BRANCH)->where('EmployeeCode', 'like', 'TSS%')->delete();
        $db->table('PumpIT.dbo.BRN_DailyBankingEmployees')->where('SSBranchId', self::BRANCH)->where('EmployeeCode', 'like', 'TSS%')->delete();

        $cashups = $db->table('agora.Cashup')->where('BranchId', self::BRANCH)->whereIn('TillNo', [81, 82])->pluck('Id')->all();
        $db->table('agora.CashupEvent')->where('BranchId', self::BRANCH)->whereIn('CashupId', $cashups ?: [0])->delete();
        $db->table('agora.Cashup')->where('BranchId', self::BRANCH)->whereIn('TillNo', [81, 82])->delete();
        $db->table('PumpIT.dbo.BRN_DailyBanking')->where('SSBranchId', self::BRANCH)->whereIn('TillNo', [81, 82])->delete();

        $db->table('PumpIT.dbo.BRN_Employee')->where('SSBranchId', self::BRANCH)
            ->whereIn('EmployeeCode', [...array_column(self::EMPLOYEES, 0), 'TSSD1'])->delete();
        $db->table('PumpIT.dbo.BRN_Tills')->where('SSBranchId', self::BRANCH)->whereIn('TillNo', [81, 82])->delete();
        $db->table('PumpIT.dbo.BRN_Shifts')->where('SSBranchId', self::BRANCH)->whereIn('ShiftNo', [7, 8])->delete();
    }

    /**
     * @param  array<string, mixed>  $change
     * @return array{employee: string, amount: string, day: string, reason: string, note: ?string, till: ?int, shift: ?int}
     */
    private function short(array $change): array
    {
        $short = $change + ['employee' => 'TSS01', 'amount' => '120.50', 'day' => self::DAY, 'reason' => 'pump_short',
            'note' => 'TEST-pump 4', 'till' => null, 'shift' => null];

        return [
            'employee' => (string) $short['employee'], 'amount' => (string) $short['amount'], 'day' => (string) $short['day'],
            'reason' => (string) $short['reason'], 'note' => $short['note'] === null ? null : (string) $short['note'],
            'till' => $short['till'] === null ? null : (int) $short['till'], 'shift' => $short['shift'] === null ? null : (int) $short['shift'],
        ];
    }

    /** @param  array<string, mixed>  $change */
    private function raise(User $user, array $change): object
    {
        return app(StaffShortService::class)->raise(self::BRANCH, $this->short($change), (int) $user->Id);
    }

    private function counter(): int
    {
        return (int) $this->db()->selectOne('SELECT NextUniqueNumber AS n FROM PumpIT.dbo.SS_UniqueNumber WHERE SectionId = 2')->n;
    }

    private function pumpit(int $no): object
    {
        return $this->db()->table('PumpIT.dbo.BRN_StaffShorts')->where('SSBranchId', self::BRANCH)->where('StaffShortsNo', $no)->sole();
    }

    private function shortRow(int $no): object
    {
        return $this->db()->table('agora.vw_CashStaffShort')->where('BranchId', self::BRANCH)->where('StaffShortsNo', $no)->sole();
    }

    /** @return Collection<int, \stdClass> */
    private function shorts(): Collection
    {
        return $this->db()->table('PumpIT.dbo.BRN_StaffShorts')->where('SSBranchId', self::BRANCH)->where('EmployeeCode', 'like', 'TSS%')->get();
    }

    /** @return Collection<int, \stdClass> */
    private function events(int $no): Collection
    {
        return $this->db()->table('agora.StaffShortEvent')->where('BranchId', self::BRANCH)->where('StaffShortsNo', $no)->orderBy('Id')->get();
    }

    /** The test cashup as agora.usp_Cash_GetCashup reads it. */
    private function cashup(): object
    {
        return $this->procedures()->callSets('usp_Cash_GetCashup', [
            'BranchId' => self::BRANCH, 'DateFrom' => self::DAY, 'DateTo' => self::DAY, 'TillNo' => 81, 'ShiftNo' => 7,
        ])[0]->sole();
    }

    private function header(): object
    {
        return $this->db()->table('PumpIT.dbo.BRN_DailyBanking')->where('SSBranchId', self::BRANCH)->where('TillNo', 81)->where('ShiftNo', 7)->sole();
    }

    private function assertRefused(string $code, callable $press, ?string $says = null): void
    {
        try {
            $press();
            $this->fail("Expected the refusal {$code}.");
        } catch (AgoraProcException $e) {
            $this->assertSame($code, $e->code(), $e->getMessage());
            if ($says !== null) {
                $this->assertStringContainsString($says, $e->getMessage());
            }
        }
    }

    private function person(string $profile, ?string $label = null): User
    {
        $user = User::query()->acrossBranches()->create([
            'BranchId' => (int) config('agora.group_branch_id', 2),
            'EmailAddress' => 'TEST-t040-'.($label ?? $profile).'-'.uniqid().'@agora.invalid',
            'UserName' => 'TEST-'.($label ?? $profile),
            'IsActive' => true,
            'IsLocked' => false,
            'Workspace' => 'ho',
            'PasswordHash' => 'TEST-only-'.Str::random(24),
        ]);

        $this->grantProfile($user, $profile);
        $this->made[] = $user;

        return $user;
    }

    /** @param  array<int, string>  $codes */
    private function grant(User $user, array $codes): User
    {
        foreach (Permission::query()->acrossBranches()->whereIn('Code', $codes)->get() as $permission) {
            UserPermission::query()->acrossBranches()->firstOrCreate([
                'BranchId' => (int) $user->BranchId, 'UserId' => (int) $user->Id, 'PermissionId' => (int) $permission->Id,
            ]);
        }

        app(PermissionService::class)->forget($user);

        return $user;
    }

    private function procedures(): ProcedureService
    {
        return app(ProcedureService::class);
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }
}
