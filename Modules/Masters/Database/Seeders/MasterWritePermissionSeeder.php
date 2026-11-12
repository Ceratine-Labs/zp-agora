<?php

namespace Modules\Masters\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Permission;
use Modules\Core\Services\PermissionService;

/**
 * The reference masters' write-back permissions (T022; CLAUDE.md rule 1, PM
 * decision 01a1cecf-8e08): masters.{resource}.edit, one per master that
 * writes PumpIT. Edit covers the form, its save and Put back; there is no
 * delete, because no PumpIT row is removed.
 *
 * CREATED, NOT GRANTED BY NAME: an administrator's `*.*.*` covers these;
 * anyone else is ticked on Setup → Users and access. An auditor's `*.*.view`
 * does not. Upserts on the code. Writes agora.Permission only.
 */
class MasterWritePermissionSeeder extends Seeder
{
    /** After ReferenceMastersPermissionSeeder (65). */
    public int $seedOrder = 66;

    /** @var array<string, string> resource => what editing it writes */
    public const PERMISSIONS = [
        'categorygl' => 'Change a POS category\'s GP and days-cover band and active flag — written to PumpIT\'s SDK_CategoryGLCode; needs a reason',
    ];

    public function run(): void
    {
        $branchId = (int) config('agora.group_branch_id', 2);
        $sort = 1400;

        foreach (self::PERMISSIONS as $resource => $name) {
            Permission::query()->withoutGlobalScopes()->updateOrCreate(
                ['BranchId' => $branchId, 'Code' => Permission::code('masters', $resource, 'edit')],
                ['Module' => 'masters', 'Resource' => $resource, 'Action' => 'edit', 'Name' => $name,
                    'SortOrder' => $sort += 10, 'UpdatedAt' => now()]
            );
        }

        app(PermissionService::class)->forgetAll();

        $this->command?->info('  Masters: '.count(self::PERMISSIONS).' write-back permission(s) created.');
    }
}
