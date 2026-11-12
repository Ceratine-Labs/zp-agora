<?php

namespace Modules\Masters\Http\Controllers;

use App\Grid\GridService;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Modules\Masters\Services\ReferenceMasterService;

/**
 * Every reference master's listing and record page (T022), one controller for
 * all of them: the master is named by the route's `resource` default, which is
 * also its GridKey's last part, and everything that differs between masters
 * is declared on its ReferenceMasterGrid.
 *
 * Read-only by design — there is no edit, save or delete route for any of
 * these, because the truth is PumpIT's (v1__02a).
 */
class ReferenceMasterController extends Controller
{
    public function __construct(private ReferenceMasterService $masters) {}

    public function index(Request $request, GridService $grids): View
    {
        $definition = $this->masters->definition((string) $request->route('resource'));

        return view('masters::reference.index', [
            'definition' => $definition,
            'grid' => $grids->build($definition, $request, $request->user()?->Id),
            'branches' => $this->masters->branches($definition),
        ]);
    }

    public function show(Request $request): View
    {
        // Read by name, not by position: a route default (`resource`) and a
        // URI parameter (`branch`) arrive in an order the signature cannot
        // promise.
        $definition = $this->masters->definition((string) $request->route('resource'));
        $record = $this->masters->record($definition, (int) $request->route('branch'), (string) $request->query('key', ''));

        return view($request->ajax() ? 'masters::reference.detail' : 'masters::reference.show', $record + [
            'definition' => $definition,
        ]);
    }
}
