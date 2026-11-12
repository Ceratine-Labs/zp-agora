<?php

namespace Modules\People\Http\Controllers;

use App\Grid\GridRegistry;
use App\Grid\GridService;
use App\Http\Controllers\Controller;
use App\Support\BranchContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Modules\Core\Models\Branch;
use Modules\People\Services\EmployeeService;

/**
 * Setup → People and assets → Employee posts (T023). Read-only.
 *
 * With exactly one site in scope the page also says what that site's next
 * post number would be (agora.usp_People_NextPost) — the number a new post
 * will take once adding one is built.
 */
class EmployeePostController extends Controller
{
    public function __construct(private EmployeeService $service, private BranchContext $context) {}

    public function index(Request $request, GridRegistry $registry, GridService $grids): View
    {
        $grid = $grids->build($registry->findOrFail('app.setup.employee-posts'), $request, $request->user()?->Id);
        $selected = array_values(array_filter($grid->gridQuery->branchIds, fn (int $id) => $id > 0));
        $branch = count($selected) === 1 ? Branch::query()->visibleTo($this->context->allowed())->where('BranchId', $selected[0])->first() : null;

        return view('people::posts.index', [
            'grid' => $grid,
            'branches' => Branch::query()->visibleTo($this->context->allowed())->ordered()->get(),
            'branch' => $branch,
            'nextPost' => $branch === null ? null : $this->service->nextPost((int) $branch->BranchId),
        ]);
    }
}
