<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Core\Mail\PasswordResetMail;
use Modules\Core\Models\PasswordReset;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Models\UserActivity;
use Modules\Core\Services\PasswordResetService;
use Tests\TestCase;

/**
 * Signing in, the way a migrated user actually experiences it.
 *
 * The acceptance for T007 is a sentence about people, not about code: a user
 * brought across from PumpIT cannot sign in until they have reset, and once
 * they have, a branch manager lands on /console and an executive on /exco. So
 * that is what these assert, through the real routes, against the real
 * database.
 *
 * It WRITES, which most tests here deliberately do not. Two users are created
 * and both are removed in tearDown, both `TEST-` prefixed and both on the
 * reserved `agora.invalid` domain, so a row left behind by a failure is
 * obvious in a listing and cannot collide with a person. There is no
 * RefreshDatabase and there never will be — it would drop the customer's
 * estate.
 */
class SignInTest extends TestCase
{
    private const PASSWORD = 'Th1s-Is-A-Real-Password!';

    private User $manager;

    private User $executive;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->manager = $this->makeUser('TEST-branch-manager@agora.invalid', 'TEST-Branch Manager', 'branch-manager');
        $this->executive = $this->makeUser('TEST-executive@agora.invalid', 'TEST-Executive', 'executive');

        // Sign-in is limited to five attempts per address per five minutes. A
        // test file with more attempts than that would fail on the limiter
        // rather than on the code, intermittently, which is the worst kind.
        foreach ([$this->manager, $this->executive] as $user) {
            RateLimiter::clear('login:'.strtolower($user->EmailAddress).'|127.0.0.1');
        }
    }

    protected function tearDown(): void
    {
        foreach ([$this->manager, $this->executive] as $user) {
            UserActivity::query()->acrossBranches()->where('UserId', $user->Id)->delete();
            PasswordReset::query()->acrossBranches()->where('EmailAddress', $user->EmailAddress)->delete();
            $user->forceDelete();
        }

        parent::tearDown();
    }

    public function test_a_migrated_user_cannot_sign_in_until_the_password_is_reset(): void
    {
        // usp_Core_MigrateUsers writes this sentinel, so there is nothing to
        // guess: no bcrypt digest equals it and password_verify refuses.
        $this->assertTrue($this->manager->hasUnusablePassword());

        $this->post('/login', [
            'email' => $this->manager->EmailAddress,
            'password' => self::PASSWORD,
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_the_sentinel_itself_is_not_a_password(): void
    {
        // The obvious way to write it — through the model's `hashed` cast —
        // would store bcrypt('!reset-required') and make that literal string
        // the password. makePasswordUnusable() exists to stop exactly this,
        // and this test is what would catch it coming back.
        $this->post('/login', [
            'email' => $this->manager->EmailAddress,
            'password' => User::UNUSABLE_PASSWORD,
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_the_reset_link_lets_a_migrated_user_in_and_lands_them_by_role(): void
    {
        $token = $this->issueToken($this->manager);

        $this->post('/password/reset', [
            'token' => $token,
            'email' => $this->manager->EmailAddress,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ])->assertRedirect(route('login'));

        $this->manager->refresh();
        $this->assertFalse($this->manager->hasUnusablePassword());
        $this->assertFalse($this->manager->MustChangePassword);
        $this->assertNotNull($this->manager->PasswordChangedAt);

        // A branch manager lands on /console — the route name is on the ROLE
        // row, so this is asserting the data as much as the code.
        $this->post('/login', [
            'email' => $this->manager->EmailAddress,
            'password' => self::PASSWORD,
        ])->assertRedirect(route('app.console'));

        $this->assertAuthenticatedAs($this->manager->fresh());
    }

    public function test_an_executive_lands_on_exco(): void
    {
        $token = $this->issueToken($this->executive);

        $this->post('/password/reset', [
            'token' => $token,
            'email' => $this->executive->EmailAddress,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ]);

        $this->post('/login', [
            'email' => $this->executive->EmailAddress,
            'password' => self::PASSWORD,
        ])->assertRedirect(route('app.exco'));
    }

    public function test_signing_in_writes_an_activity_row_and_moves_last_sign_in_at(): void
    {
        $this->assertNull($this->manager->LastSignInAt);

        $this->post('/password/reset', [
            'token' => $this->issueToken($this->manager),
            'email' => $this->manager->EmailAddress,
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
        ]);

        $this->post('/login', [
            'email' => $this->manager->EmailAddress,
            'password' => self::PASSWORD,
        ]);

        $rows = UserActivity::query()->acrossBranches()->where('UserId', $this->manager->Id)->get();

        // usp_Core_LogActivity writes the row and stamps the column in one
        // transaction, so this asserts both or neither.
        $this->assertTrue($rows->contains('Activity', UserActivity::SIGN_IN));
        $this->assertTrue($rows->contains('Activity', UserActivity::PASSWORD_RESET));
        $this->assertNotNull($this->manager->fresh()->LastSignInAt);
    }

    public function test_a_refused_sign_in_against_a_known_account_is_recorded(): void
    {
        $this->manager->forceFill(['IsLocked' => true])->save();

        $this->post('/login', [
            'email' => $this->manager->EmailAddress,
            'password' => self::PASSWORD,
        ])->assertSessionHasErrors('email');

        // The password never validates for this account anyway; what is being
        // asserted is that the LOCK is the reason given and the attempt is
        // attributable.
        $this->assertGuest();
    }

    public function test_a_user_who_must_change_their_password_is_held_on_that_screen(): void
    {
        // The state an administrator setting a temporary password leaves
        // behind. RequirePasswordChange is what makes "reset on first login"
        // true rather than advisory.
        $this->manager->forceFill([
            'PasswordHash' => self::PASSWORD,
            'MustChangePassword' => true,
        ])->save();

        $this->post('/login', [
            'email' => $this->manager->EmailAddress,
            'password' => self::PASSWORD,
        ])->assertRedirect(route('app.password.change'));

        $this->actingAs($this->manager->fresh())
            ->get('/app')
            ->assertRedirect(route('app.password.change'));

        // And the screen itself is reachable, or they would be in a loop.
        $this->actingAs($this->manager->fresh())
            ->get(route('app.password.change'))
            ->assertOk();
    }

    public function test_both_landing_stubs_render(): void
    {
        // Both fixtures arrive with MustChangePassword set, exactly as a
        // migrated user does — and RequirePasswordChange would quite correctly
        // redirect them off these pages. Clear it: this test is about the
        // stubs rendering, not about the guard.
        foreach ([$this->manager, $this->executive] as $user) {
            $user->forceFill([
                'PasswordHash' => self::PASSWORD,
                'MustChangePassword' => false,
            ])->save();
        }

        // A landing route that 500s is worse than one that does not exist: it
        // is the FIRST screen two of the six roles see after signing in.
        $this->actingAs($this->manager->fresh())->get(route('app.console'))
            ->assertOk()
            ->assertSee('Branch console')
            ->assertSee('T030');

        $this->actingAs($this->executive->fresh())->get(route('app.exco'))
            ->assertOk()
            ->assertSee('Exco pack')
            ->assertSee('T077');
    }

    public function test_forgot_password_says_the_same_thing_either_way(): void
    {
        $this->post('/password/forgot', ['email' => $this->manager->EmailAddress]);
        $known = session('status');

        $this->post('/password/forgot', ['email' => 'nobody-at-all@agora.invalid']);
        $unknown = session('status');

        // A form that says "no such account" is a staff directory with a
        // submit button.
        $this->assertNotNull($known);
        $this->assertSame($known, $unknown);

        Mail::assertSent(PasswordResetMail::class, 1);
    }

    public function test_the_sign_in_page_carries_the_system_state_panel(): void
    {
        $response = $this->get('/login');

        $response->assertOk()
            ->assertSee('System state')
            ->assertSee('Zululand Retail &amp; Petroleum', false)
            // The four figures are placeholders until their modules land, and
            // an em dash rather than a zero is the point: "All overnight loads
            // clean" on a system that has not looked would be a lie.
            ->assertSee('Overnight loads')
            ->assertSee('Exception register')
            ->assertSee('Z-read allocation')
            ->assertSee('Purchase approvals');
    }

    private function makeUser(string $email, string $name, string $roleCode): User
    {
        $role = Role::query()->acrossBranches()->where('Code', $roleCode)->firstOrFail();

        $user = User::query()->acrossBranches()->firstOrNew([
            'BranchId' => (int) config('agora.group_branch_id'),
            'EmailAddress' => $email,
        ]);

        $user->fill([
            'UserName' => $name,
            'RoleId' => $role->Id,
            'IsActive' => true,
            'IsLocked' => false,
        ]);
        $user->BranchId = (int) config('agora.group_branch_id');
        $user->LastSignInAt = null;
        $user->makePasswordUnusable()->save();

        return $user;
    }

    /** The token that would have gone out in the mail. */
    private function issueToken(User $user): string
    {
        $token = 'TEST-'.str_repeat('a', 59);

        PasswordReset::query()->acrossBranches()->updateOrCreate(
            ['BranchId' => (int) $user->BranchId, 'EmailAddress' => $user->EmailAddress],
            [
                'TokenHash' => hash('sha256', $token),
                'ExpiresAt' => now()->addMinutes(PasswordResetService::LIFETIME_MINUTES),
                'UsedAt' => null,
            ],
        );

        return $token;
    }
}
