<?php

namespace Modules\Core\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Badges\BadgeRegistry;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\User;
use Modules\Core\Services\ActivityLogger;

/**
 * Sign in and out.
 *
 * Deliberately hand-written rather than scaffolded: the user table is
 * PascalCase, the key is `Id`, the password is `PasswordHash`, and the row
 * carries IsActive, IsLocked and MustChangePassword flags that a stock starter
 * kit knows nothing about. A locked user whose password is correct must be
 * refused, and refused for the right reason.
 *
 * The sign-in page also shows the system state before anybody is
 * authenticated, which is the mockup's design and a real decision: the first
 * question at 06:00 is "did last night's loads run", and making somebody sign
 * in to find out costs a minute every morning across 31 sites. Those four
 * figures come through the badge registry — the same providers the bell and
 * the menu items use (T011) — so the number on this page cannot drift from the
 * number inside.
 */
class LoginController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        protected ActivityLogger $activity,
        protected BadgeRegistry $badges,
    ) {}

    public function show(): View|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->intended(route('app.dashboard'));
        }

        return view('core::auth.login', [
            // Which figures, and in what order, is configuration rather than
            // markup — a module that takes over a key changes what this shows
            // without touching the view.
            'state' => $this->badges->badges((array) config('core.signin_state', [])),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:160'],
            'password' => ['required', 'string'],
        ]);

        $key = 'login:'.strtolower($credentials['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'email' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        $user = User::query()
            ->acrossBranches()
            ->where('EmailAddress', $credentials['email'])
            ->first();

        /*
         * The unusable-password check comes FIRST, and it is not belt and
         * braces.
         *
         * Laravel's bcrypt hasher is configured to verify the algorithm, so
         * check() against a value that is not a bcrypt digest THROWS
         * ("This password does not use the Bcrypt algorithm") rather than
         * returning false. Every one of the customer's 85 migrated users has
         * exactly such a value, so without this line the first thing any of
         * them would meet on day one is a 500.
         */
        $unusable = $user?->hasUnusablePassword() ?? false;

        if (! $user || $unusable || ! Auth::getProvider()->validateCredentials($user, ['password' => $credentials['password']])) {
            RateLimiter::hit($key, 300);

            // One message for "no such user" and "wrong password" — telling
            // them apart tells an attacker which addresses exist. A migrated
            // user lands here too: their PasswordHash is the unusable
            // sentinel, so nothing can match it and the only way in is the
            // reset link under the form.
            throw ValidationException::withMessages([
                'email' => 'Those details do not match an account.',
            ]);
        }

        if (! $user->canSignIn()) {
            RateLimiter::hit($key, 300);

            $why = $user->IsLocked
                ? 'This account is locked. Head office can unlock it.'
                : 'This account is not active.';

            // Attributable, because we know who it was. An unknown address
            // writes no row at all — see ActivityLogger.
            $this->activity->signInRefused($user, $why, $request);

            throw ValidationException::withMessages(['email' => $why]);
        }

        RateLimiter::clear($key);

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        // The proc writes the activity row AND stamps LastSignInAt, in one
        // transaction, so the log and the column cannot disagree.
        $this->activity->signIn($user, $request);

        // A credential somebody else set gets them exactly as far as the
        // change-password screen; RequirePasswordChange holds them there.
        if ($user->MustChangePassword) {
            return redirect()->route('app.password.change');
        }

        return redirect()->intended(route($user->landingRoute()));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user instanceof User) {
            $this->activity->signOut($user, $request);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
