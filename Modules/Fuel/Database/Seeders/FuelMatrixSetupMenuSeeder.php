<?php

namespace Modules\Fuel\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Services\MenuService;

/**
 * Setup → Trading rules → Fuel matrix (T025).
 *
 * The fuel matrix is a master, and the masters live under Setup → Trading
 * rules beside the stock recon master and the critical lines. Core seeded
 * this entry as a dead placeholder, and FuelMenuSeeder (8 Nov 2026) switched
 * it off when the Fuel section took the screen. This ATTACHES the route and
 * the permission to that same row — MenuService::item() upserts on
 * (workspace, section, path) — rather than adding a second one beside it, and
 * switches it back on.
 *
 * Its own seeder because FuelMenuSeeder is already in the agora.SeedMaster
 * ledger, on the customer's instance as well, and will not run again.
 *
 * Head office only, like the other two masters there. The Fuel section's own
 * entry stays: Fuel is where the price history and the report live.
 */
class FuelMatrixSetupMenuSeeder extends Seeder
{
    /** After FuelMenuSeeder (131), which switched the placeholder off. */
    public int $seedOrder = 132;

    public function run(): void
    {
        MenuService::item('ho', 'setup', [
            'path' => 'trading-rules/fuel-matrix',
            'parent' => 'trading-rules',
            'label' => 'Fuel matrix',
            'route' => 'app.fuel.matrix',
            'permission' => 'fuel.prices.view',
            'sort' => 10,
        ]);

        $this->command?->info('Setup → Trading rules → Fuel matrix now points at app.fuel.matrix.');
    }
}
