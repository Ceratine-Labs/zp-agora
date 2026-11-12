<?php

namespace Tests\Feature\Debtors;

use App\Exceptions\AgoraProcException;
use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Core\Models\Permission;
use Modules\Core\Models\User;
use Modules\Core\Models\UserPermission;
use Modules\Core\Services\PermissionService;
use Modules\Debtors\Mail\DebtorStatementMail;
use Modules\Debtors\Services\DebtorStatementService;
use Tests\TestCase;

/**
 * Debtors statements: the arithmetic, the address list, the three send modes,
 * the monthly run, and who may do what.
 *
 * One till account, TEST-D1 (PumpIT customer code TEST-C1), at branch 999
 * (TEST-Playwright, inactive and non-trading) in the LOCAL PumpIT stub, built
 * the way ZP's statement reads it (12 Nov 2026): invoices and credit notes in
 * MIST_Trans_Customer, payments as Debtors bank lines carrying the customer
 * code, and a take-on balance on BRN_Customers.
 *
 *    1 Jun  taken on   100.00
 *   10 Jul  invoice  1 000.00   loaded again two hours later — counted once
 *   20 Jul  payment    500.00                 opening for August = 600.00
 *    5 Aug  invoice    500.00   1 100.00   two lines: 15.5 l diesel + a lube
 *   12 Aug  payment    300.00     800.00
 *   20 Aug  credit      50.00     750.00
 *   25 Aug  invoice    200.00     950.00   loaded again on the next day's EOD
 *
 * plus a cash sale at the same site, a Debtors bank line for somebody else
 * and an FNB line carrying the code — none of which touch the account. Aged
 * at 31 Aug, the 950.00 owed is the newest charges: 200 + 500 current, and
 * 250 of the July invoice at 30 days.
 */
class DebtorStatementTest extends TestCase
{
    private const BRANCH = 999;

    private const ACCOUNT = 'TEST-D1';

    private const CODE = 'TEST-C1';

    /** Whether MIST_Trans_Customer had ODO / REGNO when the test began. */
    private ?bool $hadVehicleColumns = null;

    /** @var array<int, User> */
    private array $made = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessLocalStub('This fixture writes debtor postings into the PumpIT stub.');

        if (Permission::query()->acrossBranches()->where('Code', 'debtors.statements.view')->doesntExist()) {
            $this->markTestSkipped('Run: php artisan seed:master');
        }

        config(['debtors.send_mode' => 'off', 'debtors.test_recipient' => '', 'debtors.bcc' => '']);
        $this->cleanUp();
        $this->seedLedger();
    }

    protected function tearDown(): void
    {
        if ($this->app !== null) {
            $this->cleanUp();

            if ($this->hadVehicleColumns !== null) {
                $this->vehicleColumns($this->hadVehicleColumns);
            }

            foreach ($this->made as $user) {
                UserPermission::query()->acrossBranches()->where('UserId', $user->Id)->delete();
                $this->db()->table('agora.UserGridColumn')->where('UserId', $user->Id)->delete();
                User::query()->acrossBranches()->where('Id', $user->Id)->forceDelete();
            }
        }

        parent::tearDown();
    }

    public function test_the_grid_states_the_period_once_per_account_and_counts_a_double_loaded_slip_once(): void
    {
        $row = $this->gridRow();

        $this->assertNotNull($row, 'The account has a balance and postings in August, so it is listed.');
        $this->assertEquals(600.00, (float) $row->Opening);
        $this->assertSame(self::CODE, $row->CustomerCode);
        $this->assertEquals(700.00, (float) $row->Debits, 'The slip MIST loaded again on the next EOD is counted once.');
        $this->assertEquals(350.00, (float) $row->Credits, 'Payments are the Debtors bank lines with the customer code, and nobody else\'s.');
        $this->assertEquals(950.00, (float) $row->Closing);
        $this->assertSame('2026-08-12', substr((string) $row->LastPayment, 0, 10));
        $this->assertSame('No email', $row->Status);
    }

    public function test_the_statement_runs_the_balance_and_ages_what_is_owed_from_the_newest_charges(): void
    {
        $s = app(DebtorStatementService::class)->statement(self::BRANCH, self::ACCOUNT, '2026-08-01', '2026-08-31');
        $a = $s['account'];

        $this->assertEquals(600.00, (float) $a->Opening);
        $this->assertEquals(950.00, (float) $a->Closing);
        $this->assertSame(['1100.00', '800.00', '750.00', '950.00'],
            $s['lines']->map(fn ($l) => number_format((float) $l->Balance, 2, '.', ''))->all());
        $this->assertSame(['Invoice', 'Payment', 'Credit note', 'Invoice'], $s['lines']->pluck('TranType')->all());

        $this->assertEquals(700.00, (float) $a->AgeCurrent);
        $this->assertEquals(250.00, (float) $a->Age30);
        $this->assertEquals(0.00, (float) $a->Age60 + (float) $a->Age90 + (float) $a->Age120);
    }

    public function test_the_running_balance_starts_from_the_balance_brought_forward_take_on_included(): void
    {
        $service = app(DebtorStatementService::class);

        $august = $service->statement(self::BRANCH, self::ACCOUNT, '2026-08-01', '2026-08-31');
        $a = $august['account'];

        $this->assertSame(self::CODE, $a->StatementAccountNo, 'The statement carries the PumpIT customer code.');
        $this->assertSame('TEST Debtor One', $a->AccountName);
        $this->assertEquals(600.00, (float) $a->Opening, '100 taken on + 1 000 invoiced - 500 paid, the reload not counted.');
        $first = $august['lines']->first();
        $this->assertEquals(500.00, (float) $first->Amount);
        $this->assertEquals(1100.00, (float) $first->Balance, 'The first row is the opening plus its own amount, not its amount alone.');
        $this->assertEquals((float) $a->Closing, (float) $august['lines']->last()->Balance, 'The last row agrees with the balance due.');
        $this->assertSame('2026-08-25', substr((string) $a->LastTransactionDate, 0, 10));

        $this->assertSame(['127', '-300.00', '142', '147'], $august['lines']->map(fn (object $l): string => $l->TranCode === 'R'
            ? number_format((float) $l->Amount, 2, '.', '') : $l->DocumentNo)->all(),
            'Documents by number, the payment as the bank line, the double load once.');
        $this->assertEquals(15.5, (float) $first->Litres, 'Litres are the fuel lines only.');
        $this->assertSame('Diesel 10', $first->FuelType);
        $this->assertSame('TEST-ORD-1', $first->OrderNo);
        $this->assertSame('TL# 1 #127', $first->Description);

        $winter = $service->statement(self::BRANCH, self::ACCOUNT, '2026-06-01', '2026-07-31');
        $this->assertEquals(0.00, (float) $winter['account']->Opening);
        $this->assertSame(['Balance taken on', 'Invoice', 'Payment'], $winter['lines']->pluck('TranType')->all());
        $this->assertSame(['100.00', '1100.00', '600.00'],
            $winter['lines']->map(fn ($l) => number_format((float) $l->Balance, 2, '.', ''))->all());
        $this->assertNull($winter['account']->LedgerFrom, 'An account taken on carries no short-ledger warning.');
    }

    public function test_km_and_reg_are_blank_without_the_columns_and_fill_once_they_exist(): void
    {
        config(['debtors.statement.show_vehicle' => true]);
        $service = app(DebtorStatementService::class);

        $this->vehicleColumns(false);
        $without = $service->statement(self::BRANCH, self::ACCOUNT, '2026-08-01', '2026-08-31');
        $this->assertFalse((bool) $without['account']->HasVehicle);
        $this->assertSame([null], $without['lines']->pluck('Km')->unique()->values()->all());
        $this->assertSame([null], $without['lines']->pluck('Reg')->unique()->values()->all());

        $this->vehicleColumns(true);
        DB::connection(config('agora.connections.app'))->statement(
            "UPDATE PumpIT.dbo.MIST_Trans_Customer SET ODO = '66547', REGNO = 'BF23 CH ZN'
             WHERE MIST_BranchId = ? AND BusinessPartnerCode = ? AND TransactionNumber = '147'",
            [self::BRANCH, self::ACCOUNT],
        );

        $with = $service->statement(self::BRANCH, self::ACCOUNT, '2026-08-01', '2026-08-31');
        $this->assertTrue((bool) $with['account']->HasVehicle);
        $invoice = $with['lines']->firstWhere('DocumentNo', '147');
        $this->assertSame('66547', $invoice->Km);
        $this->assertSame('BF23 CH ZN', $invoice->Reg);
        $this->assertNull($with['lines']->firstWhere('DocumentNo', '127')->Km, 'A slip with no vehicle stays blank.');
        $this->assertNull($with['lines']->firstWhere('TranCode', 'R')->Reg, 'A payment has no vehicle.');
        $this->assertEquals((float) $without['account']->Closing, (float) $with['account']->Closing);
    }

    public function test_km_and_reg_stay_blank_while_show_vehicle_is_off_even_with_the_columns_filled(): void
    {
        $this->assertFalse(config('debtors.statement.show_vehicle'), 'Off until MIST fills ODO / REGNO from the right slips (Ryan, 12 Nov 2026).');

        $this->vehicleColumns(true);
        DB::connection(config('agora.connections.app'))->statement(
            "UPDATE PumpIT.dbo.MIST_Trans_Customer SET ODO = '66547', REGNO = 'BF23 CH ZN'
             WHERE MIST_BranchId = ? AND BusinessPartnerCode = ? AND TransactionNumber = '147'",
            [self::BRANCH, self::ACCOUNT],
        );

        $service = app(DebtorStatementService::class);
        $statement = $service->statement(self::BRANCH, self::ACCOUNT, '2026-08-01', '2026-08-31');

        $this->assertFalse((bool) $statement['account']->HasVehicle);
        $this->assertSame([null], $statement['lines']->pluck('Km')->unique()->values()->all());
        $this->assertSame([null], $statement['lines']->pluck('Reg')->unique()->values()->all());

        $html = view('debtors::pdf.statement', [
            'a' => $statement['account'], 'lines' => $statement['lines'], 'issuer' => '', 'note' => '',
        ])->render();
        $this->assertStringContainsString('>KM</th>', $html, 'The columns stay, so the layout is ZP\'s.');
        $this->assertStringContainsString('>REG</th>', $html);
        $this->assertStringNotContainsString('66547', $html);
        $this->assertStringNotContainsString('BF23 CH ZN', $html);

        $this->actingAs($this->person(['statements.view']))
            ->get(route('app.debtors.show', ['branch' => self::BRANCH, 'account' => self::ACCOUNT, 'from' => '2026-08-01', 'to' => '2026-08-31']))
            ->assertOk()
            ->assertSee('<th>KM</th>', false)
            ->assertDontSee('BF23 CH ZN');
    }

    public function test_the_pdf_is_zp_detailed_layout(): void
    {
        $service = app(DebtorStatementService::class);
        $statement = $service->statement(self::BRANCH, self::ACCOUNT, '2026-08-01', '2026-08-31');

        $html = view('debtors::pdf.statement', [
            'a' => $statement['account'], 'lines' => $statement['lines'], 'issuer' => '', 'note' => '',
        ])->render();

        foreach (['DEBTOR STATEMENT', 'Date From : 2026/08/01', 'Date To : 2026/08/31', 'ACCOUNT NUMBER', self::CODE,
            'LAST TRANSACTION DATE', 'BALANCE DUE (TO) / FROM CUSTOMER', 'DOCUMENT<br>NUMBER', 'FUEL TYPE', 'ORDER NR',
            'Balance brought forward', '600.00', '1100.00', '950.00', '-300.00'] as $text) {
            $this->assertStringContainsString($text, $html);
        }
        $this->assertStringStartsWith('%PDF', $service->pdf($statement));
        $this->assertSame('Statement TEST-C1 2026-08.pdf', $service->filename($statement['account']));
    }

    public function test_an_unknown_account_is_refused_not_rendered_empty(): void
    {
        $this->expectException(AgoraProcException::class);
        $this->expectExceptionMessage('There is no such account at that site.');

        app(DebtorStatementService::class)->statement(self::BRANCH, 'TEST-NONE', '2026-08-01', '2026-08-31');
    }

    public function test_the_address_must_be_an_address_and_the_monthly_run_needs_one(): void
    {
        $service = app(DebtorStatementService::class);

        try {
            $service->saveContact(self::BRANCH, self::ACCOUNT, 'not an address', null, false, null, null);
            $this->fail('A malformed address was saved.');
        } catch (AgoraProcException $e) {
            $this->assertSame('BAD_EMAIL', $e->code());
        }

        try {
            $service->saveContact(self::BRANCH, self::ACCOUNT, null, null, true, null, null);
            $this->fail('An account went on the monthly run with nowhere to send.');
        } catch (AgoraProcException $e) {
            $this->assertSame('EMAIL_REQUIRED', $e->code());
        }

        $service->saveContact(self::BRANCH, self::ACCOUNT, 'debtor@example.test', 'a@example.test; b@example.test', true, 'TEST-', null);

        $this->assertSame('a@example.test, b@example.test',
            $this->db()->table('agora.DebtorContact')->where('BranchId', self::BRANCH)->where('AccountNo', self::ACCOUNT)->value('CcEmail'));
        $this->assertSame('On the monthly run', $this->gridRow()->Status);
    }

    public function test_off_mails_nothing_and_records_nothing(): void
    {
        Mail::fake();
        $this->contact();

        $result = app(DebtorStatementService::class)->send(self::BRANCH, self::ACCOUNT, '2026-08-01', '2026-08-31', 'manual', null);

        $this->assertFalse($result['ok']);
        Mail::assertNothingSent();
        $this->assertSame(0, $this->sends()->count());
    }

    public function test_test_mode_goes_to_the_test_address_never_to_the_debtor(): void
    {
        Mail::fake();
        config(['debtors.send_mode' => 'test', 'debtors.test_recipient' => 'head-office@example.test']);
        $this->contact();

        $result = app(DebtorStatementService::class)->send(self::BRANCH, self::ACCOUNT, '2026-08-01', '2026-08-31', 'manual', null);

        $this->assertTrue($result['ok'], $result['message']);
        Mail::assertSent(DebtorStatementMail::class, fn (DebtorStatementMail $m) => $m->hasTo('head-office@example.test')
            && ! $m->hasTo('debtor@example.test') && ! $m->hasCc('copy@example.test')
            && str_starts_with($m->pdf, '%PDF'));
        $this->assertSame('test', $this->sends()->value('Mode'));
    }

    public function test_live_mode_mails_the_debtor_and_its_copies_with_the_pdf_and_records_the_balances(): void
    {
        Mail::fake();
        config(['debtors.send_mode' => 'live']);
        $this->contact();

        $result = app(DebtorStatementService::class)->send(self::BRANCH, self::ACCOUNT, '2026-08-01', '2026-08-31', 'manual', null);

        $this->assertTrue($result['ok'], $result['message']);
        Mail::assertSent(DebtorStatementMail::class, fn (DebtorStatementMail $m) => $m->hasTo('debtor@example.test')
            && $m->hasCc('copy@example.test') && $m->filename === 'Statement TEST-C1 2026-08.pdf');

        $sent = $this->sends()->first();
        $this->assertSame('sent', $sent->Status);
        $this->assertEquals(600.00, (float) $sent->OpeningBalance);
        $this->assertEquals(950.00, (float) $sent->ClosingBalance);
    }

    public function test_the_monthly_run_sends_an_account_on_the_run_once_per_period(): void
    {
        Mail::fake();
        config(['debtors.send_mode' => 'live']);
        $this->contact();

        $this->artisan('debtors:send-statements', ['--to' => '2026-08-31', '--branch' => [self::BRANCH]])
            ->expectsOutputToContain('1 account(s) due')
            ->assertSuccessful();
        Mail::assertSentCount(1);

        $this->artisan('debtors:send-statements', ['--to' => '2026-08-31', '--branch' => [self::BRANCH]])
            ->expectsOutputToContain('0 account(s) due')
            ->assertSuccessful();
        Mail::assertSentCount(1);
        $this->assertSame('schedule', $this->sends()->value('Trigger'));
    }

    public function test_a_reader_sees_the_statement_without_send_or_edit_and_cannot_post_either(): void
    {
        config(['debtors.send_mode' => 'live']);
        $this->contact();
        $reader = $this->person(['statements.view']);

        $this->actingAs($reader)
            ->get(route('app.debtors.show', ['branch' => self::BRANCH, 'account' => self::ACCOUNT, 'from' => '2026-08-01', 'to' => '2026-08-31']))
            ->assertOk()
            ->assertSee('TEST Debtor One')
            ->assertSee('Balance brought forward')
            ->assertSee('R950.00')
            ->assertDontSee('Send statement')
            ->assertDontSee('data-modal-open="debtor-contact"', false);

        $this->actingAs($reader)
            ->post(route('app.debtors.send', ['branch' => self::BRANCH, 'account' => self::ACCOUNT]), ['from' => '2026-08-01', 'to' => '2026-08-31'])
            ->assertForbidden();

        $this->actingAs($reader)
            ->post(route('app.debtors.contact', ['branch' => self::BRANCH, 'account' => self::ACCOUNT]), ['email' => 'x@example.test'])
            ->assertForbidden();

        $this->actingAs($reader)
            ->get(route('app.debtors.pdf', ['branch' => self::BRANCH, 'account' => self::ACCOUNT, 'from' => '2026-08-01', 'to' => '2026-08-31']))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_the_preview_shows_who_it_goes_to_and_what_they_read_and_sends_nothing(): void
    {
        Mail::fake();
        $this->contact();

        $this->actingAs($this->person(['statements.view']))
            ->get(route('app.debtors.preview', ['branch' => self::BRANCH, 'account' => self::ACCOUNT, 'from' => '2026-08-01', 'to' => '2026-08-31']))
            ->assertOk()
            ->assertSee('Sending is switched off')
            ->assertSee('debtor@example.test')
            ->assertSee('copy@example.test')
            ->assertSee('Statement of account TEST-C1')
            ->assertSee('Statement TEST-C1 2026-08.pdf')
            ->assertSee('data-statement-pdf', false)
            ->assertSee('The balance due at 31 August 2026 is');

        Mail::assertNothingSent();
        $this->assertSame(0, $this->sends()->count());
    }

    public function test_the_list_ticks_every_account_with_an_address_and_emails_the_ticked_ones(): void
    {
        Mail::fake();
        config(['debtors.send_mode' => 'live']);
        $this->contact();
        $this->invoice('TEST-D2', '2026-08-15', '21', [['TEST-LUBE', 'LUBES', 1, 120.00]]);
        $sender = $this->person(['statements.view', 'statements.send']);
        $period = ['from' => '2026-08-01', 'to' => '2026-08-31'];

        $page = $this->actingAs($sender)
            ->get(route('app.debtors.index', $period + ['branches' => [self::BRANCH]]))
            ->assertOk()
            ->assertSee('Email selected')
            ->getContent();

        $this->assertMatchesRegularExpression('/value="999\|TEST-D1\|2026-08-01\|2026-08-31"\s+checked/', $page,
            'An account with an address starts ticked.');
        $this->assertMatchesRegularExpression('/value="999\|TEST-D2\|2026-08-01\|2026-08-31"\s+aria-label/', $page,
            'An account with no address starts unticked.');

        $this->actingAs($sender)
            ->post(route('app.debtors.sendSelected'), ['items' => [
                '999|TEST-D1|2026-08-01|2026-08-31',
                '999|TEST-D2|2026-08-01|2026-08-31',
            ]])
            ->assertRedirect()
            ->assertSessionHas('status', fn ($s) => str_contains($s, '1 statement(s) are being mailed')
                && str_contains($s, '1 ticked account(s) have no email address'));

        Mail::assertSentCount(1);
        Mail::assertSent(DebtorStatementMail::class, fn (DebtorStatementMail $m) => $m->hasTo('debtor@example.test'));
        $this->assertSame('2026-08-31', substr((string) $this->sends()->value('PeriodTo'), 0, 10));
    }

    public function test_emailing_the_selected_is_refused_while_sending_is_off_and_for_a_reader(): void
    {
        Mail::fake();
        $this->contact();
        $items = ['items' => ['999|TEST-D1|2026-08-01|2026-08-31']];

        $this->actingAs($this->person(['statements.view', 'statements.send']))
            ->post(route('app.debtors.sendSelected'), $items)
            ->assertSessionHasErrors('refusal');

        $this->actingAs($this->person(['statements.view']))
            ->post(route('app.debtors.sendSelected'), $items)
            ->assertForbidden();

        Mail::assertNothingSent();
    }

    public function test_without_view_the_list_is_refused(): void
    {
        $this->actingAs($this->person([]))
            ->get(route('app.debtors.index'))
            ->assertForbidden();
    }

    public function test_somebody_who_may_send_and_edit_is_offered_both(): void
    {
        config(['debtors.send_mode' => 'live']);
        $this->contact();

        $this->actingAs($this->person(['statements.view', 'statements.send', 'contacts.edit']))
            ->get(route('app.debtors.show', ['branch' => self::BRANCH, 'account' => self::ACCOUNT, 'from' => '2026-08-01', 'to' => '2026-08-31']))
            ->assertOk()
            ->assertSee('Send statement')
            ->assertSee('data-modal-open="debtor-contact"', false);
    }

    // ---- fixture -------------------------------------------------------------

    private function seedLedger(): void
    {
        $this->db()->table('PumpIT.dbo.BRN_Customers')->insert([
            'SSBranchId' => self::BRANCH, 'CustomerCode' => self::CODE, 'CustomerName' => 'TEST Debtor One',
            'DebtorID' => self::ACCOUNT, 'DebtorGroupID' => self::ACCOUNT, 'CreateDateTime' => '2026-06-01 09:00:00',
            'OpeningPumpITBalance' => 100.00,
        ]);

        $fuel = fn (float $litres, float $amount) => ['9910405', 'FUEL9910405', $litres, $amount, 'Diesel 10'];

        // 10 Jul, and the same slip loaded again two hours later in the same EOD.
        $this->invoice(self::ACCOUNT, '2026-07-10', '101', [$fuel(30, 1000.00)], '06:10:00');
        $this->invoice(self::ACCOUNT, '2026-07-10', '101', [$fuel(30, 1000.00)], '08:15:00');
        $this->invoice(self::ACCOUNT, '2026-08-05', '127', [$fuel(15.5, 300.00), ['TEST-LUBE', 'LUBES', 1, 200.00]], '06:10:00', 'TEST-ORD-1');
        $this->invoice(self::ACCOUNT, '2026-08-20', '142', [['TEST-LUBE', 'LUBES', 1, 50.00]], '06:10:00', null, 'C');
        // 25 Aug, and the same slip again on the next day's EOD.
        $this->invoice(self::ACCOUNT, '2026-08-25', '147', [$fuel(10, 200.00)], '06:10:00');
        $this->invoice(self::ACCOUNT, '2026-08-26', '147', [$fuel(10, 200.00)], '06:05:00');

        // A cash sale at the same site: not an account posting.
        $this->invoice('CASH', '2026-08-05', '128', [$fuel(5, 75.00)]);

        $this->bankLine(self::CODE, 'Debtors', '2026-07-20', 500.00);
        $this->bankLine(self::CODE, 'Debtors', '2026-08-12', 300.00);
        $this->bankLine('TEST-C9', 'Debtors', '2026-08-12', 999.00);   // somebody else
        $this->bankLine(self::CODE, 'FNB', '2026-08-13', 888.00);      // not a Debtors line

        // The till's account master still gives the address.
        $this->db()->table('PumpIT.dbo.DBF_CLDB_CUSTOMER')->insert([
            'SSBranchId' => self::BRANCH, 'CNUM' => self::ACCOUNT, 'INIT' => 'T', 'SNAM' => 'TEST Debtor One',
            'BADD1' => '1 TEST Street', 'CRLIMIT' => 5000,
        ]);
    }

    /**
     * One MIST load of a document: every line on the given EOD date, at the
     * given load time.
     *
     * @param  array<int, array{0: string, 1: string, 2: float|int, 3: float, 4?: string}>  $lines  item, category, qty, amount, standard category
     */
    private function invoice(string $account, string $eod, string $number, array $lines, string $time = '06:10:00',
        ?string $order = null, string $type = 'I'): void
    {
        foreach ($lines as $i => $line) {
            $this->db()->table('PumpIT.dbo.MIST_Trans_Customer')->insert([
                'CompanyId' => 1, 'MIST_BranchId' => self::BRANCH, 'EODDate' => $eod.' 00:00:00',
                'TransactionTime' => $time, 'PosEODNumber' => '9'.substr(str_replace('-', '', $eod), 4),
                'TransactionNumber' => $number, 'TransTypeId' => 5, 'BusPartnerTypeId' => 1,
                'DebitCredit' => $type === 'C' ? 'C' : 'D', 'Attendant' => 'TEST', 'TenderTypeId' => 5,
                'DocumentNumber' => 'TL# 1 #'.$number, 'ItemCode' => $line[0].'-'.$i, 'PackSize' => 1,
                'UnitCostPriceExcl' => 0, 'Vat' => 0, 'VatType' => 'Z', 'BusinessPartnerCode' => $account,
                'POSSystemId' => 5, 'EODId' => 'TEST', 'PeriodId' => 0, 'TransactionDate' => $eod.' 00:00:00',
                'TransactionType' => $type, 'BusinessPartnerType' => 'D', 'BusinessPartnerName' => 'TEST DEBTOR ONE',
                'OrderNumber' => $order, 'ItemDescription' => 'TEST-'.$line[0], 'ItemUOM' => str_starts_with($line[1], 'FUEL') ? 'Lt' : 'Each',
                'Category' => $line[1], 'StandardCategory' => $line[4] ?? null, 'QtyMovement' => $line[2], 'Qty' => $line[2],
                'VatSubTypeId' => 1, 'LineTotalAmountIncl' => $line[3], 'isOMNI_LoadStatus' => 0,
            ]);
        }
    }

    private function bankLine(string $code, string $type, string $date, float $amount): void
    {
        $this->db()->table('PumpIT.dbo.RCN_BankStatementLinesPumpIT')->insert([
            'SSBranchId' => self::BRANCH, 'LineDate' => $date.' 00:00:00', 'Description' => 'TEST- SETTLEMENT '.$code,
            'Amount' => $amount, 'Type' => $type, 'IDState' => 2, 'ReconState' => 1, 'ReconBatchNo' => 0, 'CustomerCode' => $code,
        ]);
    }

    /** Give MIST_Trans_Customer ODO and REGNO, or take them away — the local stub only. */
    private function vehicleColumns(bool $present): void
    {
        $db = $this->db();
        $has = $db->selectOne("SELECT COL_LENGTH('PumpIT.dbo.MIST_Trans_Customer', 'ODO') AS L")->L !== null;
        $this->hadVehicleColumns ??= $has;

        if ($present && ! $has) {
            $db->statement('ALTER TABLE PumpIT.dbo.MIST_Trans_Customer ADD ODO CHAR(10) NULL, REGNO CHAR(10) NULL');
        } elseif (! $present && $has) {
            $db->statement('ALTER TABLE PumpIT.dbo.MIST_Trans_Customer DROP COLUMN ODO, REGNO');
        }
    }

    private function contact(): void
    {
        app(DebtorStatementService::class)->saveContact(
            self::BRANCH, self::ACCOUNT, 'debtor@example.test', 'copy@example.test', true, 'TEST-', null,
        );
    }

    private function gridRow(): ?object
    {
        $sets = app(ProcedureService::class)->callSets('agora.usp_Debtors_GridAccounts', [
            'BranchIds' => (string) self::BRANCH, 'DateFrom' => '2026-08-01', 'DateTo' => '2026-08-31', 'PageSize' => 500,
        ]);

        return $sets[0]->firstWhere('AccountNo', self::ACCOUNT);
    }

    private function sends(): Builder
    {
        return $this->db()->table('agora.DebtorStatementSend')->where('BranchId', self::BRANCH)->where('AccountNo', self::ACCOUNT);
    }

    /** @param  array<int, string>  $grants  resource.action under debtors */
    private function person(array $grants): User
    {
        $user = User::query()->acrossBranches()->create([
            'BranchId' => (int) config('agora.group_branch_id', 2),
            'EmailAddress' => 'TEST-debtors-'.uniqid().'@agora.invalid',
            'UserName' => 'TEST-debtors',
            'IsActive' => true,
            'IsLocked' => false,
            'Workspace' => 'ho',
            'PasswordHash' => 'TEST-only-'.Str::random(24),
        ]);

        foreach ($grants as $grant) {
            $permission = Permission::query()->acrossBranches()->where('Code', 'debtors.'.$grant)->firstOrFail();

            UserPermission::query()->acrossBranches()->create([
                'BranchId' => (int) $user->BranchId,
                'UserId' => (int) $user->Id,
                'PermissionId' => (int) $permission->Id,
            ]);
        }

        app(PermissionService::class)->forget($user);
        $this->made[] = $user;

        return $user;
    }

    private function cleanUp(): void
    {
        $db = $this->db();

        $db->table('agora.DebtorStatementSend')->where('BranchId', self::BRANCH)->delete();
        $db->table('agora.DebtorContact')->where('BranchId', self::BRANCH)->delete();
        $db->table('PumpIT.dbo.DBF_CLDB_CUSTOMER')->where('SSBranchId', self::BRANCH)->delete();
        // Only this test's rows: the recon and cashup suites keep their own at
        // branch 999 too.
        $db->table('PumpIT.dbo.MIST_Trans_Customer')->where('MIST_BranchId', self::BRANCH)->where('EODId', 'TEST')->delete();
        $db->table('PumpIT.dbo.RCN_BankStatementLinesPumpIT')->where('SSBranchId', self::BRANCH)
            ->where('Description', 'like', 'TEST- SETTLEMENT %')->delete();
        $db->table('PumpIT.dbo.BRN_Customers')->where('SSBranchId', self::BRANCH)->where('CustomerCode', self::CODE)->delete();
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }
}
