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

/**
 * Setup → Trading rules → Suppliers (T024): the list and one supplier.
 *
 * Read-only. There is no create, edit or delete — the rows are PumpIT's
 * BRN_Suppliers, and whether Agora writes them back, keeps its own or leaves
 * them alone is waiting on Ryan.
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

    public function show(int $branch, string $code, Request $request): View
    {
        return view($request->ajax() ? 'partners::suppliers.detail' : 'partners::suppliers.show', $this->service->supplier($branch, $code));
    }
}
