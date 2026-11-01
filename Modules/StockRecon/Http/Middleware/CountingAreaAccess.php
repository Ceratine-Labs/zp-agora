<?php

namespace Modules\StockRecon\Http\Middleware;

use App\Support\BranchContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Services\MenuAccessService;
use Symfony\Component\HttpFoundation\Response;

class CountingAreaAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(app(BranchContext::class)->workspace() === 'ho', 403);
        Gate::authorize('stockrecon.countingareas.view');
        abort_unless(app(MenuAccessService::class)->canReachRoute($request->user(), 'app.stockrecon.countingareas'), 403);

        return $next($request);
    }
}
