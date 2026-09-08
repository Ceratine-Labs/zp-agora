<?php

namespace Tests\Feature\Core;

use App\Support\ProcedureService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Core\Mail\PasswordResetMail;
use Modules\Core\Models\Branch;
use Modules\Core\Models\PasswordReset;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Models\UserBranch;
use Modules\Core\Models\UserPermission;
use Modules\Core\Models\UserRole;
use Modules\Core\Services\PermissionService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Setup → Users and access (T028).
 *
 * The screens exist to answer two questions — who holds what, and what that
 * lets them do — so the tests are about the permission boundary rather than
 * about markup. The grid itself is Lane C's and already tested.
 */
class UserAdminScreenTest extends TestCase
{
    private int $branchId;

    /** @var array<int, User> */
    private array $made = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->branchId = (int) config('agora.group_branch_id', 2);
    }

    protected function tearDown(): void
    {
        foreach ($this->made as $user) {
            UserRole::query()->acrossBranches()->where('UserId', $user->Id)->delete();
            UserBranch::query()->acrossBranches()->where('UserId', $user->Id)->delete();
            UserPermission::query()->acrossBranches()->where('UserId', $user->Id)->delete();
            PasswordReset::query()->acrossBranches()
                ->where('EmailAddress', strtolower((string) $user->EmailAddress))->delete();
            User::query()->acrossBranches()->where('Id', $user->Id)->forceDelete();
        }

        parent::tearDown();
    }

    private function person(string $roleCode): User
    {
        $role = Role::query()->acrossBranches()->where('Code', $roleCode)->firstOrFail();

        /*
         * An ORDINARY signed-in person: a real password and no forced change.
         *
         * It used to pass User::UNUSABLE_PASSWORD to create(), which the
         * `hashed` cast turned into bcrypt('!reset-required') — a working
         * password whose plaintext is written down in the model. Writing the
         * sentinel properly (makePasswordUnusable) is worse for a FIXTURE
         * though, because it sets MustChangePassword too, and
         * RequirePasswordChange then redirects every one of these tests to the
         * change-password screen. A migrated user is a state one test needs,
         * not the state they all start in — so that test asks for it.
         */
        $user = User::query()->acrossBranches()->create([
            'BranchId' => $this->branchId,
            'EmailAddress' => 'TEST-t028-'.uniqid().'@agora.invalid',
            'UserName' => 'TEST-'.$roleCode,
            'RoleId' => $role->Id,
            'IsActive' => true,
            'IsLocked' => false,
            'PasswordHash' => 'TEST-only-'.Str::random(24),
        ]);

        UserRole::query()->acrossBranches()->create([
            'BranchId' => $this->branchId,
            'UserId' => $user->Id,
            'RoleId' => $role->Id,
            'IsPrimary' => true,
        ]);

        app(PermissionService::class)->forget($user);
        $this->made[] = $user;

        return $user;
    }

    public function test_an_admin_sees_the_user_list(): void
    {
        $this->actingAs($this->person('admin'))
            ->get(route('app.setup.users.index'))
            ->assertOk()
            ->assertSee('Users and access');
    }

    public function test_a_branch_manager_is_refused_the_user_list(): void
    {
        $this->actingAs($this->person('branch-manager'))
            ->get(route('app.setup.users.index'))
            ->assertForbidden();
    }

    public function test_the_auditor_may_look_but_not_save(): void
    {
        $auditor = $this->person('auditor');
        $subject = $this->person('operations');

        $this->actingAs($auditor)
            ->get(route('app.setup.users.show', ['user' => $subject->Id]))
            ->assertOk();

        // *.*.view covers setup.users.view but never setup.users.edit.
        $this->actingAs($auditor)
            ->put(route('app.setup.users.update', ['user' => $subject->Id]), ['roles' => []])
            ->assertForbidden();
    }

    public function test_saving_roles_replaces_the_set_rather_than_adding_to_it(): void
    {
        $admin = $this->person('admin');
        $subject = $this->person('operations');

        $finance = Role::query()->acrossBranches()->where('Code', 'finance')->firstOrFail();

        $this->actingAs($admin)
            ->put(route('app.setup.users.update', ['user' => $subject->Id]), [
                'roles' => [$finance->Id],
                'primary' => $finance->Id,
            ])
            ->assertRedirect(route('app.setup.users.edit', ['user' => $subject->Id]).'#roles');

        $held = UserRole::query()->acrossBranches()->where('UserId', $subject->Id)->get();

        $this->assertCount(1, $held, 'The old Operations grant must be gone, not kept alongside.');
        $this->assertSame($finance->Id, (int) $held->first()->RoleId);
        $this->assertTrue((bool) $held->first()->IsPrimary);

        // RoleId is the denormalised pointer the landing route reads; it must
        // follow the primary grant or the two disagree silently.
        $this->assertSame($finance->Id, (int) $subject->fresh()->RoleId);

        // And the change must be visible immediately, not after a cache TTL.
        $this->assertTrue(app(PermissionService::class)->userHas($subject->fresh(), 'recon.runs.execute'));
    }

    public function test_the_edit_screen_is_behind_the_edit_permission(): void
    {
        $subject = $this->person('operations');

        $this->actingAs($this->person('admin'))
            ->get(route('app.setup.users.edit', ['user' => $subject->Id]))
            ->assertOk()
            ->assertSee('Save roles')
            ->assertSee('Save sites');

        // The Auditor holds *.*.view, which reaches the VIEW screen and stops
        // there. A read-only role must not reach a page made of Save buttons.
        $this->actingAs($this->person('auditor'))
            ->get(route('app.setup.users.edit', ['user' => $subject->Id]))
            ->assertForbidden();
    }

    public function test_granting_sites_replaces_the_set_and_settles_what_the_person_is(): void
    {
        $admin = $this->person('admin');
        $subject = $this->person('operations');

        $sites = Branch::query()->acrossBranches()->trading()->ordered()->take(2)->get();
        $this->assertCount(2, $sites, 'Needs at least two trading branches seeded.');

        // Two sites: head office, and no home branch invented for them.
        $this->actingAs($admin)
            ->put(route('app.setup.users.branches.update', ['user' => $subject->Id]), [
                'branches' => $sites->pluck('BranchId')->all(),
            ])
            ->assertRedirect(route('app.setup.users.edit', ['user' => $subject->Id]).'#branches');

        $this->assertSame(
            $sites->pluck('BranchId')->map(fn ($id) => (int) $id)->sort()->values()->all(),
            collect($subject->fresh()->allowedBranchIds())->sort()->values()->all()
        );
        $this->assertSame('ho', $subject->fresh()->UserType);

        // One site: a branch user, pinned to it. And the SET is replaced —
        // the other grant is gone, not kept alongside.
        $this->actingAs($admin)
            ->put(route('app.setup.users.branches.update', ['user' => $subject->Id]), [
                'branches' => [$sites->first()->BranchId],
            ])->assertRedirect();

        $after = $subject->fresh();
        $this->assertSame([(int) $sites->first()->BranchId], $after->allowedBranchIds());
        $this->assertSame('branch', $after->UserType);
        $this->assertSame((int) $sites->first()->BranchId, (int) $after->HomeBranchId);
    }

    public function test_granting_no_site_means_every_site_rather_than_none(): void
    {
        $admin = $this->person('admin');
        $subject = $this->person('operations');
        $site = Branch::query()->acrossBranches()->trading()->ordered()->firstOrFail();

        $this->actingAs($admin)->put(
            route('app.setup.users.branches.update', ['user' => $subject->Id]),
            ['branches' => [$site->BranchId]]
        );

        $this->actingAs($admin)->put(
            route('app.setup.users.branches.update', ['user' => $subject->Id]),
            ['branches' => []]
        );

        $after = $subject->fresh();

        $this->assertSame([], $after->allowedBranchIds());
        // NULL, not 'ho' and not 'branch': nobody has decided, and the grid
        // and the view screen both say "every site" rather than guessing.
        $this->assertNull($after->UserType);
        // The pin is released with the grant that justified it.
        $this->assertNull($after->HomeBranchId);
    }

    public function test_the_nav_branch_filter_offers_only_the_sites_a_person_is_granted(): void
    {
        $admin = $this->person('admin');
        $subject = $this->person('branch-manager');

        $sites = Branch::query()->acrossBranches()->trading()->ordered()->take(2)->get();

        // Before: no grant, so the whole trading estate.
        $estate = Branch::query()->acrossBranches()->trading()->count();
        $this->assertGreaterThan(2, $estate, 'Needs more branches than the two granted below.');

        $this->actingAs($admin)->put(
            route('app.setup.users.branches.update', ['user' => $subject->Id]),
            ['branches' => $sites->pluck('BranchId')->all()]
        );

        // Read the RENDERED bar, not the composer's array. The whole claim is
        // that what a person can pick in the nav equals what they were granted
        // on the user screen, and only the markup carries that end to end.
        $response = $this->actingAs($subject->fresh())->get('/app');
        $response->assertOk()->assertSee('2 sites granted to you');

        preg_match('~<select name="branch".*?</select>~s', $response->getContent() ?: '', $select);
        $this->assertNotEmpty($select, 'The branch workspace must render a site selector.');

        preg_match_all('~<option value="(\d+)"~', $select[0], $options);
        $offered = collect($options[1])->map(fn ($id) => (int) $id)->sort()->values()->all();

        $this->assertSame(
            $sites->pluck('BranchId')->map(fn ($id) => (int) $id)->sort()->values()->all(),
            $offered,
            'The scope bar must offer the granted sites and nothing else.'
        );
    }

    public function test_a_permission_granted_by_name_is_held_and_then_taken_away(): void
    {
        $admin = $this->person('admin');
        // Operations may preview a reconciliation and deliberately may not
        // commit one — that is the grant RolePermissionSeeder withholds.
        $subject = $this->person('operations');
        $this->assertFalse(app(PermissionService::class)->userHas($subject, 'recon.runs.execute'));

        $execute = Permission::query()->acrossBranches()->where('Code', 'recon.runs.execute')->firstOrFail();

        $this->actingAs($admin)->put(
            route('app.setup.users.permissions.update', ['user' => $subject->Id]),
            ['permissions' => [$execute->Id]]
        )->assertRedirect(route('app.setup.users.edit', ['user' => $subject->Id]).'#permissions');

        $this->assertTrue(
            app(PermissionService::class)->userHas($subject->fresh(), 'recon.runs.execute'),
            'A direct grant must be held without a second role being handed over with it.'
        );
        // And only that one — a direct grant is a named permission, not a
        // wildcard, so reverse must NOT have come along with execute.
        $this->assertFalse(app(PermissionService::class)->userHas($subject->fresh(), 'recon.runs.reverse'));

        $this->actingAs($admin)->put(
            route('app.setup.users.permissions.update', ['user' => $subject->Id]),
            ['permissions' => []]
        );

        $this->assertFalse(app(PermissionService::class)->userHas($subject->fresh(), 'recon.runs.execute'));
    }

    public function test_an_administrator_cannot_remove_their_own_way_back_in(): void
    {
        $admin = $this->person('admin');

        $this->actingAs($admin)
            ->put(route('app.setup.users.update', ['user' => $admin->Id]), ['roles' => []])
            ->assertSessionHasErrors('roles');

        // Refused INSIDE the transaction, so the grant is still there rather
        // than half-removed with an apology on the screen.
        $this->assertTrue(
            app(PermissionService::class)->userHas($admin->fresh(), 'setup.users.edit'),
            'The refusal has to roll the write back, not merely report it.'
        );
        $this->assertSame(
            1,
            UserRole::query()->acrossBranches()->where('UserId', $admin->Id)->count()
        );
    }

    public function test_details_save_refuses_a_home_site_the_person_is_not_granted(): void
    {
        $admin = $this->person('admin');
        $subject = $this->person('operations');

        $sites = Branch::query()->acrossBranches()->trading()->ordered()->take(2)->get();

        $this->actingAs($admin)->put(
            route('app.setup.users.branches.update', ['user' => $subject->Id]),
            ['branches' => [$sites->first()->BranchId]]
        );

        $this->actingAs($admin)->put(
            route('app.setup.users.details.update', ['user' => $subject->Id]),
            [
                'UserName' => $subject->UserName,
                'EmailAddress' => $subject->EmailAddress,
                'HomeBranchId' => $sites->last()->BranchId,
                'IsActive' => 1,
                'IsLocked' => 0,
            ]
        )->assertSessionHasErrors('details');

        // Still pinned to the site they ARE granted.
        $this->assertSame((int) $sites->first()->BranchId, (int) $subject->fresh()->HomeBranchId);
    }

    public function test_the_user_list_gives_you_a_way_into_a_row(): void
    {
        $subject = $this->person('operations');

        $html = $this->actingAs($this->person('admin'))
            ->get(route('app.setup.users.index'))
            ->assertOk()
            ->getContent();

        // The regression this exists for: rowUrl() was declared on
        // GridDefinition, overridden here, and read by NOTHING — so the list
        // rendered 90 names as plain text with no way to open any of them.
        $show = route('app.setup.users.show', ['user' => $subject->Id]);
        $edit = route('app.setup.users.edit', ['user' => $subject->Id]);

        $this->assertStringContainsString('href="'.$show.'"', (string) $html, 'The name must link to the person.');
        $this->assertStringContainsString('href="'.$edit.'"', (string) $html, 'An admin must get an Edit action on the row.');
    }

    public function test_a_reader_who_cannot_edit_is_offered_no_edit_action(): void
    {
        $subject = $this->person('operations');

        // The Auditor holds *.*.view — it reaches the list and the view screen
        // and stops there. feature-rules §4: a control the user cannot use is
        // not rendered, as well as being refused by the route.
        $html = (string) $this->actingAs($this->person('auditor'))
            ->get(route('app.setup.users.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            'href="'.route('app.setup.users.show', ['user' => $subject->Id]).'"',
            $html,
            'A reader still gets in — they just cannot change anything.'
        );
        $this->assertStringNotContainsString(
            'href="'.route('app.setup.users.edit', ['user' => $subject->Id]).'"',
            $html
        );
    }

    public function test_a_temporary_password_is_generated_shown_once_and_forces_a_change(): void
    {
        $admin = $this->person('admin');
        $subject = $this->person('operations');

        // The state the 88 migrated users actually arrive in, written past the
        // `hashed` cast — assigning the constant normally would store
        // bcrypt('!reset-required') and make that literal string the password.
        $subject->makePasswordUnusable()->save();
        $this->assertTrue($subject->fresh()->hasUnusablePassword());

        $response = $this->actingAs($admin)->post(
            route('app.setup.users.password.update', ['user' => $subject->Id]),
            ['action' => 'temporary']
        );

        $response->assertRedirect(route('app.setup.users.edit', ['user' => $subject->Id]).'#password');
        $plain = session('temporary_password');

        $this->assertIsString($plain);
        $this->assertGreaterThanOrEqual(12, strlen($plain), 'Length is what carries the strength — symbols are off so it can be read down a phone.');

        $after = $subject->fresh();

        // The credential works, is not the sentinel, and cannot be kept.
        $this->assertFalse($after->hasUnusablePassword());
        $this->assertTrue(Hash::check($plain, $after->getAuthPassword()));
        $this->assertTrue($after->MustChangePassword, 'RequirePasswordChange must hold them until they replace it.');
        $this->assertNotNull($after->PasswordChangedAt);
    }

    public function test_clearing_a_password_puts_the_account_beyond_signing_in(): void
    {
        $admin = $this->person('admin');
        $subject = $this->person('operations');

        $this->actingAs($admin)->post(
            route('app.setup.users.password.update', ['user' => $subject->Id]),
            ['action' => 'temporary']
        );
        $this->assertFalse($subject->fresh()->hasUnusablePassword());

        $this->actingAs($admin)->post(
            route('app.setup.users.password.update', ['user' => $subject->Id]),
            ['action' => 'clear']
        );

        // The sentinel itself, past the `hashed` cast — bcrypt of it would
        // make the literal string a working password.
        $this->assertTrue($subject->fresh()->hasUnusablePassword());
    }

    public function test_a_set_password_link_is_issued_and_refused_when_there_is_nowhere_to_send_it(): void
    {
        $admin = $this->person('admin');
        $subject = $this->person('operations');

        Mail::fake();

        $this->actingAs($admin)->post(
            route('app.setup.users.password.update', ['user' => $subject->Id]),
            ['action' => 'link']
        )->assertSessionHas('status');

        // The LOWERCASED address, not the one on the row. PasswordResetService
        // normalises before it looks anybody up, because agora.PasswordReset is
        // unique on (BranchId, EmailAddress) and two casings of one address
        // would be two live tokens for one person.
        Mail::assertSent(
            PasswordResetMail::class,
            fn ($mail) => $mail->hasTo(strtolower($subject->EmailAddress))
        );

        // A live token, through the same table "Forgot your password?" uses.
        $this->assertNotNull(
            PasswordReset::query()->acrossBranches()
                ->where('EmailAddress', strtolower($subject->EmailAddress))->first()
        );

        // And there is no such thing as a person with nowhere to send it:
        // agora.User.EmailAddress is NOT NULL and the address IS the sign-in
        // identity, so the details form refuses a blank one on the field
        // rather than letting it reach the driver as a 500.
        $this->actingAs($admin)->put(
            route('app.setup.users.details.update', ['user' => $subject->Id]),
            ['UserName' => $subject->UserName, 'EmailAddress' => '', 'IsActive' => 1, 'IsLocked' => 0]
        )->assertSessionHasErrors('EmailAddress');

        $this->assertNotEmpty($subject->fresh()->EmailAddress);
    }

    public function test_a_mail_server_that_refuses_is_reported_and_not_a_500(): void
    {
        $admin = $this->person('admin');
        $subject = $this->person('operations');

        // Real SMTP can fail; MAIL_MAILER=log never could, which is how the
        // forgot-password form ended up with an uncaught throw on the one page
        // every migrated user has to use.
        Mail::shouldReceive('to->send')
            ->andThrow(new \RuntimeException('535 Authentication failed'));

        $this->actingAs($admin)->post(
            route('app.setup.users.password.update', ['user' => $subject->Id]),
            ['action' => 'link']
        )->assertSessionHasErrors('password');

        // The administrator is told plainly, but the SMTP account's name is
        // not repeated onto the screen — that stays in the log.
        $this->assertStringNotContainsString(
            '535',
            (string) session('errors')?->first('password')
        );
    }

    public function test_the_view_screen_renders_for_somebody_who_has_actually_signed_in(): void
    {
        $subject = $this->person('operations');

        // THE GAP THAT 500'd LIVE. Every fixture here had LastSignInAt null,
        // so the statstrip's "Never" branch was the only one any test, the
        // browser walk or the deploy smoke test ever rendered — and the other
        // branch called Format::datetime(), which has never existed. Nothing
        // caught it until Ryan opened a real person on agora.ceratine.com.
        $subject->forceFill(['LastSignInAt' => now()->subDays(3)])->saveQuietly();

        $this->actingAs($this->person('admin'))
            ->get(route('app.setup.users.show', ['user' => $subject->Id]))
            ->assertOk()
            ->assertSee($subject->fresh()->LastSignInAt->format('Y-m-d H:i'));
    }

    public function test_the_role_matrix_renders_for_someone_who_may_see_it(): void
    {
        $this->actingAs($this->person('admin'))
            ->get(route('app.setup.roles.index'))
            ->assertOk()
            ->assertSee('recon.runs.execute');
    }

    /**
     * A header filter that matches NOTHING must return nothing.
     *
     * This is the only shape of test that catches the bug this screen shipped
     * with: agora.usp_Core_GridUsers read @FiltersJson as OPENJSON keyed on
     * `[key]` looking for `$.q`, and over a JSON array `[key]` is the index and
     * `$.q` is nowhere — so every filter variable stayed NULL, every predicate
     * short-circuited to true, and the filters narrowed nothing at all from the
     * day the screen went live. A test that filters on a value which IS present
     * passes either way, which is why it survived review and a green suite.
     *
     * @return array<string, array{0: array<string, mixed>, 1: bool}>
     */
    public static function headerFilters(): array
    {
        return [
            'name that exists' => [['column' => 'UserName', 'type' => 'text', 'op' => 'contains', 'value' => 'Ryan'], true],
            'name that does not' => [['column' => 'UserName', 'type' => 'text', 'op' => 'contains', 'value' => 'ZZZZNOSUCHNAME'], false],
            'email that does not' => [['column' => 'EmailAddress', 'type' => 'text', 'op' => 'contains', 'value' => 'ZZZZ@nowhere'], false],
            'role that does not' => [['column' => 'RoleNames', 'type' => 'text', 'op' => 'contains', 'value' => 'ZZZZNOSUCHROLE'], false],
            'status that does not' => [['column' => 'Status', 'type' => 'text', 'op' => 'contains', 'value' => 'ZZZZNOSUCHSTATE'], false],
            // A set filter has no $.value at all, so it was broken twice over.
            'type that does not' => [['column' => 'UserType', 'type' => 'set', 'in' => ['ZZZZ']], false],
        ];
    }

    /**
     * @param  array<string, mixed>  $filter
     */
    #[DataProvider('headerFilters')]
    public function test_a_header_filter_actually_narrows(array $filter, bool $expectRows): void
    {
        $sets = app(ProcedureService::class)->callSets('agora.usp_Core_GridUsers', [
            'BranchIds' => null, 'DateFrom' => null, 'DateTo' => null, 'Search' => null,
            'SortColumn' => 'UserName', 'SortAsc' => 1, 'Page' => 1, 'PageSize' => 200,
            'FiltersJson' => json_encode([$filter]),
        ]);

        $rows = $sets[0]->count();
        $total = (int) $sets[1]->first()->TotalRows;

        $expectRows
            ? $this->assertGreaterThan(0, $rows, 'A filter on a value that exists should find it.')
            : $this->assertSame(0, $rows, 'A filter matching nothing must return nothing — not everything.');

        // The footer counts the same set as the page. It used to repeat the
        // predicates by hand and had drifted: RoleNames and Status were never
        // counted, so filtering on either gave a pager offering empty pages.
        $this->assertSame($rows, $total, 'The total must count the same set the page came from.');
    }
}
