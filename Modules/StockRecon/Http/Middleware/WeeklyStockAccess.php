<?php

namespace Modules\StockRecon\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Services\MenuAccessService;
use Symfony\Component\HttpFoundation\Response;

class WeeklyStockAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        Gate::authorize('stockrecon.weekly.view');
        abort_unless(app(MenuAccessService::class)->canReachRoute($request->user(), 'app.stockrecon.weekly'), 403);
        $request->validate(['snapshot' => ['nullable', 'uuid']]);

        return $next($request);
    }
}
