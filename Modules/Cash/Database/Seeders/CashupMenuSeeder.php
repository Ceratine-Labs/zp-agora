<?php

namespace Modules\Cash\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Services\MenuService;

/**
 * Daily banking's navigation (T037).
 *
 * Core seeded the plan's entries with no route — "Daily banking" under the
 * branch's Close the day and head office's Capture, "Daily banking exceptions"
 * under Control → Money — and this ATTACHES the routes to them rather than
 * seeding second entries beside them. MenuService::item() upserts on
 * (workspace, section, path), so it is an update, not a duplicate.
 *
 *   branch  Today → Close the day → Daily banking         app.cash.banking
 *   ho      Today → Capture → Daily banking               app.cash.banking
 *           Today → Capture → Cashups                     app.cash.cashups (new)
 *           Today → The day → Z-reads with no cashup      app.cash.cashups.nocashup (new)
 *           Control → Money → Daily banking exceptions    app.cash.cashups
 *
 * HEAD OFFICE'S "Daily banking" used to open the Reports module's
 * daily-banking report. That report is now the manager's view in the report
 * library and no longer claims the Capture entry (its `menu` key was removed
 * in Modules/Reports/Config/config.php by the same change), so the Capture
 * entry goes to the screen where a cashup is captured and confirmed.
 *
 * "Open cashups" stays the Reports queue (the-day/open-cashups), which now
 * reads the same figures and the same status as these screens.
 *
 * The phone's list (app.cash.banking.phone) and a grid row's panel are
 * reached from the screens, not the menu.
 *
 * `permission` hides the entry from anybody without cash.cashup.view whatever
 * their menu ticks say, so the link never 403s in somebody's face. A person
 * whose menu is ticked item by item sees the two NEW entries only once they
 * are ticked for them.
 */
class CashupMenuSeeder extends Seeder
{
    /** After Core's (30), Reports' (46) and Cash's first (47). */
    public int $seedOrder = 48;

    public function run(): void
    {
        MenuService::item('branch', 'today', [
            'path' => 'close-the-day/daily-banking',
            'parent' => 'close-the-day',
            'label' => 'Daily banking',
            'route' => 'app.cash.banking',
            'permission' => 'cash.cashup.view',
            'hint' => 'Live',
            'sort' => 50,
        ]);

        MenuService::item('ho', 'today', [
            'path' => 'capture/daily-banking',
            'parent' => 'capture',
            'label' => 'Daily banking',
            'route' => 'app.cash.banking',
            'permission' => 'cash.cashup.view',
            'hint' => 'Live',
            'sort' => 30,
        ]);

        MenuService::item('ho', 'today', [
            'path' => 'capture/cashups',
            'parent' => 'capture',
            'label' => 'Cashups',
            'route' => 'app.cash.cashups',
            'permission' => 'cash.cashup.view',
            'hint' => 'Live',
            'sort' => 32,
        ]);

        MenuService::item('ho', 'today', [
            'path' => 'the-day/zreads-no-cashup',
            'parent' => 'the-day',
            'label' => 'Z-reads with no cashup',
            'route' => 'app.cash.cashups.nocashup',
            'permission' => 'cash.cashup.view',
            'hint' => 'Live',
            'sort' => 55,
        ]);

        MenuService::item('ho', 'control', [
            'path' => 'money/banking-exceptions',
            'parent' => 'money',
            'label' => 'Daily banking exceptions',
            'route' => 'app.cash.cashups',
            'permission' => 'cash.cashup.view',
            'hint' => 'Live',
            'sort' => 30,
        ]);

        $this->command?->info('  Cash: Daily banking on Close the day (branch) and Capture (head office); Cashups, Z-reads with no cashup, Daily banking exceptions.');
    }
}
