<?php

namespace Modules\Core\Http\Middleware;

use App\Grid\GridRegistry;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Modules\Core\Services\MenuAccessService;
use Modules\StockRecon\Http\Middleware\CountingAreaAccess;
use Modules\StockRecon\Http\Middleware\WaterMeterAccess;
use Modules\StockRecon\Http\Middleware\WeeklyStockAccess;
use Modules\StockRecon\Models\StockReconRun;
use Symfony\Component\HttpFoundation\Response;

/**
 * A grid's extract and its saved layout need the access its screen needs.
 *
 * The extract re-runs the grid's procedure out of the screen's sight, so
 * without this anybody signed in could download any grid's rows by its key.
 * Every registered grid is held to its own screen route
 * (GridDefinition::screenRoute()): each `can:` that route declares, and the
 * menu entry EnforceMenuAccess requires for it. Nothing is listed by hand — a
 * grid added tomorrow is guarded by the route it is shown on. A grid with no
 * screen route is refused. An unknown key passes through so the endpoint can
 * answer 404, as it always has.
 *
 * The stock recon water, counting-area and weekly grids keep their own access
 * middleware, which is what their screens use instead of a `can:`.
 */
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

        $definition = is_string($grid) ? app(GridRegistry::class)->find($grid) : null;
        if ($definition === null) {
            return $next($request);
        }

        $screen = $definition->screenRoute();
        $route = $screen === null ? null : Route::getRoutes()->getByName($screen);
        abort_if($route === null, 403);

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'can:')) {
                Gate::authorize(explode(',', substr($middleware, 4))[0]);
            }
        }
        abort_unless(app(MenuAccessService::class)->canReachRoute($request->user(), $screen), 403);

        return $next($request);
    }
}
