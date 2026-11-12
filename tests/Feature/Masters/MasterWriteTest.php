<?php

namespace Tests\Feature\Masters;

use App\Exceptions\AgoraProcException;
use App\Support\BranchContext;
use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\User;
use Modules\Core\Services\PermissionService;
use Tests\TestCase;

/**
 * The reference masters' write-backs to PumpIT (T022; CLAUDE.md rule 1, PM
 * decision 01a1cecf-8e08): a GP band edit writes SDK_CategoryGLCode, records
 * every prior in agora.MasterWrite, shows on the record's page with who and
 * before/after, refuses a stale form, and Put back restores the exact float.
 *
 * WRITES THE LOCAL PUMPIT STUB ONLY — every connection is pinned to the
 * container and asserted loopback. The row it edits is its own
 * (branch 13, POS system 5, category 'TEST-BAND ' with PumpIT's trailing
 * space), inserted in setUp and deleted, with its ledger rows, in tearDown.
 */
class MasterWriteTest extends TestCase
{
    private const BRANCH = 13;

    private const KEY = '5:TEST-BAND';

    private const CENTRE = 9901;

    private const STANDARD = 9901;

    /** The site masters' TEST rows, all at BRANCH: expense, till, shift, … numbers. */
    private const SITE_NO = 9901;

    /** BRN_TransactionType.TypeCode is two characters. */
    private const TYPE = 'T9';

    /** The same Smart ATM number at another site, for the one-active-row guard. */
    private const OTHER_TERMINAL = 9902;

    private const OTHER_BRANCH = 14;

    /** BRN_Tank.TankNo and BRN_Pump.PumpNo are four. */
    private const FORECOURT = 'TT99';

    /** @var array<int, User> */
    private array $users = [];

    private bool $safe = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessLocalStub('This test writes the PumpIT stub.');
        $local = config('database.connections.'.config('agora.connections.app'));
        foreach (config('agora.connections') as $name) {
            $database = config("database.connections.{$name}.database");
            config(["database.connections.{$name}" => array_replace($local, ['database' => $database])]);
            DB::purge($name);
            $this->assertContains(config("database.connections.{$name}.host"), ['127.0.0.1', 'localhost', '::1']);
        }
        $this->safe = true;
        config(['masters.write_mode' => 'live']);
        $this->cleanUp();
        $this->db()->statement("INSERT [PumpIT].dbo.SDK_CategoryGLCode (SSBranchId, POSSystemId, Category, StandardCategory, ProfitCentre,
            IsActive, MINGP, MAXGP, MINDaysCover, MAXDaysCover) VALUES (?, 5, 'TEST-BAND ', 'Bread', 'Conv Shop', 1, 10.800000000000001, 12, 0, 0)", [self::BRANCH]);
        $this->db()->statement("INSERT [PumpIT].dbo.SS_ProfitCentre (ProfitCentreId, ProfitCentre, IsActive) VALUES (?, 'TEST-Centre', 1)", [self::CENTRE]);
        $this->db()->statement("INSERT [PumpIT].dbo.SS_StandardCategory (StandardCategoryId, StandardCategory, SupplierGLCode, SalesGLCode, IsActive)
            VALUES (?, 'TEST-Standard', '51019999', '41019999', 1)", [self::STANDARD]);
        $this->db()->statement("INSERT [PumpIT].dbo.BRN_Expenses (SSBranchId, ExpenseNo, ExpenseDescription, GLCode, ApproverUserId, IsAsset, IsActive, Vat)
            VALUES (?, ?, 'TEST-Expense ', '71139999', 0, 0, 1, 15)", [self::BRANCH, self::SITE_NO]);
        $this->db()->statement("INSERT [PumpIT].dbo.BRN_Tills (SSBranchId, TillNo, TillDescription, Location) VALUES (?, ?, 'TEST-Till', 'WINBRANCH')",
            [self::BRANCH, self::SITE_NO]);
        $this->db()->statement("INSERT [PumpIT].dbo.BRN_Shifts (SSBranchId, ShiftNo, ShiftDescription, IsActive) VALUES (?, ?, 'TEST-Shift', 1)",
            [self::BRANCH, self::SITE_NO]);
        $this->db()->statement("INSERT [PumpIT].dbo.BRN_TransactionType (SSBranchId, TypeCode, TypeDescription, AlpNum, HighNum) VALUES (?, ?, 'TEST-Type', 'TT', 41)",
            [self::BRANCH, self::TYPE]);
        $this->db()->statement("INSERT [PumpIT].dbo.BRN_FuelType (SSBranchId, FuelTypeNo, FuelTypeDescription, IsFleetCard, POSType, POSIdentifier, HandlingFee)
            VALUES (?, ?, 'TEST-Fuel', 0, 'WINBRANCH', '99', 0.5)", [self::BRANCH, self::SITE_NO]);
        $this->db()->statement("INSERT [PumpIT].dbo.BRN_Tank (SSBranchId, TankNo, TankDescription) VALUES (?, ?, 'TEST-Tank')", [self::BRANCH, self::FORECOURT]);
        $this->db()->statement("INSERT [PumpIT].dbo.BRN_UtilityMeter (UtilityMeterId, SSBranchId, UtilityType, MeterNo, Description) VALUES (?, ?, 'W', 'TEST-M1', 'TEST-Meter')",
            [self::SITE_NO, self::BRANCH]);
        $this->db()->statement("INSERT [PumpIT].dbo.SS_Branch_SMARTATM_TerminalNo (SMARTATMTerminalNoId, SSBranchId, SMARTATM_TerminalNo, Description, IsActive)
            VALUES (?, ?, 'TEST99', 'TEST-Terminal', 1)", [self::SITE_NO, self::BRANCH]);
        $this->db()->statement("INSERT [PumpIT].dbo.SS_Branch_SMARTATM_TerminalNo (SMARTATMTerminalNoId, SSBranchId, SMARTATM_TerminalNo, Description, IsActive)
            VALUES (?, ?, 'TEST99', 'TEST-Terminal there', 0)", [self::OTHER_TERMINAL, self::OTHER_BRANCH]);
        $this->db()->statement("INSERT [PumpIT].dbo.BRN_Pump (SSBranchId, PumpNo, FuelTypeNo, TankNo, IsActive, [Order], POSType, POSIdentifier)
            VALUES (?, ?, ?, ?, 1, 7, 'WINBRANCH', '9999')", [self::BRANCH, self::FORECOURT, self::SITE_NO, self::FORECOURT]);
    }

    protected function tearDown(): void
    {
        if ($this->safe) {
            $this->cleanUp();
            foreach ($this->users as $user) {
                foreach (['UserPermission', 'UserBranch', 'UserGridColumn', 'UserMenuItem'] as $table) {
                    $this->db()->table('agora.'.$table)->where('UserId', $user->Id)->delete();
                }
                User::query()->acrossBranches()->where('Id', $user->Id)->forceDelete();
            }
        }
        parent::tearDown();
    }

    public function test_a_viewer_gets_no_edit_and_the_procedure_refuses_without_the_permission(): void
    {
        $this->actingAs($this->person(['categorygl.view']));
        $this->get($this->showUrl())->assertOk()->assertDontSee('Edit in PumpIT')->assertSee('Written to PumpIT');
        $this->get($this->editUrl())->assertForbidden();
        $this->put($this->updateUrl(), $this->input(['MinGP' => 25]))->assertForbidden();

        $this->assertRefused('DENIED', array_replace($this->procInput(), ['Authorized' => 0]));
        $this->assertSame(10.8, round($this->pumpit()->MINGP, 10), 'Nothing reached PumpIT.');
    }

    public function test_a_min_gp_change_writes_pumpit_and_shows_before_after_and_who_on_the_record(): void
    {
        $editor = $this->person(['categorygl.view', 'categorygl.edit']);
        $this->actingAs($editor);
        $this->get($this->showUrl())->assertOk()->assertSee('Edit in PumpIT');
        $this->get($this->editUrl())->assertOk()->assertSee('data-confirm="Write this to PumpIT?"', false)
            ->assertSee('PumpIT dbo.SDK_CategoryGLCode', false)->assertSee('data-diff-field="MINGP"', false);

        $this->put($this->updateUrl(), $this->input(['MinGP' => 25, 'MaxGP' => 30]))->assertRedirect($this->showUrl());

        $row = $this->pumpit();
        $this->assertSame([25.0, 30.0, 0.0, 0.0, true], [$row->MINGP, $row->MAXGP, $row->MINDaysCover, $row->MAXDaysCover, (bool) $row->IsActive]);
        $ledger = $this->ledger()->keyBy('Field');
        $this->assertSame(['MAXGP', 'MINGP'], $ledger->keys()->sort()->values()->all(), 'One row per column changed, and only those.');
        $this->assertSame(['10.8', '25'], [$ledger['MINGP']->PriorValue, $ledger['MINGP']->NewValue]);
        $this->assertSame((int) $editor->Id, (int) $ledger['MINGP']->CreatedBy);
        $this->assertSame('SDK_CategoryGLCode', $ledger['MINGP']->PumpItTable);
        $this->assertSame('TEST-BAND ', json_decode($ledger['MINGP']->KeyJson)->Category, 'The PumpIT key keeps its trailing space.');

        $this->get($this->showUrl())->assertOk()->assertSee('MINGP')->assertSee('10.8')->assertSee('TEST-Master write')->assertSee('TEST-raise the bread band');

        // The audit trail (vw_PumpItChange, Reports v1__22o) carries it with before/after and who.
        $audit = $this->db()->selectOne("SELECT * FROM agora.vw_PumpItChange WHERE Ledger = 'MasterWrite' AND LedgerId = ?", [$ledger['MINGP']->Id]);
        $this->assertSame(['Masters', 'Category band changed', 'SDK_CategoryGLCode', 'MINGP 10.8 → 25'],
            [$audit->Module, $audit->Action, $audit->PumpItTable, $audit->Change]);
        $this->assertSame((int) $editor->Id, (int) $audit->ChangedBy);

        // The listing reads PumpIT, so it shows the new band at once.
        $listed = app(ProcedureService::class)->callSets('agora.usp_Masters_GridCategoryGlCodes', [
            'BranchIds' => (string) self::BRANCH, 'Key' => self::KEY, 'Authorized' => 1])[0]->first();
        $this->assertSame('25.0000', (string) $listed->MinGP);
    }

    public function test_stale_out_of_band_unreasoned_and_unchanged_forms_write_nothing(): void
    {
        $this->actingAs($this->person(['categorygl.view', 'categorygl.edit']));

        $this->assertRefused('STALE', array_replace($this->procInput(), ['ExpectedMinGP' => 11]));
        $this->assertRefused('BAND', array_replace($this->procInput(), ['MinGP' => 40, 'MaxGP' => 30]));
        $this->assertRefused('BAND', array_replace($this->procInput(), ['MaxGP' => 120]));
        $this->assertRefused('REASON_REQUIRED', array_replace($this->procInput(), ['Reason' => 'short']));
        $this->assertRefused('WRITES_OFF', array_replace($this->procInput(), ['WriteMode' => 'off']));

        $this->put($this->updateUrl(), $this->input(['MinGP' => 25, 'ExpectedMinGP' => 11]))->assertSessionHasErrors('refusal');

        $same = app(ProcedureService::class)->write('agora.usp_Masters_SaveCategoryBand',
            array_replace($this->procInput(), ['MinGP' => 10.8, 'MaxGP' => 12]));
        $this->assertSame('NOTHING_CHANGED', $same->Code);
        $this->assertCount(0, $this->ledger());
        $this->assertSame(10.8, round($this->pumpit()->MINGP, 10));
    }

    public function test_put_back_restores_the_exact_float_and_refuses_once_pumpit_has_moved(): void
    {
        $this->actingAs($this->person(['categorygl.view', 'categorygl.edit']));
        $exact = $this->db()->selectOne("SELECT CONVERT(varchar(30), MINGP, 3) AS v FROM [PumpIT].dbo.SDK_CategoryGLCode WHERE SSBranchId = ? AND Category = 'TEST-BAND '", [self::BRANCH])->v;

        $this->put($this->updateUrl(), $this->input(['MinGP' => 11, 'IsActive' => 0]))->assertSessionHasNoErrors();
        $write = (string) $this->ledger()->first()->WriteId;
        $this->post($this->reverseUrl($write), ['key' => self::KEY, 'Reason' => 'TEST-wrong category, put it back'])->assertRedirect($this->showUrl());

        $this->assertSame($exact, $this->db()->selectOne("SELECT CONVERT(varchar(30), MINGP, 3) AS v FROM [PumpIT].dbo.SDK_CategoryGLCode WHERE SSBranchId = ? AND Category = 'TEST-BAND '", [self::BRANCH])->v,
            'The prior float, not its decimal text.');
        $this->assertTrue((bool) $this->pumpit()->IsActive);
        $this->assertSame(['reversed'], $this->ledger()->pluck('State')->unique()->values()->all());
        $this->post($this->reverseUrl($write), ['key' => self::KEY, 'Reason' => 'TEST-second time round'])->assertSessionHasErrors('refusal');

        // A second change, then PumpIT moves underneath it: Put back refuses.
        $this->put($this->updateUrl(), $this->input(['MinGP' => 11.5]))->assertSessionHasNoErrors();
        $second = (string) $this->ledger()->where('State', 'active')->first()->WriteId;
        $this->db()->statement("UPDATE [PumpIT].dbo.SDK_CategoryGLCode SET MINGP = 11.75 WHERE SSBranchId = ? AND Category = 'TEST-BAND '", [self::BRANCH]);
        try {
            app(ProcedureService::class)->write('agora.usp_Masters_ReverseWrite', ['BranchId' => self::BRANCH, 'WriteId' => $second,
                'Reason' => 'TEST-put back over a move', 'UserId' => null, 'AuthorizedEntities' => 'CategoryGl', 'WriteMode' => 'live']);
            $this->fail('A reversal over a later PumpIT change must be refused.');
        } catch (AgoraProcException $e) {
            $this->assertSame('MOVED', $e->code());
        }
        $this->assertSame(11.75, $this->pumpit()->MINGP);
    }

    public function test_with_writes_off_nothing_is_offered_or_reachable(): void
    {
        config(['masters.write_mode' => 'off']);
        $this->actingAs($this->person(['categorygl.view', 'categorygl.edit']));
        $this->get($this->showUrl())->assertOk()->assertDontSee('Edit in PumpIT');
        $this->get($this->editUrl())->assertNotFound();
        $this->put($this->updateUrl(), $this->input(['MinGP' => 25]))->assertNotFound();
        $this->assertCount(0, $this->ledger());
    }

    public function test_a_profit_centre_is_switched_off_in_pumpit_but_never_while_in_use_and_never_renamed(): void
    {
        $this->actingAs($this->person(['profitcentres.view', 'profitcentres.edit']));
        $show = route('app.setup.profit-centres.show', ['branch' => 2, 'key' => self::CENTRE]);
        $edit = $this->get(route('app.setup.profit-centres.edit', ['branch' => 2, 'key' => self::CENTRE]))->assertOk()
            ->assertSee('PumpIT dbo.SS_ProfitCentre', false);
        $edit->assertDontSee('name="Name"', false);

        // In use: an active category names it by text.
        $this->db()->statement("UPDATE [PumpIT].dbo.SDK_CategoryGLCode SET ProfitCentre = 'TEST-Centre' WHERE SSBranchId = ? AND Category = 'TEST-BAND '", [self::BRANCH]);
        $off = ['key' => (string) self::CENTRE, 'IsActive' => 0, 'ExpectedIsActive' => 1, 'Reason' => 'TEST-centre no longer trades'];
        $this->put(route('app.setup.profit-centres.update', ['branch' => 2]), $off)->assertSessionHasErrors('refusal');
        $this->assertTrue((bool) $this->db()->selectOne('SELECT IsActive FROM [PumpIT].dbo.SS_ProfitCentre WHERE ProfitCentreId = ?', [self::CENTRE])->IsActive);

        $this->db()->statement("UPDATE [PumpIT].dbo.SDK_CategoryGLCode SET IsActive = 0 WHERE SSBranchId = ? AND Category = 'TEST-BAND '", [self::BRANCH]);
        $this->put(route('app.setup.profit-centres.update', ['branch' => 2]), $off)->assertRedirect($show);
        $this->assertFalse((bool) $this->db()->selectOne('SELECT IsActive FROM [PumpIT].dbo.SS_ProfitCentre WHERE ProfitCentreId = ?', [self::CENTRE])->IsActive);
        $write = $this->db()->table('agora.MasterWrite')->where('Entity', 'ProfitCentre')->where('RowKey', (string) self::CENTRE)->first();
        $this->assertSame(['IsActive', '1', '0', 'SS_ProfitCentre'], [$write->Field, $write->PriorValue, $write->NewValue, $write->PumpItTable]);
        $this->get($show)->assertOk()->assertSee('Written to PumpIT')->assertSee('TEST-centre no longer trades');

        $this->post(route('app.setup.profit-centres.writes.reverse', ['branch' => 2, 'write' => $write->WriteId]),
            ['key' => (string) self::CENTRE, 'Reason' => 'TEST-it trades after all'])->assertRedirect($show);
        $this->assertTrue((bool) $this->db()->selectOne('SELECT IsActive FROM [PumpIT].dbo.SS_ProfitCentre WHERE ProfitCentreId = ?', [self::CENTRE])->IsActive);
        $this->assertSame('TEST-Centre', $this->db()->selectOne('SELECT ProfitCentre FROM [PumpIT].dbo.SS_ProfitCentre WHERE ProfitCentreId = ?', [self::CENTRE])->ProfitCentre);
    }

    public function test_a_standard_category_gl_change_writes_one_row_and_puts_back(): void
    {
        $this->actingAs($this->person(['standardcategories.view', 'standardcategories.edit']));
        $update = route('app.setup.standard-categories.update', ['branch' => 2]);
        $form = ['key' => (string) self::STANDARD, 'SupplierGLCode' => '51010209', 'SalesGLCode' => '41019999', 'IsActive' => 1,
            'ExpectedSupplierGLCode' => '51019999', 'ExpectedSalesGLCode' => '41019999', 'ExpectedIsActive' => 1, 'Reason' => 'TEST-post to the shop GL'];

        $this->put($update, array_replace($form, ['SupplierGLCode' => '51A10209']))->assertSessionHasErrors('refusal');
        $this->put($update, array_replace($form, ['ExpectedSupplierGLCode' => '51000000']))->assertSessionHasErrors('refusal');
        $this->put($update, $form)->assertSessionHasNoErrors();

        $row = $this->db()->selectOne('SELECT StandardCategory, SupplierGLCode, SalesGLCode FROM [PumpIT].dbo.SS_StandardCategory WHERE StandardCategoryId = ?', [self::STANDARD]);
        $this->assertSame(['TEST-Standard', '51010209', '41019999'], [$row->StandardCategory, $row->SupplierGLCode, $row->SalesGLCode]);
        $writes = $this->db()->table('agora.MasterWrite')->where('Entity', 'StandardCategory')->where('RowKey', (string) self::STANDARD)->get();
        $this->assertSame([['SupplierGLCode', '51019999', '51010209']], $writes->map(fn ($w) => [$w->Field, $w->PriorValue, $w->NewValue])->all());

        $this->post(route('app.setup.standard-categories.writes.reverse', ['branch' => 2, 'write' => $writes->first()->WriteId]),
            ['key' => (string) self::STANDARD, 'Reason' => 'TEST-wrong account, put back'])->assertSessionHasNoErrors();
        $this->assertSame('51019999', $this->db()->selectOne('SELECT SupplierGLCode FROM [PumpIT].dbo.SS_StandardCategory WHERE StandardCategoryId = ?', [self::STANDARD])->SupplierGLCode);
    }

    public function test_an_expense_code_writes_one_row_refuses_the_till_balance_name_and_in_use_and_puts_back(): void
    {
        $this->actingAs($this->person(['expenses.view', 'expenses.edit']));
        $update = route('app.setup.expense-codes.update', ['branch' => self::BRANCH]);
        $show = route('app.setup.expense-codes.show', ['branch' => self::BRANCH, 'key' => self::SITE_NO]);
        $this->get(route('app.setup.expense-codes.edit', ['branch' => self::BRANCH, 'key' => self::SITE_NO]))->assertOk()
            ->assertSee('PumpIT dbo.BRN_Expenses', false)->assertSee('data-diff-field="GLCode"', false)->assertDontSee('name="ApproverUserId"', false);
        $form = ['key' => (string) self::SITE_NO, 'ExpenseDescription' => 'TEST-Expense renamed', 'GLCode' => '71130100', 'Vat' => 0, 'IsActive' => 1,
            'ExpectedExpenseDescription' => 'TEST-Expense', 'ExpectedGLCode' => '71139999', 'ExpectedVat' => 15, 'ExpectedIsActive' => 1,
            'Reason' => 'TEST-move it to the shop GL'];

        // Refusals write nothing.
        $this->put($update, array_replace($form, ['ExpenseDescription' => 'Till Balance Control']))->assertSessionHasErrors('refusal');
        $this->put($update, array_replace($form, ['Vat' => 14]))->assertSessionHasErrors('refusal');
        $this->put($update, array_replace($form, ['GLCode' => '7113A']))->assertSessionHasErrors('refusal');
        $this->put($update, array_replace($form, ['ExpectedGLCode' => '71130000']))->assertSessionHasErrors('refusal');
        $taken = $this->db()->selectOne("SELECT TOP 1 ExpenseDescription FROM [PumpIT].dbo.BRN_Expenses WHERE SSBranchId = ? AND ExpenseNo <> ? AND ExpenseDescription NOT LIKE 'TEST-%'",
            [self::BRANCH, self::SITE_NO])->ExpenseDescription;
        $this->put($update, array_replace($form, ['ExpenseDescription' => $taken]))->assertSessionHasErrors('refusal');
        $this->assertSiteRefused('RESERVED', 'agora.usp_Masters_SaveExpense', $this->reservedExpenseOff());

        // A purchase request waiting for approval holds it on.
        $this->db()->statement("INSERT [PumpIT].dbo.BRN_Transaction (SSBranchId, TransactionNo, TypeCode, SupplierCode, QuoteNo, Approved, IsDeclined, Posted)
            VALUES (?, 'TEST-PR1', '01', 'TEST', '', 0, 0, 0)", [self::BRANCH]);
        $this->db()->statement("INSERT [PumpIT].dbo.BRN_TransactionLine (SSBranchId, TransactionNo, TypeCode, ItemNo, ExpenseNo, IsEmailSent, IsApprovedEmailSent, Posted)
            VALUES (?, 'TEST-PR1', '01', 1, ?, 0, 0, 0)", [self::BRANCH, self::SITE_NO]);
        $this->assertSiteRefused('IN_USE', 'agora.usp_Masters_SaveExpense', $this->siteProc('Expense', ['IsActive' => 0]));
        $this->assertCount(0, $this->siteLedger('Expense'));

        $this->put($update, $form)->assertRedirect($show);
        $row = $this->db()->selectOne('SELECT ExpenseDescription, GLCode, Vat, IsActive, ApproverUserId FROM [PumpIT].dbo.BRN_Expenses WHERE SSBranchId = ? AND ExpenseNo = ?',
            [self::BRANCH, self::SITE_NO]);
        $this->assertSame(['TEST-Expense renamed', '71130100', 0, true, 0], [$row->ExpenseDescription, $row->GLCode, (int) $row->Vat, (bool) $row->IsActive, (int) $row->ApproverUserId]);
        $writes = $this->siteLedger('Expense')->keyBy('Field');
        $this->assertSame(['ExpenseDescription', 'GLCode', 'Vat'], $writes->keys()->sort()->values()->all());
        $this->assertSame(['TEST-Expense ', 'TEST-Expense renamed'], [$writes['ExpenseDescription']->PriorValue, $writes['ExpenseDescription']->NewValue]);
        $audit = $this->db()->selectOne("SELECT * FROM agora.vw_PumpItChange WHERE Ledger = 'MasterWrite' AND LedgerId = ?", [$writes['Vat']->Id]);
        $this->assertSame(['Masters', 'BRN_Expenses', 'Vat 15 → 0'], [$audit->Module, $audit->PumpItTable, $audit->Change]);
        $this->get($show)->assertOk()->assertSee('Written to PumpIT')->assertSee('TEST-move it to the shop GL');

        $this->post(route('app.setup.expense-codes.writes.reverse', ['branch' => self::BRANCH, 'write' => $writes->first()->WriteId]),
            ['key' => (string) self::SITE_NO, 'Reason' => 'TEST-wrong account, put back'])->assertRedirect($show);
        $row = $this->db()->selectOne('SELECT ExpenseDescription, GLCode, Vat FROM [PumpIT].dbo.BRN_Expenses WHERE SSBranchId = ? AND ExpenseNo = ?', [self::BRANCH, self::SITE_NO]);
        $this->assertSame(['TEST-Expense ', '71139999', 15], [$row->ExpenseDescription, $row->GLCode, (int) $row->Vat], 'Put back exactly, trailing space and all.');
    }

    public function test_a_till_is_renamed_in_pumpit_and_nothing_else_moves(): void
    {
        $this->actingAs($this->person(['tills.view', 'tills.edit']));
        $update = route('app.setup.tills.update', ['branch' => self::BRANCH]);
        $show = route('app.setup.tills.show', ['branch' => self::BRANCH, 'key' => self::SITE_NO]);
        $this->get(route('app.setup.tills.edit', ['branch' => self::BRANCH, 'key' => self::SITE_NO]))->assertOk()
            ->assertSee('PumpIT dbo.BRN_Tills', false)->assertDontSee('name="Location"', false)->assertDontSee('name="TillNo"', false);
        $form = ['key' => (string) self::SITE_NO, 'TillDescription' => 'TEST-Till front', 'ExpectedTillDescription' => 'TEST-Till', 'Reason' => 'TEST-the front till'];

        $this->put($update, array_replace($form, ['TillDescription' => str_repeat('x', 31)]))->assertSessionHasErrors();
        $this->put($update, array_replace($form, ['ExpectedTillDescription' => 'TEST-Other']))->assertSessionHasErrors('refusal');
        $this->put($update, $form)->assertRedirect($show);
        $this->assertSame(['TEST-Till front', 'WINBRANCH'], array_values((array) $this->db()->selectOne(
            'SELECT TillDescription, Location FROM [PumpIT].dbo.BRN_Tills WHERE SSBranchId = ? AND TillNo = ?', [self::BRANCH, self::SITE_NO])));
        $write = $this->siteLedger('Till')->first();
        $this->assertSame(['TillDescription', 'TEST-Till', 'TEST-Till front', 'BRN_Tills'], [$write->Field, $write->PriorValue, $write->NewValue, $write->PumpItTable]);
        $this->assertSame(['SSBranchId' => self::BRANCH, 'TillNo' => self::SITE_NO], (array) json_decode($write->KeyJson));

        // PumpIT moves underneath: Put back refuses rather than undo that too.
        $this->db()->statement("UPDATE [PumpIT].dbo.BRN_Tills SET TillDescription = 'TEST-Till moved' WHERE SSBranchId = ? AND TillNo = ?", [self::BRANCH, self::SITE_NO]);
        $this->post(route('app.setup.tills.writes.reverse', ['branch' => self::BRANCH, 'write' => $write->WriteId]),
            ['key' => (string) self::SITE_NO, 'Reason' => 'TEST-put back over a move'])->assertSessionHasErrors('refusal');
        $this->db()->statement("UPDATE [PumpIT].dbo.BRN_Tills SET TillDescription = 'TEST-Till front' WHERE SSBranchId = ? AND TillNo = ?", [self::BRANCH, self::SITE_NO]);
        $this->post(route('app.setup.tills.writes.reverse', ['branch' => self::BRANCH, 'write' => $write->WriteId]),
            ['key' => (string) self::SITE_NO, 'Reason' => 'TEST-the old name was right'])->assertRedirect($show);
        $this->assertSame('TEST-Till', $this->db()->selectOne('SELECT TillDescription FROM [PumpIT].dbo.BRN_Tills WHERE SSBranchId = ? AND TillNo = ?',
            [self::BRANCH, self::SITE_NO])->TillDescription);
    }

    public function test_a_shift_is_renamed_and_switched_off_but_never_while_an_area_runs_it(): void
    {
        $this->actingAs($this->person(['shifts.view', 'shifts.edit']));
        $update = route('app.setup.shifts.update', ['branch' => self::BRANCH]);
        $show = route('app.setup.shifts.show', ['branch' => self::BRANCH, 'key' => self::SITE_NO]);
        $this->get(route('app.setup.shifts.edit', ['branch' => self::BRANCH, 'key' => self::SITE_NO]))->assertOk()
            ->assertSee('PumpIT dbo.BRN_Shifts', false)->assertSee('data-diff-field="IsActive"', false);

        // The site's real Day Shift: its counting areas run it, so it stays on.
        $day = array_replace($this->siteProc('Shift', ['IsActive' => 0]), ['Key' => '1', 'ShiftDescription' => 'Day Shift', 'ExpectedShiftDescription' => 'Day Shift']);
        $this->assertSiteRefused('IN_USE', 'agora.usp_Masters_SaveShift', $day);
        $this->assertTrue((bool) $this->db()->selectOne('SELECT IsActive FROM [PumpIT].dbo.BRN_Shifts WHERE SSBranchId = ? AND ShiftNo = 1', [self::BRANCH])->IsActive);
        $this->assertSiteRefused('DUPLICATE', 'agora.usp_Masters_SaveShift', $this->siteProc('Shift', ['ShiftDescription' => 'Night Shift']));
        $this->assertSiteRefused('DESCRIPTION', 'agora.usp_Masters_SaveShift', $this->siteProc('Shift', ['ShiftDescription' => str_repeat('x', 21)]));

        $form = ['key' => (string) self::SITE_NO, 'ShiftDescription' => 'TEST-Shift late', 'IsActive' => 0,
            'ExpectedShiftDescription' => 'TEST-Shift', 'ExpectedIsActive' => 1, 'Reason' => 'TEST-the late shift is closed'];
        $this->put($update, $form)->assertRedirect($show);
        $row = $this->db()->selectOne('SELECT ShiftDescription, IsActive FROM [PumpIT].dbo.BRN_Shifts WHERE SSBranchId = ? AND ShiftNo = ?', [self::BRANCH, self::SITE_NO]);
        $this->assertSame(['TEST-Shift late', false], [$row->ShiftDescription, (bool) $row->IsActive]);
        $writes = $this->siteLedger('Shift');
        $this->assertSame([['ShiftDescription', 'TEST-Shift', 'TEST-Shift late'], ['IsActive', '1', '0']],
            $writes->map(fn ($w) => [$w->Field, $w->PriorValue, $w->NewValue])->all());
        $this->assertSame(['SSBranchId' => self::BRANCH, 'ShiftNo' => self::SITE_NO], (array) json_decode($writes->first()->KeyJson), 'Keyed by site AND number.');

        $this->post(route('app.setup.shifts.writes.reverse', ['branch' => self::BRANCH, 'write' => $writes->first()->WriteId]),
            ['key' => (string) self::SITE_NO, 'Reason' => 'TEST-it opens again'])->assertRedirect($show);
        $row = $this->db()->selectOne('SELECT ShiftDescription, IsActive FROM [PumpIT].dbo.BRN_Shifts WHERE SSBranchId = ? AND ShiftNo = ?', [self::BRANCH, self::SITE_NO]);
        $this->assertSame(['TEST-Shift', true], [$row->ShiftDescription, (bool) $row->IsActive]);
    }

    public function test_a_transaction_type_is_renamed_and_its_numbering_is_never_touched(): void
    {
        $this->actingAs($this->person(['transactiontypes.view', 'transactiontypes.edit']));
        $update = route('app.setup.transaction-types.update', ['branch' => self::BRANCH]);
        $show = route('app.setup.transaction-types.show', ['branch' => self::BRANCH, 'key' => self::TYPE]);
        $this->get(route('app.setup.transaction-types.edit', ['branch' => self::BRANCH, 'key' => self::TYPE]))->assertOk()
            ->assertSee('PumpIT dbo.BRN_TransactionType', false)->assertDontSee('name="NumberPrefix"', false)->assertDontSee('name="LastNumber"', false);

        $this->assertSiteRefused('DUPLICATE', 'agora.usp_Masters_SaveTransactionType',
            array_replace($this->siteProc('TransactionType', ['TypeDescription' => 'Purchase Request - Expense Cash']), ['Key' => self::TYPE]));
        $this->put($update, ['key' => self::TYPE, 'TypeDescription' => 'TEST-Type petty cash', 'ExpectedTypeDescription' => 'TEST-Type',
            'Reason' => 'TEST-name it as the sites say it'])->assertRedirect($show);
        $row = $this->db()->selectOne('SELECT TypeDescription, AlpNum, HighNum FROM [PumpIT].dbo.BRN_TransactionType WHERE SSBranchId = ? AND TypeCode = ?', [self::BRANCH, self::TYPE]);
        $this->assertSame(['TEST-Type petty cash', 'TT', 41], [$row->TypeDescription, $row->AlpNum, (int) $row->HighNum]);
        $write = $this->db()->table('agora.MasterWrite')->where('BranchId', self::BRANCH)->where('Entity', 'TransactionType')->where('RowKey', self::TYPE)->first();
        $this->assertSame(['TypeDescription', 'TEST-Type', 'TEST-Type petty cash'], [$write->Field, $write->PriorValue, $write->NewValue]);

        $this->post(route('app.setup.transaction-types.writes.reverse', ['branch' => self::BRANCH, 'write' => $write->WriteId]),
            ['key' => self::TYPE, 'Reason' => 'TEST-keep the old name'])->assertRedirect($show);
        $this->assertSame('TEST-Type', $this->db()->selectOne('SELECT TypeDescription FROM [PumpIT].dbo.BRN_TransactionType WHERE SSBranchId = ? AND TypeCode = ?',
            [self::BRANCH, self::TYPE])->TypeDescription);
    }

    public function test_a_fuel_type_is_renamed_at_one_site_and_its_pos_link_fleet_flag_and_fee_stay(): void
    {
        $this->actingAs($this->person(['fueltypes.view', 'fueltypes.edit']));
        $update = route('app.setup.fuel-types.update', ['branch' => self::BRANCH]);
        $show = route('app.setup.fuel-types.show', ['branch' => self::BRANCH, 'key' => self::SITE_NO]);
        $edit = $this->get(route('app.setup.fuel-types.edit', ['branch' => self::BRANCH, 'key' => self::SITE_NO]))->assertOk()
            ->assertSee('PumpIT dbo.BRN_FuelType', false);
        foreach (['POSIdentifier', 'IsFleetCard', 'HandlingFee', 'CostPrice', 'SellingPrice'] as $never) {
            $edit->assertDontSee('name="'.$never.'"', false);
        }

        // The same name twice at one site is PumpIT's own (a fleet grade beside the forecourt one), so it is not refused.
        $this->put($update, ['key' => (string) self::SITE_NO, 'FuelTypeDescription' => 'TEST-Fuel ULP 95', 'ExpectedFuelTypeDescription' => 'TEST-Fuel',
            'Reason' => 'TEST-name the grade properly'])->assertRedirect($show);
        $row = $this->db()->selectOne('SELECT FuelTypeDescription, POSIdentifier, IsFleetCard, HandlingFee FROM [PumpIT].dbo.BRN_FuelType WHERE SSBranchId = ? AND FuelTypeNo = ?',
            [self::BRANCH, self::SITE_NO]);
        $this->assertSame(['TEST-Fuel ULP 95', '99', false, 0.5], [$row->FuelTypeDescription, $row->POSIdentifier, (bool) $row->IsFleetCard, round((float) $row->HandlingFee, 4)]);
        $write = $this->siteLedger('FuelType')->sole();
        $this->assertSame(['SSBranchId' => self::BRANCH, 'FuelTypeNo' => self::SITE_NO], (array) json_decode($write->KeyJson));
        $this->assertSame(1, $this->db()->table('agora.MasterWrite')->where('WriteId', $write->WriteId)->count(), 'One site\'s row, not every site\'s fuel type '.self::SITE_NO.'.');

        $this->post(route('app.setup.fuel-types.writes.reverse', ['branch' => self::BRANCH, 'write' => $write->WriteId]),
            ['key' => (string) self::SITE_NO, 'Reason' => 'TEST-keep the PumpIT name'])->assertRedirect($show);
        $this->assertSame('TEST-Fuel', $this->db()->selectOne('SELECT FuelTypeDescription FROM [PumpIT].dbo.BRN_FuelType WHERE SSBranchId = ? AND FuelTypeNo = ?',
            [self::BRANCH, self::SITE_NO])->FuelTypeDescription);
    }

    public function test_a_tank_is_renamed_and_a_pump_reordered_and_switched_off_without_moving_its_mapping(): void
    {
        $this->actingAs($this->person(['tanks.view', 'tanks.edit', 'pumps.view', 'pumps.edit']));

        $this->assertSiteRefused('DESCRIPTION', 'agora.usp_Masters_SaveTank',
            array_replace($this->siteProc('Tank', ['TankDescription' => ' ']), ['Key' => self::FORECOURT]));
        $this->put(route('app.setup.tanks.update', ['branch' => self::BRANCH]), ['key' => self::FORECOURT, 'TankDescription' => 'TEST-Tank 50 ppm',
            'ExpectedTankDescription' => 'TEST-Tank', 'Reason' => 'TEST-the tank was relined'])->assertSessionHasNoErrors();
        $this->assertSame('TEST-Tank 50 ppm', $this->db()->selectOne('SELECT TankDescription FROM [PumpIT].dbo.BRN_Tank WHERE SSBranchId = ? AND TankNo = ?',
            [self::BRANCH, self::FORECOURT])->TankDescription);

        $update = route('app.setup.pumps.update', ['branch' => self::BRANCH]);
        $show = route('app.setup.pumps.show', ['branch' => self::BRANCH, 'key' => self::FORECOURT]);
        $edit = $this->get(route('app.setup.pumps.edit', ['branch' => self::BRANCH, 'key' => self::FORECOURT]))->assertOk()
            ->assertSee('PumpIT dbo.BRN_Pump', false)->assertSee('data-diff-field="Order"', false);
        foreach (['FuelTypeNo', 'TankNo', 'POSIdentifier'] as $never) {
            $edit->assertDontSee('name="'.$never.'"', false);
        }
        $form = ['key' => self::FORECOURT, 'PumpOrder' => 2, 'IsActive' => 0, 'ExpectedPumpOrder' => 7, 'ExpectedIsActive' => 1, 'Reason' => 'TEST-pump taken out of service'];
        $this->put($update, array_replace($form, ['PumpOrder' => 2.5]))->assertSessionHasErrors('refusal');
        $this->put($update, array_replace($form, ['ExpectedPumpOrder' => 8]))->assertSessionHasErrors('refusal');
        $this->put($update, $form)->assertRedirect($show);
        $row = $this->db()->selectOne('SELECT [Order] AS PumpOrder, IsActive, FuelTypeNo, TankNo, POSIdentifier FROM [PumpIT].dbo.BRN_Pump WHERE SSBranchId = ? AND PumpNo = ?',
            [self::BRANCH, self::FORECOURT]);
        $this->assertSame([2, false, self::SITE_NO, self::FORECOURT, '9999'], [(int) $row->PumpOrder, (bool) $row->IsActive, (int) $row->FuelTypeNo, $row->TankNo, $row->POSIdentifier]);
        $writes = $this->db()->table('agora.MasterWrite')->where('BranchId', self::BRANCH)->where('Entity', 'Pump')->where('RowKey', self::FORECOURT)->orderBy('Id')->get();
        $this->assertSame([['Order', '7', '2'], ['IsActive', '1', '0']], $writes->map(fn ($w) => [$w->Field, $w->PriorValue, $w->NewValue])->all());

        $this->post(route('app.setup.pumps.writes.reverse', ['branch' => self::BRANCH, 'write' => $writes->first()->WriteId]),
            ['key' => self::FORECOURT, 'Reason' => 'TEST-back in service'])->assertRedirect($show);
        $row = $this->db()->selectOne('SELECT [Order] AS PumpOrder, IsActive FROM [PumpIT].dbo.BRN_Pump WHERE SSBranchId = ? AND PumpNo = ?', [self::BRANCH, self::FORECOURT]);
        $this->assertSame([7, true], [(int) $row->PumpOrder, (bool) $row->IsActive]);
    }

    public function test_a_utility_meter_description_changes_the_meter_row_only(): void
    {
        $this->actingAs($this->person(['meters.view', 'meters.edit']));
        $update = route('app.setup.meters.update', ['branch' => self::BRANCH]);
        $show = route('app.setup.meters.show', ['branch' => self::BRANCH, 'key' => self::SITE_NO]);
        $this->get(route('app.setup.meters.edit', ['branch' => self::BRANCH, 'key' => self::SITE_NO]))->assertOk()
            ->assertSee('PumpIT dbo.BRN_UtilityMeter', false)->assertDontSee('name="MeterNo"', false)->assertDontSee('name="UtilityType"', false);

        // 50, not the column's 100: PumpIT's capture copies it into an nvarchar(50).
        $this->assertSiteRefused('DESCRIPTION', 'agora.usp_Masters_SaveUtilityMeter', $this->siteProc('UtilityMeter', ['MeterDescription' => str_repeat('m', 51)]));
        $this->assertSiteRefused('NOT_FOUND', 'agora.usp_Masters_SaveUtilityMeter',
            array_replace($this->siteProc('UtilityMeter', ['MeterDescription' => 'TEST-Meter elsewhere']), ['BranchId' => self::BRANCH + 1]));

        $this->put($update, ['key' => (string) self::SITE_NO, 'MeterDescription' => 'TEST-Meter borehole', 'ExpectedMeterDescription' => 'TEST-Meter',
            'Reason' => 'TEST-say which water meter'])->assertRedirect($show);
        $row = $this->db()->selectOne('SELECT Description, MeterNo, UtilityType FROM [PumpIT].dbo.BRN_UtilityMeter WHERE UtilityMeterId = ?', [self::SITE_NO]);
        $this->assertSame(['TEST-Meter borehole', 'TEST-M1', 'W'], [$row->Description, $row->MeterNo, $row->UtilityType]);
        $write = $this->siteLedger('UtilityMeter')->sole();
        $this->assertSame(['Description', 'TEST-Meter', 'Meter TEST-M1'], [$write->Field, $write->PriorValue, $write->RowLabel]);

        $this->post(route('app.setup.meters.writes.reverse', ['branch' => self::BRANCH, 'write' => $write->WriteId]),
            ['key' => (string) self::SITE_NO, 'Reason' => 'TEST-the old description'])->assertRedirect($show);
        $this->assertSame('TEST-Meter', $this->db()->selectOne('SELECT Description FROM [PumpIT].dbo.BRN_UtilityMeter WHERE UtilityMeterId = ?', [self::SITE_NO])->Description);
    }

    public function test_a_terminal_description_names_its_own_table_and_never_offers_the_number(): void
    {
        $this->actingAs($this->person(['terminals.view', 'terminals.edit']));
        $key = 'SMARTATM:'.self::SITE_NO;
        $update = route('app.setup.terminals.update', ['branch' => self::BRANCH]);
        $show = route('app.setup.terminals.show', ['branch' => self::BRANCH, 'key' => $key]);
        $edit = $this->get(route('app.setup.terminals.edit', ['branch' => self::BRANCH, 'key' => $key]))->assertOk()
            ->assertSee('PumpIT dbo.SS_Branch_SMARTATM_TerminalNo', false);
        foreach (['Number', 'Active', 'SMARTATM_TerminalNo'] as $never) {
            $edit->assertDontSee('name="'.$never.'"', false);
        }

        $this->assertSiteRefused('NOT_FOUND', 'agora.usp_Masters_SaveTerminal',
            array_replace($this->siteProc('Terminal', ['Description' => 'TEST-x']), ['Key' => 'BANK:'.self::SITE_NO]));
        $this->put($update, ['key' => $key, 'Description' => 'TEST-Terminal shop safe', 'ExpectedDescription' => 'TEST-Terminal',
            'IsActive' => 1, 'ExpectedIsActive' => 1, 'Reason' => 'TEST-name the safe it sits in'])->assertRedirect($show);
        $row = $this->db()->selectOne('SELECT Description, SMARTATM_TerminalNo, IsActive FROM [PumpIT].dbo.SS_Branch_SMARTATM_TerminalNo WHERE SMARTATMTerminalNoId = ?', [self::SITE_NO]);
        $this->assertSame(['TEST-Terminal shop safe', 'TEST99', true], [$row->Description, $row->SMARTATM_TerminalNo, (bool) $row->IsActive]);
        $write = $this->db()->table('agora.MasterWrite')->where('BranchId', self::BRANCH)->where('Entity', 'Terminal')->where('RowKey', $key)->sole();
        $this->assertSame(['Description', 'SS_Branch_SMARTATM_TerminalNo', 'Smart ATM terminal TEST99'], [$write->Field, $write->PumpItTable, $write->RowLabel]);

        $this->post(route('app.setup.terminals.writes.reverse', ['branch' => self::BRANCH, 'write' => $write->WriteId]),
            ['key' => $key, 'Reason' => 'TEST-back to the old name'])->assertRedirect($show);
        $this->assertSame('TEST-Terminal', $this->db()->selectOne('SELECT Description FROM [PumpIT].dbo.SS_Branch_SMARTATM_TerminalNo WHERE SMARTATMTerminalNoId = ?',
            [self::SITE_NO])->Description);
    }

    public function test_a_terminal_is_never_active_at_two_sites_and_the_confirmation_says_where_deposits_land(): void
    {
        $this->actingAs($this->person(['terminals.view', 'terminals.edit']));
        $here = 'SMARTATM:'.self::SITE_NO;
        $there = 'SMARTATM:'.self::OTHER_TERMINAL;
        $site = $this->db()->selectOne('SELECT Name FROM agora.Branch WHERE BranchId = ?', [self::BRANCH])->Name;
        $other = $this->db()->selectOne('SELECT Name FROM agora.Branch WHERE BranchId = ?', [self::OTHER_BRANCH])->Name;

        // The warning, in plain words, on the flag's own confirmation line.
        $this->get(route('app.setup.terminals.edit', ['branch' => self::BRANCH, 'key' => $here]))->assertOk()
            ->assertSee('data-diff-field="IsActive"', false)
            ->assertSee('data-diff-on="On (Smart ATM deposits under TEST99 are assigned to '.e($site).')"', false)
            ->assertSee('data-diff-off="Off (Smart ATM deposits under TEST99 are left unassigned)"', false);
        $this->get(route('app.setup.terminals.edit', ['branch' => self::OTHER_BRANCH, 'key' => $there]))->assertOk()
            ->assertSee('Smart ATM deposits under TEST99 are assigned to '.e($other), false);

        // A cash-device number has no flag to switch.
        $this->assertSiteRefused('NO_FLAG', 'agora.usp_Masters_SaveTerminal', array_replace($this->siteProc('Terminal'),
            ['Key' => 'CASHDEVICE:'.self::SITE_NO, 'IsActive' => 1, 'ExpectedIsActive' => 0]));

        // On at the other site while it is active here: refused, naming this site.
        $on = ['BranchId' => self::OTHER_BRANCH, 'Key' => $there, 'Description' => 'TEST-Terminal there', 'ExpectedDescription' => 'TEST-Terminal there',
            'IsActive' => 1, 'ExpectedIsActive' => 0, 'Reason' => 'TEST-the safe moved there', 'UserId' => null, 'Authorized' => 1, 'WriteMode' => 'live'];
        try {
            app(ProcedureService::class)->write('agora.usp_Masters_SaveTerminal', $on);
            $this->fail('A second active row under one number must be refused.');
        } catch (AgoraProcException $e) {
            $this->assertSame('TERMINAL_ACTIVE_ELSEWHERE', $e->code());
            $this->assertStringContainsString($site, $e->getMessage());
        }
        $this->assertFalse($this->otherActive());

        // Off here, then on there: one active row throughout.
        $this->put(route('app.setup.terminals.update', ['branch' => self::BRANCH]), ['key' => $here, 'Description' => 'TEST-Terminal',
            'ExpectedDescription' => 'TEST-Terminal', 'IsActive' => 0, 'ExpectedIsActive' => 1, 'Reason' => 'TEST-the safe is leaving'])->assertSessionHasNoErrors();
        $off = $this->db()->table('agora.MasterWrite')->where('BranchId', self::BRANCH)->where('Entity', 'Terminal')->where('RowKey', $here)->sole();
        $this->assertSame(['IsActive', '1', '0'], [$off->Field, $off->PriorValue, $off->NewValue]);
        app(ProcedureService::class)->write('agora.usp_Masters_SaveTerminal', $on);
        $this->assertTrue($this->otherActive());

        // Putting the switch-off back would make two active rows: refused.
        $this->post(route('app.setup.terminals.writes.reverse', ['branch' => self::BRANCH, 'write' => $off->WriteId]),
            ['key' => $here, 'Reason' => 'TEST-it never left'])->assertSessionHasErrors('refusal');
        $this->assertFalse((bool) $this->db()->selectOne('SELECT IsActive FROM [PumpIT].dbo.SS_Branch_SMARTATM_TerminalNo WHERE SMARTATMTerminalNoId = ?', [self::SITE_NO])->IsActive);

        // Once the other site lets it go, it goes back.
        $this->db()->statement('UPDATE [PumpIT].dbo.SS_Branch_SMARTATM_TerminalNo SET IsActive = 0 WHERE SMARTATMTerminalNoId = ?', [self::OTHER_TERMINAL]);
        $this->post(route('app.setup.terminals.writes.reverse', ['branch' => self::BRANCH, 'write' => $off->WriteId]),
            ['key' => $here, 'Reason' => 'TEST-it never left'])->assertSessionHasNoErrors();
        $this->assertTrue((bool) $this->db()->selectOne('SELECT IsActive FROM [PumpIT].dbo.SS_Branch_SMARTATM_TerminalNo WHERE SMARTATMTerminalNoId = ?', [self::SITE_NO])->IsActive);
    }

    private function otherActive(): bool
    {
        return (bool) $this->db()->selectOne('SELECT IsActive FROM [PumpIT].dbo.SS_Branch_SMARTATM_TerminalNo WHERE SMARTATMTerminalNoId = ?', [self::OTHER_TERMINAL])->IsActive;
    }

    /**
     * The save procedure's parameters for one of the site masters' TEST
     * rows, with every value as setUp left it and the given changes.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function siteProc(string $entity, array $changes = []): array
    {
        $now = match ($entity) {
            'Expense' => ['ExpenseDescription' => 'TEST-Expense', 'GLCode' => '71139999', 'Vat' => 15, 'IsActive' => 1],
            'Till' => ['TillDescription' => 'TEST-Till'],
            'Shift' => ['ShiftDescription' => 'TEST-Shift', 'IsActive' => 1],
            'TransactionType' => ['TypeDescription' => 'TEST-Type'],
            'FuelType' => ['FuelTypeDescription' => 'TEST-Fuel'],
            'Tank' => ['TankDescription' => 'TEST-Tank'],
            'Pump' => ['PumpOrder' => 7, 'IsActive' => 1],
            'UtilityMeter' => ['MeterDescription' => 'TEST-Meter'],
            'Terminal' => ['Description' => 'TEST-Terminal', 'IsActive' => 1],
            default => throw new \InvalidArgumentException("No TEST row for {$entity}."),
        };
        $expected = [];
        foreach ($now as $name => $value) {
            $expected['Expected'.$name] = $value;
        }

        return array_replace($now, $changes) + $expected + ['BranchId' => self::BRANCH, 'Key' => (string) self::SITE_NO,
            'Reason' => 'TEST-site master change', 'UserId' => null, 'Authorized' => 1, 'WriteMode' => 'live'];
    }

    /** @return array<string, mixed> */
    private function reservedExpenseOff(): array
    {
        $row = $this->db()->selectOne("SELECT ExpenseNo, GLCode, Vat FROM [PumpIT].dbo.BRN_Expenses WHERE SSBranchId = ? AND ExpenseDescription = 'Till Balance Control'", [self::BRANCH]);
        $this->assertNotNull($row, 'The stub carries the site\'s Till Balance Control expense.');

        return ['BranchId' => self::BRANCH, 'Key' => (string) $row->ExpenseNo, 'ExpenseDescription' => 'Till Balance Control', 'GLCode' => $row->GLCode,
            'Vat' => $row->Vat, 'IsActive' => 0, 'ExpectedExpenseDescription' => 'Till Balance Control', 'ExpectedGLCode' => $row->GLCode,
            'ExpectedVat' => $row->Vat, 'ExpectedIsActive' => 1, 'Reason' => 'TEST-switch off the control', 'UserId' => null, 'Authorized' => 1, 'WriteMode' => 'live'];
    }

    /** @param  array<string, mixed>  $parameters */
    private function assertSiteRefused(string $code, string $procedure, array $parameters): void
    {
        try {
            app(ProcedureService::class)->write($procedure, $parameters);
            $this->fail("{$procedure} must refuse with {$code}.");
        } catch (AgoraProcException $e) {
            $this->assertSame($code, $e->code());
        }
    }

    /** @return Collection<int, \stdClass> */
    private function siteLedger(string $entity): Collection
    {
        return $this->db()->table('agora.MasterWrite')->where('BranchId', self::BRANCH)->where('Entity', $entity)
            ->where('RowKey', (string) self::SITE_NO)->orderBy('Id')->get();
    }

    /** @param  array<string, mixed>  $parameters */
    private function assertRefused(string $code, array $parameters): void
    {
        try {
            app(ProcedureService::class)->write('agora.usp_Masters_SaveCategoryBand', $parameters);
            $this->fail("The save must be refused with {$code}.");
        } catch (AgoraProcException $e) {
            $this->assertSame($code, $e->code());
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function input(array $overrides = []): array
    {
        return array_replace([
            'key' => self::KEY, 'MinGP' => 10.8, 'MaxGP' => 12, 'MinDaysCover' => 0, 'MaxDaysCover' => 0, 'IsActive' => 1,
            'ExpectedMinGP' => '10.8000', 'ExpectedMaxGP' => '12.0000', 'ExpectedMinDaysCover' => '0.00', 'ExpectedMaxDaysCover' => '0.00',
            'ExpectedIsActive' => 1, 'Reason' => 'TEST-raise the bread band',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function procInput(): array
    {
        return ['BranchId' => self::BRANCH, 'Key' => self::KEY, 'MinGP' => 25, 'MaxGP' => 30, 'MinDaysCover' => 0, 'MaxDaysCover' => 0,
            'IsActive' => 1, 'ExpectedMinGP' => 10.8, 'ExpectedMaxGP' => 12, 'ExpectedMinDaysCover' => 0, 'ExpectedMaxDaysCover' => 0,
            'ExpectedIsActive' => 1, 'Reason' => 'TEST-raise the bread band', 'UserId' => null, 'Authorized' => 1, 'WriteMode' => 'live'];
    }

    private function pumpit(): object
    {
        $row = $this->db()->selectOne("SELECT MINGP, MAXGP, MINDaysCover, MAXDaysCover, IsActive FROM [PumpIT].dbo.SDK_CategoryGLCode
            WHERE SSBranchId = ? AND POSSystemId = 5 AND Category = 'TEST-BAND '", [self::BRANCH]);
        $row->MINGP = (float) $row->MINGP;
        $row->MAXGP = (float) $row->MAXGP;
        $row->MINDaysCover = (float) $row->MINDaysCover;
        $row->MAXDaysCover = (float) $row->MAXDaysCover;

        return $row;
    }

    /** @return Collection<int, \stdClass> */
    private function ledger(): Collection
    {
        return $this->db()->table('agora.MasterWrite')->where('BranchId', self::BRANCH)->where('Entity', 'CategoryGl')
            ->where('RowKey', self::KEY)->orderBy('Id')->get();
    }

    private function showUrl(): string
    {
        return route('app.setup.categories.show', ['branch' => self::BRANCH, 'key' => self::KEY]);
    }

    private function editUrl(): string
    {
        return route('app.setup.categories.edit', ['branch' => self::BRANCH, 'key' => self::KEY]);
    }

    private function updateUrl(): string
    {
        return route('app.setup.categories.update', ['branch' => self::BRANCH]);
    }

    private function reverseUrl(string $write): string
    {
        return route('app.setup.categories.writes.reverse', ['branch' => self::BRANCH, 'write' => $write]);
    }

    /** @param  array<int, string>  $actions  resource.action under masters. */
    private function person(array $actions): User
    {
        $user = User::query()->acrossBranches()->create([
            'BranchId' => 2, 'EmailAddress' => 'TEST-master-write-'.Str::uuid().'@agora.invalid', 'UserName' => 'TEST-Master write',
            'IsActive' => true, 'IsLocked' => false, 'Workspace' => 'ho', 'PasswordHash' => 'TEST-only-'.Str::random(24),
        ]);
        $this->users[] = $user;
        foreach ($actions as $action) {
            $permission = $this->db()->table('agora.Permission')->where('Code', 'masters.'.$action)->value('Id');
            $this->assertNotNull($permission, "masters.{$action} is seeded");
            $this->db()->table('agora.UserPermission')->insert(['BranchId' => 2, 'UserId' => $user->Id, 'PermissionId' => $permission]);
        }
        app(PermissionService::class)->forget($user);
        app(BranchContext::class)->setWorkspace('ho')->setAllowed([]);

        return $user;
    }

    private function cleanUp(): void
    {
        $this->db()->table('agora.MasterWrite')->where('BranchId', self::BRANCH)->where('RowKey', (string) self::SITE_NO)->delete();
        $this->db()->statement("DELETE FROM [PumpIT].dbo.BRN_TransactionLine WHERE SSBranchId = ? AND TransactionNo = 'TEST-PR1'", [self::BRANCH]);
        $this->db()->statement("DELETE FROM [PumpIT].dbo.BRN_Transaction WHERE SSBranchId = ? AND TransactionNo = 'TEST-PR1'", [self::BRANCH]);
        $this->db()->table('agora.MasterWrite')->where('BranchId', self::BRANCH)->where('Entity', 'TransactionType')->where('RowKey', self::TYPE)->delete();
        $this->db()->table('agora.MasterWrite')->where('BranchId', self::BRANCH)->whereIn('Entity', ['Tank', 'Pump'])->where('RowKey', self::FORECOURT)->delete();
        $this->db()->statement('DELETE FROM [PumpIT].dbo.BRN_Pump WHERE SSBranchId = ? AND PumpNo = ?', [self::BRANCH, self::FORECOURT]);
        $this->db()->statement('DELETE FROM [PumpIT].dbo.BRN_Tank WHERE SSBranchId = ? AND TankNo = ?', [self::BRANCH, self::FORECOURT]);
        $this->db()->table('agora.MasterWrite')->where('BranchId', self::BRANCH)->where('Entity', 'Terminal')->where('RowKey', 'SMARTATM:'.self::SITE_NO)->delete();
        $this->db()->statement('DELETE FROM [PumpIT].dbo.BRN_UtilityMeter WHERE UtilityMeterId = ?', [self::SITE_NO]);
        $this->db()->table('agora.MasterWrite')->where('BranchId', self::OTHER_BRANCH)->where('Entity', 'Terminal')->where('RowKey', 'SMARTATM:'.self::OTHER_TERMINAL)->delete();
        $this->db()->statement('DELETE FROM [PumpIT].dbo.SS_Branch_SMARTATM_TerminalNo WHERE SMARTATMTerminalNoId IN (?, ?)', [self::SITE_NO, self::OTHER_TERMINAL]);
        $this->db()->statement('DELETE FROM [PumpIT].dbo.BRN_TransactionType WHERE SSBranchId = ? AND TypeCode = ?', [self::BRANCH, self::TYPE]);
        foreach (['BRN_Expenses' => 'ExpenseNo', 'BRN_Tills' => 'TillNo', 'BRN_Shifts' => 'ShiftNo', 'BRN_FuelType' => 'FuelTypeNo'] as $table => $key) {
            $this->db()->statement("DELETE FROM [PumpIT].dbo.{$table} WHERE SSBranchId = ? AND {$key} = ?", [self::BRANCH, self::SITE_NO]);
        }
        $this->db()->table('agora.MasterWrite')->whereIn('Entity', ['ProfitCentre', 'StandardCategory'])->where('RowKey', (string) self::CENTRE)->delete();
        $this->db()->statement('DELETE FROM [PumpIT].dbo.SS_ProfitCentre WHERE ProfitCentreId = ?', [self::CENTRE]);
        $this->db()->statement('DELETE FROM [PumpIT].dbo.SS_StandardCategory WHERE StandardCategoryId = ?', [self::STANDARD]);
        $this->db()->table('agora.MasterWrite')->where('Entity', 'CategoryGl')->where('RowKey', self::KEY)->delete();
        $this->db()->statement("DELETE FROM [PumpIT].dbo.SDK_CategoryGLCode WHERE SSBranchId = ? AND POSSystemId = 5 AND Category = 'TEST-BAND '", [self::BRANCH]);
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }
}
