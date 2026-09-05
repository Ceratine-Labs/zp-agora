<?php

namespace Modules\Recon\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Services\MenuService;

/**
 * This module's navigation.
 *
 * Core already seeds `money/bank-recon` under Control → Money, because the
 * menu was built from the customer's own function list before any of it was
 * implemented. MenuService::item() upserts on (workspace, section, path), so
 * this attaches a route to the entry that is already there rather than
 * seeding a second one beside it — a menu with two "Bank reconciliation"
 * links is worse than one with none.
 */
class MenuSeeder extends Seeder
{
    /** After Core's, which creates the sections and the parent this hangs off. */
    public int $seedOrder = 45;

    public function run(): void
    {
        MenuService::item('ho', 'control', [
            'path' => 'money/bank-recon',
            'parent' => 'money',
            'label' => 'Bank reconciliation',
            'route' => 'app.recon.index',
            'hint' => '5 areas',
            'sort' => 10,
        ]);

        // The five areas as their own entries: the recon clerk works in one
        // area at a time and should not have to pass through a hub to reach
        // the one they are on today.
        foreach (config('recon.areas') as $key => $area) {
            MenuService::item('ho', 'control', [
                'path' => 'money/bank-recon/'.strtolower($key),
                'parent' => 'money/bank-recon',
                'label' => $area['label'],
                'url' => '/app/recon/auto/'.$key,
                'sort' => 10,
            ]);
        }
    }
}
