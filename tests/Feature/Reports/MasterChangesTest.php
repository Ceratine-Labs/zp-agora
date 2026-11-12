<?php

namespace Tests\Feature\Reports;

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
 * Setup → Governance (T027): the master data change log and user activity.
 *
 * The acceptance: changing a category's MinGP through
 * usp_Masters_SaveCategoryBand shows in the Master data changes grid with
 * before/after, the user and the reason. Also an Agora-only change (a site's
 * own setting value, agora.MasterChange), the overrides Agora holds over
 * PumpIT's rows (stock lines, a batch flag, critical lines, bank recon rules),
 * a site master's label (Reports v1__22q), the branch scope, the refusal, the
 * screens and the menu.
 *
 * WRITES THE LOCAL CONTAINER AND PUMPIT STUB ONLY — every connection is pinned
 * to the container and asserted loopback. Its rows are its own: category
 * 'TEST-MCHG ' and till 9902 at branch 13 in the stub; TEST- branch 979 with
 * its setting value, stock lines, critical line and recon rule in Agora and
 * its stock master, area and POS rows in the stub; a TEST- user and that
 * user's activity. All are
 * deleted, with their ledger rows, in tearDown.
 */
class MasterChangesTest extends TestCase
{
    private const BRANCH = 13;

    private const KEY = '5:TEST-MCHG';

    private const TILL = 9902;

    private const SITE = 979;

    private const SETTING = 'cash.cashup.variance_tolerance';

    private ?User $user = null;

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
        $this->cleanUp();

        $this->db()->statement("INSERT [PumpIT].dbo.SDK_CategoryGLCode (SSBranchId, POSSystemId, Category, StandardCategory, ProfitCentre,
            IsActive, MINGP, MAXGP, MINDaysCover, MAXDaysCover) VALUES (?, 5, 'TEST-MCHG ', 'Bread', 'Conv Shop', 1, 10.800000000000001, 12, 0, 0)", [self::BRANCH]);
        $this->db()->statement("INSERT [PumpIT].dbo.BRN_Tills (SSBranchId, TillNo, TillDescription, Location) VALUES (?, ?, 'TEST-Till', 'WINBRANCH')",
            [self::BRANCH, self::TILL]);
        $this->db()->table('agora.Branch')->insert([
            'BranchId' => self::SITE, 'Name' => 'TEST-Master changes site', 'IsTrading' => 1, 'IsActive' => 1, 'SortOrder' => self::SITE,
            'Status' => 'Trading', 'POSType' => 'BRANCH', 'IsAdminEntity' => 0, 'CreatedAt' => now(),
        ]);
        $this->user = User::query()->acrossBranches()->create([
            'BranchId' => 2, 'EmailAddress' => 'TEST-master-changes-'.Str::uuid().'@agora.invalid', 'UserName' => 'TEST-Master changes',
            'IsActive' => true, 'IsLocked' => false, 'Workspace' => 'ho', 'PasswordHash' => 'TEST-only-'.Str::random(24),
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->safe) {
            $this->cleanUp();
        }
        parent::tearDown();
    }

    public function test_a_category_min_gp_change_shows_with_before_after_the_user_and_the_reason(): void
    {
        $saved = $this->procedures()->write('agora.usp_Masters_SaveCategoryBand', [
            'BranchId' => self::BRANCH, 'Key' => self::KEY, 'MinGP' => 25, 'MaxGP' => 30, 'MinDaysCover' => 0, 'MaxDaysCover' => 0,
            'IsActive' => 1, 'ExpectedMinGP' => 10.8, 'ExpectedMaxGP' => 12, 'ExpectedMinDaysCover' => 0, 'ExpectedMaxDaysCover' => 0,
            'ExpectedIsActive' => 1, 'Reason' => 'TEST-raise the bread band', 'UserId' => (int) $this->user->Id, 'Authorized' => 1, 'WriteMode' => 'live',
        ]);
        $this->assertSame(1, (int) $saved->Ok, (string) ($saved->Message ?? ''));

        [$rows, $total] = $this->masterChanges(['BranchIds' => (string) self::BRANCH, 'Search' => 'TEST-MCHG']);
        $this->assertSame(2, $total, 'One row per column changed: MINGP and MAXGP, not the three sent unchanged.');
        $pairs = [];
        foreach ($rows as $changed) {
            $pairs[$changed->Field] = [$changed->PriorValue, $changed->NewValue];
        }
        ksort($pairs);
        $this->assertSame(['MAXGP' => ['12', '30'], 'MINGP' => ['10.8', '25']], $pairs);
        $row = $rows->firstWhere('Field', 'MINGP');
        $this->assertSame(
            ['Category band', 'Category band changed', 'MINGP', '10.8', '25', 'MINGP 10.8 → 25', 'TEST-raise the bread band', 'TEST-Master changes', 'PumpIT write-back', 'active', 'SDK_CategoryGLCode', 'MasterWrite'],
            [$row->Entity, $row->Action, $row->Field, $row->PriorValue, $row->NewValue, $row->Change, $row->Reason, $row->ChangedBy, $row->Source, $row->State, $row->PumpItTable, $row->Ledger]
        );
        $this->assertStringContainsString('TEST-MCHG', $row->RowKey);

        // Typed header filters narrow in the procedure: a set on the master, a number on the ledger row, a date on the day.
        $filters = [
            ['column' => 'Entity', 'type' => 'set', 'in' => ['Category band']],
            ['column' => 'LedgerId', 'type' => 'number', 'op' => 'eq', 'value' => (string) $row->LedgerId],
            ['column' => 'ChangedAt', 'type' => 'date', 'op' => 'eq', 'value' => substr((string) $row->ChangedAt, 0, 10)],
        ];
        $this->assertSame(1, $this->masterChanges(['BranchIds' => (string) self::BRANCH, 'FiltersJson' => json_encode($filters)])[1]);
        $this->assertSame(0, $this->masterChanges(['BranchIds' => (string) self::BRANCH, 'Search' => 'TEST-MCHG',
            'FiltersJson' => json_encode([['column' => 'Entity', 'type' => 'set', 'in' => ['Setting']]])])[1]);
        $this->assertSame(1, $this->masterChanges(['BranchIds' => (string) self::BRANCH, 'Search' => 'TEST-MCHG',
            'FiltersJson' => json_encode([['column' => 'Field', 'type' => 'text', 'op' => 'eq', 'value' => 'MAXGP']])])[1]);
        $this->assertSame(0, $this->masterChanges(['BranchIds' => (string) self::BRANCH, 'Search' => 'TEST-MCHG',
            'FiltersJson' => json_encode([['column' => 'Field', 'type' => 'text', 'op' => 'eq', 'value' => 'MINDaysCover']])])[1]);

        // The branch scope: another site picked, or the caller not allowed this one, and it is gone.
        $this->assertSame(0, $this->masterChanges(['BranchIds' => '14', 'Search' => 'TEST-MCHG'])[1]);
        $this->assertSame(0, $this->masterChanges(['AllowedBranchIds' => '14', 'Search' => 'TEST-MCHG'])[1]);
        $this->assertSame(2, $this->masterChanges(['AllowedBranchIds' => '14,'.self::BRANCH, 'Search' => 'TEST-MCHG'])[1]);

        // Put back, and the same row reads as reversed.
        $write = $this->db()->table('agora.MasterWrite')->where('Id', $row->LedgerId)->value('WriteId');
        $this->procedures()->write('agora.usp_Masters_ReverseWrite', ['BranchId' => self::BRANCH, 'WriteId' => $write,
            'Reason' => 'TEST-put the band back', 'UserId' => (int) $this->user->Id, 'AuthorizedEntities' => 'CategoryGl', 'WriteMode' => 'live']);
        $this->assertSame(['reversed'], $this->masterChanges(['BranchIds' => (string) self::BRANCH, 'Search' => 'TEST-MCHG'])[0]
            ->pluck('State')->unique()->values()->all());
    }

    public function test_an_agora_only_setting_change_shows_from_master_change(): void
    {
        $saved = $this->procedures()->write('agora.usp_Core_SaveSetting', [
            'SettingKey' => self::SETTING, 'BranchId' => self::SITE, 'Mode' => 'create', 'Value' => '250',
            'Reason' => 'TEST-this site runs a bigger float', 'UserId' => (int) $this->user->Id, 'Authorized' => 1,
        ]);
        $this->assertSame(1, (int) $saved->Ok, (string) ($saved->Message ?? ''));

        [$rows, $total] = $this->masterChanges(['BranchIds' => (string) self::SITE]);
        $this->assertSame(1, $total);
        $row = $rows->sole();
        $this->assertSame(
            ['TEST-Master changes site', 'Setting', 'Site value added', self::SETTING, 'Override', null, '250.00', 'Override blank → 250.00', 'TEST-this site runs a bigger float', 'TEST-Master changes', 'Agora', 'MasterChange'],
            [$row->BranchName, $row->Entity, $row->Action, $row->RowKey, $row->Field, $row->PriorValue, $row->NewValue, $row->Change, $row->Reason, $row->ChangedBy, $row->Source, $row->Ledger]
        );
        $this->assertSame(1, $this->masterChanges(['BranchIds' => (string) self::SITE,
            'FiltersJson' => json_encode([['column' => 'Source', 'type' => 'set', 'in' => ['Agora']]])])[1]);
        $this->assertSame(0, $this->masterChanges(['BranchIds' => (string) self::SITE,
            'FiltersJson' => json_encode([['column' => 'Source', 'type' => 'set', 'in' => ['PumpIT write-back']]])])[1]);
    }

    public function test_a_site_master_reads_by_its_own_name_not_its_entity_code(): void
    {
        $this->procedures()->write('agora.usp_Masters_SaveTill', [
            'BranchId' => self::BRANCH, 'Key' => (string) self::TILL, 'TillDescription' => 'TEST-Till front', 'ExpectedTillDescription' => 'TEST-Till',
            'Reason' => 'TEST-the front till', 'UserId' => (int) $this->user->Id, 'Authorized' => 1, 'WriteMode' => 'live',
        ]);
        $id = $this->db()->table('agora.MasterWrite')->where('BranchId', self::BRANCH)->where('Entity', 'Till')->where('RowKey', (string) self::TILL)->value('Id');
        $audit = $this->db()->selectOne("SELECT Action FROM agora.vw_PumpItChange WHERE Ledger = 'MasterWrite' AND LedgerId = ?", [$id]);
        $this->assertSame('Till changed', $audit->Action, 'Reports v1__22q: the audit trail names the master.');

        $row = $this->masterChanges(['BranchIds' => (string) self::BRANCH, 'Search' => 'TEST-the front till'])[0]->sole();
        $this->assertSame(['Till', 'Till changed', 'TillDescription', 'TEST-Till', 'TEST-Till front'],
            [$row->Entity, $row->Action, $row->Field, $row->PriorValue, $row->NewValue]);
    }

    public function test_a_stock_line_override_logs_each_field_it_moved_and_a_park_logs_the_way_back(): void
    {
        $this->seedStockLine('T1', '1200');
        $line = ['BranchId' => self::SITE, 'StockItemNo' => 'T1', 'Action' => 'save', 'StockItemDescription' => 'TEST-PIE CHICKEN 200G',
            'AreaNo' => 1, 'PosSystem' => 'WINBRANCH', 'POSCode' => '1200', 'SellingPrice' => 31.5, 'PriceType' => 'Set Price', 'Factor' => 0,
            'UOMCode' => 'Each', 'IssueMultiple' => 1, 'IssueMultiplePercentage' => 0, 'QtyVarAllowance' => 0, 'IsMonitoredItem' => 0,
            'IsDoCloseQtyCalc' => 1, 'IsAllowNegativeQtyIssued' => 0, 'IsAllowNegativeQtyClose' => 0, 'IsStockItemPreProduction' => 0,
            'IsPreProductionItem' => 0, 'PreProductionTypeNo' => 0, 'Ratio' => 0, 'ProduceLimitPercentage' => 0, 'IsActive' => 1,
            'Reason' => 'TEST-the 200g pack', 'UserId' => (int) $this->user->Id];
        $this->assertSame('CREATED', $this->procedures()->write('agora.usp_Product_SaveStockItem', $line)->Code);

        $changes = $this->changesOf('Stock line');
        $this->assertSame(['Override', 'SellingPrice', 'StockItemDescription'], array_keys($changes), 'Only what moved: the other nineteen were sent as they were.');
        $this->assertSame(['None', 'In force', 'Stock line override added'], $changes['Override']);
        $this->assertSame(['TEST-PIE CHICKEN', 'TEST-PIE CHICKEN 200G', 'Stock line changed'], $changes['StockItemDescription']);
        $this->assertEqualsWithDelta([29.99, 31.5], [(float) $changes['SellingPrice'][0], (float) $changes['SellingPrice'][1]], 0.0001);
        $row = $this->masterChanges(['BranchIds' => (string) self::SITE, 'Search' => 'StockItemDescription'])[0]->sole();
        $this->assertSame(['T1 · TEST-PIE CHICKEN 200G', 'TEST-the 200g pack', 'TEST-Master changes', 'Agora', 'MasterChange'],
            [$row->RowKey, $row->Reason, $row->ChangedBy, $row->Source, $row->Ledger]);

        $this->procedures()->write('agora.usp_Product_SaveStockItem', ['BranchId' => self::SITE, 'StockItemNo' => 'T1', 'Action' => 'park',
            'Reason' => 'TEST-back to the customer row', 'UserId' => (int) $this->user->Id]);
        $parked = $this->changesOf('Stock line', 'TEST-back to the customer row');
        $this->assertSame(['In force', 'Parked', 'Stock line override parked'], $parked['Override']);
        $this->assertSame(['TEST-PIE CHICKEN 200G', 'TEST-PIE CHICKEN'], array_slice($parked['StockItemDescription'], 0, 2));
    }

    public function test_a_batch_flag_logs_one_row_per_line_whose_flag_moved(): void
    {
        $this->seedStockLine('T2', '1201');
        $this->seedStockLine('T3', '1202');
        $this->procedures()->write('agora.usp_Product_SetStockItemFlag', [
            'ItemsJson' => json_encode([['BranchId' => self::SITE, 'StockItemNo' => 'T2'], ['BranchId' => self::SITE, 'StockItemNo' => 'T3']]),
            'Flag' => 'IsMonitoredItem', 'Value' => 1, 'Reason' => 'TEST-monitor the pies', 'UserId' => (int) $this->user->Id,
        ]);

        [$rows, $total] = $this->masterChanges(['BranchIds' => (string) self::SITE, 'Search' => 'TEST-monitor the pies']);
        $this->assertSame(4, $total, 'Two lines, each: the override it became and the flag.');
        $byLine = [];
        foreach ($rows as $row) {
            $byLine[$row->RowKey][$row->Field] = [$row->PriorValue, $row->NewValue];
        }
        ksort($byLine);
        $this->assertSame([
            'T2 · TEST-PIE CHICKEN' => ['IsMonitoredItem' => ['0', '1'], 'Override' => ['None', 'In force']],
            'T3 · TEST-PIE CHICKEN' => ['IsMonitoredItem' => ['0', '1'], 'Override' => ['None', 'In force']],
        ], array_map(function (array $fields): array {
            ksort($fields);

            return $fields;
        }, $byLine));

        // Switched on again: nothing moved, nothing logged.
        $this->procedures()->write('agora.usp_Product_SetStockItemFlag', [
            'ItemsJson' => json_encode([['BranchId' => self::SITE, 'StockItemNo' => 'T2']]),
            'Flag' => 'IsMonitoredItem', 'Value' => 1, 'Reason' => 'TEST-monitor it again', 'UserId' => (int) $this->user->Id,
        ]);
        $this->assertSame(0, $this->masterChanges(['BranchIds' => (string) self::SITE, 'Search' => 'TEST-monitor it again'])[1]);
    }

    public function test_a_critical_line_logs_its_fields_and_taking_it_off_the_list(): void
    {
        $this->db()->table('PumpIT.dbo.DBF_STDB')->insert(['SSBranchId' => self::SITE, 'CODE' => '1200', 'LOCATION' => 'WINBRANCH', 'DESC' => 'TEST-PIE CHICKEN',
            'CAT' => 'TEST', 'STDCOST' => 10, 'STDSELL' => 15, 'QTY' => 4, 'M_SOLD' => 0, 'L_SOLD' => null, 'PACKSIZE' => 1, 'VATCODE' => 'A']);
        $critical = ['BranchId' => self::SITE, 'PosSystem' => 'WINBRANCH', 'PosCode' => '1200', 'Description' => 'TEST-CRITICAL PIE',
            'Reason' => 'TEST-never out of pies', 'UserId' => (int) $this->user->Id];
        $this->procedures()->write('agora.usp_Product_SaveCriticalLine', $critical + ['Action' => 'save']);

        $added = $this->changesOf('Critical line', 'TEST-never out of pies');
        $this->assertSame(['None', 'In force', 'Critical line override added'], $added['Override']);
        $this->assertSame([null, 'TEST-CRITICAL PIE'], array_slice($added['Description'], 0, 2), 'Nothing on the customer\'s list before.');
        $this->assertSame([null, 'TEST'], array_slice($added['Category'], 0, 2), 'The category taken from the POS file.');
        $row = $this->masterChanges(['BranchIds' => (string) self::SITE, 'Search' => 'TEST-never out of pies',
            'FiltersJson' => json_encode([['column' => 'Field', 'type' => 'text', 'op' => 'eq', 'value' => 'Description']])])[0]->sole();
        $this->assertSame('WINBRANCH 1200 · TEST-CRITICAL PIE', $row->RowKey);

        $this->procedures()->write('agora.usp_Product_SaveCriticalLine', array_replace($critical, ['Reason' => 'TEST-pies are seasonal']) + ['Action' => 'remove']);
        $this->assertSame(['IsActive' => ['1', '0', 'Critical line changed']], $this->changesOf('Critical line', 'TEST-pies are seasonal'));
    }

    public function test_a_recon_rule_override_logs_its_positions_and_a_park_logs_them_going(): void
    {
        $rule = ['BranchId' => self::SITE, 'ReconArea' => 'FNB', 'ProcessOrder' => 1, 'BankStartPosition' => 1, 'BankEndPosition' => 10,
            'UserId' => (int) $this->user->Id];
        $this->procedures()->write('agora.usp_Recon_SaveCriteria', $rule + ['Action' => 'save', 'Reason' => 'TEST-the reference is ten long']);

        $this->assertSame([
            'BANK_EndPosition' => [null, '10', 'Recon rule changed'],
            'BANK_StartPosition' => [null, '1', 'Recon rule changed'],
            'Override' => ['None', 'In force', 'Recon rule override added'],
        ], $this->sorted($this->changesOf('Recon rule', 'TEST-the reference is ten long')), 'A rule the site never had: every position from nothing.');
        $row = $this->masterChanges(['BranchIds' => (string) self::SITE, 'Search' => 'TEST-the reference is ten long'])[0]->first();
        $this->assertSame('FNB rule 1', $row->RowKey);

        $this->procedures()->write('agora.usp_Recon_SaveCriteria', array_replace($rule, ['BankEndPosition' => 12]) + ['Action' => 'save', 'Reason' => 'TEST-twelve after all']);
        $this->assertSame(['BANK_EndPosition' => ['10', '12', 'Recon rule changed']], $this->changesOf('Recon rule', 'TEST-twelve after all'));

        $this->procedures()->write('agora.usp_Recon_SaveCriteria', $rule + ['Action' => 'park', 'Reason' => 'TEST-park the rule']);
        $parked = $this->sorted($this->changesOf('Recon rule', 'TEST-park the rule'));
        $this->assertSame(['In force', 'Parked', 'Recon rule override parked'], $parked['Override']);
        $this->assertSame(['12', null], array_slice($parked['BANK_EndPosition'], 0, 2), 'Parked, and the site has no rule of its own to fall back to.');
    }

    public function test_the_procedures_refuse_without_the_permission(): void
    {
        foreach (['agora.usp_Reports_GridMasterChanges', 'agora.usp_Reports_GridUserActivity'] as $procedure) {
            try {
                $this->procedures()->callSets($procedure, ['Authorized' => 0]);
                $this->fail("{$procedure} must refuse without @Authorized.");
            } catch (AgoraProcException $e) {
                $this->assertSame('DENIED', $e->code());
            }
        }
    }

    public function test_user_activity_lists_a_sign_in_with_the_person(): void
    {
        $this->procedures()->write('usp_Core_LogActivity', ['BranchId' => 2, 'UserId' => (int) $this->user->Id, 'Activity' => 'signin',
            'Detail' => 'TEST-sign-in for the grid', 'IpAddress' => '127.0.0.1', 'UserAgent' => 'TEST-agent', 'OccurredAt' => now()->format('Y-m-d H:i:s')]);

        $sets = $this->procedures()->callSets('agora.usp_Reports_GridUserActivity', ['Authorized' => 1,
            'FiltersJson' => json_encode([['column' => 'UserName', 'type' => 'text', 'op' => 'eq', 'value' => 'TEST-Master changes']])]);
        $this->assertSame(1, (int) $sets[1]->first()->TotalRows);
        $row = $sets[0]->sole();
        $this->assertSame(['Signed in', 'signin', 'TEST-sign-in for the grid', '127.0.0.1', 'Zululand Petroleum'],
            [$row->Activity, $row->ActivityCode, $row->Detail, $row->IpAddress, $row->BranchName]);

        $refused = $this->procedures()->callSets('agora.usp_Reports_GridUserActivity', ['Authorized' => 1, 'Search' => 'TEST-sign-in for the grid',
            'FiltersJson' => json_encode([['column' => 'Activity', 'type' => 'set', 'in' => ['Sign-in refused']]])]);
        $this->assertSame(0, (int) $refused[1]->first()->TotalRows);
    }

    public function test_both_screens_render_under_setup_governance_behind_their_permissions(): void
    {
        $this->grant(['reports.master-changes.view', 'reports.user-activity.view']);
        $this->actingAs($this->user);
        app(BranchContext::class)->setWorkspace('ho')->setAllowed([]);

        $this->get(route('app.setup.master-changes.index'))->assertOk()
            ->assertSee('Master data changes')->assertSee('agora.usp_Reports_GridMasterChanges')
            ->assertSee('TEST-Master changes site')                                   // the branches selector offers the sites
            ->assertSee('-extract"', false)                                           // the extract drawer
            ->assertSee('Before')->assertSee('After')->assertSee('Why');
        $this->get(route('app.setup.user-activity.index'))->assertOk()
            ->assertSee('User activity')->assertSee('agora.usp_Reports_GridUserActivity');

        $menu = $this->db()->select("SELECT i.Path, i.RouteName, i.PermissionCode FROM agora.MenuItem i JOIN agora.MenuSection s ON s.Id = i.SectionId
            WHERE s.Workspace = 'ho' AND s.Code = 'setup' AND i.Path LIKE 'governance/%' ORDER BY i.SortOrder");
        $this->assertSame([
            ['governance/master-changes', 'app.setup.master-changes.index', 'reports.master-changes.view'],
            ['governance/user-activity', 'app.setup.user-activity.index', 'reports.user-activity.view'],
        ], array_map(fn ($m) => [$m->Path, $m->RouteName, $m->PermissionCode], $menu), 'GovernanceReportsSeeder has run here.');
    }

    public function test_without_the_permission_the_screens_are_forbidden(): void
    {
        $this->grant(['reports.user-activity.view']);
        $this->actingAs($this->user);
        app(BranchContext::class)->setWorkspace('ho')->setAllowed([]);

        $this->get(route('app.setup.master-changes.index'))->assertForbidden();
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{0: Collection<int, object>, 1: int}
     */
    private function masterChanges(array $parameters): array
    {
        $sets = $this->procedures()->callSets('agora.usp_Reports_GridMasterChanges', $parameters + ['Authorized' => 1]);

        return [$sets[0], (int) $sets[1]->first()->TotalRows];
    }

    /**
     * One master's changes on the TEST- site, by field: [before, after, action].
     *
     * @return array<string, array{0: ?string, 1: ?string, 2: string}>
     */
    private function changesOf(string $entity, ?string $reason = null): array
    {
        $rows = $this->masterChanges(['BranchIds' => (string) self::SITE, 'Search' => $reason,
            'FiltersJson' => json_encode([['column' => 'Entity', 'type' => 'set', 'in' => [$entity]]])])[0];
        $changes = [];
        foreach ($rows->sortBy('LedgerId') as $row) {
            if ($reason === null || $row->Reason === $reason) {
                $changes[$row->Field] = [$row->PriorValue, $row->NewValue, $row->Action];
            }
        }
        ksort($changes);

        return $changes;
    }

    /**
     * @param  array<string, array{0: ?string, 1: ?string, 2: string}>  $changes
     * @return array<string, array{0: ?string, 1: ?string, 2: string}>
     */
    private function sorted(array $changes): array
    {
        ksort($changes);

        return $changes;
    }

    /** A customer stock line at the TEST- site, in its TEST- area. */
    private function seedStockLine(string $itemNo, string $posCode): void
    {
        if (! $this->db()->table('PumpIT.dbo.STK_Area')->where('SSBranchId', self::SITE)->exists()) {
            $this->db()->table('PumpIT.dbo.STK_Area')->insert(['SSBranchId' => self::SITE, 'AreaNo' => 1, 'AreaDescription' => 'TEST-Shop floor',
                'AreaGroup' => 'Retail Items', 'DayShift' => 1, 'AfternoonShift' => 0, 'NightShift' => 0, 'ShowReport' => 1, 'IsCaptureWaste' => 0]);
        }
        $this->db()->table('PumpIT.dbo.STK_StockMaster')->insert(['SSBranchId' => self::SITE, 'StockItemNo' => $itemNo,
            'StockItemDescription' => 'TEST-PIE CHICKEN', 'AreaNo' => 1, 'Location' => 'WINBRANCH', 'POSCode' => $posCode, 'SellingPrice' => 29.99,
            'PriceType' => 'Set Price', 'Factor' => 0, 'UOMCode' => 'Each', 'IssueMultiple' => 1, 'IssueMultiplePercentage' => 0, 'QtyVarAllowance' => 0,
            'isMonitoredItem' => 0, 'IsDoCloseQtyCalc' => 1, 'IsAllowNegativeQtyIssued' => 0, 'IsAllowNegativeQtyClose' => 0,
            'IsStockItemPreProduction' => 0, 'isPreProductionItem' => 0, 'PreProductionTypeNo' => 0, 'Ratio' => 0, 'ProduceLimitPercentage' => 0,
            'CreateDateTime' => '2026-01-01 00:00:00']);
    }

    /** @param  array<int, string>  $codes */
    private function grant(array $codes): void
    {
        foreach ($codes as $code) {
            $permission = $this->db()->table('agora.Permission')->where('Code', $code)->value('Id');
            $this->assertNotNull($permission, "{$code} is seeded (GovernanceReportsSeeder)");
            $this->db()->table('agora.UserPermission')->insert(['BranchId' => 2, 'UserId' => $this->user->Id, 'PermissionId' => $permission]);
        }
        app(PermissionService::class)->forget($this->user);
    }

    private function cleanUp(): void
    {
        $this->db()->table('agora.MasterWrite')->where('BranchId', self::BRANCH)->where('Entity', 'CategoryGl')->where('RowKey', self::KEY)->delete();
        $this->db()->table('agora.MasterWrite')->where('BranchId', self::BRANCH)->where('Entity', 'Till')->where('RowKey', (string) self::TILL)->delete();
        $this->db()->statement("DELETE FROM [PumpIT].dbo.SDK_CategoryGLCode WHERE SSBranchId = ? AND POSSystemId = 5 AND Category = 'TEST-MCHG '", [self::BRANCH]);
        $this->db()->statement('DELETE FROM [PumpIT].dbo.BRN_Tills WHERE SSBranchId = ? AND TillNo = ?', [self::BRANCH, self::TILL]);
        $this->db()->table('agora.MasterChange')->where('BranchId', self::SITE)->delete();
        $this->db()->table('agora.Setting')->where('BranchId', self::SITE)->delete();
        foreach (['StockItem', 'StockItemCritical', 'ReconCriteria'] as $table) {
            $this->db()->table('agora.'.$table)->where('BranchId', self::SITE)->delete();
        }
        foreach (['STK_StockMaster', 'STK_Area', 'DBF_STDB', 'STK_StockMasterCritical'] as $table) {
            $this->db()->table('PumpIT.dbo.'.$table)->where('SSBranchId', self::SITE)->delete();
        }
        $this->db()->table('agora.Branch')->where('BranchId', self::SITE)->delete();

        $users = User::query()->acrossBranches()->where('EmailAddress', 'like', 'TEST-master-changes-%')->pluck('Id');
        foreach ($users as $id) {
            foreach (['UserActivity', 'UserPermission', 'UserBranch', 'UserGridColumn', 'UserMenuItem'] as $table) {
                $this->db()->table('agora.'.$table)->where('UserId', $id)->delete();
            }
            User::query()->acrossBranches()->where('Id', $id)->forceDelete();
        }
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
