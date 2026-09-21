<?php

namespace Modules\Product\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Services\MenuService;

/**
 * Setup → Trading rules → Critical lines (T025).
 *
 * Its own seeder rather than a line added to this module's MenuSeeder, which
 * has already run and which the ledger will not run again — the same one-shot
 * rule that governs migrations.
 *
 * It ATTACHES to the dead placeholder Core seeded, exactly as the stock master
 * entry did: Setup → Trading rules → Critical lines has existed with a NULL
 * RouteName since the mega menu was seeded. Two entries called Critical lines
 * would be worse than one that goes nowhere.
 */
class CriticalLinesMenuSeeder extends Seeder
{
    /** After this module's MenuSeeder (31). */
    public int $seedOrder = 32;

    public function run(): void
    {
        MenuService::item('ho', 'setup', [
            'path' => 'trading-rules/critical-lines',
            'parent' => 'trading-rules',
            'label' => 'Critical lines',
            'route' => 'app.master.critical.index',
            'permission' => 'master.stock.view',
            'sort' => 30,
        ]);

        $this->command?->info('Setup → Trading rules → Critical lines now points at app.master.critical.index.');
    }
}
