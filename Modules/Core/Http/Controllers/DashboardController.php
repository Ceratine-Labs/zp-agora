<?php

namespace Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\BranchContext;
use Illuminate\Contracts\View\View;
use Modules\Core\Models\Branch;

/**
 * The landing page behind the shell.
 *
 * A placeholder in the sense that it shows no trading figures yet — those
 * arrive with the branch console (T030) and the trading position (T068). What
 * it does prove is real: the shell renders, the menu comes out of the
 * database, and the branch scope resolved to a set of sites this user may see.
 */
class DashboardController extends Controller
{
    public function __invoke(BranchContext $context): View
    {
        return view('core::dashboard', [
            'branchCount' => Branch::query()->trading()->count(),
            // IsActive as well as IsTrading: the browser-test fixture is a
            // non-trading branch that is also inactive, and without the second
            // condition it was counted here — the page reported 7
            // administrative entities where the estate has 6.
            'entityCount' => Branch::query()
                ->acrossBranches()
                ->where('IsTrading', false)
                ->where('IsActive', true)
                ->count(),
            'context' => $context,
        ]);
    }
}
