<?php

namespace Modules\People\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Services\MenuService;

/**
 * The people master's navigation (T023).
 *
 * Core seeded Setup → People and assets → Employees as a placeholder with no
 * route; this ATTACHES the route to it rather than adding a second link,
 * keeping Core's label and place (sort 10). MenuService::item() overwrites
 * every field it is given and nulls the ones it is not, so all of them are
 * given.
 *
 * It adds two entries beside it — Employee posts and Employee areas — which
 * the mockup had no slot for, sorted between Employees (10) and Users and
 * access (20).
 *
 * Each entry carries the same permission as its route's `can:`, so it is
 * hidden from anybody who could not open it. Upserts on (workspace, section,
 * path): re-running changes nothing.
 */
class MenuSeeder extends Seeder
{
    /** After Core's (30), which creates the placeholder. */
    public int $seedOrder = 33;

    public function run(): void
    {
        MenuService::item('ho', 'setup', [
            'path' => 'people-assets/employees',
            'parent' => 'people-assets',
            'label' => 'Employees',
            'route' => 'app.setup.employees.index',
            'permission' => 'people.employees.view',
            'sort' => 10,
        ]);

        MenuService::item('ho', 'setup', [
            'path' => 'people-assets/employee-posts',
            'parent' => 'people-assets',
            'label' => 'Employee posts',
            'route' => 'app.setup.employee-posts.index',
            'permission' => 'people.posts.view',
            'sort' => 13,
        ]);

        MenuService::item('ho', 'setup', [
            'path' => 'people-assets/employee-areas',
            'parent' => 'people-assets',
            'label' => 'Employee areas',
            'route' => 'app.setup.employee-areas.index',
            'permission' => 'people.areas.view',
            'sort' => 16,
        ]);
    }
}
