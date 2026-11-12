<?php

namespace Modules\Partners\Http\Controllers;

use App\Grid\GridRegistry;
use App\Grid\GridService;
use App\Http\Controllers\Controller;
use App\Support\BranchContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Modules\Core\Models\Branch;
use Modules\Partners\Grids\CustomerGrid;
use Modules\Partners\Services\PartnerService;

/**
 * Setup → Trading rules → Account customers (T024): the list and one customer.
 *
 * Read-only. The rows are PumpIT's BRN_Customers; where a statement goes is
 * the Debtors module's, edited on the debtor's statement, and this links
 * there rather than offering a second form for it.
 */
class CustomerController extends Controller
{
    public function __construct(private PartnerService $service, private BranchContext $context) {}

    public function index(Request $request, GridRegistry $registry, GridService $grids): View
    {
        return view('partners::customers.index', [
            'grid' => $grids->build($registry->findOrFail('app.setup.customers'), $request, $request->user()?->Id),
            'branches' => Branch::query()->visibleTo($this->context->allowed())->ordered()->get(),
            'includesControls' => CustomerGrid::includesControls(),
        ]);
    }

    public function show(int $branch, string $code, Request $request): View
    {
        return view($request->ajax() ? 'partners::customers.detail' : 'partners::customers.show', [
            'customer' => $this->service->customer($branch, $code),
        ]);
    }
}
