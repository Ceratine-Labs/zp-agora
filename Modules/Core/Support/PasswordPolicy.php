<?php

namespace Modules\Core\Support;

use Illuminate\Validation\Rules\Password;

/**
 * What Agora will accept as a password, in one place.
 *
 * One place because it is asked for in three: the reset form, the
 * change-password form, and whatever Setup screen T008 grows for creating a
 * user. Three copies of a policy is three policies.
 *
 * Twelve characters, mixed case, a digit and a symbol. No maximum beyond
 * bcrypt's own 72 bytes, no forced rotation, and no dictionary check:
 *
 *  - **`uncompromised()` is deliberately NOT used.** It calls the Have I Been
 *    Pwned API. The customer's application server reaches a database on a
 *    private address and nothing else is promised; a password rule that fails
 *    closed when an outbound call times out would lock people out of an ERP
 *    for a reason nobody could see from the screen.
 *  - **Rotation is not enforced** because NIST stopped recommending it in
 *    2017 and it reliably produces Password1!, Password2!, Password3!.
 *
 * `MustChangePassword` is a different thing and IS enforced: it means this
 * particular credential was set by somebody other than its owner, or was never
 * set at all.
 */
class PasswordPolicy
{
    public const MINIMUM = 12;

    /** @return array<int, mixed> */
    public static function rules(): array
    {
        return [
            'required',
            'string',
            'confirmed',
            'max:72',
            Password::min(self::MINIMUM)->mixedCase()->numbers()->symbols(),
        ];
    }

    /** The sentence shown under the field, so the rule is visible before it is broken. */
    public static function help(): string
    {
        return self::MINIMUM.' characters or more, with upper and lower case, a number and a symbol.';
    }
}
