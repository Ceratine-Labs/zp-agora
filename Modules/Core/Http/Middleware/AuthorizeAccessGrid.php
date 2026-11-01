<?php

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Services\MenuAccessService;
use Modules\StockRecon\Http\Middleware\CountingAreaAccess;
use Modules\StockRecon\Http\Middleware\WaterMeterAccess;
use Modules\StockRecon\Http\Middleware\WeeklyStockAccess;
use Modules\StockRecon\Models\StockReconRun;
use Symfony\Component\HttpFoundation\Response;

/** Access-management exports and layout endpoints need the screen's access. */
class AuthorizeAccessGrid
{
    public function handle(Request $request, Closure $next): Response
    {
        $grid = $request->route('grid');
        if ($grid === 'app.stockrecon.exceptions') {
            Gate::authorize('stockrecon.exceptions.view');
            abort_unless(app(MenuAccessService::class)->canReachRoute($request->user(), 'app.stockrecon.exceptions'), 403);
            if ($request->routeIs('app.grids.extract')) {
                $runId = $request->query('run');
                abort_unless(is_scalar($runId) && ctype_digit((string) $runId), 404);
                StockReconRun::query()->findOrFail((int) $runId);
            }
        }
        if ($grid === 'app.stockrecon.countingareas') {
            return app(CountingAreaAccess::class)->handle($request, $next);
        }
        if ($grid === 'app.stockrecon.water') {
            return app(WaterMeterAccess::class)->handle($request, $next);
        }
        if ($grid === 'app.stockrecon.weekly') {
            return app(WeeklyStockAccess::class)->handle($request, $next);
        }

        if (in_array($grid, ['app.setup.users', 'app.setup.roles'], true)) {
            Gate::authorize(substr($grid, 4).'.view');
            abort_unless(app(MenuAccessService::class)->canReachRoute($request->user(), $grid.'.index'), 403);
        }

        return $next($request);
    }
}
