<?php

namespace Modules\Masters\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Permission;
use Modules\Core\Services\MenuService;
use Modules\Core\Services\PermissionService;

/**
 * Bank accounts (T022, PM decision 01a1ceda-3523): masters.bankaccounts.view
 * and its menu entry under Setup → Sites and forecourt. A seeder of its own
 * because ReferenceMastersPermissionSeeder and ReferenceMastersMenuSeeder are
 * one-shots that may already have run. Created, not granted by name.
 */
class BankAccountMasterSeeder extends Seeder
{
    /** After ReferenceMastersPermissionSeeder (65) and the menu seeders. */
    public int $seedOrder = 67;

    public function run(): void
    {
        Permission::query()->withoutGlobalScopes()->updateOrCreate(
            ['BranchId' => (int) config('agora.group_branch_id', 2), 'Code' => Permission::code('masters', 'bankaccounts', 'view')],
            ['Module' => 'masters', 'Resource' => 'bankaccounts', 'Action' => 'view',
                'Name' => 'See the bank account each site banks each method of payment into', 'SortOrder' => 1390, 'UpdatedAt' => now()]
        );
        app(PermissionService::class)->forgetAll();

        MenuService::item('ho', 'setup', ['path' => 'site-setup', 'label' => 'Sites and forecourt', 'sort' => 25]);
        MenuService::item('ho', 'setup', [
            'path' => 'site-setup/bank-accounts',
            'parent' => 'site-setup',
            'label' => 'Bank accounts',
            'route' => 'app.setup.bank-accounts.index',
            'permission' => 'masters.bankaccounts.view',
            'sort' => 75,
        ]);
    }
}
