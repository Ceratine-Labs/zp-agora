<?php

namespace Tests\Feature\StockRecon;

use App\Exceptions\AgoraProcException;
use App\Support\BranchContext;
use App\Support\ProcedureService;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\User;
use Modules\Core\Services\PermissionService;
use Modules\StockRecon\Services\WeeklyStockService;
use Tests\TestCase;

class WeeklyPatternTest extends TestCase
{
    private const SITES = [973, 974, 975];

    /** @var array<int, User> */
    private array $users = [];

    /** @var array<int, int> */
    private array $createdSites = [];

    private bool $safe = false;

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessLocalStub();
        foreach (config('agora.connections') as $name) {
            $this->assertContains(config("database.connections.{$name}.host"), ['127.0.0.1', 'localhost', '::1']);
        }
        $this->safe = true;
        $migration = require base_path('Modules/StockRecon/Database/Migrations/v1__14pzh_stockrecon_weekly_patterns.php');
        $migration->up();
        foreach (self::SITES as $site) {
            $this->assertFalse($this->db()->table('agora.Branch')->where('BranchId', $site)->exists());
            $this->db()->table('agora.Branch')->insert(['BranchId' => $site, 'Name' => 'TEST-Pattern '.$site, 'IsActive' => 1, 'IsTrading' => 1]);
            $this->createdSites[] = $site;
        }
        $this->reviewer = $this->person(self::SITES);
        $this->actingAs($this->reviewer);
        $this->buildSites();
    }

    protected function tearDown(): void
    {
        if ($this->safe) {
            $ids = array_map(fn ($u) => $u->Id, $this->users);
            $refs = $this->db()->table('agora.StockWeeklySnapshot')->whereIn('CreatedBy', $ids)->pluck('SnapshotRef');
            $cases = $this->db()->table('agora.StockWeeklyCase')->whereIn('SnapshotRef', $refs)->pluck('Id');
            $this->db()->table('agora.StockWeeklyCaseChange')->whereIn('CaseId', $cases)->delete();
            $this->db()->table('agora.StockWeeklyCase')->whereIn('SnapshotRef', $refs)->delete();
            $this->db()->table('agora.StockWeeklySnapshot')->whereIn('SnapshotRef', $refs)->delete();
            foreach ($this->users as $user) {
                foreach (['UserPermission', 'UserBranch', 'UserGridColumn', 'UserMenuItem'] as $table) {
                    $this->db()->table('agora.'.$table)->where('UserId', $user->Id)->delete();
                }
                User::query()->acrossBranches()->where('Id', $user->Id)->forceDelete();
            }
            $this->db()->table('agora.Branch')->whereIn('BranchId', $this->createdSites)->delete();
        }
        parent::tearDown();
    }

    public function test_pattern_capture_is_immutable_idempotent_and_preserves_prior_revisions_in_the_grid(): void
    {
        $this->get(route('app.stockrecon.weekly', ['branches' => self::SITES, 'from' => '2026-09-14', 'to' => '2026-09-20']))
            ->assertOk()->assertSee('Open cross-site investigation');
        $response = $this->post(route('app.stockrecon.weekly.patterns.store'), ['branches' => self::SITES,
            'period_from' => '2026-09-14', 'period_to' => '2026-09-20', 'pos_code' => 'T0']);
        $response->assertRedirect();
        $pattern = $this->capture();
        $response->assertRedirect(route('app.stockrecon.weekly.show', ['branch' => $pattern->BranchId, 'case' => $pattern->Id]));
        $this->assertSame($pattern->Id, $this->capture()->Id);
        $case = $this->getCase($pattern);
        $source = $this->db()->table('agora.StockWeeklySnapshot')->where('SnapshotRef', $pattern->SnapshotRef)->value('SourceJson');
        $facts = json_decode($case->EvidenceJson);
        $this->assertSame(3, $facts->Sites);
        $this->assertSame(3, $facts->ShortSites);
        $this->assertCount(6, $facts->RelatedCases);
        $this->assertEqualsWithDelta(-30 / 28, $facts->MedianPct, 0.00001);
        $this->assertSame('Cross-Site Patterns', $case->RegisterName);
        $this->get(route('app.stockrecon.weekly.show', ['branch' => $pattern->BranchId, 'case' => $pattern->Id]))
            ->assertOk()->assertSee('TEST-Pattern 973')->assertSee('Head-office sign-off');
        $this->buildSites([973]);
        $second = $this->capture();
        $this->assertNotSame($pattern->Id, $second->Id);
        $this->assertSame($case->EvidenceJson, $this->getCase($pattern)->EvidenceJson);
        $this->assertSame($source, $this->db()->table('agora.StockWeeklySnapshot')->where('SnapshotRef', $pattern->SnapshotRef)->value('SourceJson'));
        $rows = app(ProcedureService::class)->call('agora.usp_StockRecon_GridWeeklyCases', ['BranchIds' => implode(',', self::SITES), 'PageSize' => 1000] + WeeklyStockService::access());
        $this->assertCount(2, $rows->where('RegisterName', 'Cross-Site Patterns'));
        $csv = $this->get(route('app.grids.extract', ['grid' => 'app.stockrecon.weekly', 'branches' => self::SITES,
            'from' => '2026-09-14', 'to' => '2026-09-20', 'columns' => 'RegisterName,Description,Status,OwnerName,DueDate,Finding,RecoveryAmount,CorrectionStatus,SignedOffName']))->assertOk()->streamedContent();
        $this->assertSame(2, substr_count($csv, 'Cross-Site Patterns'));
    }

    public function test_every_pattern_surface_and_owner_requires_all_contributing_site_grants(): void
    {
        $pattern = $this->capture();
        $restricted = $this->person([973, 974]);
        $this->actingAs($restricted);
        $this->refused('WEEKLY_FORBIDDEN', fn () => $this->getCase($pattern));
        $this->refused('WEEKLY_FORBIDDEN', fn () => $this->capture());
        $this->refused('WEEKLY_FORBIDDEN', fn () => app(WeeklyStockService::class)->save($pattern->BranchId, $pattern->Id, ['ExpectedVersion' => '0x0000000000000000', 'Reason' => 'TEST-Unauthorised reopen attempt'], 'reopen'));
        $rows = app(ProcedureService::class)->call('agora.usp_StockRecon_GridWeeklyCases', ['SnapshotRef' => $pattern->SnapshotRef] + WeeklyStockService::access());
        $this->assertCount(0, $rows);
        $csv = $this->get(route('app.grids.extract', ['grid' => 'app.stockrecon.weekly', 'snapshot' => $pattern->SnapshotRef]))->assertOk()->streamedContent();
        $this->assertStringNotContainsString('Cross-Site Patterns', $csv);
        $this->get(route('app.stockrecon.weekly.show', ['branch' => $pattern->BranchId, 'case' => $pattern->Id]))->assertForbidden();
        $this->actingAs($this->reviewer);
        app(BranchContext::class)->setWorkspace('ho')->setAllowed(self::SITES)->set(null);
        $owners = app(WeeklyStockService::class)->get($pattern->BranchId, $pattern->Id)['owners'];
        $this->assertNotContains($restricted->Id, $owners->pluck('Id')->all());
        $input = $this->closure($pattern);
        $input['OwnerId'] = $restricted->Id;
        $this->refused('WEEKLY_OWNER', fn () => app(WeeklyStockService::class)->save($pattern->BranchId, $pattern->Id, $input));
        $this->reviewer->forceFill(['Workspace' => 'branch', 'HomeBranchId' => 973])->save();
        app(BranchContext::class)->setWorkspace('branch')->set(973);
        $this->refused('WEEKLY_FORBIDDEN', fn () => $this->getCase($pattern));
        $this->post(route('app.stockrecon.weekly.patterns.store'), ['branches' => self::SITES,
            'period_from' => '2026-09-14', 'period_to' => '2026-09-20', 'pos_code' => 'T0'])->assertForbidden();
    }

    public function test_signed_pattern_requires_supported_child_approvals_and_reopening_invalidates_the_parent(): void
    {
        $pattern = $this->capture();
        $original = $this->getCase($pattern);
        $service = app(WeeklyStockService::class);
        $this->refused('WEEKLY_RELATED', fn () => $service->save($pattern->BranchId, $pattern->Id, $this->closure($pattern), 'approve'));
        $children = json_decode($original->EvidenceJson)->RelatedCases;
        foreach ($children as $child) {
            $key = (object) ['BranchId' => $child->BranchId, 'Id' => $child->CaseId];
            $service->save($key->BranchId, $key->Id, $this->closure($key), 'approve');
        }
        $input = $this->closure($pattern);
        $input['RecoveryAmount'] = 5;
        $input['RecoveryReference'] = null;
        $this->refused('WEEKLY_CLOSURE', fn () => $service->save($pattern->BranchId, $pattern->Id, $input, 'approve'));
        $input['RecoveryReference'] = 'TEST-approved recovery 123';
        $service->save($pattern->BranchId, $pattern->Id, $input, 'approve');
        $signed = $this->getCase($pattern);
        $this->assertSame('Closed', $signed->Status);
        $this->assertEquals(5, $signed->RecoveryAmount);
        $this->assertEquals($this->reviewer->Id, $signed->SignedOffBy);
        $this->assertNotNull($signed->ClosedDate);
        $child = (object) ['BranchId' => $children[0]->BranchId, 'Id' => $children[0]->CaseId];
        $service->save($child->BranchId, $child->Id, ['ExpectedVersion' => $this->getCase($child)->VersionToken, 'Reason' => 'TEST-New count evidence needs review'], 'reopen');
        $reopened = $this->getCase($pattern);
        $this->assertSame('Investigating', $reopened->Status);
        $this->assertNull($reopened->SignedOffBy);
        $this->assertNull($reopened->SignedOffAt);
        $this->assertNull($reopened->ClosedDate);
        $this->assertSame($original->EvidenceJson, $reopened->EvidenceJson);
        $history = $service->get($pattern->BranchId, $pattern->Id)['history'];
        $this->assertCount(2, $history);
        $this->assertSame('Closed', json_decode($history->first()->PriorJson)->Status);
        $this->assertEquals($this->reviewer->Id, json_decode($history->first()->PriorJson)->SignedOffBy);
        $this->refused('WEEKLY_STALE', fn () => $service->save($pattern->BranchId, $pattern->Id, $input, 'approve'));
    }

    private function capture(): object
    {
        return app(WeeklyStockService::class)->createPattern(self::SITES, '2026-09-14', '2026-09-20', 'T0');
    }

    private function getCase(object $key): object
    {
        return app(WeeklyStockService::class)->get($key->BranchId, $key->Id)['case'];
    }

    /** @return array<string, mixed> */
    private function closure(object $key): array
    {
        return ['ExpectedVersion' => $this->getCase($key)->VersionToken, 'Status' => 'Investigating', 'OwnerId' => $this->reviewer->Id,
            'DueDate' => '2026-09-24', 'Finding' => 'TEST-Independent recipe and stock evidence verified the master link.',
            'EvidenceReference' => 'TEST-count sheet and master 123', 'ClosureOutcome' => 'System / master data fixed',
            'RecoveryAmount' => 0, 'CorrectionStatus' => 'Y', 'CorrectionReference' => 'TEST-master correction 123',
            'ActionTaken' => 'TEST-Master data controller corrected the mapping and checked every contributing site.',
            'ActionDate' => '2026-09-24', 'SignoffNote' => 'TEST-Head office verified all supporting findings.',
            'Reason' => 'TEST-Record and approve the supported correction'];
    }

    /** @param array<int, int> $sites */
    private function buildSites(array $sites = self::SITES): void
    {
        $source = $this->source([['A1', -10, -10], ['A1', -20, -20]]);
        foreach ($source['recon'] as &$row) {
            $row['POSCode'] = 'T0';
        }
        unset($row);
        foreach ($source['counts'] as &$row) {
            $row['POSCode'] = 'T0';
        }
        unset($row);
        foreach ($sites as $site) {
            app(ProcedureService::class)->write('agora.usp_StockRecon_CreateWeeklySnapshot', ['BranchId' => $site, 'WeekStart' => '2026-09-14',
                'PeriodFrom' => '2026-09-14', 'PeriodTo' => '2026-09-20', 'ReportingAsOf' => '2026-09-23', 'SnapshotRef' => (string) Str::uuid(),
                'SourceReadAt' => '2026-09-23 10:00:00', 'SourceJson' => json_encode($source, JSON_THROW_ON_ERROR), 'CoverageJson' => '[]'] + WeeklyStockService::access('create'));
        }
    }

    /** @param array<int, int> $branches */
    private function person(array $branches): User
    {
        $user = User::query()->acrossBranches()->create(['BranchId' => 2, 'EmailAddress' => 'TEST-pattern-'.Str::uuid().'@agora.invalid',
            'UserName' => 'TEST-Pattern reviewer', 'IsActive' => true, 'IsLocked' => false, 'Workspace' => 'ho', 'PasswordHash' => Str::random(24)]);
        $this->users[] = $user;
        foreach (['view', 'create', 'edit', 'save', 'approve', 'reopen'] as $action) {
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

    private function refused(string $code, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected '.$code);
        } catch (AgoraProcException $e) {
            $this->assertSame($code, $e->code());
        }
    }

    private function db(): Connection
    {
        return DB::connection(config('agora.connections.app'));
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
}
