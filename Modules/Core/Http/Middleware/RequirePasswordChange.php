<?php

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds a person on the change-password screen until they have set one.
 *
 * "Passwords reset on first login" is only true if something enforces it. A
 * banner would be dismissed; a check inside each controller would be forgotten
 * by the fortieth. So it is one middleware on the web stack, and the only
 * routes it lets past are the ones needed to comply or to leave.
 *
 * Cheap by construction: the flag is already on the authenticated user, so a
 * request by anybody who has set a password costs one boolean read.
 *
 * A POST is redirected like anything else rather than being allowed through
 * "because it is a form submission" — the whole point is that the session
 * cannot act on the system yet.
 */
class RequirePasswordChange
{
    /** Comply, or leave. Nothing else. */
    private const ALLOWED = [
        'app.password.change',
        'app.password.change.update',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (! $user || ! $user->MustChangePassword) {
            return $next($request);
        }

        if (in_array($request->route()?->getName(), self::ALLOWED, true)) {
            return $next($request);
        }

        // An asynchronous caller gets a status it can act on rather than an
        // HTML page it will try to parse.
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Set a password before using Agora.',
            ], 423);
        }

        return redirect()->route('app.password.change');
    }
}
