<?php

namespace Modules\Core\Models;

use App\Models\BaseModel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * A live forgot-password request, and the one-time code that gates it.
 *
 * Keyed by address rather than by user, because the request arrives before
 * anybody is identified and an address that matches no user has to behave
 * exactly like one that does — otherwise the form becomes a way of asking
 * which of your staff have accounts.
 *
 * TWO SECRETS, AND THEY ANSWER DIFFERENT QUESTIONS. `TokenHash` comes from the
 * link and says WHICH request this is; `OtpHash` is typed by a person and says
 * WHO. Both are hashes — the plaintext of either exists only in the email, so
 * a reader of this table can neither follow the link nor complete the code.
 *
 * The code's rules are project-manager's `Share`, which has been in front of
 * real recipients since v1.14.0 and got them right:
 *
 *  - **Lock, do not throttle.** Five wrong codes kills the request. A delay
 *    only makes guessing slower; a lock makes it pointless, and the person it
 *    inconveniences can ask for a new link.
 *  - **A wrong code on a CLOSED request costs nothing.** An expired or used
 *    request cannot be brute-forced, so burning an attempt on one would only
 *    punish a legitimate recipient who was slow to the mail.
 *  - **A reissue is a clean slate**, counter and lock included, or a locked-out
 *    person could never be rescued without a DBA.
 *
 * @property int $BranchId
 * @property int $Id
 * @property string $EmailAddress
 * @property string $TokenHash
 * @property Carbon $ExpiresAt
 * @property Carbon|null $UsedAt
 * @property string|null $RequestedIp
 * @property string|null $OtpHash
 * @property int $OtpAttempts
 * @property Carbon|null $LockedAt
 * @property Carbon|null $OtpVerifiedAt
 */
class PasswordReset extends BaseModel
{
    /**
     * Five characters, and no 0/O or 1/I/L in the alphabet.
     *
     * This is read off one screen and typed into another, often on a phone on
     * a forecourt. A code somebody cannot transcribe is a support call, and
     * the two characters everyone mistypes are worth more than the two bits
     * they cost. 31^5 is 28.6 million against five attempts.
     */
    public const OTP_LENGTH = 5;

    public const OTP_ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    /** Wrong codes allowed before the request is dead. The real defence. */
    public const MAX_ATTEMPTS = 5;

    protected $table = 'PasswordReset';

    protected $hidden = ['TokenHash', 'OtpHash'];

    protected $casts = [
        'ExpiresAt' => 'datetime',
        'UsedAt' => 'datetime',
        'LockedAt' => 'datetime',
        'OtpVerifiedAt' => 'datetime',
        'OtpAttempts' => 'integer',
    ];

    /** A fresh code, in the alphabet above. Returned once and never stored plain. */
    public static function mintOtp(): string
    {
        $alphabet = self::OTP_ALPHABET;
        $code = '';

        for ($i = 0; $i < self::OTP_LENGTH; $i++) {
            // random_int, not rand(): this is a credential, and the difference
            // between a CSPRNG and a seeded one is the whole of the guarantee.
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }

    public function isLive(): bool
    {
        return $this->UsedAt === null && $this->ExpiresAt->isFuture();
    }

    public function isLocked(): bool
    {
        return $this->LockedAt !== null || $this->OtpAttempts >= self::MAX_ATTEMPTS;
    }

    /** Can a code still be entered against this request at all? */
    public function isOpen(): bool
    {
        return $this->isLive() && ! $this->isLocked();
    }

    /** Shown to the recipient, so a typo does not feel like a cliff edge. */
    public function attemptsRemaining(): int
    {
        return max(0, self::MAX_ATTEMPTS - $this->OtpAttempts);
    }

    /**
     * Has the code already been accepted for this request?
     *
     * A request that predates the OTP columns has no code and counts as
     * verified: it is at most an hour old, it was issued under the old rules,
     * and refusing it would lock out whoever is holding that mail right now.
     */
    public function otpSatisfied(): bool
    {
        return $this->OtpHash === null || $this->OtpVerifiedAt !== null;
    }

    /**
     * Check a submitted code, counting the failure if it is wrong.
     *
     * Case is folded and spaces are stripped before the comparison — the code
     * is displayed in upper case and people type it however their keyboard
     * feels, and rejecting `a1b2c` for a code shown as `A1B2C` would be a
     * refusal with no cause a person could see.
     */
    public function verifyOtp(string $otp): bool
    {
        if (! $this->isOpen() || $this->OtpHash === null) {
            return false;
        }

        $candidate = strtoupper(preg_replace('/\s+/', '', trim($otp)) ?? '');

        if (! Hash::check($candidate, $this->OtpHash)) {
            $this->OtpAttempts = $this->OtpAttempts + 1;

            if ($this->OtpAttempts >= self::MAX_ATTEMPTS) {
                $this->LockedAt = now();
            }

            $this->save();

            return false;
        }

        // Counter cleared on success as well as on reissue: the next thing
        // this person does is choose a password, and a stale count from a
        // fumbled first attempt should not follow them there.
        $this->forceFill([
            'OtpVerifiedAt' => now(),
            'OtpAttempts' => 0,
        ])->save();

        return true;
    }

    /**
     * Why this request will not accept a code — in the recipient's terms.
     *
     * Separate from verifyOtp() on purpose: "wrong code" is not the honest
     * answer to expired, used or locked, and telling somebody to retype a
     * correct code is how a support call starts.
     */
    public function closedReason(): ?string
    {
        return match (true) {
            $this->UsedAt !== null => 'That link has already been used. Ask for a new one.',
            $this->ExpiresAt->isPast() => 'That link has expired. Ask for a new one.',
            $this->isLocked() => 'Too many incorrect codes were entered, so this link is locked. Ask for a new one.',
            default => null,
        };
    }
}
