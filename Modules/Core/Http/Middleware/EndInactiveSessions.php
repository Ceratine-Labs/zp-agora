<?php

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Core\Models\User;
use Modules\Core\Models\UserActivity;
use Modules\Core\Services\ActivityLogger;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the session of somebody who may no longer sign in.
 *
 * The sign-in form and the SSO handshake both refuse an account that is
 * inactive or locked, but a session already open outlived the change: a
 * person deactivated at 09:00 kept working until their session expired.
 * Sessions are files (SESSION_DRIVER=file, docs/deployment.md), so there is
 * no table of a user's sessions to delete from — the check has to happen
 * where the session is next USED, which is here, on every web request.
 *
 * FREE BY CONSTRUCTION. The session guard reloads the user from agora.User on
 * every request (the stock Eloquent provider, by Id), so IsActive and IsLocked
 * are already the row's current values; this reads two booleans and adds no
 * query. Nothing is cached, so there is no window to tune.
 *
 * The same two flags as the door, so a LOCKED account's open session ends
 * too: a lock that let the session it was meant to stop carry on would be the
 * same defect. Read as an explicit revocation (IsActive false, IsLocked true)
 * rather than as `! canSignIn()`: both columns are NOT NULL, so every row the
 * guard loads carries them and the two readings agree; an in-memory model
 * that never set them (a test double) is not somebody being switched off.
 *
 * Registered FIRST of Core's web middleware, so nothing else — a branch
 * context, a forced password change, a menu check — is evaluated for an
 * account that is about to be signed out.
 */
class EndInactiveSessions
{
    public function __construct(private ActivityLogger $activity) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (! $user instanceof User || ! $this->revoked($user)) {
            return $next($request);
        }

        $why = $user->IsLocked
            ? 'Your account has been locked, so you have been signed out. Head office can unlock it.'
            : 'Your account has been deactivated, so you have been signed out. Ask head office if you still need access.';

        $this->activity->recordQuietly($user, UserActivity::SIGN_OUT, 'Session ended: '.($user->IsLocked ? 'account locked.' : 'account deactivated.'), $request);

        // logout() also cycles a remember-me token the account holds, so the
        // cookie that would have signed them straight back in is dead too.
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['message' => $why], 401);
        }

        return redirect()->route('login')->withErrors(['email' => $why]);
    }

    private function revoked(User $user): bool
    {
        return $user->getAttribute('IsActive') === false || $user->getAttribute('IsLocked') === true;
    }
}
