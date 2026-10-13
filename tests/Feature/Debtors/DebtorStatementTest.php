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
 * One account, TEST-D1, at branch 999 (TEST-Playwright, inactive and
 * non-trading) in the LOCAL PumpIT stub. Its ledger for July and August 2026:
 *
 *   10 Jul  invoice  1 000.00          opening for August = 600.00
 *   20 Jul  payment    400.00
 *    5 Aug  invoice    500.00   1 100.00
 *   12 Aug  payment    300.00     800.00   loaded TWICE, as MIST does — counted once
 *   20 Aug  credit      50.00     750.00
 *   25 Aug  invoice    200.00     950.00
 *
 * plus a cash sale at the same site that must not touch the account. Aged at
 * 31 Aug, the 950.00 owed is the newest charges: 200 + 500 current, and 250 of
 * the July invoice at 30 days.
 */
class DebtorStatementTest extends TestCase
{
    private const BRANCH = 999;

    private const ACCOUNT = 'TEST-D1';

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

            foreach ($this->made as $user) {
                UserPermission::query()->acrossBranches()->where('UserId', $user->Id)->delete();
                $this->db()->table('agora.UserGridColumn')->where('UserId', $user->Id)->delete();
                User::query()->acrossBranches()->where('Id', $user->Id)->forceDelete();
            }
        }

        parent::tearDown();
    }

    public function test_the_grid_states_the_period_once_per_account_and_counts_a_double_loaded_payment_once(): void
    {
        $row = $this->gridRow();

        $this->assertNotNull($row, 'The account has a balance and postings in August, so it is listed.');
        $this->assertEquals(600.00, (float) $row->Opening);
        $this->assertEquals(700.00, (float) $row->Debits);
        $this->assertEquals(350.00, (float) $row->Credits, 'The payment MIST loaded twice is counted once.');
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
            && $m->hasCc('copy@example.test') && $m->filename === 'Statement TEST-D1 2026-08.pdf');

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
            ->assertSee('Statement of account TEST-D1')
            ->assertSee('Statement TEST-D1 2026-08.pdf')
            ->assertSee('The balance due at 31 August 2026 is');

        Mail::assertNothingSent();
        $this->assertSame(0, $this->sends()->count());
    }

    public function test_the_list_ticks_every_account_with_an_address_and_emails_the_ticked_ones(): void
    {
        Mail::fake();
        config(['debtors.send_mode' => 'live']);
        $this->contact();
        $this->posting('TEST-D2', 'A', '2026-08-15', 'I', 'D', '21', 120.00, '     140');
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
        $rows = [
            ['2026-07-10', 'I', 'D', '1', 1000.00, '     101'],
            ['2026-07-20', 'R', 'C', '2', 400.00, '     111'],
            ['2026-08-05', 'I', 'D', '3', 500.00, '     127'],
            ['2026-08-12', 'R', 'C', '4', 300.00, '        '],
            ['2026-08-12', 'R', 'C', '4', 300.00, '     134'],
            ['2026-08-20', 'C', 'C', '5', 50.00, '     142'],
            ['2026-08-25', 'I', 'D', '6', 200.00, '     147'],
        ];

        foreach ($rows as [$date, $code, $drcr, $ref, $total, $eod]) {
            $this->posting(self::ACCOUNT, 'A', $date, $code, $drcr, $ref, $total, $eod);
        }

        // A cash sale at the same site: not an account posting.
        $this->posting('CASH', null, '2026-08-05', 'I', 'D', '9', 75.00, '     127');

        $this->db()->table('PumpIT.dbo.DBF_CLDB_CUSTOMER')->insert([
            'SSBranchId' => self::BRANCH, 'CNUM' => self::ACCOUNT, 'INIT' => 'T', 'SNAM' => 'TEST Debtor One',
            'BADD1' => '1 TEST Street', 'CRLIMIT' => 5000,
        ]);
    }

    private function posting(string $account, ?string $cust, string $date, string $code, string $drcr, string $ref, float $total, string $eod): void
    {
        $this->db()->table('PumpIT.dbo.DBF_POSTR_CUSTOMER')->insert([
            'SSBranchId' => self::BRANCH, 'DATE' => $date.' 00:00:00', 'TRCOD' => $code, 'CNUM' => $account,
            'CNAM' => 'TEST Debtor One', 'DESC' => 'TEST-', 'DRCR' => $drcr, 'REF' => str_pad($ref, 8, ' ', STR_PAD_LEFT),
            'LOGFILE' => 'XXX', 'CUST' => $cust, 'TOTAL' => $total, 'LOCATION' => 'WINBRANCH', 'EOD_CNTR' => $eod,
        ]);
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
        $db->table('PumpIT.dbo.DBF_POSTR_CUSTOMER')->where('SSBranchId', self::BRANCH)->delete();
        $db->table('PumpIT.dbo.DBF_CLDB_CUSTOMER')->where('SSBranchId', self::BRANCH)->delete();
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }
}
