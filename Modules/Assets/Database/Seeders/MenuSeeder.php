<?php

namespace Modules\Assets\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Services\MenuService;

/**
 * The Assets module's navigation (T057).
 *
 * Core seeded four asset entries from the mockup before any screen existed,
 * all with a NULL route. This ATTACHES the routes to them rather than adding a
 * second link beside each:
 *
 *   head office  Setup → People and assets → Asset register
 *   branch       Assets → Equipment → Asset register · Movement · Repairs
 *
 * and adds the three head-office entries the mockup had no slot for:
 *
 *   head office  Setup → People and assets → Asset movement · Asset repair ·
 *                Asset groups and types
 *
 * Each entry carries the same permission as its route's own `can:`, so it is
 * hidden from anybody who could not open it (docs/development.md §7). Hints
 * are kept as Core seeded them — MenuService::item() overwrites every field
 * it is given and nulls the ones it is not.
 *
 * MenuService::item() upserts on (workspace, section, path), so re-running
 * changes nothing.
 */
class MenuSeeder extends Seeder
{
    /** After Core's (30), which creates the placeholders, and Reports' (46) and Utilities' (47). */
    public int $seedOrder = 48;

    public function run(): void
    {
        MenuService::item('ho', 'setup', [
            'path' => 'people-assets/assets',
            'parent' => 'people-assets',
            'label' => 'Asset register',
            'route' => 'app.assets.register',
            'permission' => 'assets.register.view',
            'hint' => 'Live',
            'sort' => 40,
        ]);

        MenuService::item('ho', 'setup', [
            'path' => 'people-assets/asset-movement',
            'parent' => 'people-assets',
            'label' => 'Asset movement',
            'route' => 'app.assets.movement',
            'permission' => 'assets.movement.view',
            'sort' => 50,
        ]);

        MenuService::item('ho', 'setup', [
            'path' => 'people-assets/asset-repairs',
            'parent' => 'people-assets',
            'label' => 'Asset repair',
            'route' => 'app.assets.repairs',
            'permission' => 'assets.repair.view',
            'sort' => 60,
        ]);

        MenuService::item('ho', 'setup', [
            'path' => 'people-assets/asset-masters',
            'parent' => 'people-assets',
            'label' => 'Asset groups and types',
            'route' => 'app.assets.masters',
            'permission' => 'assets.register.view',
            'sort' => 70,
        ]);

        foreach ([
            ['register', 'Asset register', 'app.assets.register', 'assets.register.view', 10],
            ['movement', 'Movement', 'app.assets.movement', 'assets.movement.view', 20],
            ['repairs', 'Repairs', 'app.assets.repairs', 'assets.repair.view', 30],
        ] as [$slug, $label, $route, $permission, $sort]) {
            MenuService::item('branch', 'assets', [
                'path' => "equipment/{$slug}",
                'parent' => 'equipment',
                'label' => $label,
                'route' => $route,
                'permission' => $permission,
                'hint' => 'Live',
                'sort' => $sort,
            ]);
        }
    }
}
