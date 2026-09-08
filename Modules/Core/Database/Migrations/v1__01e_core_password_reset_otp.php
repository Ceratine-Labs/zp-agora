<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A one-time code on the password-set link (Ryan, 7 September 2026).
 *
 * The link on its own is a bearer credential: whoever holds the URL sets the
 * password. That is the standard trade and it is usually fine — but Agora's
 * link is not a convenience for the forgetful, it is the FRONT DOOR for 88
 * people migrated out of SS_Users with no usable password, and it is sent to
 * addresses on a domain nobody here controls. A forwarded mail, a shared
 * mailbox, a phone left unlocked on a forecourt: each of those is one click
 * from an ERP account.
 *
 * So the link says WHERE, and the code says WHO. Both travel in the same mail
 * today, which is honest about what this defends against — it is not
 * two-factor, because there is only one factor and one channel. What it buys
 * is that a URL alone, pasted or logged or shoulder-surfed out of a browser
 * history, is not enough.
 *
 * FIVE CHARACTERS, from an alphabet with no 0/O and no 1/I/L: this gets read
 * off a screen and typed on a phone, and a code somebody cannot transcribe is
 * a support call. 31^5 is 28.6 million, against five attempts and a
 * sixty-minute life — the attempt lock is the real defence, not the entropy.
 *
 * Every decision here is project-manager's `Share` model, which has been in
 * front of real recipients since v1.14.0: hash the code, count the failures,
 * lock rather than throttle, and let a reissue be a clean slate so a locked-out
 * person can be rescued without a DBA.
 *
 * NULLABLE, because a reset issued before this migration ran has no code and
 * must still work — the request is at most an hour old and refusing it would
 * lock out whoever is holding the mail right now.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = config('agora.schema');

        Schema::table("{$schema}.PasswordReset", function (Blueprint $table) {
            // Hashed, like the token beside it. A reader of this table must
            // not be able to complete a reset they did not receive.
            $table->string('OtpHash', 255)->nullable();

            // The real defence. Five wrong codes and the request is dead;
            // asking for a new link is the only way on, which is exactly the
            // cost we want to impose on somebody guessing.
            $table->integer('OtpAttempts')->default(0);
            $table->dateTime('LockedAt', 0)->nullable();

            // Stamped when the code is accepted, so choosing the password is a
            // second request that cannot be reached by URL alone. Without it
            // the password form would be back to being a bearer credential and
            // the code would have bought nothing.
            $table->dateTime('OtpVerifiedAt', 0)->nullable();
        });

        MigrationHelper::recordVersion(
            '1.6',
            'agora.PasswordReset carries a hashed one-time code, its attempt counter and its lock.'
        );
    }

    public function down(): void
    {
        $schema = config('agora.schema');

        Schema::table("{$schema}.PasswordReset", function (Blueprint $table) {
            $table->dropColumn(['OtpHash', 'OtpAttempts', 'LockedAt', 'OtpVerifiedAt']);
        });
    }
};
