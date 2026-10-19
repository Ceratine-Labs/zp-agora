<?php

namespace Modules\StockRecon\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\MenuItem;
use Modules\Core\Models\MenuSection;
use Modules\Core\Models\UserMenuItem;
use Modules\Core\Services\MenuAccessService;
use Modules\Core\Services\MenuService;

/**
 * Original vs amended variance's place in the navigation (19 Oct 2026).
 *
 *   head office  Control → Stock → Original vs amended variance
 *   branch       Stock → Counting → Original vs amended variance
 *
 * Beside the balancing it reports on. Its own seeder: MenuSeeder has run and is
 * in the ledger, and a seeder is a one-shot.
 *
 * TICKED FOR WHOEVER CAN ALREADY SEE STOCK RECON. A menu tick is access
 * (EnforceMenuAccess), so a person with ticks of their own would otherwise
 * neither see the entry nor be let in until an administrator ticked it. The
 * entry goes to exactly the people ticked for the stock recon centre (head
 * office) or Balancing (branch). A person with no ticks at all sees everything
 * already and gets no row.
 *
 * MenuService::item() upserts on (workspace, section, path).
 */
class StockReconVarianceMenuSeeder extends Seeder
{
    /** After MenuSeeder (46), whose entries the ticks copy, and the permission (68). */
    public int $seedOrder = 69;

    public function run(): void
    {
        $ho = MenuService::item('ho', 'control', [
            'path' => 'stock/variance',
            'parent' => 'stock',
            'label' => 'Original vs amended variance',
            'route' => 'app.stockrecon.variance',
            'permission' => 'stockrecon.variance.view',
            'hint' => 'after balancing',
            'sort' => 20,
        ]);

        $branch = MenuService::item('branch', 'stock', [
            'path' => 'counting/variance',
            'parent' => 'counting',
            'label' => 'Original vs amended variance',
            'route' => 'app.stockrecon.variance',
            'permission' => 'stockrecon.variance.view',
            'sort' => 35,
        ]);

        $this->tickLike($ho, 'app.stockrecon.index', 'ho');
        $this->tickLike($branch, 'app.stockrecon.index', 'branch');
    }

    /** Tick $item for everyone ticked for the entry opening $route in the same workspace. */
    private function tickLike(MenuItem $item, string $route, string $workspace): void
    {
        $sections = MenuSection::query()->withoutGlobalScopes()->where('Workspace', $workspace)->pluck('Id');
        $source = MenuItem::query()->withoutGlobalScopes()
            ->whereIn('SectionId', $sections)->where('RouteName', $route)->value('Id');

        if ($source === null) {
            $this->command?->warn("  No {$workspace} entry opens {$route}; the variance entry was ticked for nobody there.");

            return;
        }

        $holders = UserMenuItem::query()->acrossBranches()
            ->where('MenuItemId', $source)
            ->whereNotIn('UserId', UserMenuItem::query()->acrossBranches()
                ->where('MenuItemId', $item->Id)->select('UserId'))
            ->get(['BranchId', 'UserId']);

        foreach ($holders as $holder) {
            UserMenuItem::query()->acrossBranches()->create([
                'BranchId' => (int) $holder->BranchId,
                'UserId' => (int) $holder->UserId,
                'MenuItemId' => (int) $item->Id,
                'CreatedAt' => now(),
            ]);

            app(MenuAccessService::class)->forget((int) $holder->UserId);
        }

        $this->command?->info("  Original vs amended variance ({$workspace}) ticked for {$holders->count()} person(s) holding {$route}.");
    }
}
