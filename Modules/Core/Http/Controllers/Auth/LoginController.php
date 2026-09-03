<?php

namespace Modules\Core\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\User;

/**
 * Sign in and out.
 *
 * Deliberately hand-written rather than scaffolded: the user table is
 * PascalCase, lives in the customer's database, and carries IsActive and
 * IsLocked flags that a stock starter kit knows nothing about. A locked user
 * whose password is correct must be refused, and refused for the right reason.
 */
class LoginController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    public function show(): View|RedirectResponse
    {
        return Auth::check()
            ? redirect()->intended(route('app.dashboard'))
            : view('core::auth.login');
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

        if (! $user || ! Auth::getProvider()->validateCredentials($user, ['password' => $credentials['password']])) {
            RateLimiter::hit($key, 300);

            // One message for "no such user" and "wrong password" — telling
            // them apart tells an attacker which addresses exist.
            throw ValidationException::withMessages([
                'email' => 'Those details do not match an account.',
            ]);
        }

        if (! $user->canSignIn()) {
            RateLimiter::hit($key, 300);

            throw ValidationException::withMessages([
                'email' => $user->IsLocked
                    ? 'This account is locked. Head office can unlock it.'
                    : 'This account is not active.',
            ]);
        }

        RateLimiter::clear($key);

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        $user->forceFill(['LastSignInAt' => now()])->saveQuietly();

        return redirect()->intended($this->landing($user));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /**
     * Each role lands somewhere different (plan §2) — Finance on Control,
     * a branch manager on their own day. The route lives on the role row, so
     * changing it is data, not a deploy.
     */
    protected function landing(User $user): string
    {
        $route = $user->role?->LandingRoute;

        return $route && Route::has($route)
            ? route($route)
            : route('app.dashboard');
    }
}
