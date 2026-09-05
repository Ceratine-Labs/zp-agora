<?php

namespace Modules\Reports\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Core\Services\MenuService;

/**
 * This module's navigation.
 *
 * Core already seeds every item on the Today menu — the menu was built from the
 * mockup before any of it was implemented, so the entries are there with no
 * route on them and render as plain text marked "not built yet".
 *
 * MenuService::item() upserts on (workspace, section, path), so this ATTACHES a
 * route to the entry that is already there rather than seeding a second one
 * beside it. A menu with two "Staff shorts" links is worse than one with none.
 *
 * The paths come from the registry so the two cannot drift: a report added to
 * Config/config.php without a menu path will fail here rather than quietly not
 * appear in the navigation.
 */
class MenuSeeder extends Seeder
{
    /** After Core's, which creates the sections and the items this attaches to. */
    public int $seedOrder = 46;

    public function run(): void
    {
        $attached = 0;

        foreach (config('reports.reports') as $key => $report) {
            if (empty($report['menu'])) {
                continue;
            }

            MenuService::item('ho', 'today', [
                'path' => $report['menu'],
                'parent' => Str::beforeLast($report['menu'], '/'),
                'label' => $report['label'],
                // URL, not route name. MenuItem::href() prefers RouteName and
                // calls route() with NO parameters, so naming a parameterised
                // route here would throw on every render of the app bar. The
                // slug is the parameter, so the resolved path is what is stored.
                'url' => '/app/reports/'.$key,
                'sort' => ($attached + 1) * 10,
            ]);

            $attached++;
        }

        // The catalogue itself, under Trade → Reports, beside the library
        // entries Core already seeded there.
        MenuService::item('ho', 'trade', [
            'path' => 'reports/today',
            'parent' => 'reports',
            'label' => 'Today reports',
            'route' => 'app.reports.index',
            'hint' => (string) $attached,
            'sort' => 5,
        ]);

        $this->command?->info("  Reports: {$attached} Today menu entries wired to a route.");
    }
}
