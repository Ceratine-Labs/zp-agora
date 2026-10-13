<?php

namespace Modules\Debtors\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\MenuItem;
use Modules\Core\Models\MenuSection;
use Modules\Core\Models\UserMenuItem;
use Modules\Core\Services\MenuAccessService;
use Modules\Core\Services\MenuService;

/**
 * Debtors statements' place in the navigation (13 Oct 2026).
 *
 *   head office  Control → Money → Debtors statements
 *   branch       My site → Financial → Debtors statements
 *
 * Head office has no "Financial" section; Money is where its money screens
 * already live. A site had no money heading under My site, so this adds one,
 * named as asked.
 *
 * TICKED FOR WHOEVER CAN ALREADY SEE DAILY BANKING. A menu tick is access
 * (EnforceMenuAccess), so a person with ticks of their own would otherwise
 * neither see the entry nor be let in until an administrator ticked it.
 * Debtors are a cashup leg, so the entry goes to exactly the people who hold
 * Daily banking in the same workspace. A person with no ticks at all sees
 * everything already and gets no row.
 *
 * MenuService::item() upserts on (workspace, section, path).
 */
class MenuSeeder extends Seeder
{
    /** After Cash's menu seeders, whose Daily banking entries the ticks copy. */
    public int $seedOrder = 48;

    public function run(): void
    {
        $ho = MenuService::item('ho', 'control', [
            'path' => 'money/debtors-statements',
            'parent' => 'money',
            'label' => 'Debtors statements',
            'route' => 'app.debtors.index',
            'permission' => 'debtors.statements.view',
            'hint' => 'balances, statements, email',
            'sort' => 60,
        ]);

        MenuService::item('branch', 'trade', [
            'path' => 'financial',
            'label' => 'Financial',
            'sort' => 15,
        ]);

        $branch = MenuService::item('branch', 'trade', [
            'path' => 'financial/debtors-statements',
            'parent' => 'financial',
            'label' => 'Debtors statements',
            'route' => 'app.debtors.index',
            'permission' => 'debtors.statements.view',
            'sort' => 10,
        ]);

        $this->tickLike($ho, 'app.cash.banking', 'ho');
        $this->tickLike($branch, 'app.cash.banking', 'branch');
    }

    /** Tick $item for everyone ticked for the entry opening $route in the same workspace. */
    private function tickLike(MenuItem $item, string $route, string $workspace): void
    {
        $sections = MenuSection::query()->withoutGlobalScopes()->where('Workspace', $workspace)->pluck('Id');
        $source = MenuItem::query()->withoutGlobalScopes()
            ->whereIn('SectionId', $sections)->where('RouteName', $route)->value('Id');

        if ($source === null) {
            $this->command?->warn("  No {$workspace} entry opens {$route}; Debtors statements was ticked for nobody there.");

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

        $this->command?->info("  Debtors statements ({$workspace}) ticked for {$holders->count()} person(s) holding {$route}.");
    }
}
