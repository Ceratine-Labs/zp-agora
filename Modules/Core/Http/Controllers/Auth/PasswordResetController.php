<?php

namespace Modules\Core\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\PasswordResetService;
use Modules\Core\Support\PasswordPolicy;

/**
 * Forgot password — the only way into a migrated account.
 *
 * The 85 users brought across from SS_Users have no usable password by design,
 * so this is not a convenience for the forgetful; it is the front door for
 * most of the customer's staff on day one. It is written to survive that:
 * throttled, mute about who exists, and honest about what it did.
 *
 * The confirmation sentence is identical whether or not the address matched.
 * That is a deliberate refusal to be helpful: on a system whose users are
 * `firstname@zp.co.za`, a form that says "no such account" is a staff
 * directory with a submit button.
 */
class PasswordResetController extends Controller
{
    /** Per address per hour. Generous for a person, useless for a script. */
    private const MAX_REQUESTS = 5;

    public function __construct(
        protected PasswordResetService $resets,
        protected ActivityLogger $activity,
    ) {}

    public function request(): View
    {
        return view('core::auth.forgot');
    }

    public function email(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'email', 'max:160'],
        ]);

        $key = 'password-reset:'.strtolower($data['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_REQUESTS)) {
            throw ValidationException::withMessages([
                'email' => 'Too many requests. Try again in '.RateLimiter::availableIn($key).' seconds.',
            ]);
        }

        RateLimiter::hit($key, 3600);

        $this->resets->request($data['email'], $request->ip());

        return redirect()->route('password.request')->with(
            'status',
            'If that address belongs to an Agora account, a link is on its way. It expires in '
            .PasswordResetService::LIFETIME_MINUTES.' minutes.'
        );
    }

    public function reset(Request $request, string $token): View
    {
        return view('core::auth.reset', [
            'token' => $token,
            'email' => (string) $request->query('email', ''),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:160'],
            'password' => PasswordPolicy::rules(),
        ]);

        $user = $this->resets->consume($data['email'], $data['token'], $data['password']);

        if (! $user) {
            // One message for expired, already used and simply wrong. Which of
            // the three it was is not the person's business and is not worth
            // telling anyone else either.
            throw ValidationException::withMessages([
                'email' => 'That link is no longer valid. Ask for a new one.',
            ]);
        }

        $this->activity->passwordReset($user, $request);

        return redirect()->route('login')->with(
            'status',
            'Your password is set. Sign in with it.'
        );
    }
}
