<?php

namespace Modules\Cash\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Services\MenuService;

/**
 * Till balance and pump shortages' navigation (T038). Both entries are new:
 * Core seeded neither.
 *
 *   branch  Today → Close the day → Till balance          app.cash.tillbalance
 *   ho      Today → Capture → Till balance                app.cash.tillbalance
 *           Control → Money → Pump shortages              app.cash.pumpshortages
 *
 * A till balance is captured where the cashups are — the site office, and
 * head office when it cashes up for a site — so it sits beside Daily banking in
 * both workspaces, just after it. Pump shortages is a head-office result set
 * across sites, beside the other money exceptions.
 *
 * `permission` hides each entry from anybody without its view permission
 * whatever their menu ticks say, so the link never 403s in somebody's face. A
 * person whose menu is ticked item by item sees the new entries only once they
 * are ticked for them.
 */
class TillBalanceMenuSeeder extends Seeder
{
    /** After CashupMenuSeeder (48), whose Daily banking entries these sit beside. */
    public int $seedOrder = 49;

    public function run(): void
    {
        MenuService::item('branch', 'today', [
            'path' => 'close-the-day/till-balance',
            'parent' => 'close-the-day',
            'label' => 'Till balance',
            'route' => 'app.cash.tillbalance',
            'permission' => 'cash.tillbalance.view',
            'hint' => 'Live',
            'sort' => 55,
        ]);

        MenuService::item('ho', 'today', [
            'path' => 'capture/till-balance',
            'parent' => 'capture',
            'label' => 'Till balance',
            'route' => 'app.cash.tillbalance',
            'permission' => 'cash.tillbalance.view',
            'hint' => 'Live',
            'sort' => 34,
        ]);

        MenuService::item('ho', 'control', [
            'path' => 'money/pump-shortages',
            'parent' => 'money',
            'label' => 'Pump shortages',
            'route' => 'app.cash.pumpshortages',
            'permission' => 'cash.pumpshortages.view',
            'hint' => 'Live',
            'sort' => 35,
        ]);

        $this->command?->info('  Cash: Till balance on Close the day (branch) and Capture (head office); Pump shortages on Control → Money.');
    }
}
