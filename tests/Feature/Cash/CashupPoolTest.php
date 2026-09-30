<?php

namespace Tests\Feature\Cash;

use App\Exceptions\AgoraProcException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The legs a cashup chooses from a pool (T037, Ryan's Q1 of 27 Sep 2026): each
 * saved leg row and its pool row's banked mark in one transaction; the
 * refusals — banked already, not the site's, no master for the POS account, an
 * unapproved request; the held legs; and reopening a closed cashup.
 *
 * The fixture is CashupFixture's: branch 999, 10 June 2026, till 41's cashup
 * and till 42's allocated Z-read with no cashup, and one unbanked row in every
 * pool. Local stub only; every row written is removed.
 */
class CashupPoolTest extends TestCase
{
    use CashupFixture;

    /**
     * Every pool leg, one row each: the leg row carries what the legacy copies,
     * the pool row is marked, WritesJson says the mark was 0, and the header's
     * variance moves by exactly the amount added.
     */
    public function test_every_pool_leg_is_written_with_its_pool_mark(): void
    {
        $cases = [
            // channel, the pool row's Reference, the leg table, a leg column => value, the pool table, the mark, amount
            ['CashBags', 'TEST-BAG2', 'BRN_DailyBankingCashBags', ['CashBagNo' => 'TEST-BAG2', 'CashBagAmount' => 150.0],
                ['BRN_DropSafe', "BagNo = 'TEST-BAG2'", 'IsSelectedForDailyBanking'], 150.0],
            ['CashDevices', 'TSAFE / TEST-S2', 'BRN_DailyBankingDeposita', ['SlipNo' => 'TEST-S2', 'DepositaAmount' => 90.0],
                ['BRN_CashDevices', "TransactionNo = 'TEST-S2'", 'IsSelectedForDailyBanking'], 90.0],
            ['SmartATM', 'TATM1 / 3', 'BRN_DailyBankingSmartATM', ['TerminalId' => 'TATM1', 'Deposited' => 70.0, 'SubTerminalId' => 'TSUB1'],
                ['BRN_SmartATM', "TerminalId = 'TATM1'", 'IsSelectedForDailyBanking'], 70.0],
            ['ABSA', 'TEST-R2', 'BRN_DailyBankingABSA', ['RetrievalRefNumber' => 'TEST-R2', 'TransactionAmount' => 55.0, 'BatchNumber' => 7, 'TransactionAuthCode' => 'A2'],
                ['BRN_ABSAMarkoff', "Dtl23_RetrievalRefNumber = 'TEST-R2'", 'IsSelectedForDailyBanking'], 55.0],
            ['Yumbi', 'TEST-Y2', 'BRN_DailyBankingYumbi', ['ID' => 'TEST-Y2', 'NetAmount' => 12.0, 'DiscountAmount' => 1.0],
                ['BRN_Yumbi', "ID = 'TEST-Y2'", 'IsSelectedForDailyBanking'], 12.0],
            ['Infinity', 'TEST-I2', 'BRN_DailyBankingInfinity', ['RedeemReference' => 'TEST-I2', 'RedeemPointsValue' => 2.5, 'TerminalQuantity' => 1.25],
                ['BRN_Infinity', "RedeemReference = 'TEST-I2'", 'IsSelectedForDailyBanking'], 2.5],
            ['Zapper', 'TEST-Z2', 'BRN_DailyBankingZapper', ['UniqueField' => 'TEST-Z2', 'NettTurnover' => 6.0],
                ['BRN_Zapper', "UniqueField = 'TEST-Z2'", 'IsSelectedForDailyBanking'], 6.0],
            ['Debtors', 'TEST01', 'BRN_DailyBankingCustomers', ['CustomerCode' => 'TCUC1', 'FuelAmount' => 80.0, 'ItemNo' => 2, 'Reference1' => '  TEST01'],
                ['DBF_INVHISTH_CUSTOMER', "REF LIKE '%TEST01%'", 'IsSelectedForBanking'], 80.0],
            ['Suppliers', 'TEST02', 'BRN_DailyBankingSuppliers', ['SupplierCode' => 'TCUS1', 'SupplierAmount' => 33.0],
                ['DBF_POSTR_SUPPLIER', "REF LIKE '%TEST02%'", 'IsSelectedForBanking'], 33.0],
            ['Expenses', 'CA-TEST2', 'BRN_DailyBankingExpenses', ['TransactionNo' => 'CA-TEST2', 'SupplierCode' => 'TCUS1', 'ExpenseAmount' => 25.0],
                ['BRN_Transaction', "TransactionNo = 'CA-TEST2'", 'Posted'], 25.0],
        ];

        foreach ($cases as [$channel, $reference, $legTable, $expected, [$poolTable, $poolWhere, $mark], $amount]) {
            $before = (float) $this->one(41, 1)->Variance;

            $status = $this->service()->savePool($this->scope(41, 1), $channel, [$this->poolKey($channel, $reference)], 1);

            $this->assertSame('CASH_CASHUP_SAVED', $status->Code, $channel);
            $this->assertSame(1, (int) $status->Inserted, $channel);

            $leg = collect($this->db()->select(
                "SELECT * FROM PumpIT.dbo.{$legTable} WHERE SSBranchId = ? AND TillNo = 41 AND ShiftNo = 1 AND ".
                ($channel === 'SmartATM' ? 'DepositDateTime' : 'TransactionDate').' = ? AND CreateDateTime >= DATEADD(minute, -5, GETDATE())',
                [self::BRANCH, self::DAY]
            ))->filter(fn ($row) => collect($expected)->every(
                fn ($value, $column) => is_float($value) ? abs((float) $row->{$column} - $value) < 0.0001 : (string) $row->{$column} === (string) $value
            ));
            $this->assertCount(1, $leg, "{$channel}: the leg row carries what the pool row held.");

            $this->assertSame(1, (int) $this->db()->selectOne(
                "SELECT {$mark} AS m FROM PumpIT.dbo.{$poolTable} WHERE SSBranchId = ? AND {$poolWhere}", [self::BRANCH]
            )->m, "{$channel}: the pool row is marked banked.");

            $this->assertEqualsWithDelta($before + $amount, (float) $this->one(41, 1)->Variance, 0.0001, "{$channel}: the variance moves by the row.");
            $this->assertEqualsWithDelta($before + $amount, (float) $this->header(41, 1)->EmployeeAmount, 0.0001, "{$channel}: and PumpIT's EmployeeAmount with it.");

            $event = collect($this->db()->select(
                "SELECT TOP 1 WritesJson FROM agora.CashupEvent WHERE BranchId = ? AND [Event] = 'saved' ORDER BY Id DESC", [self::BRANCH]
            ))->sole();
            $writes = json_decode((string) $event->WritesJson, true, 512, JSON_THROW_ON_ERROR);
            $this->assertIsArray($writes);
            $this->assertSame([$poolTable, 0, 1], [
                $writes['lines'][0]['pool']['table'],
                (int) ($writes['lines'][0]['pool']['prior'][$mark] ?? 0),
                (int) $writes['lines'][0]['pool']['new'][$mark],
            ], "{$channel}: the mark's prior value is kept.");

            // Banked now: the pool no longer offers it.
            $this->assertNull($this->pool($channel)->first(fn ($r) => $r->Reference === $reference), "{$channel}: no longer offered.");
        }

        // A purchase request is also given the cashup's shift, as the legacy does.
        $this->assertSame(1, (int) $this->db()->selectOne(
            "SELECT ShiftNo FROM PumpIT.dbo.BRN_Transaction WHERE SSBranchId = ? AND TransactionNo = 'CA-TEST2'", [self::BRANCH]
        )->ShiftNo);
    }

    /** A voucher redeemed weeks earlier is still offered — the voucher window is wide on purpose. */
    public function test_a_voucher_redeemed_weeks_before_is_offered_and_saved(): void
    {
        $status = $this->service()->savePool($this->scope(41, 1), 'YumbiVoucher', [$this->poolKey('YumbiVoucher', 'TEST-V2')], 1);

        $this->assertSame(1, (int) $status->Inserted);
        $this->assertSame(1, (int) $this->db()->selectOne(
            "SELECT IsSelectedForDailyBanking AS m FROM PumpIT.dbo.BRN_YumbiVoucher WHERE SSBranchId = ? AND CustomerReference = 'TEST-V2'", [self::BRANCH]
        )->m);
    }

    public function test_a_pool_row_already_banked_is_refused_by_name_and_nothing_is_written(): void
    {
        // TEST-BAG1 is on till 41's cashup already; TEST-BAG3 is marked banked
        // with no cashup line. Neither is offered, and a hand-made key is refused.
        $offered = $this->pool('CashBags')->pluck('Reference')->all();
        $this->assertSame(['TEST-BAG2'], $offered, 'Only the unbanked bag is offered — and it is this cashier\'s.');
        $this->assertSame('this cashier', $this->pool('CashBags')->first()->Hint);

        try {
            $this->service()->savePool($this->scope(42, 3), 'CashBags', [$this->poolKey('CashBags', 'TEST-BAG2', 42, 3), '{"BagNo":"TEST-BAG1"}'], 1);
            $this->fail('A bag already on a cashup must be refused.');
        } catch (AgoraProcException $e) {
            $this->assertSame('CASH_POOL_BANKED', $e->code());
            $this->assertStringContainsString('Bag TEST-BAG1 is already banked — on the cashup for till 41, shift 1 on 2026-06-10', $e->getMessage());
        }

        $this->assertRefused('CASH_POOL_BANKED', fn () => $this->service()->savePool($this->scope(41, 1), 'CashBags', ['{"BagNo":"TEST-BAG3"}'], 1));
        $this->assertRefused('CASH_POOL_NOT_FOUND', fn () => $this->service()->savePool($this->scope(41, 1), 'CashBags', ['{"BagNo":"TEST-NOBAG"}'], 1));
        $this->assertRefused('CASH_LEG_DUPLICATE', fn () => $this->service()->savePool($this->scope(41, 1), 'CashBags',
            ['{"BagNo":"TEST-BAG2"}', '{"BagNo":"TEST-BAG2"}'], 1));

        // All or nothing: TEST-BAG2 was in the refused press and is still unbanked,
        // and till 42 — which had no cashup — still has none.
        $this->assertSame(0, (int) $this->db()->selectOne(
            "SELECT IsSelectedForDailyBanking AS m FROM PumpIT.dbo.BRN_DropSafe WHERE SSBranchId = ? AND BagNo = 'TEST-BAG2'", [self::BRANCH]
        )->m);
        $this->assertSame(0, $this->db()->table('PumpIT.dbo.BRN_DailyBanking')->where('SSBranchId', self::BRANCH)->where('TillNo', 42)->count());
        $this->assertSame(0, $this->db()->table('agora.CashupEvent')->where('BranchId', self::BRANCH)->count());
    }

    public function test_a_pos_account_nobody_answers_to_and_an_unapproved_request_are_refused(): void
    {
        $cash = $this->pool('Debtors')->first(fn ($r) => $r->Reference === 'TEST03');
        $this->assertNotNull($cash);
        $this->assertStringContainsString('is on no PumpIT customer', (string) $cash->Problem, 'The screen says so before the press.');

        $this->assertRefused('CASH_POOL_MASTER', fn () => $this->service()->savePool($this->scope(41, 1), 'Debtors', [(string) $cash->PoolKey], 1));

        $this->assertRefused('CASH_POOL_MASTER', fn () => $this->service()->savePool($this->scope(41, 1), 'Expenses',
            ['{"TransactionNo":"CA-TEST3","TypeCode":"01"}'], 1));
        $this->assertSame(0, (int) $this->db()->selectOne(
            "SELECT Posted FROM PumpIT.dbo.BRN_Transaction WHERE SSBranchId = ? AND TransactionNo = 'CA-TEST3'", [self::BRANCH]
        )->Posted);
    }

    public function test_the_held_legs_say_why_and_write_nothing(): void
    {
        foreach (['FleetCard' => 'no PumpIT procedure states how', 'DirectDeposits' => 'not in any procedure'] as $channel => $why) {
            try {
                $this->service()->save($this->scope(41, 1), $channel, [['anything' => '1']], 1);
                $this->fail("{$channel} must be held.");
            } catch (AgoraProcException $e) {
                $this->assertSame('CASH_LEG_HELD', $e->code(), $channel);
                $this->assertStringContainsString($why, $e->getMessage(), $channel);
            }
        }

        // Pump shortages are no longer held (T040): their pool is the site's
        // approved staff shorts, and the fixture has none.
        $this->assertSame(0, (int) $this->service()->pool($this->scope(41, 1), 'StaffShorts')['summary']->Available);
        $this->assertRefused('CASH_POOL_CHANNEL', fn () => $this->service()->pool($this->scope(41, 1), 'FleetCard'));
    }

    public function test_an_enabled_trigger_on_a_pool_table_refuses_the_save(): void
    {
        DB::connection(config('agora.connections.app'))->unprepared(
            "EXEC PumpIT.sys.sp_executesql N'CREATE TRIGGER dbo.TEST_trg_CashupSync ON dbo.BRN_DropSafe AFTER UPDATE AS SET NOCOUNT ON;'"
        );

        try {
            $this->assertRefused('CASH_PUMPIT_TRIGGER', fn () => $this->service()->savePool($this->scope(41, 1), 'CashBags',
                [$this->poolKey('CashBags', 'TEST-BAG2')], 1));
        } finally {
            DB::connection(config('agora.connections.app'))->unprepared(
                "IF OBJECT_ID('PumpIT.dbo.TEST_trg_CashupSync') IS NOT NULL EXEC PumpIT.sys.sp_executesql N'DROP TRIGGER dbo.TEST_trg_CashupSync'"
            );
        }
    }

    public function test_a_manager_reopens_a_closed_cashup_with_a_reason(): void
    {
        $this->service()->confirm($this->scope(41, 1), 1);
        $this->service()->close($this->scope(41, 1), 1);

        $this->assertRefused('CASH_CASHUP_CLOSED', fn () => $this->service()->savePool($this->scope(41, 1), 'CashBags',
            [$this->poolKey('CashBags', 'TEST-BAG2')], 1));
        $this->assertRefused('CASH_CASHUP_REASON', fn () => $this->service()->reopen($this->scope(41, 1), 1, null));

        $this->assertSame('CASH_CASHUP_REOPENED', $this->service()->reopen($this->scope(41, 1), 1, 'TEST-a bag was missed')->Code);

        $state = $this->state(41, 1);
        $this->assertSame(['open', 'TEST-a bag was missed'], [$state->Status, $state->ReopenReason]);
        $this->assertSame('TEST-a bag was missed',
            $this->db()->table('agora.CashupEvent')->where('BranchId', self::BRANCH)->where('Event', 'reopened')->value('Note'));
        $this->assertSame('Open', $this->one(41, 1)->Status);

        // Open again: the missed bag can be added, confirmed and closed again.
        $this->service()->savePool($this->scope(41, 1), 'CashBags', [$this->poolKey('CashBags', 'TEST-BAG2')], 1);
        $this->service()->confirm($this->scope(41, 1), 1, 'TEST-the missed bag is over the Z-read');
        $this->assertSame('CASH_CASHUP_CLOSED_OFF', $this->service()->close($this->scope(41, 1), 1)->Code);

        $this->assertRefused('CASH_CASHUP_NOT_CLOSED', fn () => $this->service()->reopen($this->scope(42, 3), 1, 'TEST-nothing to reopen'));
    }

    public function test_the_screen_offers_the_pool_and_only_to_whoever_may_save_or_reopen(): void
    {
        $admin = $this->person('admin');
        $scope = ['branch_id' => self::BRANCH, 'date' => self::DAY, 'till' => 41, 'shift' => 1];

        $this->actingAs($admin)->get(route('app.cash.banking', $scope + ['pool' => 'CashBags']))
            ->assertOk()
            ->assertSee('agora.usp_Cash_GetCashupPool')
            ->assertSee('TEST-BAG2')
            ->assertSee('this cashier')
            ->assertSee('Add ticked rows to PumpIT')
            ->assertSee('Add from the drop safe')
            ->assertSee('Add from the approved staff shorts');

        $this->actingAs($admin)->post(route('app.cash.banking.legs'), $scope + [
            'channel' => 'CashBags', 'keys' => [$this->poolKey('CashBags', 'TEST-BAG2')],
        ])->assertRedirect(route('app.cash.banking', $scope))->assertSessionHas('cashupCode', 'CASH_CASHUP_SAVED');

        $this->service()->confirm($this->scope(41, 1), 1, 'TEST-over the Z-read');
        $this->service()->close($this->scope(41, 1), 1);

        $this->actingAs($admin)->get(route('app.cash.banking', $scope))
            ->assertOk()
            ->assertSee('action="'.route('app.cash.banking.reopen').'"', false)
            ->assertDontSee('Add from the drop safe');

        $this->actingAs($admin)->post(route('app.cash.banking.reopen'), $scope + ['note' => 'TEST-reopened from the screen'])
            ->assertSessionHas('cashupCode', 'CASH_CASHUP_REOPENED');

        $this->actingAs($admin)->get(route('app.cash.banking', $scope))->assertSee('Reopened by');

        $auditor = $this->person('auditor');

        $this->actingAs($auditor)->get(route('app.cash.banking', $scope + ['pool' => 'CashBags']))
            ->assertOk()
            ->assertDontSee('Add ticked rows to PumpIT')
            ->assertDontSee('action="'.route('app.cash.banking.reopen').'"', false);

        $this->actingAs($auditor)->post(route('app.cash.banking.legs'), $scope + ['channel' => 'CashBags', 'keys' => ['{"BagNo":"TEST-BAG2"}']])
            ->assertForbidden();
        $this->actingAs($auditor)->post(route('app.cash.banking.reopen'), $scope + ['note' => 'TEST'])->assertForbidden();
    }
}
