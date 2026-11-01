<?php

namespace Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\BranchContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Modules\Core\Models\User;
use Modules\Core\Services\ModuleMenu;

class ModuleMenuController extends Controller
{
    public function __invoke(string $module, Request $request, BranchContext $context, ModuleMenu $menus): View
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $hub = $menus->findForUser($user, $context->workspace(), $module);
        // The generic route has no module permission of its own. Its content
        // must therefore be established by the permitted tools on every visit.
        abort_if($hub === null, 404);

        return view('core::module-menu', ['module' => $hub, 'context' => $context]);
    }
}
