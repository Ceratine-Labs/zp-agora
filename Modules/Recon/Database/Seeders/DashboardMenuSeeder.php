<?php

namespace Modules\Recon\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\UserMenuItem;
use Modules\Core\Services\MenuAccessService;
use Modules\Core\Services\MenuService;

/**
 * The bank recon dashboard's place in the navigation (13 Oct 2026): first
 * under Bank recon, because "what is outstanding" is the question a person
 * opens bank recon to answer.
 *
 * Its own seeder, because MenuSeeder has already run and the ledger will not
 * run it again (see TraceMenuSeeder). MenuService::item() upserts on
 * (workspace, section, path), so re-running it is harmless.
 *
 * TICKED FOR WHOEVER CAN ALREADY SEE BANK RECON. A menu tick is access
 * (EnforceMenuAccess), so a person with ticks of their own would otherwise
 * neither see the entry nor be let in until an administrator ticked it. The
 * dashboard reads nothing the area screens do not, so it goes to exactly the
 * people who hold the Bank reconciliation entry it sits under. A person with
 * no ticks at all sees everything already and gets no row.
 */
class DashboardMenuSeeder extends Seeder
{
    /** After Recon's MenuSeeder, which creates the parent this hangs off. */
    public int $seedOrder = 46;

    public function run(): void
    {
        $item = MenuService::item('ho', 'control', [
            'path' => 'money/bank-recon/dashboard',
            'parent' => 'money/bank-recon',
            'label' => 'Dashboard',
            'route' => 'app.recon.dashboard',
            'hint' => 'what is outstanding, per site',
            'sort' => 0,
        ]);

        $holders = UserMenuItem::query()->acrossBranches()
            ->where('MenuItemId', $item->ParentId)
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
    }
}
