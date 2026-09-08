<?php

namespace Modules\Recon\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Services\MenuService;

/**
 * The Trace screen's place in the navigation.
 *
 * Its own seeder rather than a line added to MenuSeeder, because MenuSeeder
 * has already run and the ledger will not run it again — the same rule that
 * governs migrations. A seeder is a one-shot, so changing what was seeded
 * means writing another one.
 *
 * MenuService::item() upserts on (workspace, section, path), so re-running
 * this is harmless and it will not produce a second entry.
 */
class TraceMenuSeeder extends Seeder
{
    /** After Recon's MenuSeeder, which creates the parent this hangs off. */
    public int $seedOrder = 46;

    public function run(): void
    {
        MenuService::item('ho', 'control', [
            'path' => 'money/bank-recon/trace',
            'parent' => 'money/bank-recon',
            'label' => 'Trace a reference',
            'route' => 'app.recon.trace',
            'hint' => 'batch, bag, slip or narrative',
            'sort' => 5,
        ]);
    }
}
