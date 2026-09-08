<?php

namespace Modules\StockRecon\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Services\MenuService;

/**
 * This module's navigation.
 *
 * A NEW COLUMN UNDER CONTROL rather than a link squeezed into an existing one.
 * Control's three columns are Exceptions, Money and Governance, and stock
 * balancing is none of them: it is not an exception register (it produces one),
 * it is not money, and it is not a policy control. It also has to sit beside
 * two entries Core already seeded which sound like it and are not it —
 * "Stock counts and variance" under Exceptions, and "Stock recon area locks"
 * under Governance — so a fourth heading is what stops a reader guessing which
 * of three stock links is the one that balances a period. Sort 25 puts it
 * between Money (20) and Governance (30).
 *
 * The branch workspace already has `stock/balancing` from Core's own seeder and
 * this points it at the same screen: a branch manager balancing their own site
 * is the same job, and seeding a second entry beside the one that is already
 * there would be a menu with two "Balancing" links, which is worse than one
 * with none.
 *
 * MenuService::item() upserts on (workspace, section, path), so attaching a
 * route to an entry that already exists is an update rather than a duplicate.
 */
class MenuSeeder extends Seeder
{
    /** After Core's, which creates the sections this hangs off. */
    public int $seedOrder = 46;

    public function run(): void
    {
        MenuService::item('ho', 'control', [
            'path' => 'stock',
            'label' => 'Stock',
            'sort' => 25,
        ]);

        MenuService::item('ho', 'control', [
            'path' => 'stock/recon-centre',
            'parent' => 'stock',
            'label' => 'Stock recon centre',
            'route' => 'app.stockrecon.index',
            'permission' => 'stockrecon.runs.view',
            'hint' => 'Live',
            'sort' => 10,
        ]);

        // The exception report is reached through a run, so it has no URL of
        // its own — but it is the half of this module worth more than the
        // balancing, and a menu that only names the balancing hides it. Listed
        // as a child with no route: the renderer draws that as text rather than
        // as a link that 404s.
        MenuService::item('ho', 'control', [
            'path' => 'stock/recon-centre/exceptions',
            'parent' => 'stock/recon-centre',
            'label' => 'Unrecorded issues and count exceptions',
            'hint' => 'in a run',
            'sort' => 20,
        ]);

        // The branch workspace's own entry, which Core seeded with no route.
        MenuService::item('branch', 'stock', [
            'path' => 'counting/balancing',
            'parent' => 'counting',
            'label' => 'Balancing',
            'route' => 'app.stockrecon.index',
            'permission' => 'stockrecon.runs.view',
            'hint' => 'Live',
            'sort' => 30,
        ]);
    }
}
