<?php

namespace Modules\Masters\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\Permission;
use Modules\Core\Services\PermissionService;

/**
 * The reference masters' permissions (T022): masters.{resource}.view, one per
 * master — feature-rules §4 shape, `{module}.{resource}.{action}`.
 *
 * VIEW ONLY. Each master is a read-only listing over PumpIT, so there is no
 * edit, save or delete to grant; when a master is adopted or migrated by name
 * its edit permissions arrive with that change, in a seeder of their own.
 *
 * CREATED, NOT GRANTED BY NAME, like the branch master's: an administrator's
 * `*.*.*` and an auditor's `*.*.view` already cover these at the check; anyone
 * else is ticked per person or per role on Setup → Users and access.
 *
 * Upserts on the code, so a rerun after `--forget` changes nothing but the
 * wording. Writes agora.Permission only.
 */
class ReferenceMastersPermissionSeeder extends Seeder
{
    /** After MastersPermissionSeeder (64). */
    public int $seedOrder = 65;

    /** @var array<string, string> resource => what the view permission lets a person see */
    public const PERMISSIONS = [
        'profitcentres' => 'See the profit centres, the sites running each and the POS categories mapped to it',
        'standardcategories' => 'See the standard categories, their supplier and sales GL codes and the POS categories mapped to each',
        'categorygl' => 'See every POS category\'s standard category, profit centre, GL codes and GP / days-cover band, per branch',
        'expenses' => 'See the expense codes: GL account, VAT, asset flag and approver, per branch',
        'tills' => 'See the tills at each site, their POS and when each last cashed up',
        'shifts' => 'See the shifts at each site and when each last cashed up',
        'transactiontypes' => 'See the purchase-request transaction types and document counters at each site',
        'fueltypes' => 'See the fuel grades at each forecourt, their POS codes and pumps',
        'tanks' => 'See the tanks at each forecourt and the pumps drawing from them',
        'pumps' => 'See every pump, its reading order, grade and tank, per forecourt',
    ];

    public function run(): void
    {
        $branchId = (int) config('agora.group_branch_id', 2);
        $sort = 1200;

        foreach (self::PERMISSIONS as $resource => $name) {
            Permission::query()->withoutGlobalScopes()->updateOrCreate(
                ['BranchId' => $branchId, 'Code' => Permission::code('masters', $resource, 'view')],
                [
                    'Module' => 'masters',
                    'Resource' => $resource,
                    'Action' => 'view',
                    'Name' => $name,
                    'SortOrder' => $sort += 10,
                    'UpdatedAt' => now(),
                ]
            );
        }

        app(PermissionService::class)->forgetAll();

        $this->command?->info('  Masters: '.count(self::PERMISSIONS).' reference master permissions created. Tick them per person on Setup → Users and access.');
    }
}
