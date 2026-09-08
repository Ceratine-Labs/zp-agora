<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Core\Mail\PasswordResetMail;
use Modules\Core\Models\PasswordReset;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Services\PasswordResetService;
use Tests\TestCase;

/**
 * The one-time code on the password-set link (7 September 2026).
 *
 * The claim under test is narrow and worth stating plainly: **a person holding
 * the link but not the code cannot set a password.** That is the whole point
 * of the feature, and it is one line in `consume()` — so it gets a test that
 * fails loudly if anybody ever "simplifies" it away.
 *
 * Everything else here is the failure behaviour, which is where an OTP is
 * usually got wrong: counting attempts, locking rather than throttling
 * forever, telling somebody WHY a dead link is dead instead of "wrong code",
 * and letting a reissue rescue a locked-out person.
 *
 * It WRITES. One user, `TEST-` prefixed on the reserved `agora.invalid`
 * domain, removed in tearDown with its reset row. No RefreshDatabase, ever.
 */
class PasswordResetOtpTest extends TestCase
{
    private const PASSWORD = 'Th1s-Is-A-Real-Password!';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $role = Role::query()->acrossBranches()->where('Code', 'branch-manager')->firstOrFail();

        $this->user = User::query()->acrossBranches()->firstOrNew([
            'BranchId' => (int) config('agora.group_branch_id'),
            'EmailAddress' => 'TEST-otp@agora.invalid',
        ]);
        $this->user->fill([
            'UserName' => 'TEST-OTP',
            'RoleId' => $role->Id,
            'IsActive' => true,
            'IsLocked' => false,
        ]);
        $this->user->BranchId = (int) config('agora.group_branch_id');
        $this->user->makePasswordUnusable()->save();

        RateLimiter::clear('password-reset:'.strtolower($this->user->EmailAddress).'|127.0.0.1');
    }

    protected function tearDown(): void
    {
        PasswordReset::query()->acrossBranches()
            ->where('EmailAddress', strtolower($this->user->EmailAddress))->delete();
        $this->user->forceDelete();

        parent::tearDown();
    }

    /**
     * Issue a real request through the service and hand back what the mail
     * carried — the token from the URL and the code from the body.
     *
     * Read off the MAILABLE rather than re-minted here, so the test proves the
     * person actually receives the code that the database will accept. A test
     * that generated its own would pass with the two halves disconnected.
     *
     * @return array{0: string, 1: string}
     */
    private function issue(): array
    {
        app(PasswordResetService::class)->request($this->user->EmailAddress);

        $sent = null;
        Mail::assertSent(PasswordResetMail::class, function (PasswordResetMail $mail) use (&$sent) {
            $sent = $mail;

            return true;
        });

        return [$sent->token, $sent->otp];
    }

    private function row(): PasswordReset
    {
        return PasswordReset::query()->acrossBranches()
            ->where('EmailAddress', strtolower($this->user->EmailAddress))
            ->firstOrFail();
    }

    private function url(string $token): string
    {
        return route('password.reset', ['token' => $token])
            .'?email='.urlencode($this->user->EmailAddress);
    }

    public function test_the_mail_carries_a_five_character_code_that_is_stored_only_as_a_hash(): void
    {
        [$token, $otp] = $this->issue();

        $this->assertSame(PasswordReset::OTP_LENGTH, strlen($otp));
        $this->assertMatchesRegularExpression(
            '/^['.PasswordReset::OTP_ALPHABET.']+$/',
            $otp,
            'The alphabet excludes 0/O and 1/I/L because this is transcribed by hand.'
        );

        $row = $this->row();
        $this->assertNotNull($row->OtpHash);
        $this->assertNotSame($otp, $row->OtpHash, 'The code must not be readable out of the table.');
        $this->assertTrue(Hash::check($otp, $row->OtpHash));
        $this->assertNull($row->OtpVerifiedAt);

        // And the token is not the code — two secrets answering two questions.
        $this->assertNotSame($otp, $token);
    }

    public function test_the_link_lands_on_the_code_form_and_not_the_password_form(): void
    {
        [$token] = $this->issue();

        $this->get($this->url($token))
            ->assertOk()
            ->assertSee('Enter your code')
            ->assertDontSee('Confirm the new password');
    }

    /** THE POINT OF THE WHOLE FEATURE. */
    public function test_the_link_alone_cannot_set_a_password(): void
    {
        [$token] = $this->issue();

        $this->post('/password/reset', [
            'token' => $token,
            'email' => $this->user->EmailAddress,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertSessionHasErrors('email');

        $this->assertTrue(
            $this->user->fresh()->hasUnusablePassword(),
            'Holding the URL without the code must not be enough.'
        );
    }

    public function test_the_right_code_opens_the_password_step_and_the_password_is_then_set(): void
    {
        [$token, $otp] = $this->issue();

        $this->post('/password/verify', [
            'token' => $token,
            'email' => $this->user->EmailAddress,
            'otp' => $otp,
        ])->assertRedirect($this->url($token));

        $this->assertNotNull($this->row()->OtpVerifiedAt);

        $this->get($this->url($token))->assertOk()->assertSee('Confirm the new password');

        $this->post('/password/reset', [
            'token' => $token,
            'email' => $this->user->EmailAddress,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertRedirect(route('login'));

        $fresh = $this->user->fresh();
        $this->assertFalse($fresh->hasUnusablePassword());
        $this->assertFalse($fresh->MustChangePassword);
    }

    public function test_the_code_is_accepted_in_lower_case_and_with_stray_spaces(): void
    {
        [$token, $otp] = $this->issue();

        // It is shown upper case and typed however the phone's keyboard feels.
        // Refusing that would be a rejection with no cause a person can see.
        $this->post('/password/verify', [
            'token' => $token,
            'email' => $this->user->EmailAddress,
            'otp' => ' '.strtolower($otp).' ',
        ])->assertRedirect($this->url($token));

        $this->assertNotNull($this->row()->OtpVerifiedAt);
    }

    public function test_the_field_is_long_enough_to_hold_a_pasted_code_with_its_whitespace(): void
    {
        // The server normalises, but the BROWSER truncates first: a maxlength
        // of exactly OTP_LENGTH silently drops the last character of a code
        // pasted with the trailing space a mail client selected, and burns an
        // attempt on a code the person copied correctly. Caught by walking the
        // real form, not by reading it.
        [$token] = $this->issue();

        $this->get($this->url($token))
            ->assertOk()
            ->assertSee('maxlength="'.(PasswordReset::OTP_LENGTH + 4).'"', false);
    }

    public function test_a_wrong_code_counts_an_attempt_and_says_how_many_are_left(): void
    {
        [$token] = $this->issue();

        $this->from($this->url($token))->post('/password/verify', [
            'token' => $token,
            'email' => $this->user->EmailAddress,
            'otp' => 'ZZZZZ',
        ])->assertSessionHasErrors('otp');

        $this->assertSame(1, $this->row()->OtpAttempts);
        $this->assertSame(PasswordReset::MAX_ATTEMPTS - 1, $this->row()->attemptsRemaining());
        $this->assertNull($this->row()->OtpVerifiedAt);
    }

    public function test_five_wrong_codes_lock_the_link_and_the_right_one_no_longer_works(): void
    {
        [$token, $otp] = $this->issue();

        for ($i = 0; $i < PasswordReset::MAX_ATTEMPTS; $i++) {
            // The route throttle is 10/min and the lock is 5, so the lock is
            // what stops this loop — which is the assertion being made.
            $this->from($this->url($token))->post('/password/verify', [
                'token' => $token,
                'email' => $this->user->EmailAddress,
                'otp' => 'ZZZZZ',
            ]);
        }

        $this->assertTrue($this->row()->isLocked());

        $this->from($this->url($token))->post('/password/verify', [
            'token' => $token,
            'email' => $this->user->EmailAddress,
            'otp' => $otp,
        ])->assertSessionHasErrors('otp');

        $this->assertNull($this->row()->OtpVerifiedAt, 'A locked link must not open for the correct code either.');

        // And the page says WHY, before asking for a code it will not accept.
        $this->get($this->url($token))->assertOk()->assertSee('locked');
    }

    public function test_asking_for_a_new_link_rescues_a_locked_out_person(): void
    {
        [$token] = $this->issue();

        for ($i = 0; $i < PasswordReset::MAX_ATTEMPTS; $i++) {
            $this->from($this->url($token))->post('/password/verify', [
                'token' => $token,
                'email' => $this->user->EmailAddress,
                'otp' => 'ZZZZZ',
            ]);
        }
        $this->assertTrue($this->row()->isLocked());

        // A reissue is a clean slate — counter and lock — or the only way back
        // from five typos is a DBA.
        [$fresh, $freshOtp] = $this->issue();

        $this->assertFalse($this->row()->isLocked());
        $this->assertSame(0, $this->row()->OtpAttempts);

        $this->post('/password/verify', [
            'token' => $fresh,
            'email' => $this->user->EmailAddress,
            'otp' => $freshOtp,
        ])->assertRedirect($this->url($fresh));

        // And the code from the dead link is dead with it.
        $this->assertNotSame($token, $fresh);
    }

    public function test_the_public_form_stays_mute_when_the_mail_server_refuses(): void
    {
        // The confirmation sentence is identical whether or not the address
        // matched — and it has to stay identical when delivery fails, or a
        // 500 on one address and a friendly page on another is the staff
        // directory this form exists to avoid being.
        Mail::shouldReceive('to->send')->andThrow(new \RuntimeException('535 Authentication failed'));

        $this->post('/password/forgot', ['email' => $this->user->EmailAddress])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status');

        // The request itself was still recorded, so a resend after the mail
        // server is fixed does not need the person to do anything different.
        $this->assertNotNull($this->row()->OtpHash);
    }

    public function test_a_request_issued_before_the_code_existed_still_works(): void
    {
        // The columns are nullable for exactly this: a link mailed an hour
        // before the deploy is in somebody's inbox right now, and refusing it
        // would lock out the person holding it.
        $token = 'TEST-'.str_repeat('b', 59);

        PasswordReset::query()->acrossBranches()->updateOrCreate(
            ['BranchId' => (int) $this->user->BranchId, 'EmailAddress' => strtolower($this->user->EmailAddress)],
            [
                'TokenHash' => hash('sha256', $token),
                'OtpHash' => null,
                'ExpiresAt' => now()->addMinutes(PasswordResetService::LIFETIME_MINUTES),
                'UsedAt' => null,
            ],
        );

        $this->post('/password/reset', [
            'token' => $token,
            'email' => $this->user->EmailAddress,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertRedirect(route('login'));

        $this->assertFalse($this->user->fresh()->hasUnusablePassword());
    }
}
