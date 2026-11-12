<?php

namespace Tests\Feature\Core;

use App\Support\BranchContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Core\Models\Branch;
use Modules\Core\Models\User;
use Modules\Core\Models\UserActivity;
use Modules\Core\Services\UserAccessService;
use Modules\Core\Services\UserBulkAccessService;
use Tests\Fixtures\GrantsAccess;
use Tests\TestCase;

/**
 * T028: taking away the right to sign in ends the session already open.
 *
 * Before EndInactiveSessions, IsActive and IsLocked were read only at the
 * door (the sign-in form and the SSO handshake), so somebody deactivated
 * mid-shift kept working until their session expired.
 *
 * HOW A "NEXT REQUEST" IS MADE HERE. A feature test keeps one application
 * alive across requests, and the session guard memoises the user it resolved
 * on the first one — in production every request is a fresh process that
 * reloads agora.User by Id. `Auth::forgetGuards()` between the deactivation
 * and the next request is what makes the test see what production sees; it
 * does not help the middleware, it removes a staleness only the harness has.
 * The session itself is the real one the sign-in form wrote.
 *
 * Writes inside one transaction that tearDown rolls back; every person is
 * TEST- prefixed on agora.invalid.
 */
class DeactivatedSessionTest extends TestCase
{
    use GrantsAccess;

    private const PASSWORD = 'TEST-Th1s-Is-A-Real-Password!';

    private bool $transactionStarted = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->skipUnlessLocalStub();
        DB::connection(config('agora.connections.app'))->beginTransaction();
        $this->transactionStarted = true;
    }

    protected function tearDown(): void
    {
        if ($this->transactionStarted) {
            DB::connection(config('agora.connections.app'))->rollBack();
        }
        parent::tearDown();
    }

    /** @param  array<string, mixed>  $extra */
    private function person(string $profile = 'operations', array $extra = []): User
    {
        $person = User::query()->acrossBranches()->create([
            'BranchId' => config('agora.group_branch_id'),
            'UserName' => 'TEST-session-'.$profile,
            'EmailAddress' => 'TEST-session-'.uniqid().'@agora.invalid',
            'IsActive' => true,
            'IsLocked' => false,
            'MustChangePassword' => false,
            'Workspace' => 'ho',
            'PasswordHash' => self::PASSWORD,
            ...$extra,
        ]);
        $this->grantProfile($person, $profile);
        RateLimiter::clear('login:'.strtolower($person->EmailAddress).'|127.0.0.1');

        return $person;
    }

    /** Through the real form, so the session holds what production's does. */
    private function signIn(User $person): void
    {
        $this->post('/login', ['email' => $person->EmailAddress, 'password' => self::PASSWORD])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->get('/app')->assertOk();
        $this->assertSame((int) $person->Id, (int) Auth::id());
    }

    /** The next request, as a fresh process would make it. */
    private function nextRequest(): void
    {
        Auth::forgetGuards();
    }

    public function test_deactivating_a_signed_in_person_ends_their_session_on_the_next_request(): void
    {
        $admin = $this->person('admin');
        $subject = $this->person();
        $this->signIn($subject);

        app(UserAccessService::class)->updateDetails($subject->fresh(), ['IsActive' => false], $admin);
        $this->nextRequest();

        $message = 'Your account has been deactivated, so you have been signed out. Ask head office if you still need access.';
        $this->get('/app')->assertRedirect(route('login'));
        $this->assertGuest();

        // The sign-in page says why, and the session stays ended. (Read off the
        // page, not with assertSessionHasErrors first: under JSON session
        // serialisation that assertion leaves the bag in a shape the next
        // request in the SAME harness cannot unpack — a test artefact, not
        // something a browser meets.)
        $this->get(route('login'))->assertOk()->assertSee($message);
        $this->get('/app')->assertRedirect(route('login'));

        $this->assertTrue(UserActivity::query()->acrossBranches()
            ->where('UserId', $subject->Id)->where('Activity', UserActivity::SIGN_OUT)
            ->where('Detail', 'Session ended: account deactivated.')->exists());

        // And the door stays shut.
        $this->post('/login', ['email' => $subject->EmailAddress, 'password' => self::PASSWORD])
            ->assertSessionHasErrors(['email' => 'This account is not active.']);
        $this->assertGuest();
    }

    public function test_deactivation_through_the_admin_screen_ends_the_session_too(): void
    {
        $admin = $this->person('admin');
        $subject = $this->person();
        $this->signIn($subject);

        // The admin's request, then the subject's — one browser each in life,
        // one guard swapped here.
        $this->actingAs($admin)->post(route('app.setup.users.bulk.update'), ['items' => [$subject->Id], 'action' => 'deactivate'])
            ->assertSessionHasNoErrors();
        $this->assertFalse($subject->fresh()->IsActive);

        $this->nextRequest();
        $this->withSession([Auth::guard()->getName() => $subject->Id]);
        $this->get('/app')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_bulk_deactivation_ends_every_selected_session_and_rotates_remember_tokens(): void
    {
        $admin = $this->person('admin');
        $first = $this->person('operations', ['RememberToken' => 'TEST-first-remember']);
        $second = $this->person('operations', ['RememberToken' => 'TEST-second-remember']);
        $untouched = $this->person('operations', ['RememberToken' => 'TEST-untouched-remember']);

        $this->signIn($first);

        app(UserBulkAccessService::class)->apply([$first->Id, $second->Id], 'deactivate', [], $admin);
        $this->nextRequest();

        $this->get('/app')->assertRedirect(route('login'))->assertSessionHasErrors('email');
        $this->assertGuest();

        // A remember-me cookie issued before the change no longer names a
        // valid token, so it cannot sign either of them back in later — even
        // after they are switched on again.
        $this->assertNotSame('TEST-first-remember', $first->fresh()->RememberToken);
        $this->assertNotSame('TEST-second-remember', $second->fresh()->RememberToken);
        $this->assertSame('TEST-untouched-remember', $untouched->fresh()->RememberToken);

        // The second person's session, opened in another browser, ends the same way.
        $this->withSession([Auth::guard()->getName() => $second->Id]);
        $this->nextRequest();
        $this->get('/app')->assertRedirect(route('login'));
        $this->assertGuest();

        // Someone still active is not touched.
        $this->signIn($untouched);
        $this->nextRequest();
        $this->get('/app')->assertOk();
    }

    public function test_locking_a_signed_in_person_ends_their_session_with_its_own_reason(): void
    {
        $admin = $this->person('admin');
        $subject = $this->person();
        $this->signIn($subject);

        app(UserAccessService::class)->updateDetails($subject->fresh(), ['IsLocked' => true], $admin);
        $this->nextRequest();

        $this->get('/app')->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Your account has been locked, so you have been signed out. Head office can unlock it.']);
        $this->assertGuest();
    }

    public function test_an_asynchronous_caller_gets_a_401_rather_than_a_page(): void
    {
        $admin = $this->person('admin');
        $subject = $this->person();
        $this->signIn($subject);

        app(UserAccessService::class)->updateDetails($subject->fresh(), ['IsActive' => false], $admin);
        $this->nextRequest();

        $this->getJson('/app')->assertStatus(401)->assertJsonPath('message', fn ($m) => str_contains($m, 'deactivated'));
        $this->assertGuest();
    }

    public function test_a_head_office_person_with_no_branch_grant_sees_every_branch(): void
    {
        $subject = $this->person();
        $this->assertSame([], $subject->allowedBranchIds());
        $this->assertNull($subject->HomeBranchId);

        $estate = Branch::query()->acrossBranches()->trading()->ordered()->pluck('BranchId')
            ->map(fn ($id) => (int) $id)->all();
        $this->assertGreaterThan(2, count($estate), 'Needs a trading estate to compare against.');

        $this->signIn($subject);

        // Head office: no selector, and the note says the list is the estate,
        // not a grant.
        $this->nextRequest();
        $this->get('/app')->assertOk()
            ->assertSee(count($estate).' sites in scope — every trading site')
            ->assertDontSee('granted to you');

        $context = app(BranchContext::class);
        $this->assertSame([], $context->allowed());
        foreach ($estate as $branchId) {
            $this->assertTrue($context->maySee($branchId), "Branch {$branchId} must be visible to an ungranted head-office user.");
        }

        // Dropping into the branch workspace offers every trading site.
        $this->nextRequest();
        $response = $this->get('/app?ws=branch')->assertOk();
        preg_match('~<select name="branch".*?</select>~s', $response->getContent() ?: '', $select);
        $this->assertNotEmpty($select, 'The branch workspace must render a site selector.');
        preg_match_all('~<option value="(\d+)"~', $select[0], $options);
        $offered = collect($options[1])->map(fn ($id) => (int) $id)->sort()->values()->all();
        $this->assertSame(collect($estate)->sort()->values()->all(), $offered);
    }
}
