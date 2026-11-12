<?php

namespace Modules\Partners\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Services\MenuService;

/**
 * The trading partners' navigation (T024).
 *
 * Core seeded Setup → Trading rules → Suppliers and Account customers as
 * placeholders with no route; this ATTACHES the routes to them rather than
 * adding second links, keeping Core's labels and places (sorts 40 and 50).
 * MenuService::item() overwrites every field it is given and nulls the ones
 * it is not, so all of them are given.
 *
 * Head office only, like the stock master: a supplier list is group-wide, and
 * a site manager does not maintain it.
 *
 * Each entry carries the same permission as its route's `can:`, so it is
 * hidden from anybody who could not open it. Upserts on (workspace, section,
 * path): re-running changes nothing.
 */
class MenuSeeder extends Seeder
{
    /** After Core's (30), which creates the placeholders. */
    public int $seedOrder = 34;

    public function run(): void
    {
        MenuService::item('ho', 'setup', [
            'path' => 'trading-rules/suppliers',
            'parent' => 'trading-rules',
            'label' => 'Suppliers',
            'route' => 'app.setup.suppliers.index',
            'permission' => 'partners.suppliers.view',
            'sort' => 40,
        ]);

        MenuService::item('ho', 'setup', [
            'path' => 'trading-rules/account-customers',
            'parent' => 'trading-rules',
            'label' => 'Account customers',
            'route' => 'app.setup.customers.index',
            'permission' => 'partners.customers.view',
            'sort' => 50,
        ]);
    }
}
