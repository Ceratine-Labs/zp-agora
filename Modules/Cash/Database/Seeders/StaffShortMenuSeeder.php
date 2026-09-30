<?php

namespace Modules\Cash\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Services\MenuService;

/**
 * The staff shorts' navigation (T040).
 *
 *   branch  Today → Raise → Staff short               app.cash.staffshorts
 *           (Core seeded it with no route; this ATTACHES the route rather than
 *           seeding a second entry — MenuService::item() upserts on
 *           (workspace, section, path))
 *   ho      Today → Capture → Staff shorts            app.cash.staffshorts (new)
 *           Today → Waiting on a decision → Staff shorts
 *                                                     app.cash.staffshorts.list
 *           (REPOINTED from the Reports module's read-only staff-shorts report
 *           to the list a short is opened from and decided on — the report is
 *           still in the report library; the menu entry is where an approver
 *           goes to approve)
 *           Control → Money → Staff shorts            app.cash.staffshorts.list (new)
 *
 * The phone screen (app.cash.staffshorts.phone) is reached from the screen, not
 * the menu, as the drop safe and cashup phone screens are.
 *
 * `permission` hides each entry from anybody without cash.staffshort.view
 * whatever their menu ticks say, so the link never 403s in somebody's face. A
 * person whose menu is ticked item by item sees the NEW entries only once they
 * are ticked for them.
 */
class StaffShortMenuSeeder extends Seeder
{
    /** After DropSafeMenuSeeder (51). */
    public int $seedOrder = 52;

    public function run(): void
    {
        MenuService::item('branch', 'today', [
            'path' => 'raise/staff-short',
            'parent' => 'raise',
            'label' => 'Staff short',
            'route' => 'app.cash.staffshorts',
            'permission' => 'cash.staffshort.view',
            'hint' => 'Live',
            'sort' => 20,
        ]);

        MenuService::item('ho', 'today', [
            'path' => 'capture/staff-shorts',
            'parent' => 'capture',
            'label' => 'Staff shorts',
            'route' => 'app.cash.staffshorts',
            'permission' => 'cash.staffshort.view',
            'hint' => 'Live',
            'sort' => 38,
        ]);

        MenuService::item('ho', 'today', [
            'path' => 'decisions/staff-shorts',
            'parent' => 'decisions',
            'label' => 'Staff shorts',
            'route' => 'app.cash.staffshorts.list',
            'permission' => 'cash.staffshort.view',
            'hint' => 'Live',
            'sort' => 70,
        ]);

        MenuService::item('ho', 'control', [
            'path' => 'money/staff-shorts',
            'parent' => 'money',
            'label' => 'Staff shorts',
            'route' => 'app.cash.staffshorts.list',
            'permission' => 'cash.staffshort.view',
            'hint' => 'Live',
            'sort' => 36,
        ]);

        $this->command?->info('  Cash: Staff short on Raise (branch); Staff shorts on Capture, Waiting on a decision and Control → Money (head office).');
    }
}
