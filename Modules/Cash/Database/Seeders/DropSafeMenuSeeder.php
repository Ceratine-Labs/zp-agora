<?php

namespace Modules\Cash\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Services\MenuService;

/**
 * The drop safe's navigation (T039).
 *
 *   branch  Today → Close the day → Drop safe         app.cash.dropsafe
 *           (Core seeded it with no route; this ATTACHES the route rather
 *           than seeding a second entry — MenuService::item() upserts on
 *           (workspace, section, path))
 *   ho      Today → Capture → Drop safe               app.cash.dropsafe (new)
 *           Control → Money → Drop safe bags          app.cash.dropsafe.bags (new)
 *
 * HEAD OFFICE'S "Drop safe collections" under Today → Waiting on a decision
 * stays the Reports module's drop-safe report (decisions/drop-safe): it is the
 * collections set against their bags, which this screen does not replace.
 *
 * The phone screen (app.cash.dropsafe.phone) is reached from the screen, not
 * the menu, as the Z-read and cashup phone screens are.
 *
 * `permission` hides each entry from anybody without cash.dropsafe.view
 * whatever their menu ticks say, so the link never 403s in somebody's face. A
 * person whose menu is ticked item by item sees the NEW entries only once they
 * are ticked for them.
 */
class DropSafeMenuSeeder extends Seeder
{
    /** After TillBalanceMenuSeeder (49), whose Close-the-day entry this sits after. */
    public int $seedOrder = 51;

    public function run(): void
    {
        MenuService::item('branch', 'today', [
            'path' => 'close-the-day/drop-safe',
            'parent' => 'close-the-day',
            'label' => 'Drop safe',
            'route' => 'app.cash.dropsafe',
            'permission' => 'cash.dropsafe.view',
            'hint' => 'Live',
            'sort' => 60,
        ]);

        MenuService::item('ho', 'today', [
            'path' => 'capture/drop-safe',
            'parent' => 'capture',
            'label' => 'Drop safe',
            'route' => 'app.cash.dropsafe',
            'permission' => 'cash.dropsafe.view',
            'hint' => 'Live',
            'sort' => 36,
        ]);

        MenuService::item('ho', 'control', [
            'path' => 'money/drop-safe-bags',
            'parent' => 'money',
            'label' => 'Drop safe bags',
            'route' => 'app.cash.dropsafe.bags',
            'permission' => 'cash.dropsafe.view',
            'hint' => 'Live',
            'sort' => 37,
        ]);

        $this->command?->info('  Cash: Drop safe on Close the day (branch) and Capture (head office); Drop safe bags on Control → Money.');
    }
}
