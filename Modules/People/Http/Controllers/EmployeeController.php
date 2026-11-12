<?php

namespace Modules\People\Http\Controllers;

use App\Grid\GridRegistry;
use App\Grid\GridService;
use App\Http\Controllers\Controller;
use App\Support\BranchContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Modules\Core\Models\Branch;
use Modules\People\Grids\EmployeeGrid;
use Modules\People\Services\EmployeeService;

/**
 * Setup → People and assets → Employees (T023): the list and one employee.
 *
 * Read-only. There is no create, edit or delete yet — the rows are PumpIT's,
 * and whether Agora writes them back, keeps its own copy or leaves them alone
 * is waiting on Ryan.
 */
class EmployeeController extends Controller
{
    public function __construct(private EmployeeService $service, private BranchContext $context) {}

    public function index(Request $request, GridRegistry $registry, GridService $grids): View
    {
        $grid = $grids->build($registry->findOrFail('app.setup.employees'), $request, $request->user()?->Id);

        return view('people::employees.index', [
            'grid' => $grid,
            'branches' => Branch::query()->visibleTo($this->context->allowed())->ordered()->get(),
            'posts' => $this->service->postNames(array_values(array_filter($grid->gridQuery->branchIds, fn (int $id) => $id > 0))),
            'post' => (string) data_get($request->query($grid->param('f')), 'Post.q', ''),
            'status' => EmployeeGrid::status(),
            'statuses' => EmployeeGrid::STATUSES,
        ]);
    }

    public function show(int $branch, string $code, Request $request): View
    {
        return view($request->ajax() ? 'people::employees.detail' : 'people::employees.show', $this->service->get($branch, $code));
    }
}
