<?php

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Support\Sso;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ask ZP-NQL, once, whether this browser is already signed in there.
 *
 * Runs on every web request, so almost all of its work is deciding NOT to
 * act. The conditions below are each a way the handshake would be wrong
 * rather than merely unnecessary:
 *
 *   - Not a plain GET page load. A redirect discards a POST body, so a form
 *     submission would be silently thrown away and look like the app losing
 *     the user's work.
 *   - Not an XHR or a JSON client. A fetch() that follows a redirect to
 *     another origin gets a CORS failure, not a login.
 *   - Not the SSO endpoints themselves, and not the login or password-reset
 *     pages. Someone standing on /login is answering the question already,
 *     and someone mid password-reset must not be signed in as anybody.
 *   - Not while the loop guard is set. That cookie IS the memory of having
 *     asked; without it a signed-out visitor bounces between the two hosts.
 *
 * It runs before Laravel's auth middleware and never blocks: a visitor who
 * turns out to have no session at Agora carries on to whatever they asked
 * for, which is usually the login page, one redirect later.
 */
class AttemptCrossAppSignIn
{
    /**
     * Route names this must never fire on.
     *
     * The password-reset pair is here for a reason that is easy to miss:
     * those pages are reached WITHOUT a session by design, and signing the
     * visitor in from a peer session halfway through would leave them
     * resetting a password for an account they are no longer acting as.
     */
    private const EXEMPT = [
        'sso.*',
        'login',
        'logout',
        // Agora's whole reset flow — request, the emailed code, the new
        // password — sits on `password.*` outside /app.
        'password.*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->shouldAsk($request)) {
            return $next($request);
        }

        // getRequestUri() is the path and query exactly as asked for, which
        // is what has to survive the round trip — a dashboard opened with
        // filters in the query string should come back with them.
        return redirect()->away(Sso::emitUrl($request->getRequestUri()));
    }

    private function shouldAsk(Request $request): bool
    {
        if (! Sso::enabled() || Sso::guarded()) {
            return false;
        }

        if ($request->user() !== null) {
            return false;
        }

        if (! $request->isMethod('GET') || $request->ajax() || $request->expectsJson()) {
            return false;
        }

        // A prefetch or an <img> is not somebody arriving at a page, and
        // spending their one handshake on it wastes the guard cookie.
        if (! str_contains((string) $request->header('Accept'), 'text/html')) {
            return false;
        }

        if ($request->routeIs(...self::EXEMPT)) {
            return false;
        }

        return true;
    }
}
