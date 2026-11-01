<?php

namespace Modules\StockRecon\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Services\MenuAccessService;
use Symfony\Component\HttpFoundation\Response;

/** The separate meter view and its extracts retain the parent variance access. */
class WaterMeterAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        Gate::authorize('stockrecon.variance.view');
        abort_unless(app(MenuAccessService::class)->canReachRoute($request->user(), 'app.stockrecon.variance'), 403);

        return $next($request);
    }
}
