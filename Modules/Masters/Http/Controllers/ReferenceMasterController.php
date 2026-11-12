<?php

namespace Modules\Masters\Http\Controllers;

use App\Grid\GridService;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Modules\Masters\Services\MasterWriteService;
use Modules\Masters\Services\ReferenceMasterService;

/**
 * Every reference master's listing and record page (T022), one controller for
 * all of them: the master is named by the route's `resource` default, which is
 * also its GridKey's last part, and everything that differs between masters
 * is declared on its ReferenceMasterGrid.
 *
 * The edits are ReferenceMasterWriteController's: they write PumpIT's own
 * table back (CLAUDE.md rule 1), for the masters whose definition says so.
 */
class ReferenceMasterController extends Controller
{
    public function __construct(private ReferenceMasterService $masters, private MasterWriteService $writes) {}

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
            'writes' => $this->writes->writes($definition, $record['row']),
        ]);
    }
}
