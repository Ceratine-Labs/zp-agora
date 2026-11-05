<?php

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Services\MenuAccessService;
use Modules\Core\Services\PermissionService;
use Symfony\Component\HttpFoundation\Response;

/** Enforce effective permissions for menu destinations and direct visits. */
class EnforceMenuAccess
{
    public function __construct(private MenuAccessService $access, private PermissionService $permissions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $name = $request->route()?->getName();

        if ($user === null || $name === null) {
            return $next($request);
        }

        if (str_starts_with($name, 'app.') && ! in_array($name, [
            'app.access-required', 'app.password.change', 'app.password.change.update',
        ], true) && $this->permissions->patternsFor($user) === []) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'You have no permissions. Contact your administrator.'], 403);
            }

            return redirect()->route('app.access-required');
        }

        if ($this->access->canReachRoute($user, $name)) {
            return $next($request);
        }

        abort(403, 'This screen is not on your menu. Ask an administrator to tick it for you.');
    }
}
