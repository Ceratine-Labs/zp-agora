<?php

namespace Modules\Masters\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Services\MenuService;

/**
 * The branch master's navigation (T021).
 *
 * Core seeded Setup → The business → Branches as a placeholder with no route;
 * this ATTACHES the route to it rather than adding a second link, keeping
 * Core's label, hint and place (sort 10). MenuService::item() overwrites every
 * field it is given and nulls the ones it is not, so all of them are given.
 *
 * It adds one entry beside it — Regions, brands and formats — which the
 * mockup had no slot for.
 *
 * Each entry carries the same permission as its route's `can:`, so it is
 * hidden from anybody who could not open it. Upserts on (workspace, section,
 * path): re-running changes nothing.
 */
class MenuSeeder extends Seeder
{
    /** After Core's (30), which creates the placeholder. */
    public int $seedOrder = 32;

    public function run(): void
    {
        MenuService::item('ho', 'setup', [
            'path' => 'business/branches',
            'parent' => 'business',
            'label' => 'Branches',
            'route' => 'app.setup.branches.index',
            'permission' => 'masters.branches.view',
            'hint' => '31',
            'sort' => 10,
        ]);

        MenuService::item('ho', 'setup', [
            'path' => 'business/branch-groupings',
            'parent' => 'business',
            'label' => 'Regions, brands and formats',
            'route' => 'app.setup.branch-groupings.index',
            'permission' => 'masters.groupings.view',
            'sort' => 15,
        ]);
    }
}
