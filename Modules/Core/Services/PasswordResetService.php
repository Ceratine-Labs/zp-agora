<?php

namespace Modules\Core\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Core\Mail\PasswordResetMail;
use Modules\Core\Models\PasswordReset;
use Modules\Core\Models\User;

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
 */
class PasswordResetService
{
    /** Long enough to walk to a phone and read the mail; short enough that a forwarded link dies. */
    public const LIFETIME_MINUTES = 60;

    public function __construct(protected ActivityLogger $activity) {}

    /**
     * Issue a link, if that address belongs to somebody. Says nothing either
     * way — see the class docblock.
     */
    public function request(string $email, ?string $ip = null): void
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

        PasswordReset::query()->acrossBranches()->updateOrCreate(
            ['BranchId' => (int) $user->BranchId, 'EmailAddress' => $email],
            [
                'TokenHash' => $this->hash($token),
                'ExpiresAt' => now()->addMinutes(self::LIFETIME_MINUTES),
                'UsedAt' => null,
                'RequestedIp' => $ip,
            ],
        );

        Mail::to($email)->send(new PasswordResetMail($user, $token));
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

        if (! $reset) {
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
