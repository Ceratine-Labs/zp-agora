<?php

namespace Tests\Feature\Core;

use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Models\UserRole;
use Modules\Core\Services\PermissionService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * RBAC (T008).
 *
 * The acceptance is about the SPLIT: a route that commits must refuse the
 * person who may only look. The plan writes it as cash.allocate_zread, which
 * belongs to a module that does not exist yet; recon.runs.execute is the same
 * shape against the one module that already writes to the customer's estate.
 */
class PermissionServiceTest extends TestCase
{
    private PermissionService $service;

    private int $branchId;

    /** @var array<int, User> */
    private array $made = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(PermissionService::class);
        $this->branchId = (int) config('agora.group_branch_id', 2);
    }

    protected function tearDown(): void
    {
        foreach ($this->made as $user) {
            UserRole::query()->withoutGlobalScopes()->where('UserId', $user->Id)->delete();
            User::query()->withoutGlobalScopes()->where('Id', $user->Id)->delete();
        }

        parent::tearDown();
    }

    private function userWithRole(string $roleCode): User
    {
        $role = Role::query()->withoutGlobalScopes()->where('Code', $roleCode)->firstOrFail();

        $user = User::query()->withoutGlobalScopes()->create([
            'BranchId' => $this->branchId,
            'EmailAddress' => 'TEST-'.$roleCode.'-'.uniqid().'@agora.invalid',
            'UserName' => 'TEST-'.$roleCode,
            'RoleId' => $role->Id,
            'IsActive' => true,
            'IsLocked' => false,
            'PasswordHash' => User::UNUSABLE_PASSWORD,
        ]);

        UserRole::query()->withoutGlobalScopes()->create([
            'BranchId' => $this->branchId,
            'UserId' => $user->Id,
            'RoleId' => $role->Id,
            'IsPrimary' => true,
        ]);

        $this->service->forget($user);
        $this->made[] = $user;

        return $user;
    }

    public function test_finance_may_commit_a_reconciliation_and_the_auditor_may_not(): void
    {
        $finance = $this->userWithRole('finance');
        $auditor = $this->userWithRole('auditor');

        $this->assertTrue($this->service->userHas($finance, 'recon.runs.execute'), 'Finance must be able to commit.');
        $this->assertFalse($this->service->userHas($auditor, 'recon.runs.execute'), 'The Auditor must never commit.');

        // Both may look. The split is about writing, not about access.
        $this->assertTrue($this->service->userHas($finance, 'recon.runs.view'));
        $this->assertTrue($this->service->userHas($auditor, 'recon.runs.view'));
    }

    public function test_operations_may_preview_but_not_execute_or_reverse(): void
    {
        $ops = $this->userWithRole('operations');

        $this->assertTrue($this->service->userHas($ops, 'recon.runs.create'), 'Operations previews.');
        $this->assertTrue($this->service->userHas($ops, 'recon.runs.delete'), 'Operations clears a preview.');
        $this->assertFalse($this->service->userHas($ops, 'recon.runs.execute'), 'Operations must not commit.');
        $this->assertFalse($this->service->userHas($ops, 'recon.runs.reverse'), 'Operations must not reverse.');
    }

    public function test_the_auditor_reads_everything_and_writes_nothing(): void
    {
        $auditor = $this->userWithRole('auditor');

        foreach (Permission::query()->withoutGlobalScopes()->get() as $permission) {
            $held = $this->service->userHas($auditor, $permission->Code);

            if ($permission->Action === 'view' || $permission->Module === 'audit') {
                $this->assertTrue($held, "Auditor should hold {$permission->Code}.");

                continue;
            }

            $this->assertFalse($held, "Auditor must NOT hold {$permission->Code}.");
        }
    }

    public function test_admin_holds_everything(): void
    {
        $admin = $this->userWithRole('admin');

        foreach (Permission::query()->withoutGlobalScopes()->get() as $permission) {
            $this->assertTrue($this->service->userHas($admin, $permission->Code), "Admin should hold {$permission->Code}.");
        }
    }

    public function test_a_branch_manager_cannot_reach_reconciliation_at_all(): void
    {
        $manager = $this->userWithRole('branch-manager');

        $this->assertFalse($this->service->userHas($manager, 'recon.runs.view'));
        $this->assertFalse($this->service->userHas($manager, 'recon.runs.execute'));
        $this->assertTrue($this->service->userHas($manager, 'reports.report.view'), 'A site still reads its own numbers.');
    }

    /** @return array<string, array{0: string, 1: string, 2: bool}> */
    public static function patterns(): array
    {
        return [
            'exact match' => ['cash.dropsafe.view', 'cash.dropsafe.view', true],
            'action differs' => ['cash.dropsafe.view', 'cash.dropsafe.edit', false],
            'resource wildcard' => ['cash.*.view', 'cash.dropsafe.view', true],
            'resource wildcard, wrong module' => ['cash.*.view', 'fuel.dropsafe.view', false],
            'module wildcard' => ['*.*.view', 'anything.at.view', true],
            'module wildcard, wrong action' => ['*.*.view', 'cash.dropsafe.edit', false],
            'whole module' => ['audit.*.*', 'audit.activity.view', true],
            'bare star is everything' => ['*', 'cash.dropsafe.delete', true],
            'two parts are padded' => ['cash.*', 'cash.dropsafe.delete', true],
            'case is irrelevant' => ['CASH.Dropsafe.VIEW', 'cash.dropsafe.view', true],
        ];
    }

    #[DataProvider('patterns')]
    public function test_wildcard_expansion(string $pattern, string $code, bool $expected): void
    {
        $this->assertSame($expected, $this->service->matches($pattern, $code), "{$pattern} vs {$code}");
    }
}
