<?php

namespace Modules\Reports\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Services\MenuService;

/**
 * Control → Governance → Audit trail, wired to the audit-trail report.
 *
 * Core's MenuSeeder has carried the entry since the menu was first laid out,
 * with no route and no hint: a placeholder. This gives it the report's URL and
 * the "Live" hint. MenuService::item() upserts on (workspace, section, path),
 * so this updates the existing entry rather than adding a second one.
 *
 * Its own seeder, not a line in Reports' MenuSeeder: that one has already run
 * on the customer's instance and the ledger will not run it again.
 */
class AuditTrailMenuSeeder extends Seeder
{
    /** After Core's MenuSeeder, which creates Control → Governance. */
    public int $seedOrder = 47;

    public function run(): void
    {
        MenuService::item('ho', 'control', [
            'path' => 'governance/audit-trail',
            'parent' => 'governance',
            'label' => 'Audit trail',
            // URL, not route name: the report route takes the slug as a
            // parameter, and MenuItem::href() calls route() with none.
            'url' => '/app/reports/audit-trail',
            'hint' => 'Live',
            'sort' => 10,
        ]);

        $this->command?->info('  Reports: Audit trail on Control → Governance (head office).');
    }
}
