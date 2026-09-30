<?php

namespace Tests\Feature\Cash;

use App\Exceptions\AgoraProcException;
use Illuminate\Support\Facades\DB;
use Modules\Cash\Grids\CashupGrid;
use Modules\Cash\Grids\ZreadNoCashupGrid;
use Tests\TestCase;

/**
 * Daily banking and cashups (T037): the legacy variance, leg by leg; the card
 * load; the one PumpIT write and its refusals; confirm and close; the grids
 * and the screens.
 *
 * THE FIXTURE (branch 999, 10 June 2026, all TEST- data in the LOCAL PumpIT
 * stub):
 *
 *   till 41 · day   cashier TCU01, Z-read 1 000.00 (EOD 501, log 9001, CUA),
 *                   a cashup in PumpIT with a line in every leg — including the
 *                   four quirks the legacy total has: a customer and a person
 *                   not on PumpIT's lists (not counted), a Smart ATM deposit
 *                   stamped 10:00 rather than midnight (not this cashup's at
 *                   all), and a fleet-card line with no oil amount (NULL, so
 *                   skipped). Two FNB lines, one reconciled in recon batch 777.
 *                   Actual 1 008.00, variance 8.00.
 *   till 42 · night cashier TCU02, Z-read 500.00 (EOD 501, log 9002, CUB),
 *                   allocated, and NO cashup — the "no cashup" queue's row, and
 *                   the cashup the first save creates.
 *
 * The card terminal's batch file carries, for till 41: batch 1 at 150.00 (as
 * on the leg), batch 2 at 45.00 (the leg says 40.00), batch 3 at 20.00 (not on
 * the leg), and two lines the load must ignore (no batch number, no POS
 * reference); for till 42, batch 9 at 120.00.
 *
 * Writes the LOCAL stub and Agora's database only, on branch 999, and removes
 * every row it wrote. skipUnlessLocalStub guarantees it never runs against the
 * customer's instance.
 */
class CashupTest extends TestCase
{
    use CashupFixture;

    /* ------------------------------------------------------------------ the figures */

    public function test_the_cashup_totals_are_the_legacy_getters_leg_by_leg(): void
    {
        $sets = $this->procedures()->callSets('usp_Cash_GetCashup', ['BranchId' => self::BRANCH, 'DateFrom' => self::DAY]);

        $this->assertCount(4, $sets, 'Always four result sets.');
        $this->assertCount(1, $sets[0], 'One header, one cashup — and no fan-out from a label lookup.');

        $a = $sets[0]->first();
        $expected = [
            'ZRead' => 1000.00, 'CashBags' => 300.00, 'Debtors' => 50.00, 'SmartATM' => 100.00, 'CashDevices' => 200.00,
            'DirectDeposits' => 25.00, 'StaffShorts' => 10.00, 'Expenses' => -15.00, 'FleetCard' => 45.00,
            'Suppliers' => 30.00, 'ABSA' => 60.00, 'Zapper' => 1.00, 'Yumbi' => 7.00, 'YumbiVoucher' => 3.00,
            'FNB' => 190.00, 'Infinity' => 2.00, 'ActualTotal' => 1008.00, 'Variance' => 8.00, 'Cards' => 250.00,
        ];

        foreach ($expected as $column => $value) {
            $this->assertEqualsWithDelta($value, (float) $a->{$column}, 0.0001, "{$column}");
        }

        $this->assertSame(2, (int) $a->NotCountedLines, 'The customer and the person PumpIT has no record of are listed, not counted.');
        $this->assertSame('Agrees', $a->ZreadCheck);
        $this->assertTrue((bool) $a->LegacyGridShows);
        $this->assertSame('Open', $a->Status);

        // The same figure the legacy getter's own SQL produces over the same rows.
        $this->assertEqualsWithDelta($this->legacyEmployeeAmount(41, 1), (float) $a->Variance, 0.0001,
            'Variance is dbo.sp_GetDailyBankingWithMOPS\'s EmployeeAmount, recomputed by its own joins.');

        // The day's Z-reads: the one cashed up, and the one nobody has.
        $zreads = $sets[2]->keyBy(fn ($z) => (int) $z->TillNo);
        $this->assertTrue((bool) $zreads[41]->HasCashup);
        $this->assertFalse((bool) $zreads[42]->HasCashup);
    }

    public function test_one_cashup_returns_its_lines_and_says_which_do_not_count(): void
    {
        $sets = $this->procedures()->callSets('usp_Cash_GetCashup', [
            'BranchId' => self::BRANCH, 'DateFrom' => self::DAY, 'TillNo' => 41, 'ShiftNo' => 1,
        ]);

        $lines = $sets[1];
        $this->assertCount(19, $lines, 'Every line of every leg at the exact key — the 10:00 Smart ATM deposit is not one of them.');

        $nope = $lines->first(fn ($l) => $l->Channel === 'Debtors' && str_starts_with($l->LineRef, 'NOPE'));
        $this->assertFalse((bool) $nope->InActualTotal);

        $reconciled = $lines->first(fn ($l) => $l->Channel === 'FNB' && str_starts_with($l->LineRef, '0000000001'));
        $this->assertTrue((bool) $reconciled->IsReconciled);
        $this->assertSame(777, (int) $reconciled->ReconBatchNo);

        $this->assertNull($lines->first(fn ($l) => $l->Channel === 'Debtors')->ReconBatchNo, 'A leg with no stamp column says so with NULL.');
    }

    public function test_the_card_load_sums_the_batch_file_as_the_legacy_does(): void
    {
        $sets = $this->procedures()->callSets('usp_Cash_LoadCardAmounts', $this->key(41, 1));

        $byBatch = $sets[0]->keyBy('BatchNo');
        $this->assertCount(3, $byBatch, 'Lines with no batch number or no POS reference are not batches.');
        $this->assertSame(['same', 150.00], [$byBatch['0000000001']->Action, (float) $byBatch['0000000001']->Amount]);
        $this->assertSame(['changed', 45.00, 40.00], [$byBatch['0000000002']->Action, (float) $byBatch['0000000002']->Amount, (float) $byBatch['0000000002']->OnLegAmount]);
        $this->assertSame('new', $byBatch['0000000003']->Action);
        $this->assertSame('100001', $byBatch['0000000003']->MerchantNo, 'Characters 8–13 of MERCHID, as the legacy cuts them.');

        $summary = $sets[1]->first();
        $this->assertSame(1, (int) $summary->Zreads);
        $this->assertEqualsWithDelta(215.00, (float) $summary->Total, 0.0001);
        $this->assertEqualsWithDelta(190.00, (float) $summary->OnLegTotal, 0.0001);
    }

    /* ------------------------------------------------------------------ the write */

    public function test_saving_the_card_load_writes_fnb_and_the_header_and_keeps_what_it_replaced(): void
    {
        $status = $this->service()->saveCardLoad($this->scope(41, 1), 1);

        $this->assertSame('CASH_CASHUP_SAVED', $status->Code);
        $this->assertSame([1, 1, 1], [(int) $status->Inserted, (int) $status->Updated, (int) $status->Unchanged]);
        $this->assertEqualsWithDelta(33.00, (float) $status->Variance, 0.0001, 'Actual 1 033 (1 008 + 5 + 20) less the Z read.');

        $fnb = $this->db()->table('PumpIT.dbo.BRN_DailyBankingFNB')->where('SSBranchId', self::BRANCH)->where('TillNo', 41)
            ->pluck('Amount', 'BatchNo')->map(fn ($a) => (float) $a);
        $this->assertEqualsWithDelta(150.00, $fnb['0000000001'], 0.0001, 'The reconciled line was sent unchanged and not written.');
        $this->assertEqualsWithDelta(45.00, $fnb['0000000002'], 0.0001);
        $this->assertEqualsWithDelta(20.00, $fnb['0000000003'], 0.0001);

        $header = $this->header(41, 1);
        $this->assertEqualsWithDelta(215.00, (float) $header->CreditCardAmount, 0.0001, 'CreditCardAmount is the FNB leg.');
        $this->assertEqualsWithDelta(215.00, (float) $header->CardAmount1, 0.0001);
        $this->assertEqualsWithDelta(33.00, (float) $header->EmployeeAmount, 0.0001, 'EmployeeAmount is the variance, as the client stores it.');
        $this->assertEqualsWithDelta(1000.00, (float) $header->ClosingBalance, 0.0001);

        $event = collect($this->db()->select("SELECT * FROM agora.CashupEvent WHERE BranchId = ? AND [Event] = 'saved'", [self::BRANCH]))->sole();
        $writes = json_decode((string) $event->WritesJson, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($writes);
        $this->assertSame('update', $writes['header']['action']);
        $this->assertEqualsWithDelta(0.0, (float) $writes['header']['prior']['CreditCardAmount'], 0.0001);
        $this->assertIsArray($writes['lines']);
        $lines = [];
        foreach ($writes['lines'] as $line) {
            $this->assertIsArray($line);
            $lines[(string) $line['key']['BatchNo']] = $line;
        }
        $this->assertSame(['update', 40.0, 45.0],
            [$lines['0000000002']['action'], (float) $lines['0000000002']['prior']['Amount'], (float) $lines['0000000002']['new']['Amount']]);
        $this->assertSame('insert', $lines['0000000003']['action']);
        $this->assertSame('live', $event->WriteMode);

        // The same press again finds PumpIT already right, and writes nothing.
        $again = $this->service()->saveCardLoad($this->scope(41, 1), 1);
        $this->assertSame('CASH_CASHUP_UNCHANGED', $again->Code);
        $this->assertSame(1, (int) $this->db()->table('agora.CashupEvent')->where('BranchId', self::BRANCH)->where('Event', 'saved')->count());
    }

    public function test_a_reconciled_line_is_never_changed_and_the_whole_save_is_refused(): void
    {
        // The terminal now says batch 1 was 160.00 — but Recon has matched
        // that line to the bank.
        $this->card('9001', 'CUA', '7', '0000000001', 10.00, 'XXXXXXX100001', 'P9');

        try {
            $this->service()->saveCardLoad($this->scope(41, 1), 1);
            $this->fail('A save that would change a reconciled line must be refused.');
        } catch (AgoraProcException $e) {
            $this->assertSame('CASH_LEG_RECONCILED', $e->code());
            $this->assertStringContainsString('0000000001', $e->getMessage());
            $this->assertStringContainsString('777', $e->getMessage());
        }

        $fnb = $this->db()->table('PumpIT.dbo.BRN_DailyBankingFNB')->where('SSBranchId', self::BRANCH)->where('TillNo', 41)->pluck('Amount', 'BatchNo');
        $this->assertCount(2, $fnb, 'Batch 3 was not inserted either: nothing is written.');
        $this->assertEqualsWithDelta(40.00, (float) $fnb['0000000002'], 0.0001);
        $this->assertEqualsWithDelta(0.00, (float) $this->header(41, 1)->CreditCardAmount, 0.0001);
        $this->assertSame(0, $this->db()->table('agora.CashupEvent')->where('BranchId', self::BRANCH)->count());
    }

    public function test_the_first_save_creates_the_cashup_from_its_zread(): void
    {
        $this->assertSame(1, (int) $this->noCashup()[1]->first()->TotalRows, 'Till 42 is on the no-cashup queue.');

        $status = $this->service()->saveCardLoad($this->scope(42, 3), 1);

        $this->assertSame('CASH_CASHUP_SAVED', $status->Code);
        $header = $this->header(42, 3);
        $this->assertSame(['TCU02', '501'], [trim($header->EmployeeCode), trim($header->DayEndNo)]);
        $this->assertEqualsWithDelta(500.00, (float) $header->ClosingBalance, 0.0001);
        $this->assertEqualsWithDelta(120.00, (float) $header->CreditCardAmount, 0.0001);
        $this->assertEqualsWithDelta(-380.00, (float) $header->EmployeeAmount, 0.0001);
        $this->assertSame([0, 0], [(int) $header->Posted, (int) $header->Imported]);

        $this->assertEqualsCanonicalizing(['created', 'saved'],
            $this->db()->table('agora.CashupEvent')->where('BranchId', self::BRANCH)->where('TillNo', 42)->pluck('Event')->all());
        $this->assertSame(0, (int) $this->noCashup()[1]->first()->TotalRows, 'And it leaves the queue.');
    }

    public function test_the_switch_the_held_legs_and_an_enabled_trigger_each_refuse(): void
    {
        config(['cash.cashup.write_mode' => 'off']);
        $this->assertRefused('CASH_WRITES_OFF', fn () => $this->service()->saveCardLoad($this->scope(41, 1), 1));
        config(['cash.cashup.write_mode' => 'live']);

        // Un-held by T040: pump shortages are chosen from the approved staff
        // shorts, each named by its number — a line typed as a person is not one.
        $this->assertRefused('CASH_LEG_LINE', fn () => $this->service()->save($this->scope(41, 1), 'StaffShorts',
            [['EmployeeCode' => 'TCU02']], 1));
        $this->assertRefused('CASH_LEG_HELD', fn () => $this->service()->save($this->scope(41, 1), 'FleetCard',
            [['anything' => '1']], 1));

        $this->assertRefused('CASH_CASHUP_NO_ZREAD', fn () => $this->service()->save($this->scope(43, 1), 'FNB',
            [['BatchNo' => '0000000001', 'MerchantNo' => '100001', 'Amount' => '1']], 1));

        // The customer's header carries a trigger that rewrites the Alteryx copy
        // on every change. Disabled on their instance; enabled, nothing is written.
        DB::connection(config('agora.connections.app'))->unprepared(
            "EXEC PumpIT.sys.sp_executesql N'CREATE TRIGGER dbo.TEST_trg_CashupSync ON dbo.BRN_DailyBanking AFTER UPDATE AS SET NOCOUNT ON;'"
        );
        $this->assertRefused('CASH_PUMPIT_TRIGGER', fn () => $this->service()->saveCardLoad($this->scope(41, 1), 1));
    }

    /* ------------------------------------------------------------------ confirm and close */

    public function test_confirm_then_close_and_nothing_moves_a_closed_cashup(): void
    {
        $confirmed = $this->service()->confirm($this->scope(41, 1), 1);
        $this->assertSame('CASH_CASHUP_CONFIRMED', $confirmed->Code);

        $state = $this->state(41, 1);
        $this->assertSame('confirmed', $state->Status);
        $this->assertEqualsWithDelta(8.00, (float) $state->ConfirmedVariance, 0.0001);
        $this->assertSame('Confirmed', $this->one(41, 1)->Status);

        $this->assertRefused('CASH_CASHUP_ALREADY', fn () => $this->service()->confirm($this->scope(41, 1), 1));

        $closed = $this->service()->close($this->scope(41, 1), 1, 'TEST-signed off');
        $this->assertSame('CASH_CASHUP_CLOSED_OFF', $closed->Code);
        $this->assertSame('closed', $this->state(41, 1)->Status);

        $this->assertRefused('CASH_CASHUP_CLOSED', fn () => $this->service()->saveCardLoad($this->scope(41, 1), 1));
        $this->assertRefused('CASH_CASHUP_CLOSED', fn () => $this->service()->confirm($this->scope(41, 1), 1));
        $this->assertSame(['confirmed', 'closed'],
            $this->db()->table('agora.CashupEvent')->where('BranchId', self::BRANCH)->orderBy('Id')->pluck('Event')->all());

        // Nothing in PumpIT moved: confirm and close are Agora's own record.
        $this->assertEqualsWithDelta(0.00, (float) $this->header(41, 1)->CreditCardAmount, 0.0001);
    }

    public function test_a_reason_is_kept_for_every_exception(): void
    {
        config(['cash.cashup.variance_tolerance' => 5.00]);
        $this->assertRefused('CASH_CASHUP_REASON', fn () => $this->service()->confirm($this->scope(41, 1), 1));
        $this->assertSame('CASH_CASHUP_CONFIRMED', $this->service()->confirm($this->scope(41, 1), 1, 'TEST-counted twice')->Code);
        $this->assertSame('TEST-counted twice', $this->state(41, 1)->ConfirmNote);

        // A Z-read that no longer agrees with the cashup is an exception too.
        config(['cash.cashup.variance_tolerance' => 150.00]);
        $this->db()->update("UPDATE PumpIT.dbo.DBF_P3TRANS_ZREAD SET Sales = 999 WHERE SSBranchId = ? AND USERID = 'CUA'", [self::BRANCH]);
        $this->assertSame('Z-read differs', $this->one(41, 1)->ZreadCheck);
        $this->db()->table('agora.Cashup')->where('BranchId', self::BRANCH)->update(['Status' => 'open']);
        $this->assertRefused('CASH_CASHUP_REASON', fn () => $this->service()->confirm($this->scope(41, 1), 1));
    }

    public function test_only_what_was_confirmed_is_closed(): void
    {
        $this->assertRefused('CASH_CASHUP_NOT_CONFIRMED', fn () => $this->service()->close($this->scope(41, 1), 1));

        $this->service()->confirm($this->scope(41, 1), 1);

        // A recon adjustment in PumpIT moves a leg after the confirmation.
        $this->db()->update('UPDATE PumpIT.dbo.BRN_DailyBankingCashBags SET CashBagAmount = 310 WHERE SSBranchId = ? AND TillNo = 41', [self::BRANCH]);

        $this->assertTrue((bool) $this->one(41, 1)->FiguresMoved);
        $this->assertRefused('CASH_CASHUP_CHANGED', fn () => $this->service()->close($this->scope(41, 1), 1));
    }

    public function test_a_save_that_moves_the_figures_withdraws_the_confirmation(): void
    {
        $this->service()->confirm($this->scope(41, 1), 1);
        $this->service()->saveCardLoad($this->scope(41, 1), 1);

        $this->assertSame('open', $this->state(41, 1)->Status);
        $this->assertSame(['confirmed', 'saved', 'unconfirmed'],
            $this->db()->table('agora.CashupEvent')->where('BranchId', self::BRANCH)->orderBy('Id')->pluck('Event')->all());
    }

    /* ------------------------------------------------------------------ grids and screens */

    public function test_the_grid_procedures_keep_the_grid_contract(): void
    {
        $sets = $this->procedures()->callSets('usp_Cash_GridCashups', [
            'BranchIds' => (string) self::BRANCH, 'DateFrom' => self::DAY, 'DateTo' => self::DAY,
            'FiltersJson' => json_encode([['column' => 'Variance', 'type' => 'number', 'op' => 'gt', 'value' => '5']]),
        ]);

        $this->assertCount(3, $sets);
        $this->assertSame(1, (int) $sets[1]->first()->TotalRows);
        $this->assertEqualsWithDelta(8.00, (float) $sets[2]->first()->Variance, 0.0001, 'Grand total of the filtered set.');

        $open = fn () => (int) $this->procedures()->callSets('usp_Cash_GridCashups', [
            'BranchIds' => (string) self::BRANCH, 'DateFrom' => self::DAY, 'DateTo' => self::DAY, 'Show' => 'open',
        ])[1]->first()->TotalRows;
        $this->assertSame(1, $open());
        $this->service()->confirm($this->scope(41, 1), 1);
        $this->assertSame(1, $open(), 'Confirmed on a day PumpIT has not balanced: still open, waiting on a close.');
        $this->service()->close($this->scope(41, 1), 1);
        $this->assertSame(0, $open());

        // An empty site list means the sites the person may see, never the estate.
        $none = $this->procedures()->callSets('usp_Cash_GridCashups', [
            'DateFrom' => self::DAY, 'DateTo' => self::DAY, 'AllowedBranchIds' => '1',
        ]);
        $this->assertSame(0, (int) $none[1]->first()->TotalRows);

        $queue = $this->noCashup();
        $this->assertSame([42, 'TCU02'], [(int) $queue[0]->first()->TillNo, $queue[0]->first()->EmployeeCode]);
        $this->assertEqualsWithDelta(500.00, (float) $queue[2]->first()->Sales, 0.0001);
    }

    public function test_the_reports_read_the_same_figures(): void
    {
        // agora.vw_Branch reads PumpIT's SS_Branch, which does not carry the
        // fixture branch 999: it is given one for this test and loses it after.
        $added = ! $this->db()->table('PumpIT.dbo.SS_Branch')->where('SSBranchId', self::BRANCH)->exists();

        if ($added) {
            $this->db()->table('PumpIT.dbo.SS_Branch')->insert(['SSBranchId' => self::BRANCH, 'BranchName' => 'TEST-Cashup site', 'IsActive' => 0]);
        }

        try {
            $banking = $this->procedures()->callSets('usp_Reports_GridDailyBanking', [
                'BranchIds' => (string) self::BRANCH, 'DateFrom' => self::DAY, 'DateTo' => self::DAY,
            ])[0]->sole();

            $this->assertEqualsWithDelta(1008.00, (float) $banking->ActualTotal, 0.0001, 'Declared is the legs, not the header\'s card total.');
            $this->assertEqualsWithDelta(8.00, (float) $banking->Variance, 0.0001);
            $this->assertEqualsWithDelta(10.00, (float) $banking->PumpShortages, 0.0001);
            $this->assertSame(['Agrees', 'Open'], [$banking->ZreadCheck, $banking->Status]);

            $open = fn () => $this->procedures()->callSets('usp_Reports_GridOpenCashups', [
                'BranchIds' => (string) self::BRANCH, 'DateFrom' => self::DAY, 'DateTo' => self::DAY,
            ])[0];
            $this->assertSame('No day-close record', $open()->sole()->HoldingUp);

            $this->service()->confirm($this->scope(41, 1), 1);
            $this->assertSame('Waiting on a manager\'s close', $open()->sole()->HoldingUp);

            $this->service()->close($this->scope(41, 1), 1);
            $this->assertCount(0, $open(), 'Closed by a manager in Agora: off the queue.');
        } finally {
            if ($added) {
                $this->db()->table('PumpIT.dbo.SS_Branch')->where('SSBranchId', self::BRANCH)->delete();
            }
        }
    }

    public function test_the_screens_press_for_a_cashier_and_only_show_for_an_auditor(): void
    {
        $admin = $this->person('admin');
        $scope = ['branch_id' => self::BRANCH, 'date' => self::DAY];

        $this->actingAs($admin)->get(route('app.cash.banking', $scope))
            ->assertOk()
            ->assertSee('Daily Banking')
            ->assertSee('agora.usp_Cash_GetCashup')
            ->assertSee('Saving a leg writes to PumpIT')
            ->assertSee('Z-reads with no cashup');

        $this->actingAs($admin)->get(route('app.cash.banking', $scope + ['till' => 41, 'shift' => 1, 'cards' => 1]))
            ->assertOk()
            ->assertSee('Card amounts from the terminal')
            ->assertSee('agora.usp_Cash_LoadCardAmounts')
            ->assertSee('Save card amounts')
            ->assertSee('Confirm')
            ->assertSee('Cash bags');

        $this->actingAs($admin)->post(route('app.cash.banking.confirm'), $scope + ['till' => 41, 'shift' => 1])
            ->assertRedirect(route('app.cash.banking', $scope + ['till' => 41, 'shift' => 1]))
            ->assertSessionHas('cashupCode', 'CASH_CASHUP_CONFIRMED');

        $this->actingAs($admin)->get(route('app.cash.cashups', ['branches' => self::BRANCH, 'from' => self::DAY, 'to' => self::DAY]))
            ->assertOk()
            ->assertSee('agora.usp_Cash_GridCashups')
            ->assertSee('Open cashups');

        $this->actingAs($admin)->get(route('app.cash.cashups.row', $scope + ['till' => 41, 'shift' => 1]), ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertOk()
            ->assertSee('Open on Daily Banking')
            ->assertSee('Close');

        $this->actingAs($admin)->get(route('app.cash.cashups.nocashup', ['branches' => self::BRANCH, 'from' => self::DAY, 'to' => self::DAY]))
            ->assertOk()
            ->assertSee('agora.usp_Cash_GridZreadsNoCashup');

        $this->actingAs($admin)->get(route('app.cash.banking.phone', $scope))
            ->assertOk()
            ->assertSee('Confirm cashups');

        $auditor = $this->person('auditor');

        $this->actingAs($auditor)->get(route('app.cash.banking', $scope + ['till' => 41, 'shift' => 1, 'cards' => 1]))
            ->assertOk()
            ->assertSee('agora.usp_Cash_GetCashup')
            ->assertDontSee('action="'.route('app.cash.banking.confirm').'"', false)
            ->assertDontSee('action="'.route('app.cash.banking.cards').'"', false);

        $this->actingAs($auditor)->post(route('app.cash.banking.confirm'), $scope + ['till' => 41, 'shift' => 1])->assertForbidden();
        $this->actingAs($auditor)->post(route('app.cash.banking.close'), $scope + ['till' => 41, 'shift' => 1])->assertForbidden();
        $this->actingAs($auditor)->post(route('app.cash.banking.cards'), $scope + ['till' => 41, 'shift' => 1])->assertForbidden();
    }

    public function test_the_cashup_grids_are_registered(): void
    {
        // Fails until config/grids.php carries
        //   'app.cash.cashups' => Modules\Cash\Grids\CashupGrid::class,
        //   'app.cash.cashups.nocashup' => Modules\Cash\Grids\ZreadNoCashupGrid::class,
        // — the extract and the saved column layout both find a grid by key.
        $grids = (require base_path('config/grids.php'))['grids'];

        $this->assertSame(CashupGrid::class, $grids['app.cash.cashups'] ?? null);
        $this->assertSame(ZreadNoCashupGrid::class, $grids['app.cash.cashups.nocashup'] ?? null);
    }
}
