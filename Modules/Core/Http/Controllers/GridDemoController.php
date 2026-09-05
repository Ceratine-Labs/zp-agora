<?php

namespace Modules\Core\Http\Controllers;

use App\Grid\GridRegistry;
use App\Grid\GridService;
use App\Http\Controllers\Controller;
use App\Support\BranchContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Modules\Core\Models\Branch;

/**
 * The grid, on one page, twice.
 *
 * Two grids on one screen on purpose, and not for variety: it is the case the
 * GridKey qualification exists for (`app.dev.grids:dayclose` and
 * `…:branches`), so sorting one must not page the other. A single-grid page
 * would never have caught a shared `?page=`, and every real screen that carries
 * a summary above a detail is this shape.
 *
 * One is over a stored procedure — the same `usp_Reports_GridDayClose` the
 * Reports module renders through `<x-table>` — and one is over Eloquent. They
 * are rendered by the same component with the same props, which is the claim
 * this page exists to make checkable.
 *
 * Not registered outside local and testing, like /dev/theme: it enumerates a
 * component rather than answering a question the business has.
 */
class GridDemoController extends Controller
{
    public function __construct(
        private GridRegistry $registry,
        private GridService $grids,
    ) {}

    public function __invoke(Request $request, BranchContext $context): View
    {
        $userId = $request->user()?->Id;

        return view('core::dev.grids', [
            'grids' => [
                $this->grids->build($this->registry->findOrFail('app.dev.grids:dayclose'), $request, $userId),
                $this->grids->build($this->registry->findOrFail('app.dev.grids:branches'), $request, $userId),
            ],
            // The branches selector is a prop, not something the component
            // fetches — a component never queries the database (plan §3.8).
            // In the branch workspace the site is already pinned, so the list
            // is not passed at all and the selector does not render: the grid
            // should not have to be told twice not to offer a choice that has
            // already been made.
            'branches' => $context->workspace() === 'branch'
                ? null
                : Branch::query()->where('IsActive', true)->orderBy('Name')->get(),
            'context' => $context,
        ]);
    }
}
