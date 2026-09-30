<?php

namespace Modules\Cash\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Services\MenuService;

/**
 * Cash's navigation: ZREAD Allocation (T036).
 *
 * The BRANCH entry already exists — Core seeded Today → Close the day →
 * "Z-read allocation" with no route, as the plan made visible. This ATTACHES
 * the route to it rather than seeding a second entry beside it: two links
 * called Z-read allocation would be worse than one that went nowhere.
 * MenuService::item() upserts on (workspace, section, path), so it is an
 * update, not a duplicate.
 *
 * HEAD OFFICE gets it under Today → Capture, beside Daily banking, in the same
 * order the branch has them — the Z-reads are allocated before the cashup can
 * be balanced against them.
 *
 * The phone's confirm list (app.cash.zread.confirm) is deliberately not a
 * menu entry: it is reached from the screen, and will be reached by device
 * once E18's pick() exists.
 *
 * `permission` hides the entry from anybody without cash.zread.view whatever
 * their menu ticks say, so the link never 403s in somebody's face.
 */
class MenuSeeder extends Seeder
{
    /** After Core's (30), which creates the sections and the placeholder. */
    public int $seedOrder = 47;

    public function run(): void
    {
        MenuService::item('branch', 'today', [
            'path' => 'close-the-day/zread',
            'parent' => 'close-the-day',
            'label' => 'Z-read allocation',
            'route' => 'app.cash.zread',
            'permission' => 'cash.zread.view',
            'hint' => 'Live',
            'sort' => 40,
        ]);

        MenuService::item('ho', 'today', [
            'path' => 'capture/zread',
            'parent' => 'capture',
            'label' => 'Z-read allocation',
            'route' => 'app.cash.zread',
            'permission' => 'cash.zread.view',
            'hint' => 'Live',
            'sort' => 25,
        ]);

        $this->command?->info('  Cash: Z-read allocation on Today → Close the day (branch) and Today → Capture (head office).');
    }
}
