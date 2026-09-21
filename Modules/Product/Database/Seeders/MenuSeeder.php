<?php

namespace Modules\Product\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Services\MenuService;

/**
 * This module's navigation (T025).
 *
 * The sidebar and mega menus are database-driven (plan §3.10) — never edit a
 * blade nav file. MenuService::item() upserts on (workspace, section, path),
 * so this ATTACHES a route and a permission to an item the shell already
 * seeded as a dead placeholder rather than adding a second entry beside it:
 * Setup → Trading rules → Products has existed with a NULL RouteName since
 * Core seeded the mega menu, and a menu carrying two Products would be worse
 * than one that goes nowhere.
 *
 * The listing lives at head office and NOT on the branch workspace. An item
 * number is per site, but the decision to add or retire a line is not a
 * branch one — when the editor ships, a site manager who wants a new line
 * asks for it.
 */
class MenuSeeder extends Seeder
{
    /** After Core's MenuSeeder, which seeded the section and the placeholder. */
    public int $seedOrder = 31;

    public function run(): void
    {
        MenuService::item('ho', 'setup', [
            'path' => 'trading-rules/products',
            'parent' => 'trading-rules',
            'label' => 'Stock master',
            'route' => 'app.master.stock.index',
            'permission' => 'master.stock.view',
            'sort' => 20,
        ]);

        $this->command?->info('Setup → Trading rules → Stock master now points at app.master.stock.index.');
    }
}
