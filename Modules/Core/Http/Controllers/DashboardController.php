<?php

namespace Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\BranchContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Modules\Core\Models\User;
use Modules\Core\Services\LandingModules;

/**
 * The landing page behind the shell.
 *
 * The modules this person can open, from the current workspace's menu.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, BranchContext $context, LandingModules $modules): View
    {
        $user = $request->user();

        return view('core::dashboard', [
            'modules' => $user instanceof User ? $modules->forUser($user, $context->workspace()) : [],
            'context' => $context,
        ]);
    }
}
