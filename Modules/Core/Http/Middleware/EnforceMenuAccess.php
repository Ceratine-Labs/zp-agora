<?php

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Services\MenuAccessService;
use Symfony\Component\HttpFoundation\Response;

/**
 * The tick on the menu is access, not decoration.
 *
 * The customer asked for a tick per menu entry meaning view, and no tick
 * meaning NO ACCESS (22 Sep 2026). Filtering the mega panel alone would give
 * them the first half and a false version of the second: the entry disappears
 * and the URL behind it still answers, which is worse than no control at all
 * because it looks like one. This puts the same answer in front of the route.
 *
 * IT ONLY SPEAKS FOR ROUTES THE MENU NAMES. A request whose route name is not
 * on any menu entry — the four Save endpoints on the edit screen, the grid
 * column writer, the CSV extract — passes straight through, because nobody
 * ticked anything about it and `can:` is what guards those. Adding a menu
 * entry that points at a route is therefore what brings it under this, which
 * is the same act as putting it in front of the customer in the first place.
 *
 * ONE ROUTE CAN BE SEVERAL ENTRIES. `app.dashboard` is reached from "Day close
 * status" at head office and "My day" at a site; holding EITHER is enough,
 * because the person demonstrably has a way to that screen.
 *
 * WHAT IT WILL NOT DO IS LOCK THE DOOR FROM THE INSIDE. The landing routes
 * named by agora.Role.LandingRoute, and the dashboard behind them, are always
 * allowed — see MenuAccessService::alwaysAllowedRoutes. A correct sign-in that
 * ends in 403 with nowhere to go is a lockout, and an administrator tidying a
 * branch manager's menu should not be able to cause one by accident.
 */
class EnforceMenuAccess
{
    public function __construct(private MenuAccessService $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $name = $request->route()?->getName();

        if ($user === null || $name === null) {
            return $next($request);
        }

        if ($this->access->canReachRoute($user, $name)) {
            return $next($request);
        }

        abort(403, 'This screen is not on your menu. Ask an administrator to tick it for you.');
    }
}
