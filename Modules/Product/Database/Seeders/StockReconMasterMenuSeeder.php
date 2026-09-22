<?php

namespace Modules\Product\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Services\MenuService;

/**
 * Setup → Trading rules → Stock master is now → Stock recon master (Ryan,
 * 22 September 2026).
 *
 * ITS OWN SEEDER, not an edit to this module's MenuSeeder, and that is the
 * same one-shot rule that governs migrations: MenuSeeder is in the
 * `agora.SeedMaster` ledger, so changing the string in it would relabel the
 * menu on a fresh database and on nobody's existing one — including the
 * customer's. `MenuService::item()` upserts on (workspace, section, path), so
 * this REACHES the row that is there rather than adding a second entry beside
 * it: the path, the route and the permission are deliberately identical and
 * only the label moves.
 *
 * The screen itself — the page head, the grid's title, the browser tab — is
 * not seeded and changed in the same commit.
 */
class StockReconMasterMenuSeeder extends Seeder
{
    /** After this module's MenuSeeder (31) and CriticalLinesMenuSeeder (32). */
    public int $seedOrder = 33;

    public function run(): void
    {
        MenuService::item('ho', 'setup', [
            'path' => 'trading-rules/products',
            'parent' => 'trading-rules',
            'label' => 'Stock recon master',
            'route' => 'app.master.stock.index',
            'permission' => 'master.stock.view',
            'sort' => 20,
        ]);

        $this->command?->info('Setup → Trading rules → Stock master is now labelled Stock recon master.');
    }
}
