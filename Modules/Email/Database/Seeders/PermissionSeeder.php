<?php

namespace Modules\Email\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Permission;

final class PermissionSeeder extends Seeder
{
    public int $seedOrder = 80;

    public function run(): void
    {
        foreach (['view' => 'View email templates and samples', 'edit' => 'Create and edit email templates and branch overrides'] as $action => $name) {
            Permission::query()->acrossBranches()->updateOrCreate([
                'BranchId' => config('agora.group_branch_id'), 'Code' => 'email.templates.'.$action,
            ], ['Module' => 'email', 'Resource' => 'templates', 'Action' => $action, 'Name' => $name, 'SortOrder' => 800]);
        }
    }
}
