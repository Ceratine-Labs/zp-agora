<?php

namespace Modules\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Role;

/**
 * The five roles from plan §2, each landing somewhere different.
 *
 * The landing route is data because "where does Finance land" is a question
 * the business changes its mind about; a developer should not be involved.
 * Routes that do not exist yet are left null — the login falls back to the
 * dashboard rather than 500ing on a missing route.
 */
class RoleSeeder extends Seeder
{
    /** Before the user that names one. */
    public int $seedOrder = 20;

    public function run(): void
    {
        $roles = [
            ['Code' => 'executive', 'Name' => 'Executive', 'Workspace' => 'ho', 'LandingRoute' => null, 'IsReadOnly' => false, 'SortOrder' => 10],
            ['Code' => 'finance', 'Name' => 'Finance', 'Workspace' => 'ho', 'LandingRoute' => null, 'IsReadOnly' => false, 'SortOrder' => 20],
            ['Code' => 'operations', 'Name' => 'Operations', 'Workspace' => 'ho', 'LandingRoute' => null, 'IsReadOnly' => false, 'SortOrder' => 30],
            ['Code' => 'branch-manager', 'Name' => 'Branch manager', 'Workspace' => 'branch', 'LandingRoute' => null, 'IsReadOnly' => false, 'SortOrder' => 40],
            ['Code' => 'auditor', 'Name' => 'Auditor', 'Workspace' => 'ho', 'LandingRoute' => null, 'IsReadOnly' => true, 'SortOrder' => 50],
            ['Code' => 'admin', 'Name' => 'System administrator', 'Workspace' => 'ho', 'LandingRoute' => null, 'IsReadOnly' => false, 'SortOrder' => 5],
        ];

        foreach ($roles as $attributes) {
            $role = Role::query()->acrossBranches()->firstOrNew([
                'BranchId' => (int) config('agora.group_branch_id'),
                'Code' => $attributes['Code'],
            ]);
            $role->fill($attributes);
            $role->BranchId = (int) config('agora.group_branch_id');
            $role->save();
        }

        $this->command?->info('  Roles: '.count($roles).'.');
    }
}
