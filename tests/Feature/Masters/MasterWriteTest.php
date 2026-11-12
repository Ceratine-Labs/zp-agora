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

    /** @return Collection<int, object> */
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
