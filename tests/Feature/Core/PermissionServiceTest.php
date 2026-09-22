<?php

namespace Tests\Feature\Core;

use Modules\Core\Models\Permission;
use Modules\Core\Models\User;
use Modules\Core\Models\UserPermission;
use Modules\Core\Services\PermissionService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * RBAC (T008), after roles were retired on 22 September 2026.
 *
 * The acceptance is unchanged and it was never really about roles: a route
 * that COMMITS must refuse the person who may only look. The plan writes it as
 * cash.allocate_zread, which belongs to a module that does not exist yet;
 * recon.runs.execute is the same shape against the one module that already
 * writes to the customer's estate.
 *
 * What changed is where a person's grants come from. There is one source now —
 * agora.UserPermission — so each fixture below states the grants it is about
 * IN THE TEST, instead of naming a role and depending on what a seeder
 * happened to put in it. That is a better test than the one it replaces: the
 * old version of "Operations may preview but not commit" would have kept
 * passing if somebody had quietly widened the Operations role.
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
            UserPermission::query()->withoutGlobalScopes()->where('UserId', $user->Id)->delete();
            User::query()->withoutGlobalScopes()->where('Id', $user->Id)->delete();
        }

        parent::tearDown();
    }

    /**
     * A person holding exactly the permissions these patterns match.
     *
     * The patterns are expanded against agora.Permission here, the same way
     * RolePermissionSeeder expanded a role's — a grant is always a concrete
     * permission id, and the wildcard is a convenience for writing the test.
     *
     * @param  array<int, string>  $patterns
     */
    private function userWith(array $patterns): User
    {
        $branchId = $this->branchId;

        $user = User::query()->withoutGlobalScopes()->create([
            'BranchId' => $branchId,
            'EmailAddress' => 'TEST-perm-'.uniqid().'@agora.invalid',
            'UserName' => 'TEST-perm',
            'IsActive' => true,
            'IsLocked' => false,
            'PasswordHash' => User::UNUSABLE_PASSWORD,
        ]);

        foreach (Permission::query()->withoutGlobalScopes()->get() as $permission) {
            if (! $this->service->anyMatches($patterns, $permission->Code)) {
                continue;
            }

            UserPermission::query()->withoutGlobalScopes()->create([
                'BranchId' => $branchId,
                'UserId' => $user->Id,
                'PermissionId' => $permission->Id,
            ]);
        }

        $this->service->forget($user);
        $this->made[] = $user;

        return $user;
    }

    public function test_the_person_who_commits_is_not_the_person_who_only_reads(): void
    {
        // The whole acceptance of T008 in two fixtures: everything in recon
        // against read-only across the system.
        $finance = $this->userWith(['recon.*.*']);
        $auditor = $this->userWith(['*.*.view']);

        $this->assertTrue($this->service->userHas($finance, 'recon.runs.execute'), 'Finance must be able to commit.');
        $this->assertFalse($this->service->userHas($auditor, 'recon.runs.execute'), 'The Auditor must never commit.');

        // Both may look. The split is about writing, not about access.
        $this->assertTrue($this->service->userHas($finance, 'recon.runs.view'));
        $this->assertTrue($this->service->userHas($auditor, 'recon.runs.view'));
    }

    public function test_previewing_can_be_granted_without_committing(): void
    {
        // Granted per action, which is the point of the three-segment slug:
        // previewing reads, committing stamps rows in the customer's estate.
        $ops = $this->userWith(['recon.runs.view', 'recon.runs.create', 'recon.runs.delete']);

        $this->assertTrue($this->service->userHas($ops, 'recon.runs.create'), 'This person previews.');
        $this->assertTrue($this->service->userHas($ops, 'recon.runs.delete'), 'And clears a preview.');
        $this->assertFalse($this->service->userHas($ops, 'recon.runs.execute'), 'But must not commit.');
        $this->assertFalse($this->service->userHas($ops, 'recon.runs.reverse'), 'And must not reverse.');
    }

    public function test_a_read_everything_grant_writes_nothing(): void
    {
        $auditor = $this->userWith(['*.*.view', 'audit.*.*']);

        foreach (Permission::query()->withoutGlobalScopes()->get() as $permission) {
            $held = $this->service->userHas($auditor, $permission->Code);

            if ($permission->Action === 'view' || $permission->Module === 'audit') {
                $this->assertTrue($held, "Should hold {$permission->Code}.");

                continue;
            }

            $this->assertFalse($held, "Must NOT hold {$permission->Code}.");
        }
    }

    public function test_a_star_grant_holds_everything(): void
    {
        $admin = $this->userWith(['*.*.*']);

        foreach (Permission::query()->withoutGlobalScopes()->get() as $permission) {
            $this->assertTrue($this->service->userHas($admin, $permission->Code), "Should hold {$permission->Code}.");
        }
    }

    public function test_a_grant_that_names_no_recon_slug_reaches_none_of_it(): void
    {
        $manager = $this->userWith(['core.*.*', 'reports.catalogue.view', 'reports.report.view']);

        $this->assertFalse($this->service->userHas($manager, 'recon.runs.view'));
        $this->assertFalse($this->service->userHas($manager, 'recon.runs.execute'));
        $this->assertTrue($this->service->userHas($manager, 'reports.report.view'), 'They still read their own numbers.');
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
