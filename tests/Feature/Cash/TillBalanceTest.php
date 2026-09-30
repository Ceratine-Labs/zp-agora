<?php

namespace Tests\Feature\Cash;

use App\Exceptions\AgoraProcException;
use App\Grid\GridRegistry;
use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Cash\Grids\PumpShortageGrid;
use Modules\Cash\Services\TillBalanceService;
use Modules\Cash\Support\TillBalanceScope;
use Modules\Core\Models\Permission;
use Modules\Core\Models\User;
use Modules\Core\Models\UserBranch;
use Modules\Core\Models\UserPermission;
use Tests\Fixtures\GrantsAccess;
use Tests\TestCase;

/**
 * Till balance and pump shortages (T038): the capture PumpIT's till-balance
 * screen makes, ported — the pair of BRN_TillBalance rows and the section 3
 * counter — its refusals, what it does (and does not yet do) to the two
 * cashups, the screen, and the pump shortages leg as a grid.
 *
 * THE FIXTURE (branch 999, 13 June 2026, all TEST- data in the LOCAL PumpIT
 * stub):
 *
 *   till 51 · day    TTB01, Z-read 1 000.00 (log TB9101), cash bags 936.33:
 *                    variance -63.67, the SHORT
 *   till 51 · night  TTB01, Z-read 500.00 (log TB9102), cash bags 541.59:
 *                    variance +41.59, the OVER
 *   till 52 · day    TTB02, Z-read 200.00 (log TB9201), cash bags 175.00 and a
 *                    pump short of 25.00 against attendant TTB01 (staff short
 *                    99001, approved): variance nil
 *   till 52 · night  TTB02, NO Z-read, cash bags 90.00 against a stored
 *                    ClosingBalance of 100.00: variance -10.00, and it cannot
 *                    be balanced
 *
 * The test person carries PumpIT user id 424242 (agora.User.LegacyUserId).
 *
 * Writes the LOCAL stub and Agora's database only, on branch 999, removes every
 * row it wrote, and puts the till balance counter back where it found it.
 * skipUnlessLocalStub guarantees it never runs against the customer's instance.
 */
class TillBalanceTest extends TestCase
{
    use GrantsAccess;

    private const BRANCH = 999;

    private const DAY = '2026-06-13';

    private const TILLS = [51, 52, 53];

    private const LEGS = ['ABSA', 'CashBags', 'Cheques', 'Customers', 'Deposita', 'DirectDeposits', 'Employees', 'Expenses',
        'FleetCard', 'FNB', 'Infinity', 'ManualCard1', 'ManualCard2', 'SmartATM', 'Suppliers', 'Yumbi', 'YumbiVoucher', 'Zapper'];

    private const ERP_USER = 424242;

    /** @var array<int, User> */
    private array $made = [];

    private ?int $counterBefore = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessLocalStub('This fixture writes cashups and till balances into the PumpIT stub.');

        if (! Permission::query()->withoutGlobalScopes()->where('Code', 'cash.tillbalance.save')->exists()) {
            $this->markTestSkipped('Run: php artisan seed:master --only=TillBalancePermissionSeeder');
        }

        if ($this->db()->table('PumpIT.dbo.SS_UniqueNumber')->where('SectionId', 3)->count() !== 1) {
            $this->markTestSkipped('The stub has no till balance counter: apply database/stubs/pumpit-cash.sql (section 5).');
        }

        // The line config/grids.php carries once this lane is merged; the
        // registration itself is asserted by test_the_pump_shortages_grid_is_registered,
        // which does NOT add it.
        if (! app(GridRegistry::class)->has('app.cash.pumpshortages')) {
            config(['grids.grids' => config('grids.grids') + ['app.cash.pumpshortages' => PumpShortageGrid::class]]);
        }

        config([
            'cash.tillbalance.write_mode' => 'live',
            'cash.tillbalance.variance_floor' => 1.00,
            'cash.cashup.write_mode' => 'live',
        ]);

        $this->cleanUp();
        $this->counterBefore = $this->counter();
        $this->seedFixture();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->cleanUp();

            // The counter goes back only if nothing but this test moved it.
            if ($this->counterBefore !== null) {
                $this->db()->table('PumpIT.dbo.SS_UniqueNumber')->where('SectionId', 3)
                    ->where('NextUniqueNumber', '>', $this->counterBefore)
                    ->whereNotExists(fn ($q) => $q->from('PumpIT.dbo.BRN_TillBalance')->selectRaw('1')
                        ->where('TillBalanceNo', '>=', $this->counterBefore))
                    ->update(['NextUniqueNumber' => $this->counterBefore]);
            }

            foreach ($this->made as $user) {
                UserPermission::query()->acrossBranches()->where('UserId', $user->Id)->delete();
                UserBranch::query()->acrossBranches()->where('UserId', $user->Id)->delete();
                $this->db()->table('agora.UserGridColumn')->where('UserId', $user->Id)->delete();
                User::query()->acrossBranches()->where('Id', $user->Id)->forceDelete();
            }
        }

        parent::tearDown();
    }

    /* ------------------------------------------------------------------ the capture */

    public function test_a_capture_writes_the_pair_and_takes_the_number_as_pumpit_does(): void
    {
        $user = $this->person('admin');
        $before = $this->counter();

        $status = $this->capture($user, over: [51, 3], short: [51, 1], amount: '63.67', note: 'TEST-captured on the wrong shift');

        $this->assertSame('CASH_TILLBAL_CAPTURED', $status->Code);
        $this->assertSame($before, (int) $status->TillBalanceNo, 'The number is the counter as it stood — dbo.sp_GenerateTillBalanceNo.');
        $this->assertSame($before, (int) $status->Id);
        $this->assertSame($before + 1, $this->counter(), 'And the counter moved on by one.');
        $this->assertStringContainsString('waits for approval in PumpIT', (string) $status->Message);

        $rows = $this->rows((int) $status->TillBalanceNo)->keyBy(fn ($r) => (int) $r->ShiftNo);
        $this->assertCount(2, $rows, 'Two rows under one number, one per cashup.');

        // Off the over: night shift.
        $off = $rows[3];
        $this->assertSame([51, 'TB9102', 'TTB01'], [(int) $off->TillNo, trim((string) $off->LogFile), $off->EmployeeCode]);
        $this->assertEqualsWithDelta(-63.67, (float) $off->TransactionAmount, 0.00001);
        $this->assertEqualsWithDelta(41.59, (float) $off->OriginalEmployeeAmount, 0.00001, 'The cashup\'s variance now.');
        $this->assertEqualsWithDelta(-22.08, (float) $off->EmployeeAmount, 0.00001, 'Original + TransactionAmount.');

        // Onto the short: day shift.
        $on = $rows[1];
        $this->assertSame([51, 'TB9101', 'TTB01'], [(int) $on->TillNo, trim((string) $on->LogFile), $on->EmployeeCode]);
        $this->assertEqualsWithDelta(63.67, (float) $on->TransactionAmount, 0.00001);
        $this->assertEqualsWithDelta(-63.67, (float) $on->OriginalEmployeeAmount, 0.00001);
        $this->assertEqualsWithDelta(0.00, (float) $on->EmployeeAmount, 0.00001);

        foreach ($rows as $row) {
            $this->assertSame(self::DAY.' 00:00:00', substr((string) $row->TransactionDate, 0, 19), 'The cashup\'s day, at midnight, as PumpIT stores it.');
            $this->assertSame(self::ERP_USER, (int) $row->CreatedByUserId, 'The person\'s PumpIT user id.');
            $this->assertSame([0, 0, 0, 0], [(int) $row->Approved, (int) $row->ApprovedByUserId, (int) $row->IsDeclined, (int) $row->DeclinedByUserId],
                'Pending, exactly as sp_InsertTillBalance leaves a row — what PumpIT\'s approval list picks up.');
            $this->assertNull($row->ApprovedDate);
            $this->assertNotNull($row->CreateDateTime);
        }

        $this->assertEqualsWithDelta(0.0, (float) $rows->sum(fn ($r) => (float) $r->TransactionAmount), 0.00001, 'A pair nets to nil.');

        // Agora's record of exactly what was written.
        $event = collect($this->db()->select('SELECT * FROM agora.TillBalanceEvent WHERE BranchId = ? AND TillBalanceNo = ?',
            [self::BRANCH, (int) $status->TillBalanceNo]))->sole();
        $this->assertSame(['captured', 'live', (int) $user->Id], [$event->Event, $event->WriteMode, (int) $event->CreatedBy]);
        $this->assertSame('TEST-captured on the wrong shift', $event->Note);
        $this->assertSame([51, 3, 51, 1], [(int) $event->FromTillNo, (int) $event->FromShiftNo, (int) $event->ToTillNo, (int) $event->ToShiftNo]);

        $writes = json_decode((string) $event->WritesJson, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($writes);
        $this->assertSame(['SS_UniqueNumber', 3, $before, $before + 1],
            [$writes['counter']['table'], $writes['counter']['sectionId'], $writes['counter']['prior'], $writes['counter']['new']]);
        $this->assertCount(2, $writes['rows']);
        $this->assertSame(['insert', 'BRN_TillBalance', 'from'], [$writes['rows'][0]['action'], $writes['rows'][0]['table'], $writes['rows'][0]['side']]);
        $this->assertEquals(-63.67, $writes['rows'][0]['new']['TransactionAmount']);
    }

    public function test_the_capture_moves_neither_cashup_until_pumpit_posts_it_and_then_moves_the_expenses_not_the_pump_shortages(): void
    {
        $user = $this->person('admin');
        $no = (int) $this->capture($user, over: [51, 3], short: [51, 1], amount: '63.67')->TillBalanceNo;

        // Captured, not yet approved: nothing on either cashup has moved.
        $this->assertEqualsWithDelta(-63.67, (float) $this->cashup(51, 1)->Variance, 0.0001);
        $this->assertEqualsWithDelta(41.59, (float) $this->cashup(51, 3)->Variance, 0.0001);

        $pumpShortsBefore = $this->pumpShortages()->count();

        // PumpIT's approver passes it: dbo.sp_Insert_TillBalanceTransactions,
        // replayed over the stub row for row — a posted cash purchase request
        // per side to 'Till Balance Expense', and a line on each cashup's
        // Expenses leg for exactly the row's TransactionAmount.
        $this->postAsPumpItWould($no);

        $short = $this->cashup(51, 1);
        $over = $this->cashup(51, 3);
        $this->assertEqualsWithDelta(0.00, (float) $short->Variance, 0.0001, 'The short is cleared — to the row\'s EmployeeAmount.');
        $this->assertEqualsWithDelta(-22.08, (float) $over->Variance, 0.0001, 'The over gave it up — to the row\'s EmployeeAmount.');
        $this->assertEqualsWithDelta(63.67, (float) $short->Expenses, 0.0001, 'It arrives on the Expenses leg.');
        $this->assertEqualsWithDelta(0.00, (float) $short->StaffShorts, 0.0001, 'Not on the pump shortages leg.');
        $this->assertSame($pumpShortsBefore, $this->pumpShortages()->count(), 'And no pump shortage appears for it.');

        $balance = $this->period()['balances']->sole();
        $this->assertSame(['approved', 2, 2], [$balance->Status, (int) $balance->PostedLines, (int) $balance->Rows]);
    }

    public function test_the_screen_lists_the_cashups_worth_balancing_and_the_till_balances_on_them(): void
    {
        $user = $this->person('admin');
        $this->capture($user, over: [51, 3], short: [51, 1], amount: '63.67', note: 'TEST-screen');

        ['cashups' => $cashups, 'balances' => $balances] = $this->period();

        $byKey = $cashups->keyBy(fn ($c) => $c->TillNo.'/'.$c->ShiftNo);
        $this->assertSame(['51/1', '51/3', '52/3'], $byKey->keys()->sort()->values()->all(),
            'Till 52 day balances to nil and is below the floor; the rest are listed.');

        $this->assertEqualsWithDelta(63.67, (float) $byKey['51/1']->Pending, 0.0001);
        $this->assertEqualsWithDelta(0.00, (float) $byKey['51/1']->VarianceIfPosted, 0.0001);
        $this->assertEqualsWithDelta(-22.08, (float) $byKey['51/3']->VarianceIfPosted, 0.0001);
        $this->assertTrue((bool) $byKey['51/1']->CanBalance);
        $this->assertFalse((bool) $byKey['52/3']->CanBalance);
        $this->assertStringContainsString('No Z-read', (string) $byKey['52/3']->Problem);

        $b = $balances->sole();
        $this->assertSame([51, 3, 51, 1], [(int) $b->FromTillNo, (int) $b->FromShiftNo, (int) $b->ToTillNo, (int) $b->ToShiftNo]);
        $this->assertEqualsWithDelta(63.67, (float) $b->Amount, 0.0001);
        $this->assertSame(['pending', 'agora', 'TEST-admin', 'TEST-screen', 0], [$b->Status, $b->CapturedIn, $b->CapturedByName, $b->Note, (int) $b->PostedLines]);
    }

    /* ------------------------------------------------------------------ refusals */

    public function test_every_refusal_is_named_and_writes_nothing(): void
    {
        $user = $this->person('admin');
        $before = $this->counter();

        $this->assertRefused('CASH_WRITES_OFF', fn () => $this->procedures()->write('usp_Cash_SaveTillBalance', $this->args([51, 3], [51, 1], '1.00') + ['WriteMode' => 'off', 'UserId' => $user->Id]));
        $this->assertRefused('CASH_TILLBAL_SAME', fn () => $this->capture($user, over: [51, 1], short: [51, 1], amount: '1.00'));
        $this->assertRefused('CASH_TILLBAL_AMOUNT', fn () => $this->capture($user, over: [51, 3], short: [51, 1], amount: '0'));
        $this->assertRefused('CASH_TILLBAL_AMOUNT', fn () => $this->capture($user, over: [51, 3], short: [51, 1], amount: '1.005'));
        $this->assertRefused('CASH_TILLBAL_NO_CASHUP', fn () => $this->capture($user, over: [53, 1], short: [51, 1], amount: '1.00'));
        $this->assertRefused('CASH_TILLBAL_ZREAD', fn () => $this->capture($user, over: [51, 3], short: [52, 3], amount: '10.00'));

        $this->db()->table('agora.Cashup')->insert(['BranchId' => self::BRANCH, 'TransactionDate' => self::DAY, 'TillNo' => 52,
            'ShiftNo' => 1, 'EmployeeCode' => 'TTB02', 'Status' => 'closed', 'CreatedAt' => now(), 'CreatedBy' => $user->Id]);
        $this->assertRefused('CASH_CASHUP_CLOSED', fn () => $this->capture($user, over: [52, 1], short: [51, 1], amount: '1.00'));

        $this->assertSame($before, $this->counter(), 'No refusal takes a number.');
        $this->assertCount(0, $this->db()->table('PumpIT.dbo.BRN_TillBalance')->where('SSBranchId', self::BRANCH)->whereIn('TillNo', self::TILLS)->get());
        $this->assertSame(0, $this->db()->table('agora.TillBalanceEvent')->where('BranchId', self::BRANCH)->count());

        // A second press of the same till balance while the first still waits.
        $first = (int) $this->capture($user, over: [51, 3], short: [51, 1], amount: '63.67')->TillBalanceNo;
        try {
            $this->capture($user, over: [51, 3], short: [51, 1], amount: '63.67');
            $this->fail('Expected CASH_TILLBAL_DUPLICATE.');
        } catch (AgoraProcException $e) {
            $this->assertSame('CASH_TILLBAL_DUPLICATE', $e->code());
            $this->assertStringContainsString("Till balance {$first}", $e->getMessage());
        }
        $this->assertSame($before + 1, $this->counter(), 'The refused second press took no number.');
    }

    public function test_a_counter_behind_the_table_is_refused_and_rolled_back(): void
    {
        $user = $this->person('admin');
        $next = $this->counter();

        // Somebody's row already carries the number the counter would give.
        $this->db()->table('PumpIT.dbo.BRN_TillBalance')->insert([
            'SSBranchId' => self::BRANCH, 'TillNo' => 53, 'ShiftNo' => 1, 'TransactionDate' => self::DAY, 'LogFile' => 'TBTAKEN',
            'TillBalanceNo' => $next, 'EmployeeCode' => 'TTB01', 'TransactionAmount' => 0, 'OriginalEmployeeAmount' => 0,
            'EmployeeAmount' => 0, 'CreatedByUserId' => null,
        ]);

        $this->assertRefused('CASH_TILLBAL_NUMBER_TAKEN', fn () => $this->capture($user, over: [51, 3], short: [51, 1], amount: '5.00'));
        $this->assertSame($next, $this->counter(), 'The counter\'s move was rolled back with the rest.');
        $this->assertSame(1, $this->db()->table('PumpIT.dbo.BRN_TillBalance')->where('SSBranchId', self::BRANCH)->where('TillBalanceNo', $next)->count());
    }

    /* ------------------------------------------------------------------ pump shortages */

    public function test_the_pump_shortages_grid_is_the_cashups_pump_shortages_leg(): void
    {
        $rows = $this->pumpShortages();
        $mine = $rows->filter(fn ($r) => (int) $r->BranchId === self::BRANCH && in_array((int) $r->TillNo, self::TILLS, true));

        $line = $mine->sole();
        $this->assertSame([52, 1, 'TTB01', 99001], [(int) $line->TillNo, (int) $line->ShiftNo, $line->EmployeeCode, (int) $line->StaffShortsNo]);
        $this->assertEqualsWithDelta(25.00, (float) $line->AmountShort, 0.0001);
        $this->assertTrue((bool) $line->Counted);
        $this->assertTrue((bool) $line->IsPumpAttendant);
        $this->assertSame('TEST-pump short', $line->Reason, 'From the staff short that put it on the cashup.');
        $this->assertSame('TTB02', $line->CashierCode);

        // Summed for the till and shift, it IS the cashup's pump shortages.
        $this->assertEqualsWithDelta((float) $this->cashup(52, 1)->StaffShorts, (float) $line->AmountShort, 0.0001);

        // An empty site list means the sites the person may see, never the estate.
        $none = $this->procedures()->callSets('usp_Cash_GridPumpShortages', [
            'DateFrom' => self::DAY, 'DateTo' => self::DAY, 'AllowedBranchIds' => '1',
        ]);
        $this->assertSame(0, (int) $none[1]->first()->TotalRows);

        // Header filters reach the procedure.
        $filtered = $this->procedures()->callSets('usp_Cash_GridPumpShortages', [
            'BranchIds' => (string) self::BRANCH, 'DateFrom' => self::DAY, 'DateTo' => self::DAY,
            'FiltersJson' => json_encode([['column' => 'AmountShort', 'type' => 'number', 'op' => 'gt', 'value' => '30']]),
        ]);
        $this->assertSame(0, (int) $filtered[1]->first()->TotalRows);
    }

    /* ------------------------------------------------------------------ the screens */

    public function test_the_screen_captures_for_a_cashier_and_only_shows_for_an_auditor(): void
    {
        $admin = $this->person('admin');
        $scope = ['branch_id' => self::BRANCH, 'date_from' => self::DAY, 'date_to' => self::DAY];

        $this->actingAs($admin)->get(route('app.cash.tillbalance', $scope))
            ->assertOk()
            ->assertSee('Till balance')
            ->assertSee('agora.usp_Cash_GetTillBalance')
            ->assertSee('Capturing writes to PumpIT')
            ->assertSee('Capture in PumpIT');

        $this->actingAs($admin)->post(route('app.cash.tillbalance.store'), $scope + [
            'from' => self::DAY.'|51|3', 'to' => self::DAY.'|51|1', 'amount' => '63.67', 'note' => 'TEST-screen press',
        ])
            ->assertRedirect(route('app.cash.tillbalance', $scope))
            ->assertSessionHas('tillBalanceCode', 'CASH_TILLBAL_CAPTURED');

        $this->assertCount(2, $this->db()->table('PumpIT.dbo.BRN_TillBalance')->where('SSBranchId', self::BRANCH)->where('TillNo', 51)->get());

        // A refusal comes back as a message, not a 500.
        $this->actingAs($admin)->post(route('app.cash.tillbalance.store'), $scope + [
            'from' => self::DAY.'|51|3', 'to' => self::DAY.'|51|1', 'amount' => '63.67',
        ])
            ->assertRedirect()
            ->assertSessionHas('tillBalanceCode', 'CASH_TILLBAL_DUPLICATE');

        // Shape is the request's to refuse.
        $this->actingAs($admin)->post(route('app.cash.tillbalance.store'), $scope + ['from' => 'nonsense', 'to' => self::DAY.'|51|1', 'amount' => '-3'])
            ->assertSessionHasErrors(['from', 'amount']);

        $this->actingAs($admin)->get(route('app.cash.pumpshortages', ['branches' => self::BRANCH, 'from' => self::DAY, 'to' => self::DAY]))
            ->assertOk()
            ->assertSee('agora.usp_Cash_GridPumpShortages')
            ->assertSee('TEST-Lindiwe Tillbalance');

        $auditor = $this->person('auditor');

        $this->actingAs($auditor)->get(route('app.cash.tillbalance', $scope))
            ->assertOk()
            ->assertSee('agora.usp_Cash_GetTillBalance')
            ->assertDontSee('id="till-balance"', false)
            ->assertDontSee('Capture in PumpIT');

        $this->actingAs($auditor)->post(route('app.cash.tillbalance.store'), $scope + [
            'from' => self::DAY.'|51|3', 'to' => self::DAY.'|51|1', 'amount' => '1.00',
        ])->assertForbidden();

        $this->actingAs($auditor)->get(route('app.cash.pumpshortages'))->assertOk();

        // Switched off: the screen says so and the press is not reachable.
        config(['cash.tillbalance.write_mode' => 'off']);
        $this->actingAs($admin)->get(route('app.cash.tillbalance', $scope))
            ->assertOk()
            ->assertSee('Capturing to PumpIT is off')
            ->assertDontSee('Capture in PumpIT');
        $this->actingAs($admin)->post(route('app.cash.tillbalance.store'), $scope + [
            'from' => self::DAY.'|51|3', 'to' => self::DAY.'|51|1', 'amount' => '1.00',
        ])->assertForbidden();
    }

    public function test_the_pump_shortages_grid_is_registered(): void
    {
        // Fails until config/grids.php carries
        //   'app.cash.pumpshortages' => Modules\Cash\Grids\PumpShortageGrid::class,
        // — the extract and the saved column layout both find a grid by key.
        $grids = (require base_path('config/grids.php'))['grids'];

        $this->assertSame(PumpShortageGrid::class, $grids['app.cash.pumpshortages'] ?? null);
    }

    /* ------------------------------------------------------------------ fixture */

    private function seedFixture(): void
    {
        $pumpit = fn (string $table) => $this->db()->table("PumpIT.dbo.{$table}");
        $at = self::DAY.' 00:00:00';

        foreach ([51 => 'TEST-TB Till M', 52 => 'TEST-TB Till N'] as $no => $name) {
            $pumpit('BRN_Tills')->insert(['SSBranchId' => self::BRANCH, 'TillNo' => $no, 'TillDescription' => $name, 'Location' => 'WINBRANCH']);
        }
        foreach ([1 => 'TEST-TB Day', 3 => 'TEST-TB Night'] as $no => $name) {
            $pumpit('BRN_Shifts')->insert(['SSBranchId' => self::BRANCH, 'ShiftNo' => $no, 'ShiftDescription' => $name, 'IsActive' => 1]);
        }
        foreach (['TTB01' => ['Lindiwe', 1], 'TTB02' => ['Bongani', 0]] as $code => [$first, $pump]) {
            $pumpit('BRN_Employee')->insert(['SSBranchId' => self::BRANCH, 'EmployeeCode' => $code, 'FirstName' => 'TEST-'.$first,
                'Surname' => 'Tillbalance', 'NickName' => null, 'IsActive' => 1, 'IsPumpAttendant' => $pump, 'PostNo' => 0]);
        }
        $pumpit('BRN_Suppliers')->insert(['SSBranchId' => self::BRANCH, 'SupplierCode' => 'TTBE1', 'SupplierName' => 'Till Balance Expense',
            'IsCash' => 1, 'IsStock' => 0, 'isActive' => 1, 'SupplierID' => 'TTBE1']);

        // [till, shift, cashier, Z-read log (null: none), closing balance, cash bags]
        foreach ([
            [51, 1, 'TTB01', 'TB9101', 1000.00, 936.33],
            [51, 3, 'TTB01', 'TB9102', 500.00, 541.59],
            [52, 1, 'TTB02', 'TB9201', 200.00, 175.00],
            [52, 3, 'TTB02', null, 100.00, 90.00],
        ] as [$till, $shift, $cashier, $log, $closing, $bags]) {
            if ($log !== null) {
                $pumpit('DBF_P3TRANS_ZREAD')->insert([
                    'SSBranchId' => self::BRANCH, 'DATE' => self::DAY, 'EOD_CNTR' => '601', 'TERMNUM' => (string) $till, 'LOGFILE' => $log,
                    'USERID' => 'TBA', 'LOCATION' => 'WINBRANCH', 'Sales' => $closing, 'TillNo' => $till, 'ShiftNo' => $shift,
                    'EmployeeCode' => $cashier, 'isCaptured' => 0, 'MIST_EOD' => null,
                ]);
            }

            $pumpit('BRN_DailyBanking')->insert([
                'SSBranchId' => self::BRANCH, 'TillNo' => $till, 'ShiftNo' => $shift, 'TransactionDate' => $at, 'EmployeeCode' => $cashier,
                'ClosingBalance' => $closing, 'CreditCardAmount' => 0, 'CardAmount1' => 0, 'CardAmount2' => 0, 'EFuelAmount' => 0,
                'DirectDepositAmount' => 0, 'EmployeeAmount' => 0, 'Note' => null, 'DayEndNo' => '601', 'Posted' => 0,
                'Imported' => 0, 'CreateDateTime' => $at,
            ]);

            $pumpit('BRN_DailyBankingCashBags')->insert(['SSBranchId' => self::BRANCH, 'TillNo' => $till, 'ShiftNo' => $shift,
                'TransactionDate' => $at, 'CashBagNo' => "TEST-TB{$till}{$shift}", 'CashBagAmount' => $bags]);
        }

        // A pump attendant's short on till 52's day cashup, put there by an approved staff short.
        $pumpit('BRN_StaffShorts')->insert(['SSBranchId' => self::BRANCH, 'StaffShortsNo' => 99001, 'EmployeeCode' => 'TTB01',
            'AmountShort' => 25.00, 'Note' => 'TEST-note', 'Reason' => 'TEST-pump short', 'TransactionDate' => $at,
            'CreatedByUserId' => self::ERP_USER, 'Approved' => 1, 'ApprovedDate' => $at, 'ApprovedByUserId' => self::ERP_USER,
            'IsDeclined' => 0, 'DeclinedByUserId' => 0, 'CreateDateTime' => $at]);
        $pumpit('BRN_DailyBankingEmployees')->insert(['SSBranchId' => self::BRANCH, 'TillNo' => 52, 'ShiftNo' => 1,
            'TransactionDate' => $at, 'EmployeeCode' => 'TTB01', 'AmountShort' => 25.00, 'Reason' => 'TEST-pump short',
            'Note' => 'TEST-note', 'StaffShortsNo' => 99001]);
    }

    /**
     * dbo.sp_Insert_TillBalanceTransactions over the stub, for one till
     * balance: per row, a posted cash purchase request to 'Till Balance
     * Expense' carrying the number in QuoteNo, and the Expenses leg line for
     * the row's TransactionAmount; then the rows marked approved.
     */
    private function postAsPumpItWould(int $no): void
    {
        $pumpit = fn (string $table) => $this->db()->table("PumpIT.dbo.{$table}");

        foreach ($this->rows($no)->values() as $i => $row) {
            $request = 'CA-TTB'.($i + 1);

            $pumpit('BRN_Transaction')->insert(['SSBranchId' => self::BRANCH, 'TransactionNo' => $request, 'TypeCode' => '01',
                'PurchaseRequestDate' => self::DAY, 'SupplierCode' => 'TTBE1', 'BankDetail' => '', 'QuoteNo' => (string) $no,
                'Posted' => 1, 'ShiftNo' => (int) $row->ShiftNo, 'IsDeclined' => 0, 'Approved' => 1, 'ApprovedByUserId' => self::ERP_USER,
                'Posted_WINCASHUP' => 0, 'CreateDateTime' => now()]);
            $pumpit('BRN_TransactionLine')->insert(['SSBranchId' => self::BRANCH, 'TransactionNo' => $request, 'TypeCode' => '01',
                'ItemNo' => 1, 'ExpenseNo' => 1, 'PurchaseAmount' => $row->TransactionAmount,
                'PurchaseRequestDescription' => 'System Generated Till Balance Request', 'Posted' => 1, 'Imported' => 0,
                'PastelPosted' => 0, 'VATInd' => 0, 'IsEmailSent' => 0, 'IsApprovedEmailSent' => 0]);
            $pumpit('BRN_DailyBankingExpenses')->insert(['SSBranchId' => self::BRANCH, 'TillNo' => (int) $row->TillNo,
                'ShiftNo' => (int) $row->ShiftNo, 'TransactionDate' => $row->TransactionDate, 'TransactionNo' => $request,
                'SupplierCode' => 'TTBE1', 'ExpenseAmount' => $row->TransactionAmount]);
        }

        $pumpit('BRN_TillBalance')->where('SSBranchId', self::BRANCH)->where('TillBalanceNo', $no)
            ->update(['Approved' => 1, 'ApprovedDate' => now(), 'ApprovedByUserId' => self::ERP_USER]);
    }

    private function cleanUp(): void
    {
        $db = $this->db();
        $pumpit = fn (string $table) => $db->table("PumpIT.dbo.{$table}");

        $numbers = $pumpit('BRN_TillBalance')->where('SSBranchId', self::BRANCH)->whereIn('TillNo', self::TILLS)
            ->where('TransactionDate', self::DAY)->pluck('TillBalanceNo')->all();

        $db->table('agora.TillBalanceEvent')->where('BranchId', self::BRANCH)
            ->where(fn ($q) => $q->whereIn('TillBalanceNo', $numbers ?: [-1])->orWhereIn('FromTillNo', self::TILLS))->delete();
        $pumpit('BRN_TillBalance')->where('SSBranchId', self::BRANCH)->whereIn('TillNo', self::TILLS)->where('TransactionDate', self::DAY)->delete();
        $db->table('agora.CashupEvent')->where('BranchId', self::BRANCH)->where('TransactionDate', self::DAY)->whereIn('TillNo', self::TILLS)->delete();
        $db->table('agora.Cashup')->where('BranchId', self::BRANCH)->where('TransactionDate', self::DAY)->whereIn('TillNo', self::TILLS)->delete();

        $pumpit('BRN_DailyBanking')->where('SSBranchId', self::BRANCH)->where('TransactionDate', self::DAY)->whereIn('TillNo', self::TILLS)->delete();
        foreach (self::LEGS as $leg) {
            $pumpit("BRN_DailyBanking{$leg}")->where('SSBranchId', self::BRANCH)->whereIn('TillNo', self::TILLS)
                ->where($leg === 'SmartATM' ? 'DepositDateTime' : 'TransactionDate', self::DAY)->delete();
        }
        $pumpit('DBF_P3TRANS_ZREAD')->where('SSBranchId', self::BRANCH)->where('USERID', 'TBA')->delete();
        $pumpit('BRN_StaffShorts')->where('SSBranchId', self::BRANCH)->where('StaffShortsNo', 99001)->delete();
        $pumpit('BRN_TransactionLine')->where('SSBranchId', self::BRANCH)->where('TransactionNo', 'like', 'CA-TTB%')->delete();
        $pumpit('BRN_Transaction')->where('SSBranchId', self::BRANCH)->where('TransactionNo', 'like', 'CA-TTB%')->delete();
        $pumpit('BRN_Suppliers')->where('SSBranchId', self::BRANCH)->where('SupplierCode', 'TTBE1')->delete();
        $pumpit('BRN_Employee')->where('SSBranchId', self::BRANCH)->whereIn('EmployeeCode', ['TTB01', 'TTB02'])->delete();
        $pumpit('BRN_Tills')->where('SSBranchId', self::BRANCH)->where('TillDescription', 'like', 'TEST-TB%')->delete();
        $pumpit('BRN_Shifts')->where('SSBranchId', self::BRANCH)->where('ShiftDescription', 'like', 'TEST-TB%')->delete();
    }

    /**
     * @param  array{0: int, 1: int}  $over  till and shift the amount comes off
     * @param  array{0: int, 1: int}  $short  till and shift it goes onto
     */
    private function capture(User $user, array $over, array $short, string $amount, ?string $note = null): object
    {
        return app(TillBalanceService::class)->capture(
            self::BRANCH,
            ['date' => self::DAY, 'till' => $over[0], 'shift' => $over[1]],
            ['date' => self::DAY, 'till' => $short[0], 'shift' => $short[1]],
            $amount,
            (int) $user->Id,
            $note,
        );
    }

    /**
     * @param  array{0: int, 1: int}  $from
     * @param  array{0: int, 1: int}  $to
     * @return array<string, mixed>
     */
    private function args(array $from, array $to, string $amount): array
    {
        return [
            'BranchId' => self::BRANCH,
            'FromDate' => self::DAY, 'FromTillNo' => $from[0], 'FromShiftNo' => $from[1],
            'ToDate' => self::DAY, 'ToTillNo' => $to[0], 'ToShiftNo' => $to[1],
            'Amount' => $amount,
        ];
    }

    /** @return Collection<int, \stdClass> */
    private function rows(int $no): Collection
    {
        return $this->db()->table('PumpIT.dbo.BRN_TillBalance')
            ->where('SSBranchId', self::BRANCH)->where('TillBalanceNo', $no)->orderBy('ShiftNo')->get();
    }

    private function cashup(int $till, int $shift): object
    {
        return $this->procedures()->callSets('usp_Cash_GetCashup', [
            'BranchId' => self::BRANCH, 'DateFrom' => self::DAY, 'TillNo' => $till, 'ShiftNo' => $shift,
        ])[0]->sole();
    }

    /** @return array{cashups: Collection<int, object>, balances: Collection<int, object>} */
    private function period(): array
    {
        return app(TillBalanceService::class)->period(new TillBalanceScope(self::BRANCH, self::DAY, self::DAY, null));
    }

    /** @return Collection<int, object> */
    private function pumpShortages(): Collection
    {
        return $this->procedures()->callSets('usp_Cash_GridPumpShortages', [
            'BranchIds' => (string) self::BRANCH, 'DateFrom' => self::DAY, 'DateTo' => self::DAY, 'PageSize' => 500,
        ])[0];
    }

    private function counter(): int
    {
        return (int) $this->db()->table('PumpIT.dbo.SS_UniqueNumber')->where('SectionId', 3)->value('NextUniqueNumber');
    }

    private function assertRefused(string $code, callable $press): void
    {
        try {
            $press();
            $this->fail("Expected the refusal {$code}.");
        } catch (AgoraProcException $e) {
            $this->assertSame($code, $e->code(), $e->getMessage());
        }
    }

    private function person(string $profile): User
    {
        $user = User::query()->acrossBranches()->create([
            'BranchId' => (int) config('agora.group_branch_id', 2),
            'EmailAddress' => 'TEST-t038-'.uniqid().'@agora.invalid',
            'UserName' => 'TEST-'.$profile,
            'IsActive' => true,
            'IsLocked' => false,
            'Workspace' => 'ho',
            'PasswordHash' => 'TEST-only-'.Str::random(24),
        ]);

        // The PumpIT user id a capture stamps as CreatedByUserId.
        $this->db()->table('agora.User')->where('Id', $user->Id)->update(['LegacyUserId' => self::ERP_USER]);

        $this->grantProfile($user, $profile);
        $this->made[] = $user;

        return $user;
    }

    private function procedures(): ProcedureService
    {
        return new ProcedureService;
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }
}
