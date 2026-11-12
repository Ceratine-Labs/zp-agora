<?php

namespace Modules\Partners\Http\Controllers;

use App\Grid\GridRegistry;
use App\Grid\GridService;
use App\Http\Controllers\Controller;
use App\Support\BranchContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Modules\Core\Models\Branch;
use Modules\Partners\Services\PartnerService;
use Modules\Partners\Services\PartnerWriteService;

/**
 * Setup → Trading rules → Suppliers (T024): the list and one supplier.
 *
 * The page lists what Agora has written to PumpIT for the supplier; the
 * edit, the new supplier and Put back are SupplierEditController's.
 */
class SupplierController extends Controller
{
    public function __construct(private PartnerService $service, private BranchContext $context) {}

    public function index(Request $request, GridRegistry $registry, GridService $grids): View
    {
        return view('partners::suppliers.index', [
            'grid' => $grids->build($registry->findOrFail('app.setup.suppliers'), $request, $request->user()?->Id),
            'branches' => Branch::query()->visibleTo($this->context->allowed())->ordered()->get(),
        ]);
    }

    public function show(int $branch, string $code, Request $request, PartnerWriteService $writes): View
    {
        $data = $this->service->supplier($branch, $code);

        if ($request->ajax()) {
            return view('partners::suppliers.detail', $data);
        }

        return view('partners::suppliers.show', $data + ['changes' => $writes->changes('Supplier', $branch, $code)]);
    }
}
