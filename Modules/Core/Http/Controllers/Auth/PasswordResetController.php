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
 *
 * THREE STEPS SINCE 7 SEP 2026: ask (`request`/`email`), prove who you are
 * with the code from the mail (`reset`/`verify`), then choose a password
 * (`update`). Which step the link lands on is decided by what is STORED
 * against the request, never by a query parameter — so the password form
 * cannot be reached by editing the URL, which is the only thing that makes the
 * code worth having.
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

    /**
     * The link's landing page — the CODE first, the password second.
     *
     * Two steps rather than one form, and the reason is the failure case: a
     * wrong code on a combined form throws away a password the person has
     * already typed, and a password field is the one thing a browser must not
     * repopulate. Five attempts each costing a retyped password would spend
     * the allowance on transcription rather than on anything security cares
     * about.
     *
     * The step is decided by the STORED state, not by a query parameter, so
     * the password form cannot be reached by editing the URL.
     */
    public function reset(Request $request, string $token): View
    {
        $email = (string) $request->query('email', '');
        $reset = $this->resets->find($email, $token);

        if ($reset && $reset->otpSatisfied()) {
            return view('core::auth.reset', ['token' => $token, 'email' => $email]);
        }

        // findForDisplay, not find: an expired or used request is not returned
        // by the gate at all, and this page has to be able to say WHY rather
        // than making somebody type a code to be told the link died an hour
        // ago. It authorises nothing — verify() re-reads through find().
        $shown = $this->resets->findForDisplay($email, $token);

        return view('core::auth.otp', [
            'token' => $token,
            'email' => $email,
            'closed' => $shown?->closedReason(),
            'remaining' => $shown?->attemptsRemaining(),
        ]);
    }

    /**
     * Check the code from the mail.
     *
     * Throttled per token per IP on top of the model's five-attempt lock. The
     * lock is the real defence and this is the cheap one: it keeps a script
     * from burning somebody's five attempts in a second, which would turn a
     * denial of service into a trivial one.
     */
    public function verify(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:160'],
            'otp' => ['required', 'string', 'max:32'],
        ]);

        $back = route('password.reset', ['token' => $data['token']])
            .'?email='.urlencode($data['email']);

        $reset = $this->resets->findForDisplay($data['email'], $data['token']);

        if (! $reset) {
            // One message for wrong, expired, used and unknown — the same
            // refusal to be helpful that the request form makes, and for the
            // same reason.
            throw ValidationException::withMessages([
                'otp' => 'That link is no longer valid. Ask for a new one.',
            ]);
        }

        if ($closed = $reset->closedReason()) {
            throw ValidationException::withMessages(['otp' => $closed]);
        }

        if (! $reset->verifyOtp($data['otp'])) {
            $left = $reset->attemptsRemaining();

            throw ValidationException::withMessages([
                'otp' => $left > 0
                    ? "That code is not right. {$left} attempt".($left === 1 ? '' : 's').' left.'
                    : 'That code is not right, and this link is now locked. Ask for a new one.',
            ]);
        }

        return redirect($back);
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
