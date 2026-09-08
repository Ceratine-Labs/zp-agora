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

            /*
             * The nav's branch filter, narrowed to what this person is granted
             * in agora.UserBranch. Setup -> Users and access writes those
             * grants, so a site taken away there disappears from here on the
             * person's next request — no cache, because the grants are read
             * per request and the scope bar is rendered from the same array
             * BranchContext refuses unauthorised ids with.
             */
            'shellBranches' => Branch::query()
                ->acrossBranches()
                ->visibleTo($this->context->allowed())
                ->ordered()
                ->get(['BranchId', 'Name']),

            // Whether that list is a grant or the whole estate. The bar says
            // which, because "31 sites" and "31 sites, and there are 31" are
            // different facts and only one of them is reassuring.
            'shellBranchesGranted' => $this->context->allowed() !== [],
        ]);
    }
}
