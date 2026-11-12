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

/** Setup → People and assets → Employee areas (T023). Read-only. */
class EmployeeAreaController extends Controller
{
    public function __construct(private BranchContext $context) {}

    public function index(Request $request, GridRegistry $registry, GridService $grids): View
    {
        return view('people::areas.index', [
            'grid' => $grids->build($registry->findOrFail('app.setup.employee-areas'), $request, $request->user()?->Id),
            'branches' => Branch::query()->visibleTo($this->context->allowed())->ordered()->get(),
            'status' => EmployeeGrid::status(),
            'statuses' => EmployeeGrid::STATUSES,
        ]);
    }
}
