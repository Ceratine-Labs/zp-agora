<?php

namespace Modules\Core\Services;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Core\Mail\PasswordResetMail;
use Modules\Core\Models\PasswordReset;
use Modules\Core\Models\User;
use Throwable;

/**
 * Forgot-password, end to end.
 *
 * This is the ONLY way into an account migrated from SS_Users. Those 85 users
 * arrive with an unusable PasswordHash on purpose — the legacy
 * `Password varchar(50)` column is not a credential anyone should carry
 * forward — so "reset on first login" is not a prompt they can dismiss, it is
 * the shape of the data.
 *
 * Four things here are security decisions rather than plumbing, and each is
 * cheap now and expensive to retrofit:
 *
 *  - **The token is stored hashed.** It is a bearer credential for an hour.
 *    Agora's database sits on the same instance as 249 GB of production data;
 *    a reader of one table should not be able to mint a session.
 *  - **`request()` tells the caller nothing.** No return value, no exception,
 *    no difference in timing worth measuring between a known and an unknown
 *    address. A form that says "no such user" is a way of asking which of the
 *    customer's staff have accounts.
 *  - **One live request per address.** A second request replaces the first, so
 *    an old link in an old inbox stops working. That is why
 *    `agora.PasswordReset` is unique on (BranchId, EmailAddress).
 *  - **Consuming a token clears MustChangePassword and stamps
 *    PasswordChangedAt**, in the same save as the hash. A reset that set the
 *    password but left the flag would send the person straight back to the
 *    form they just completed.
 *
 * SINCE 7 SEP 2026 THE LINK ALONE IS NOT ENOUGH. The mail carries a
 * five-character code as well, and `consume()` refuses a request whose code
 * was never entered. The link says WHICH request; the code says WHO. Both
 * travel in the same message, so this is not two-factor and does not claim to
 * be — what it buys is that a URL on its own, forwarded or logged or read over
 * a shoulder, does not set somebody's password. The rules around the code (a
 * five-attempt lock, a clean slate on reissue) live on the PasswordReset
 * model, and they are project-manager's `Share` rules.
 */
class PasswordResetService
{
    /** Long enough to walk to a phone and read the mail; short enough that a forwarded link dies. */
    public const LIFETIME_MINUTES = 60;

    public function __construct(protected ActivityLogger $activity) {}

    /**
     * Issue a link, if that address belongs to somebody. Says nothing either
     * way — see the class docblock.
     *
     * `$reportFailures` is the one seam in that silence, and it is for the
     * ADMIN path only. On the public form a delivery failure must look exactly
     * like a success, or the form becomes a way to probe which addresses
     * exist. On Setup → Users, an administrator is looking at the person's row
     * and already knows they exist — and telling them "sent" when the SMTP
     * server refused the login is worse than useless, because they will go and
     * wait for a mail that is never coming.
     *
     * @param  bool  $reportFailures  rethrow a transport error instead of logging it
     */
    public function request(string $email, ?string $ip = null, bool $reportFailures = false): void
    {
        $email = Str::lower(trim($email));
        $user = $this->user($email);

        if (! $user) {
            // Logged, not surfaced: a burst of these is worth seeing in the
            // application log and is worth nothing to the person typing.
            Log::info('agora.password-reset.unknown-address', ['email' => $email]);

            return;
        }

        $token = Str::random(64);
        $otp = PasswordReset::mintOtp();

        /*
         * A REISSUE IS A CLEAN SLATE. The attempt counter, the lock and the
         * verification stamp are cleared here, not just the token — somebody
         * who locked themselves out with five bad codes has to be rescuable by
         * asking for another link, or the only way back is a DBA. That is
         * project-manager's rule for the same reason.
         */
        PasswordReset::query()->acrossBranches()->updateOrCreate(
            ['BranchId' => (int) $user->BranchId, 'EmailAddress' => $email],
            [
                'TokenHash' => $this->hash($token),
                'OtpHash' => Hash::make($otp),
                'OtpAttempts' => 0,
                'LockedAt' => null,
                'OtpVerifiedAt' => null,
                'ExpiresAt' => now()->addMinutes(self::LIFETIME_MINUTES),
                'UsedAt' => null,
                'RequestedIp' => $ip,
            ],
        );

        /*
         * A SEND CAN THROW, and until 7 Sep 2026 nothing here expected it to:
         * the environment was MAIL_MAILER=log, which cannot fail. Pointing it
         * at real SMTP made an authentication failure a 500 on the
         * forgot-password form — the one page every migrated user has to use.
         * Caught here, so a broken mail server degrades to "nothing arrived"
         * rather than to a stack trace on the front door.
         */
        try {
            Mail::to($email)->send(new PasswordResetMail($user, $token, $otp));
        } catch (Throwable $e) {
            Log::error('agora.password-reset.delivery-failed', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            if ($reportFailures) {
                throw $e;
            }
        }
    }

    /**
     * The request for this address and token whether or not it is still live —
     * for TELLING somebody why their link will not work.
     *
     * Never for authorising anything: `find()` is the one that gates, and it
     * refuses a used or expired row. This exists because making a person type
     * a code only to be told the link expired an hour ago is a support call
     * with extra steps.
     *
     * Not an enumeration risk the way an address is. The caller must already
     * hold 64 random characters; learning that a token they possess has
     * expired tells them nothing they could not get by trying it.
     */
    public function findForDisplay(string $email, string $token): ?PasswordReset
    {
        $reset = PasswordReset::query()
            ->acrossBranches()
            ->where('EmailAddress', Str::lower(trim($email)))
            ->first();

        if (! $reset) {
            return null;
        }

        return hash_equals($reset->TokenHash, $this->hash($token)) ? $reset : null;
    }

    /** The live request for this address and token, or null. */
    public function find(string $email, string $token): ?PasswordReset
    {
        $email = Str::lower(trim($email));

        $reset = PasswordReset::query()
            ->acrossBranches()
            ->where('EmailAddress', $email)
            ->first();

        if (! $reset || ! $reset->isLive()) {
            return null;
        }

        // hash_equals, because a plain === on a secret leaks its prefix to
        // anyone willing to time enough attempts.
        return hash_equals($reset->TokenHash, $this->hash($token)) ? $reset : null;
    }

    /**
     * Set the password and burn the token. Returns the user, or null when the
     * link was wrong, used or expired.
     */
    public function consume(string $email, string $token, string $password): ?User
    {
        $reset = $this->find($email, $token);

        // otpSatisfied() is what makes the code worth anything: without it the
        // password form is still reachable by URL alone and the code is a
        // decoration on the way to it.
        if (! $reset || ! $reset->otpSatisfied()) {
            return null;
        }

        $user = $this->user(Str::lower(trim($email)));

        if (! $user) {
            return null;
        }

        // One save: the hash, the flag and the stamp are one fact about this
        // account and must not be able to disagree.
        $user->forceFill([
            'PasswordHash' => $password,
            'MustChangePassword' => false,
            'PasswordChangedAt' => now(),
        ])->save();

        $reset->forceFill(['UsedAt' => now()])->save();

        return $user;
    }

    protected function user(string $email): ?User
    {
        return User::query()
            ->acrossBranches()
            ->where('EmailAddress', $email)
            ->first();
    }

    protected function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
