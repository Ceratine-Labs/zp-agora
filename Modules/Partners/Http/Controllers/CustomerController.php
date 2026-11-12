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
use Modules\Partners\Services\PartnerWriteService;

/**
 * Setup → Trading rules → Account customers (T024): the list and one customer.
 *
 * The rows are PumpIT's BRN_Customers; the edit, the new customer and Put
 * back are CustomerEditController's. Where a statement goes is the Debtors
 * module's, edited on the debtor's statement, and this links there rather
 * than offering a second form for it.
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

    public function show(int $branch, string $code, Request $request, PartnerWriteService $writes): View
    {
        $customer = $this->service->customer($branch, $code);

        if ($request->ajax()) {
            return view('partners::customers.detail', ['customer' => $customer]);
        }

        return view('partners::customers.show', [
            'customer' => $customer,
            'changes' => $writes->changes('Customer', $branch, $code),
        ]);
    }
}
