<?php

namespace Modules\Utilities\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Services\MenuService;

/**
 * This module's navigation.
 *
 * Core seeded both entries from the mockup before any screen existed, so this
 * ATTACHES the route to them rather than adding a second link beside each:
 *
 *   head office  Today → Capture → Utility meters
 *   branch       Today → Raise → Utility reading
 *
 * THE HEAD-OFFICE ENTRY WAS POINTED AT THE READ-ONLY REPORT until now — the
 * Reports module's MenuSeeder (seedOrder 46) attached /app/reports/utility-meters
 * to it. That report showed readings; this screen captures them and carries the
 * same readings as its grid, so it is what "Capture → Utility meters" should
 * open. seedOrder 47 makes that the settled answer on a fresh database, where
 * both seeders run in order. The report stays reachable from the report library.
 *
 * MenuService::item() upserts on (workspace, section, path).
 */
class MenuSeeder extends Seeder
{
    /** After Core's (30), which creates the entries, and Reports' (46). */
    public int $seedOrder = 47;

    public function run(): void
    {
        MenuService::item('ho', 'today', [
            'path' => 'capture/utility-meters',
            'parent' => 'capture',
            'label' => 'Utility meters',
            'route' => 'app.utilities.index',
            'permission' => 'utilities.readings.view',
            // Where Reports' seeder left it among its neighbours, which carry
            // no hint either.
            'sort' => 140,
        ]);

        MenuService::item('branch', 'today', [
            'path' => 'raise/utility-reading',
            'parent' => 'raise',
            'label' => 'Utility reading',
            'route' => 'app.utilities.index',
            'permission' => 'utilities.readings.view',
            'hint' => 'Live',
            'sort' => 40,
        ]);
    }
}
