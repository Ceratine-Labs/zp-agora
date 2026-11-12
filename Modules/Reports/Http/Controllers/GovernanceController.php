<?php

namespace Modules\Reports\Http\Controllers;

use App\Grid\GridRegistry;
use App\Grid\GridService;
use App\Http\Controllers\Controller;
use App\Support\BranchContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Modules\Core\Models\Branch;

/**
 * Setup → Governance (T027): the master data change log and user activity,
 * each a grid over its own procedure. Read-only; the route carries the view
 * permission and the procedure checks it again for the extract.
 */
class GovernanceController extends Controller
{
    public function __construct(private BranchContext $context) {}

    public function masterChanges(Request $request, GridRegistry $registry, GridService $grids): View
    {
        return $this->screen('app.setup.master-changes', $request, $registry, $grids, [
            'title' => 'Master data changes',
            'blurb' => 'Every change to a master, field by field: who made it, when, the value before and after, and why.',
            'info' => [
                'Agora\'s own masters — branches, their regions, brands and formats, and the system settings — are read from agora.MasterChange, written by the procedure that saved them.',
                'PumpIT\'s masters that Agora writes back — categories and GP bands, profit centres, standard categories, expense codes, tills, shifts, transaction types, fuel types, tanks, pumps, utility meters, merchant and terminal numbers, employees, suppliers and customers — are read from the same ledgers the audit trail and Put back use, so a reversed write-back shows as reversed here too.',
            ],
        ]);
    }

    public function userActivity(Request $request, GridRegistry $registry, GridService $grids): View
    {
        return $this->screen('app.setup.user-activity', $request, $registry, $grids, [
            'title' => 'User activity',
            'blurb' => 'Sign-ins, sign-outs, refused sign-ins and password resets and changes, by person.',
            'info' => [
                'Read from agora.UserActivity, which every sign-in, sign-out and password reset writes through agora.usp_Core_LogActivity.',
                'A refused sign-in is recorded only against a known account; an address nobody holds writes nothing.',
            ],
        ]);
    }

    /** @param  array{title: string, blurb: string, info: array<int, string>}  $page */
    private function screen(string $key, Request $request, GridRegistry $registry, GridService $grids, array $page): View
    {
        return view('reports::governance.index', $page + [
            'grid' => $grids->build($registry->findOrFail($key), $request, $request->user()?->Id),
            // The branches selector (feature-rules §3.3): the caller's sites in
            // head office, none in the branch workspace, which has pinned one.
            'branches' => $this->context->isBranchWorkspace()
                ? null
                : Branch::query()->visibleTo($this->context->allowed())->ordered()->get(),
        ]);
    }
}
