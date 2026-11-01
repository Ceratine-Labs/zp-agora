<?php

namespace Tests\Feature\StockRecon;

use App\Exceptions\AgoraProcException;
use App\Support\BranchContext;
use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\User;
use Modules\Core\Services\PermissionService;
use Modules\StockRecon\Services\WeeklyStockService;
use Tests\TestCase;

class WeeklyStockTest extends TestCase
{
    private const BRANCH = 970;

    private const OTHER = 971;

    /** @var array<int, User> */
    private array $users = [];

    private bool $safe = false;

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessLocalStub();
        $local = config('database.connections.'.config('agora.connections.app'));
        foreach (config('agora.connections') as $name) {
            $database = config("database.connections.{$name}.database");
            config(["database.connections.{$name}" => array_replace($local, ['database' => $database])]);
            DB::purge($name);
            $this->assertContains(config("database.connections.{$name}.host"), ['127.0.0.1', 'localhost', '::1']);
        }
        $this->safe = true;
        $this->cleanup();
        foreach ([self::BRANCH, self::OTHER] as $branch) {
            $this->db()->table('agora.Branch')->insert(['BranchId' => $branch, 'Name' => 'TEST-Weekly '.$branch, 'IsActive' => 1, 'IsTrading' => 1]);
        }
        $this->reviewer = $this->person(['view', 'create', 'edit', 'save', 'approve', 'reopen']);
        $this->actingAs($this->reviewer);
    }

    protected function tearDown(): void
    {
        if ($this->safe) {
            $this->cleanup();
            foreach ($this->users as $user) {
                foreach (['UserPermission', 'UserBranch', 'UserGridColumn', 'UserMenuItem'] as $table) {
                    $this->db()->table('agora.'.$table)->where('UserId', $user->Id)->delete();
                }
                User::query()->acrossBranches()->where('Id', $user->Id)->forceDelete();
            }
        }
        parent::tearDown();
    }

    public function test_classifications_use_real_snapshot_sql_preserve_unknown_counts_and_ignore_virtual_losses(): void
    {
        $definitions = [
            ['A1', -10, -10], ['A2', -10, -20], ['A3', -20, -10], ['B1', 0, -10], ['B2', 10, -10],
            ['C1', -10, 0], ['C2', 10, 0], ['D1', 10, 10], ['D2', 0, 10], ['D3', -10, 10], ['D4', 10, 30],
            ['OK', 0, 0], ['NC', 0, null], ['E2', 0, 10000], ['E3', 100, 1, 100], ['RV', 0, 10, 1, -10],
            ['V', -100, -1000], ['E1', -200, -200],
        ];
        $source = $this->source($definitions);
        $snapshot = $this->snapshot($source);
        $cases = $this->db()->table('agora.StockWeeklyCase')->where('SnapshotRef', $snapshot->SnapshotRef)->get()->keyBy('StockItemNo');
        $this->assertCount(count($definitions), $cases);
        foreach ($definitions as $i => $definition) {
            $row = $cases['T'.$i];
            $this->assertSame($definition[0], $row->Scenario, 'Scenario for '.$definition[0]);
            $this->assertEquals($definition[1], $row->ReconQty);
            $this->assertEquals($definition[1], $row->OriginalReconQty);
            $this->assertSame('Open', $row->Status);
            if ($definition[2] === null) {
                $this->assertNull($row->CountQty);
                $this->assertNull($row->StoreroomQty);
            } else {
                $this->assertEquals($definition[2] - $definition[1], $row->StoreroomQty);
            }
        }
        $this->assertEquals(0, $cases['T16']->ValueAtRiskSelling);
        $stored = $this->db()->table('agora.StockWeeklySnapshot')->where('SnapshotRef', $snapshot->SnapshotRef)->first();
        $this->assertSame($source, json_decode($stored->SourceJson, true));
        $this->assertSame('2026-09-20', $stored->CompleteThrough);
        $same = $this->snapshot($this->source([['B1', 0, -999]]), $snapshot->SnapshotRef);
        $this->assertSame('EXISTS', $same->Code);
        $this->assertSame($stored->SourceJson, $this->db()->table('agora.StockWeeklySnapshot')->where('Id', $stored->Id)->value('SourceJson'));
    }

    public function test_receipt_issue_recipe_and_shared_count_evidence_is_kept_without_double_counting_scorecard(): void
    {
        $source = $this->source([['IR', -1, -10], ['D1', 5, 10], ['D1', 5, 10]]);
        foreach ($source['recon'] as &$row) {
            if ($row['StockItemNo'] === 'T0') {
                $row['AreaDescription'] = 'Steers BOH';
                $row['POSCode'] = 'V7';
            } else {
                $row['POSCode'] = 'SHARED';
            }
        }
        unset($row);
        $source['counts'][0]['POSCode'] = 'V7';
        $source['counts'][1]['POSCode'] = 'SHARED';
        array_pop($source['counts']);
        $source['evidence'] = [
            ['SourceSystem' => 'WINBRANCH', 'POSCode' => 'V7', 'EvidenceType' => 'Receipt', 'EvidenceDate' => '2026-09-15', 'Quantity' => 50, 'Value' => null, 'Reference' => 'TEST-GRV', 'DetailJson' => '{"ValueBasis":"unknown"}'],
            ['SourceSystem' => 'WINBRANCH', 'POSCode' => 'V7', 'EvidenceType' => 'RawConsumption', 'EvidenceDate' => '2026-09-15', 'Quantity' => 20, 'Value' => null, 'Reference' => 'TEST-Recipe', 'DetailJson' => '{}'],
        ];
        $snapshot = $this->snapshot($source);
        $cases = $this->db()->table('agora.StockWeeklyCase')->where('SnapshotRef', $snapshot->SnapshotRef)->get()->keyBy('StockItemNo');
        $this->assertSame('IR', $cases['T0']->Scenario);
        $facts = json_decode($cases['T0']->EvidenceJson);
        $this->assertEquals(1, $facts->IorDays);
        $this->assertEquals(0, $facts->IorMatched);
        $this->assertEquals(50, $facts->IorReceived);
        $this->assertEquals(18, $facts->RecipeLessReconPOS);
        $this->assertEquals(1, $facts->RecipeMismatchDays);
        $this->assertEquals(6, $facts->RecipeMissingDays);
        $this->assertEquals(2, $cases['T1']->SharedCountCases);
        $overview = app(ProcedureService::class)->callSets('agora.usp_StockRecon_GetWeeklyOverview', ['SnapshotRef' => $snapshot->SnapshotRef] + WeeklyStockService::access());
        $this->assertEquals(2, $overview[1]->sole()->PhysicalProducts);
        $this->assertEquals(2, $overview[1]->sole()->CountProven);
        $this->assertEquals(1, $overview[1]->sole()->ExcludedFromValueTotals);
    }

    public function test_missing_day_holds_later_rows_and_count_dates_and_null_count_operands_stay_unknown(): void
    {
        $source = $this->source([['B1', 0, -10]]);
        $source['recon'] = array_values(array_filter($source['recon'], fn ($row) => $row['TransactionDate'] !== '2026-09-17'));
        $snapshot = $this->snapshot($source);
        $stored = $this->db()->table('agora.StockWeeklySnapshot')->where('SnapshotRef', $snapshot->SnapshotRef)->first();
        $this->assertSame('2026-09-16', $stored->CompleteThrough);
        $case = $this->db()->table('agora.StockWeeklyCase')->where('SnapshotRef', $snapshot->SnapshotRef)->first();
        $this->assertSame('NC', $case->Scenario);
        $this->assertNull($case->CountQty);
        $this->assertEquals(6, $case->SalesQty);
        $nullCount = $this->source([['OK', 0, 0]]);
        $nullCount['counts'][0]['VarianceQty'] = null;
        $snapshot = $this->snapshot($nullCount);
        $this->assertSame('NC', $this->db()->table('agora.StockWeeklyCase')->where('SnapshotRef', $snapshot->SnapshotRef)->value('Scenario'));
    }

    public function test_recipe_daily_product_comparison_keeps_cancelling_gaps_visible_and_e1_respects_issue_multiple(): void
    {
        $source = $this->source([['A1', -10, -10, 1, null, 24], ['E1', -30, -30, 1, null, 24]]);
        foreach ($source['recon'] as &$row) {
            $row['QtyIssued'] = $row['QtyPOS'] = $row['OriginalIssued'] = $row['OriginalPOS'] = 0;
        }
        unset($row);
        $snapshot = $this->snapshot($source);
        $cases = $this->db()->table('agora.StockWeeklyCase')->where('SnapshotRef', $snapshot->SnapshotRef)->get()->keyBy('StockItemNo');
        $this->assertSame('A1', $cases['T0']->Scenario);
        $this->assertSame('E1', $cases['T1']->Scenario);
        $source = $this->source([['OK', 0, 0], ['OK', 0, 0]]);
        foreach ($source['recon'] as &$row) {
            $row['POSCode'] = 'SHARED';
        }
        unset($row);
        $source['counts'][0]['POSCode'] = 'SHARED';
        array_pop($source['counts']);
        foreach ([14 => 6, 15 => 2] as $day => $quantity) {
            $source['evidence'][] = ['SourceSystem' => 'WINBRANCH', 'POSCode' => 'SHARED', 'EvidenceType' => 'RawConsumption',
                'EvidenceDate' => '2026-09-'.$day, 'Quantity' => $quantity, 'Value' => null, 'Reference' => 'TEST-daily-recipe', 'DetailJson' => '{}'];
        }
        $snapshot = $this->snapshot($source);
        foreach ($this->db()->table('agora.StockWeeklyCase')->where('SnapshotRef', $snapshot->SnapshotRef)->get() as $case) {
            $facts = json_decode($case->EvidenceJson);
            $this->assertEquals(0, $facts->RecipeLessReconPOS);
            $this->assertEquals(2, $facts->RecipeMismatchDays);
            $this->assertEquals(2, $facts->RecipeComparedDays);
            $this->assertEquals(5, $facts->RecipeMissingDays);
            $this->assertEquals(4, $facts->RecipeDaily[0]->POSQty);
        }
    }

    public function test_real_grid_filters_export_and_detail_use_same_scope_and_never_modify_snapshots(): void
    {
        $snapshot = $this->snapshot($this->source([['A1', -10, -10], ['NC', 0, null]]));
        $case = $this->db()->table('agora.StockWeeklyCase')->where('SnapshotRef', $snapshot->SnapshotRef)->where('Scenario', 'A1')->first();
        $before = $this->db()->table('agora.StockWeeklySnapshot')->where('SnapshotRef', $snapshot->SnapshotRef)->value('SourceJson');
        $this->get(route('app.stockrecon.weekly', ['snapshot' => $snapshot->SnapshotRef, 'from' => '2026-10-01', 'to' => '2026-10-24']))->assertOk()
            ->assertSee('TEST-A1')->assertSee('usp_StockRecon_GridWeeklyCases')->assertSee('Physical products counted')
            ->assertSee('data-detail-url="'.$this->url('show', $case->Id).'"', false);
        $this->get($this->url('show', $case->Id))->assertOk()->assertSee('Count evidence')->assertSee('Action history');
        $this->get($this->url('show', $case->Id), ['X-Requested-With' => 'XMLHttpRequest'])->assertOk()->assertDontSee('<!DOCTYPE', false);
        $csv = $this->get(route('app.grids.extract', ['grid' => 'app.stockrecon.weekly', 'snapshot' => $snapshot->SnapshotRef,
            'f' => ['Scenario' => ['in' => ['A1']]]]))->assertOk()->streamedContent();
        $this->assertStringContainsString('TEST-A1', $csv);
        $this->assertStringNotContainsString('TEST-NC', $csv);
        $this->assertSame($before, $this->db()->table('agora.StockWeeklySnapshot')->where('SnapshotRef', $snapshot->SnapshotRef)->value('SourceJson'));
    }

    public function test_permissions_and_current_branch_grants_guard_screen_direct_export_actions_and_procedures(): void
    {
        $snapshot = $this->snapshot($this->source([['A1', -10, -10]]));
        $case = $this->db()->table('agora.StockWeeklyCase')->where('SnapshotRef', $snapshot->SnapshotRef)->first();
        $this->actingAs($this->person([]))->get(route('app.stockrecon.weekly'))->assertForbidden();
        $this->get(route('app.grids.extract', ['grid' => 'app.stockrecon.weekly']))->assertForbidden();
        $this->actingAs($this->person(['view']))->get($this->url('show', $case->Id))->assertOk()->assertDontSee('Record action');
        $this->get($this->url('edit', $case->Id))->assertForbidden();
        $this->post(route('app.stockrecon.weekly.store'), ['branch_id' => self::BRANCH, 'week_start' => '2026-09-14', 'Authorized' => 1])->assertForbidden();
        $this->actingAs($this->person(['view', 'edit']))->get($this->url('edit', $case->Id))->assertOk()->assertDontSee('>Save investigation action</button>', false);
        $this->put($this->url('update', $case->Id), $this->action($case->Id))->assertForbidden();
        $this->actingAs($this->person(['view'], [self::OTHER]));
        $csv = $this->get(route('app.grids.extract', ['grid' => 'app.stockrecon.weekly', 'branches' => [self::BRANCH]]))->assertOk()->streamedContent();
        $this->assertStringNotContainsString('TEST-A1', $csv);
        $this->assertRefused('WEEKLY_FORBIDDEN', fn () => app(WeeklyStockService::class)->get(self::BRANCH, $case->Id));
        $this->assertRefused('WEEKLY_FORBIDDEN', fn () => $this->snapshot($this->source([['OK', 0, 0]])));
        $this->actingAs($this->reviewer);
        foreach ([null, 0, -1] as $pin) {
            $this->assertRefused('WEEKLY_FORBIDDEN', fn () => app(ProcedureService::class)->call('agora.usp_StockRecon_AuthorizeWeekly',
                ['UserId' => $this->reviewer->Id, 'Authorized' => 1, 'Workspace' => 'branch', 'BranchPin' => $pin]));
        }
    }

    public function test_owner_finding_evidence_and_stale_protection_are_enforced_by_actual_write_procedure(): void
    {
        $snapshot = $this->snapshot($this->source([['A1', -10, -10]]));
        $case = $this->db()->table('agora.StockWeeklyCase')->where('SnapshotRef', $snapshot->SnapshotRef)->first();
        $input = $this->action($case->Id);
        $this->assertRefused('WEEKLY_OWNER', fn () => app(WeeklyStockService::class)->save(self::BRANCH, $case->Id, array_replace($input, ['OwnerId' => null])));
        $this->assertRefused('WEEKLY_FINDING', fn () => app(WeeklyStockService::class)->save(self::BRANCH, $case->Id, array_replace($input, ['Status' => 'Closed', 'Finding' => 'too short']), 'approve'));
        $this->put($this->url('update', $case->Id), $input)->assertRedirect($this->url('show', $case->Id));
        $this->assertRefused('WEEKLY_STALE', fn () => app(WeeklyStockService::class)->save(self::BRANCH, $case->Id, $input));
        $close = $this->closure($case->Id);
        $this->put($this->url('approve', $case->Id), $close)->assertRedirect();
        $history = $this->db()->table('agora.StockWeeklyCaseChange')->where('CaseId', $case->Id)->orderBy('Id')->get();
        $this->assertCount(2, $history);
        $this->assertSame('Open', json_decode($history[0]->PriorJson)->Status);
        $this->assertSame('Investigating', json_decode($history[0]->NewJson)->Status);
        $this->assertSame('Closed', json_decode($history[1]->NewJson)->Status);
        $this->assertEquals($this->reviewer->Id, $history[1]->CreatedBy);
        $this->assertSame('Closed', $this->db()->table('agora.StockWeeklyCase')->where('Id', $case->Id)->value('Status'));
    }

    public function test_closure_requires_evidence_head_office_permission_and_reopening_preserves_signed_history(): void
    {
        $snapshot = $this->snapshot($this->source([['A1', -10, -10]]));
        $case = $this->db()->table('agora.StockWeeklyCase')->where('SnapshotRef', $snapshot->SnapshotRef)->first();
        $source = $this->db()->table('agora.StockWeeklySnapshot')->where('SnapshotRef', $snapshot->SnapshotRef)->value('SourceJson');
        $closure = $this->closure($case->Id);
        foreach (['RecoveryAmount', 'CorrectionStatus', 'CorrectionReference', 'ActionTaken', 'ActionDate', 'SignoffNote', 'ClosureOutcome'] as $field) {
            $this->assertRefused('WEEKLY_CLOSURE', fn () => app(WeeklyStockService::class)->save(self::BRANCH, $case->Id, array_replace($closure, [$field => null]), 'approve'));
        }
        $this->assertRefused('WEEKLY_CLOSURE', fn () => app(WeeklyStockService::class)->save(self::BRANCH, $case->Id,
            array_replace($closure, ['RecoveryAmount' => 15, 'RecoveryReference' => null]), 'approve'));
        $this->assertRefused('WEEKLY_APPROVAL', fn () => app(WeeklyStockService::class)->save(self::BRANCH, $case->Id, $closure));
        $clerk = $this->person(['view', 'edit', 'save']);
        $this->actingAs($clerk)->put($this->url('approve', $case->Id), $closure)->assertForbidden();
        $this->assertRefused('WEEKLY_FORBIDDEN', fn () => app(WeeklyStockService::class)->save(self::BRANCH, $case->Id, $closure, 'approve'));
        $this->actingAs($this->reviewer);
        app(BranchContext::class)->setWorkspace('branch')->set(self::BRANCH);
        $this->assertRefused('WEEKLY_APPROVAL', fn () => app(WeeklyStockService::class)->save(self::BRANCH, $case->Id, $closure, 'approve'));
        app(BranchContext::class)->setWorkspace('ho')->set(null);
        $this->put($this->url('approve', $case->Id), $closure)->assertRedirect($this->url('show', $case->Id));
        $signed = app(WeeklyStockService::class)->get(self::BRANCH, $case->Id)['case'];
        $this->assertSame('Closed', $signed->Status);
        $this->assertEquals($this->reviewer->Id, $signed->SignedOffBy);
        $this->assertNotNull($signed->SignedOffAt);
        $this->assertNotNull($signed->ClosedDate);
        $this->get($this->url('show', $case->Id))->assertOk()->assertSee('TEST-Correction audit 123');
        $csv = $this->get(route('app.grids.extract', ['grid' => 'app.stockrecon.weekly', 'snapshot' => $snapshot->SnapshotRef, 'columns' => 'RegisterName,ClosureOutcome,CorrectionReference,RecoveryAmount,SignedOffName,Finding,EvidenceReference,SignedOffAt']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Paperwork corrected', $csv);
        $this->assertStringContainsString('TEST-Correction audit 123', $csv);
        $this->assertStringContainsString('TEST-Weekly reviewer', $csv);
        $this->assertStringContainsString('TEST-count sheet 2026-09-21 and GRV100', $csv);
        $this->assertRefused('WEEKLY_CLOSED', fn () => app(WeeklyStockService::class)->save(self::BRANCH, $case->Id, $this->action($case->Id)));
        $reopen = ['ExpectedVersion' => $signed->VersionToken, 'Reason' => 'TEST-New invoice evidence requires the finding to be reconsidered'];
        $this->actingAs($clerk)->put($this->url('reopen', $case->Id), $reopen)->assertForbidden();
        $this->actingAs($this->reviewer)->put($this->url('reopen', $case->Id), $reopen)->assertRedirect();
        $this->assertRefused('WEEKLY_STALE', fn () => app(WeeklyStockService::class)->save(self::BRANCH, $case->Id, $reopen, 'reopen'));
        $reopened = app(WeeklyStockService::class)->get(self::BRANCH, $case->Id)['case'];
        $this->assertSame('Investigating', $reopened->Status);
        $this->assertNull($reopened->SignedOffBy);
        $this->assertNull($reopened->ClosedDate);
        $this->assertSame($signed->Finding, $reopened->Finding);
        $history = $this->db()->table('agora.StockWeeklyCaseChange')->where('CaseId', $case->Id)->orderBy('Id')->get();
        $this->assertCount(2, $history);
        $this->assertEquals($signed->SignedOffBy, json_decode($history[1]->PriorJson)->SignedOffBy);
        $this->assertSame($source, $this->db()->table('agora.StockWeeklySnapshot')->where('SnapshotRef', $snapshot->SnapshotRef)->value('SourceJson'));
    }

    public function test_control_period_windows_cost_basis_actions_and_exports_are_separate_from_weekly_exposure(): void
    {
        $source = $this->source([['A1', -10, -10]]);
        $source['recon'] = [];
        for ($day = 1; $day <= 21; $day++) {
            for ($shift = 1; $shift <= 2; $shift++) {
                $source['recon'][] = ['AreaNo' => 900, 'StockItemNo' => 'T0', 'AreaDescription' => 'TEST-Area', 'Description' => 'TEST-Period product',
                    'SourceSystem' => 'WINBRANCH', 'POSCode' => 'T0', 'TransactionDate' => sprintf('2026-09-%02d', $day), 'ShiftNo' => $shift,
                    'UnitSellingPrice' => 20, 'IssueMultiple' => 10, 'IsWeighed' => 0, 'QtyOpen' => 100, 'QtyIssued' => 10, 'QtyClose' => 99, 'QtyPOS' => 10,
                    'OriginalOpen' => 100, 'OriginalIssued' => 10, 'OriginalClose' => 99, 'OriginalPOS' => 10];
            }
        }
        $source['counts'] = [];
        foreach (['2026-08-31' => [0, 4], '2026-09-07' => [-14, 5], '2026-09-14' => [-14, 6], '2026-09-21' => [-14, 7]] as $date => [$variance, $cost]) {
            $source['counts'][] = ['SourceSystem' => 'WINBRANCH', 'POSCode' => 'T0', 'CountDate' => $date,
                'VarianceQty' => $variance, 'UnitCost' => $cost, 'CountedQty' => 100 + $variance, 'ExpectedQty' => 100, 'Reference' => 'TEST-'.$date, 'DetailJson' => '{}'];
        }
        $snapshot = app(ProcedureService::class)->write('agora.usp_StockRecon_CreateWeeklySnapshot', ['BranchId' => self::BRANCH, 'WeekStart' => '2026-09-14',
            'PeriodFrom' => '2026-09-01', 'PeriodTo' => '2026-09-21', 'ReportingAsOf' => '2026-09-23', 'SnapshotRef' => (string) Str::uuid(),
            'SourceReadAt' => '2026-09-23 10:00:00', 'SourceJson' => json_encode($source, JSON_THROW_ON_ERROR), 'CoverageJson' => '[]'] + WeeklyStockService::access('create'));
        $cases = $this->db()->table('agora.StockWeeklyCase')->where('SnapshotRef', $snapshot->SnapshotRef)->get();
        $weekly = $cases->where('RegisterName', 'Item Register')->sole();
        $period = $cases->where('RegisterName', 'Period Items')->sole();
        $this->assertEquals(-14, $weekly->ReconQty);
        $this->assertEquals(-42, $period->ReconQty);
        $this->assertEquals(-42, $period->CountQty);
        $this->assertEquals(7, $period->UnitCost);
        $this->assertEquals(-294, $period->CountValueCost);
        $this->assertEquals(-294, $period->ReconValueCost);
        $this->assertNull($weekly->CountValueCost);
        $this->assertNull($period->ValueAtRiskSelling);
        $this->assertNull($period->RecoveryAmount);
        $windows = json_decode($period->EvidenceJson)->CountWindows;
        $this->assertCount(3, $windows);
        $this->assertSame('2026-09-01', $windows[0]->WindowStart);
        $this->assertSame('2026-09-08', $windows[1]->WindowStart);
        $this->assertEquals(0, json_decode($period->EvidenceJson)->PartialWindows);
        $this->assertCount(41, $cases->where('RegisterName', 'Opening vs Closing'));
        $this->assertNotEmpty($cases->where('RegisterName', 'Control Actions'));
        foreach (['nil-count-export', 'virtual-lines', 'pack-validation', 'opening-lock', 'staff-identity', 'count-reasons'] as $policy) {
            $this->assertNotNull($cases->where('RegisterName', 'Control Actions')->firstWhere('POSCode', $policy));
        }
        $this->get($this->url('show', $period->Id))->assertOk()->assertSee('Count source unit cost')->assertSee('2026-09-21');
        $this->get(route('app.stockrecon.weekly', ['snapshot' => $snapshot->SnapshotRef]))->assertOk()->assertSee('2026-09-01')->assertSee('Period Items');
        $csv = $this->get(route('app.grids.extract', ['grid' => 'app.stockrecon.weekly', 'snapshot' => $snapshot->SnapshotRef,
            'columns' => 'RegisterName,ReconValueCost,CountValueCost,UnitCost,CostBasis', 'f' => ['RegisterName' => ['in' => ['Period Items']]]]))->assertOk()->streamedContent();
        $this->assertStringContainsString('Period Items', $csv);
        $this->assertStringNotContainsString('Opening vs Closing', $csv);
        $this->assertStringContainsString('-294', $csv);
        $this->put($this->url('update', $period->Id), $this->action($period->Id))->assertRedirect();
        $this->put($this->url('approve', $period->Id), $this->closure($period->Id))->assertRedirect();
        $this->assertSame('Closed', $this->db()->table('agora.StockWeeklyCase')->where('Id', $period->Id)->value('Status'));
        $this->assertEquals(-294, $this->db()->table('agora.StockWeeklyCase')->where('Id', $period->Id)->value('CountValueCost'));
    }

    public function test_management_approval_requires_corrected_children_and_reopening_invalidates_both_approvals(): void
    {
        $source = $this->source([['A1', -10, -10]]);
        $snapshot = app(ProcedureService::class)->write('agora.usp_StockRecon_CreateWeeklySnapshot', ['BranchId' => self::BRANCH, 'WeekStart' => '2026-09-14',
            'PeriodFrom' => '2026-09-14', 'PeriodTo' => '2026-09-20', 'ReportingAsOf' => '2026-09-23', 'SnapshotRef' => (string) Str::uuid(),
            'SourceReadAt' => '2026-09-23 10:00:00', 'SourceJson' => json_encode($source, JSON_THROW_ON_ERROR), 'CoverageJson' => '[]'] + WeeklyStockService::access('create'));
        $cases = $this->db()->table('agora.StockWeeklyCase')->where('SnapshotRef', $snapshot->SnapshotRef)->get();
        $child = $cases->where('RegisterName', 'Balancing Breaches')->sole();
        $parent = $cases->where('RegisterName', 'Control Actions')->where('POSCode', 'Balancing Breaches')->sole();
        $this->assertRefused('WEEKLY_RELATED', fn () => app(WeeklyStockService::class)->save(self::BRANCH, $parent->Id, $this->closure($parent->Id), 'approve'));
        $this->assertRefused('WEEKLY_CORRECTION', fn () => app(WeeklyStockService::class)->save(self::BRANCH, $child->Id,
            array_replace($this->closure($child->Id), ['CorrectionStatus' => 'N']), 'approve'));
        app(WeeklyStockService::class)->save(self::BRANCH, $child->Id, $this->closure($child->Id), 'approve');
        app(WeeklyStockService::class)->save(self::BRANCH, $parent->Id, $this->closure($parent->Id), 'approve');
        $signed = app(WeeklyStockService::class)->get(self::BRANCH, $child->Id)['case'];
        $this->put($this->url('reopen', $child->Id), ['ExpectedVersion' => $signed->VersionToken, 'Reason' => 'TEST-New source evidence changes the correction finding'])->assertRedirect();
        foreach ([$child->Id, $parent->Id] as $id) {
            $reopened = app(WeeklyStockService::class)->get(self::BRANCH, $id);
            $this->assertSame('Investigating', $reopened['case']->Status);
            $this->assertNull($reopened['case']->SignedOffBy);
            $this->assertCount(2, $reopened['history']);
            $this->assertEquals($this->reviewer->Id, json_decode($reopened['history']->first()->PriorJson)->SignedOffBy);
        }
    }

    public function test_http_creation_with_unavailable_sources_flows_through_review_action_closure_and_export(): void
    {
        $facts = $this->source([['A1', -10, -10]]);
        $transport = new class($facts) extends ProcedureService
        {
            /** @var array<string, array<string, mixed>> */
            public array $reads = [];

            /** @param array<string, array<int, array<string, mixed>>> $facts */
            public function __construct(private array $facts) {}

            /** @return Collection<int, object> */
            private function rows(string $key): Collection
            {
                return collect($this->facts[$key] ?? [])->map($this->record(...))->values();
            }

            /** @param array<string, mixed> $row */
            private function record(array $row): object
            {
                return (object) $row;
            }

            public function callSets(string $procedure, array $params = []): array
            {
                $this->reads[$procedure] = $params;
                if ($procedure === 'agora.usp_StockRecon_WeeklyRecon') {
                    return [$this->rows('recon')];
                }
                if ($procedure === 'agora.usp_StockRecon_WeeklyCounts') {
                    return [$this->rows('counts'), $this->rows('coverage'), $this->rows('lastCounts')];
                }
                if (in_array($procedure, ['agora.usp_StockRecon_WeeklyCosts', 'agora.usp_StockRecon_WeeklyReceipts', 'agora.usp_StockRecon_WeeklyRecipeEvidence'], true)) {
                    throw new AgoraProcException('WEEKLY_SOURCE_UNAVAILABLE', 'TEST-Source is unavailable', $procedure);
                }

                return parent::callSets($procedure, $params);
            }
        };
        $this->app->instance(ProcedureService::class, $transport);
        $input = ['branch_id' => self::BRANCH, 'week_start' => '2026-09-14', 'period_from' => '2026-09-14', 'period_to' => '2026-09-20', 'reporting_as_of' => '2026-09-23'];
        $this->post(route('app.stockrecon.weekly.store'), array_replace($input, ['period_to' => '2026-09-19']))->assertStatus(422);
        $this->post(route('app.stockrecon.weekly.store'), array_replace($input, ['week_start' => '2026-09-15']))->assertStatus(422);
        $this->post(route('app.stockrecon.weekly.store'), $input)->assertRedirect();
        $snapshot = $this->db()->table('agora.StockWeeklySnapshot')->where('BranchId', self::BRANCH)->sole();
        $this->assertCount(3, array_filter(json_decode($snapshot->CoverageJson), fn ($row) => $row->State === 'Unavailable'));
        $this->assertSame('2026-09-14', $transport->reads['agora.usp_StockRecon_WeeklyCounts']['FromDate']);
        $this->assertSame('2026-09-20', $transport->reads['agora.usp_StockRecon_WeeklyCounts']['ToDate']);
        $case = $this->db()->table('agora.StockWeeklyCase')->where('SnapshotRef', $snapshot->SnapshotRef)->where('RegisterName', 'Period Items')->sole();
        $this->assertStringStartsWith('Estimated:', $case->CostBasis);
        $this->assertEqualsWithDelta(0.7 / 1.15, $case->UnitCost, 0.000001);
        $this->get($this->url('show', $case->Id))->assertOk()->assertSee('TEST-Source is unavailable');
        $this->put($this->url('update', $case->Id), $this->action($case->Id))->assertRedirect();
        $this->put($this->url('approve', $case->Id), $this->closure($case->Id))->assertRedirect();
        $csv = $this->get(route('app.grids.extract', ['grid' => 'app.stockrecon.weekly', 'snapshot' => $snapshot->SnapshotRef,
            'columns' => 'RegisterName,Status,CostBasis,RecoveryAmount,ClosureOutcome', 'f' => ['RegisterName' => ['in' => ['Period Items']]]]))->assertOk()->streamedContent();
        $this->assertStringContainsString('Closed', $csv);
        $this->assertStringContainsString('Estimated:', $csv);
        $this->assertStringContainsString('Paperwork corrected', $csv);
        $this->assertSame($snapshot->SourceJson, $this->db()->table('agora.StockWeeklySnapshot')->where('Id', $snapshot->Id)->value('SourceJson'));
        $this->assertSame($case->EvidenceJson, $this->db()->table('agora.StockWeeklyCase')->where('Id', $case->Id)->value('EvidenceJson'));
        $this->assertSame(0, $this->db()->table('agora.StockWeeklySnapshot')->where('BranchId', self::OTHER)->count());
    }

    public function test_receipt_control_keeps_period_totals_next_day_fallback_and_issues_without_delivery(): void
    {
        $source = $this->source([['OK', 0, 0], ['V', 0, 0]]);
        foreach ($source['recon'] as &$row) {
            if ($row['StockItemNo'] !== 'T0') {
                continue;
            }
            $row['AreaDescription'] = 'Steers BOH';
            $row['POSCode'] = 'B1';
            $row['SourceSystem'] = 'AURA';
            $row['QtyIssued'] = $row['OriginalIssued'] = $row['ShiftNo'] === 1 ? (['2026-09-15' => 10, '2026-09-16' => 9, '2026-09-17' => 5][$row['TransactionDate']] ?? 0) : 0;
        }
        unset($row);
        $source['counts'][0]['SourceSystem'] = 'AURA';
        $source['counts'][0]['POSCode'] = 'B1';
        foreach (['2026-09-13' => 100, '2026-09-14' => 10, '2026-09-18' => 25, '2026-09-21' => 999] as $date => $qty) {
            $source['evidence'][] = ['SourceSystem' => 'AURA', 'POSCode' => 'B1', 'EvidenceType' => 'Receipt', 'EvidenceDate' => $date, 'Quantity' => $qty, 'Reference' => 'TEST-GRV'];
        }
        $snapshot = app(ProcedureService::class)->write('agora.usp_StockRecon_CreateWeeklySnapshot', ['BranchId' => self::BRANCH, 'WeekStart' => '2026-09-14',
            'PeriodFrom' => '2026-09-14', 'PeriodTo' => '2026-09-20', 'ReportingAsOf' => '2026-09-23', 'SnapshotRef' => (string) Str::uuid(),
            'SourceReadAt' => '2026-09-23 10:00:00', 'SourceJson' => json_encode($source, JSON_THROW_ON_ERROR), 'CoverageJson' => '[]'] + WeeklyStockService::access('create'));
        $cases = $this->db()->table('agora.StockWeeklyCase')->where('SnapshotRef', $snapshot->SnapshotRef)->get();
        $this->assertCount(1, $cases->where('RegisterName', 'Period Items'));
        $control = $cases->where('RegisterName', 'Potatoes & Buns')->sole();
        $evidence = json_decode($control->EvidenceJson);
        $this->assertEquals(35, $evidence->Received);
        $this->assertEquals(24, $evidence->Issued);
        $this->assertEquals(11, $control->ReconQty);
        $days = array_column($evidence->Deliveries, null, 'EvidenceDate');
        $this->assertEquals(10, $days['2026-09-14']->MatchedIssued);
        $this->assertEquals(1, $days['2026-09-14']->Matched);
        $this->assertEquals(1, $days['2026-09-16']->IssueWithoutDelivery);
        $this->assertEquals(25, $days['2026-09-18']->Received);
        $this->assertEquals(0, $days['2026-09-18']->Matched);
        $this->assertArrayNotHasKey('2026-09-21', $days);
        $this->get($this->url('show', $control->Id))->assertOk()->assertSee('2026-09-18');
    }

    public function test_cross_site_patterns_use_whole_product_original_quantities_and_current_branch_grants(): void
    {
        $third = 972;
        $this->assertFalse($this->db()->table('agora.Branch')->where('BranchId', $third)->exists());
        $this->db()->table('agora.Branch')->insert(['BranchId' => $third, 'Name' => 'TEST-Weekly '.$third, 'IsActive' => 1, 'IsTrading' => 1]);
        try {
            $source = $this->source([['A1', -10, -10], ['A1', -20, -20]]);
            foreach ($source['recon'] as &$row) {
                if ($row['StockItemNo'] === 'T1') {
                    $row['POSCode'] = 'T0';
                    $row['SourceSystem'] = 'AURA';
                }
            }
            unset($row);
            $source['counts'][1]['POSCode'] = 'T0';
            $source['counts'][1]['SourceSystem'] = 'AURA';
            foreach ([self::BRANCH, self::OTHER, $third] as $branch) {
                app(ProcedureService::class)->write('agora.usp_StockRecon_CreateWeeklySnapshot', ['BranchId' => $branch, 'WeekStart' => '2026-09-14',
                    'PeriodFrom' => '2026-09-14', 'PeriodTo' => '2026-09-20', 'ReportingAsOf' => '2026-09-23', 'SnapshotRef' => (string) Str::uuid(),
                    'SourceReadAt' => '2026-09-23 10:00:00', 'SourceJson' => json_encode($source, JSON_THROW_ON_ERROR), 'CoverageJson' => '[]'] + WeeklyStockService::access('create'));
            }
            $read = fn () => app(ProcedureService::class)->callSets('agora.usp_StockRecon_GetWeeklyOverview', ['BranchIds' => '970,971,972'] + WeeklyStockService::access())[3];
            $pattern = $read()->sole();
            $this->assertEquals(3, $pattern->Sites);
            $this->assertCount(3, json_decode($pattern->CasesJson));
            $this->assertEquals(3, $pattern->ShortSites);
            $this->assertEqualsWithDelta(-30 / 28, $pattern->MedianPct, 0.00001);
            $this->get(route('app.stockrecon.weekly', ['branches' => [self::BRANCH, self::OTHER, $third], 'from' => '2026-09-14', 'to' => '2026-09-20']))->assertOk()->assertSee('Review and assign TEST-Weekly 972');
            $this->actingAs($this->person(['view'], [self::BRANCH, self::OTHER]));
            $this->assertCount(0, $read());
            $this->actingAs($this->reviewer);
            app(BranchContext::class)->setWorkspace('branch')->set(self::BRANCH);
            $this->assertCount(0, $read());
        } finally {
            foreach (['StockWeeklyCaseChange', 'StockWeeklyCase', 'StockWeeklySnapshot', 'Branch'] as $table) {
                $this->db()->table('agora.'.$table)->where('BranchId', $third)->delete();
            }
        }
    }

    /** @return array<string, mixed> */
    private function closure(int $case): array
    {
        return array_replace($this->action($case), ['Status' => 'Closed',
            'Finding' => 'TEST-Independent recount and invoice verified the received quantity.',
            'EvidenceReference' => 'TEST-count sheet 2026-09-21 and GRV100', 'ClosureOutcome' => 'Paperwork corrected',
            'RecoveryAmount' => 0, 'CorrectionStatus' => 'Y', 'CorrectionReference' => 'TEST-Correction audit 123',
            'ActionTaken' => 'TEST-Stock controller corrected the duplicated issue and checked the original invoice.',
            'ActionDate' => '2026-09-24', 'SignoffNote' => 'TEST-Head office verified the correction record and count.']);
    }

    /** @param array<int, array<int, mixed>> $definitions
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function source(array $definitions): array
    {
        $source = ['recon' => [], 'counts' => [], 'lastCounts' => [], 'evidence' => []];
        foreach ($definitions as $i => $definition) {
            [$scenario, $recon, $count] = $definition;
            $price = $definition[3] ?? 1;
            for ($day = 14; $day <= 20; $day++) {
                for ($shift = 1; $shift <= 2; $shift++) {
                    $close = 1000 + ($day === 14 && $shift === 1 ? $recon : 0);
                    $source['recon'][] = ['AreaNo' => 900 + $i, 'StockItemNo' => 'T'.$i, 'AreaDescription' => 'TEST-Area '.$i,
                        'Description' => $scenario === 'V' ? 'TEST-REFILL WATER' : 'TEST-'.$scenario,
                        'SourceSystem' => 'WINBRANCH', 'POSCode' => 'T'.$i, 'TransactionDate' => '2026-09-'.$day,
                        'ShiftNo' => $shift, 'UnitSellingPrice' => $price, 'IssueMultiple' => $definition[5] ?? 0, 'IsWeighed' => 0,
                        'QtyOpen' => 1000, 'QtyIssued' => 1,
                        'QtyClose' => $close, 'QtyPOS' => 1, 'OriginalOpen' => $day === 14 && $shift === 1 ? 1000 : 1000 + $recon,
                        'OriginalIssued' => 1, 'OriginalClose' => 1000 + $recon, 'OriginalPOS' => 1];
                }
            }
            if ($count !== null) {
                $source['counts'][] = ['SourceSystem' => 'WINBRANCH', 'POSCode' => 'T'.$i, 'CountDate' => '2026-09-20',
                    'VarianceQty' => $count, 'CountedQty' => 1000 + $count, 'ExpectedQty' => 1000, 'UnitCost' => null, 'Reference' => 'TEST-count', 'DetailJson' => '{}'];
            }
            if (isset($definition[4])) {
                $source['counts'][] = ['SourceSystem' => 'WINBRANCH', 'POSCode' => 'T'.$i, 'CountDate' => '2026-09-13',
                    'VarianceQty' => $definition[4], 'CountedQty' => 1000 + $definition[4], 'ExpectedQty' => 1000, 'UnitCost' => null, 'Reference' => 'TEST-previous', 'DetailJson' => '{}'];
            }
        }

        return $source;
    }

    /** @param array<string, mixed> $source */
    private function snapshot(array $source, ?string $ref = null): object
    {
        return app(ProcedureService::class)->write('agora.usp_StockRecon_CreateWeeklySnapshot', ['BranchId' => self::BRANCH, 'WeekStart' => '2026-09-14',
            'SnapshotRef' => $ref ?? (string) Str::uuid(), 'SourceReadAt' => '2026-09-22 10:00:00',
            'SourceJson' => json_encode($source, JSON_THROW_ON_ERROR), 'CoverageJson' => '[{"SourceName":"TEST-fixture","State":"Available","Note":"Deterministic test facts"}]'] + WeeklyStockService::access('create'));
    }

    /** @return array<string, mixed> */
    private function action(int $case): array
    {
        $row = app(WeeklyStockService::class)->get(self::BRANCH, $case)['case'];

        return ['ExpectedVersion' => $row->VersionToken, 'Status' => 'Investigating', 'OwnerId' => $this->reviewer->Id,
            'DueDate' => '2026-09-24', 'Finding' => null, 'EvidenceReference' => null, 'Reason' => 'TEST-Assign site review and request count evidence'];
    }

    /** @param array<int, string> $actions
     * @param  array<int, int>  $branches
     */
    private function person(array $actions, array $branches = []): User
    {
        $user = User::query()->acrossBranches()->create(['BranchId' => 2, 'EmailAddress' => 'TEST-weekly-'.Str::uuid().'@agora.invalid',
            'UserName' => 'TEST-Weekly reviewer', 'IsActive' => true, 'IsLocked' => false, 'Workspace' => 'ho', 'PasswordHash' => Str::random(24)]);
        $this->users[] = $user;
        foreach ($actions as $action) {
            $permission = $this->db()->table('agora.Permission')->where('Code', 'stockrecon.weekly.'.$action)->value('Id');
            $this->db()->table('agora.UserPermission')->insert(['BranchId' => 2, 'UserId' => $user->Id, 'PermissionId' => $permission]);
        }
        foreach ($branches as $branch) {
            $this->db()->table('agora.UserBranch')->insert(['BranchId' => $branch, 'UserId' => $user->Id]);
        }
        app(PermissionService::class)->forget($user);
        app(BranchContext::class)->setWorkspace('ho')->setAllowed($branches)->set(null);

        return $user;
    }

    private function assertRefused(string $code, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected '.$code);
        } catch (AgoraProcException $e) {
            $this->assertSame($code, $e->code());
        }
    }

    private function url(string $action, int $case): string
    {
        return route('app.stockrecon.weekly.'.$action, ['branch' => self::BRANCH, 'case' => $case]);
    }

    private function cleanup(): void
    {
        foreach (['StockWeeklyCaseChange', 'StockWeeklyCase', 'StockWeeklySnapshot', 'Branch'] as $table) {
            $this->db()->table('agora.'.$table)->whereIn('BranchId', [self::BRANCH, self::OTHER])->delete();
        }
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }
}
