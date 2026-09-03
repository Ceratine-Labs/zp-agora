<?php

namespace Modules\Core\View\Composers;

use App\Support\BranchContext;
use Illuminate\View\View;
use Modules\Core\Models\Branch;
use Modules\Core\Services\MenuService;

/**
 * Feeds the app shell.
 *
 * Every page renders the app bar, the mega menus and the scope bar, so the
 * data behind them is composed once here instead of being remembered by forty
 * controllers. Components take props and never query (plan §3.8) — this is
 * where those props come from.
 */
class ShellComposer
{
    public function __construct(
        protected MenuService $menu,
        protected BranchContext $context,
    ) {}

    public function compose(View $view): void
    {
        $view->with([
            'shellSections' => $this->menu->tree(),
            'shellWorkspace' => $this->context->workspace(),
            'shellWorkspaces' => config('core.workspaces'),
            'shellBranchId' => $this->context->id(),
            'shellBranches' => Branch::query()
                ->acrossBranches()
                ->when(
                    $this->context->allowed() !== [],
                    fn ($q) => $q->whereIn('BranchId', $this->context->allowed())
                )
                ->trading()
                ->ordered()
                ->get(['BranchId', 'Name']),
        ]);
    }
}
