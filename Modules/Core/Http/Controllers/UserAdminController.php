<?php

namespace Modules\Core\Http\Controllers;

use App\Grid\GridRegistry;
use App\Grid\GridService;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Models\UserBranch;
use Modules\Core\Models\UserPermission;
use Modules\Core\Models\UserRole;
use Modules\Core\Services\PasswordResetService;
use Modules\Core\Services\PermissionService;
use Modules\Core\Services\UserAccessService;
use RuntimeException;

/**
 * Setup → People and assets → Users and access (T028).
 *
 * Three screens, and the split between them is feature-rules §5 rather than
 * taste: the list is a grid over agora.usp_Core_GridUsers, the VIEW answers
 * "who is this person and what may they do", and the EDIT screen is the only
 * place anything changes. A view screen that also saved would need every
 * reader to hold `setup.users.edit`.
 *
 * THE EDIT SCREEN HAS FOUR SAVE BUTTONS, ONE PER CARD, and that is deliberate.
 * Each card owns a complete set — the roles, the sites, the extra permissions,
 * the person themselves — and each Save REPLACES its set rather than diffing
 * it, because a grant removed is the change that matters. A single Save across
 * all four would mean one refusal (a home branch that is not granted, say)
 * discarding the other three cards' work, and it would make "absent means
 * empty" ambiguous the moment one card fails validation.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO. It does not create a user: the 88 people
 * come from PumpIT through usp_Core_MigrateUsers, and a hand-made 89th before
 * that migration has run would collide with it on email address. It does not
 * set anybody's password either — an administrator who can type a colleague's
 * password is exactly what User::UNUSABLE_PASSWORD exists to prevent, so the
 * only lever here is "reset it and make them choose".
 */
class UserAdminController extends Controller
{
    public function __construct(
        private GridRegistry $registry,
        private GridService $grids,
        private PermissionService $permissions,
        private UserAccessService $access,
    ) {}

    /** The user list. */
    public function index(Request $request): View
    {
        return view('core::setup.users.index', [
            'grid' => $this->grids->build(
                $this->registry->findOrFail('app.setup.users'),
                $request,
                $request->user()?->Id,
            ),
        ]);
    }

    /** One person, read only: their roles, their sites, and what those add up to. */
    public function show(Request $request, int $user): View
    {
        $person = $this->person($user);

        return view('core::setup.users.show', array_merge(
            $this->accessState($person),
            ['person' => $person]
        ));
    }

    /** The same person, with every set editable. */
    public function edit(Request $request, int $user): View
    {
        $person = $this->person($user);

        $state = $this->accessState($person);

        /*
         * Only the sites this person is granted, because ResolveBranchContext
         * puts the whole request in the branch workspace off HomeBranchId — a
         * home site they cannot open pins them to one the scope bar refuses to
         * offer. With no grants they may see everything, so the whole estate is
         * offered.
         */
        $home = $state['grantedBranchIds'] === []
            ? Branch::query()->acrossBranches()->ordered()->get()
            : $state['grantedBranches'];

        return view('core::setup.users.edit', array_merge($state, [
            'person' => $person,
            'allBranches' => Branch::query()->acrossBranches()->ordered()->get(),
            'homeBranchChoices' => ['' => 'None — head office']
                + $home->mapWithKeys(fn (Branch $b) => [(int) $b->BranchId => $b->Name])->all(),
        ]));
    }

    /**
     * Change which roles someone holds.
     *
     * The route and the payload are unchanged from the first cut of this
     * screen, so a link or a form written against it still works.
     */
    public function update(Request $request, int $user): RedirectResponse
    {
        $person = $this->person($user);

        $data = $request->validate([
            'roles' => ['array'],
            'roles.*' => ['integer'],
            'primary' => ['nullable', 'integer'],
        ]);

        return $this->save($person, 'roles', function () use ($person, $request, $data): string {
            $result = $this->access->setRoles(
                $person,
                $data['roles'] ?? [],
                isset($data['primary']) ? (int) $data['primary'] : null,
                $request->user(),
            );

            $count = count($result['roles']);

            return $count === 0
                ? $person->UserName.' now holds no roles and can sign in but see nothing.'
                : $person->UserName.' now holds '.$count.' role'.($count === 1 ? '' : 's').'.';
        });
    }

    /**
     * Change which sites someone may see.
     *
     * An EMPTY set is a real answer and means every site — see
     * UserAccessService::setBranches, which also settles UserType and
     * HomeBranchId off the same save so the scope bar cannot disagree with
     * this screen.
     */
    public function updateBranches(Request $request, int $user): RedirectResponse
    {
        $person = $this->person($user);

        $data = $request->validate([
            'branches' => ['array'],
            'branches.*' => ['integer'],
        ]);

        return $this->save($person, 'branches', function () use ($person, $data): string {
            $granted = $this->access->setBranches($person, $data['branches'] ?? []);

            return $granted === []
                ? $person->UserName.' is granted no site individually, which means every site.'
                : $person->UserName.' is granted '.count($granted).' site'.(count($granted) === 1 ? '' : 's')
                    .' and is now a '.($person->fresh()?->UserType === 'branch' ? 'branch' : 'head office').' user.';
        });
    }

    /** Change which permissions someone holds by name, beside their roles. */
    public function updatePermissions(Request $request, int $user): RedirectResponse
    {
        $person = $this->person($user);

        $data = $request->validate([
            'permissions' => ['array'],
            'permissions.*' => ['integer'],
        ]);

        return $this->save($person, 'permissions', function () use ($person, $request, $data): string {
            $granted = $this->access->setPermissions($person, $data['permissions'] ?? [], $request->user());

            return $granted === []
                ? $person->UserName.' holds nothing beyond what their roles carry.'
                : $person->UserName.' holds '.count($granted).' permission'.(count($granted) === 1 ? '' : 's')
                    .' directly, beside their roles.';
        });
    }

    /**
     * Change the person: their name, how they are reached, and whether they may
     * sign in at all.
     *
     * The email address is unique per branch in the estate and this validates
     * for it, because the collision it prevents — two rows the sign-in query
     * cannot choose between — surfaces as a failed login rather than as an
     * error anybody would connect to this screen.
     */
    public function updateDetails(Request $request, int $user): RedirectResponse
    {
        $person = $this->person($user);

        $data = $request->validate([
            'UserName' => ['required', 'string', 'max:120'],
            // REQUIRED, because agora.User.EmailAddress is NOT NULL and the
            // address IS the sign-in identity. Validating it as nullable let a
            // blank field reach the insert and come back as a 500 from the
            // driver rather than as a message on the field.
            'EmailAddress' => ['required', 'email', 'max:160'],
            'UserCode' => ['nullable', 'string', 'max:40'],
            'HomeBranchId' => ['nullable', 'integer'],
            'IsActive' => ['boolean'],
            'IsLocked' => ['boolean'],
        ]);

        $email = $data['EmailAddress'];

        if (User::query()->acrossBranches()
            ->where('EmailAddress', $email)
            ->where('Id', '!=', $person->Id)
            ->exists()
        ) {
            return back()
                ->withInput()
                ->withErrors(['EmailAddress' => 'Another user already signs in with that address.']);
        }

        return $this->save($person, 'details', function () use ($person, $request, $data, $email): string {
            $this->access->updateDetails($person, [
                'UserName' => $data['UserName'],
                'EmailAddress' => $email,
                'UserCode' => $data['UserCode'] ?? null,
                'HomeBranchId' => isset($data['HomeBranchId']) ? (int) $data['HomeBranchId'] : null,
                'IsActive' => (bool) ($data['IsActive'] ?? false),
                'IsLocked' => (bool) ($data['IsLocked'] ?? false),
            ], $request->user());

            return $person->UserName.' is saved.';
        });
    }

    /**
     * The three ways to get somebody into their account.
     *
     * One endpoint rather than three, because they are one decision with three
     * answers and only ever one of them is taken. Which is chosen arrives as
     * the submit button's own value, so the button IS the choice and there is
     * no state to get out of step with it.
     *
     * A generated temporary password comes back through the SESSION and is
     * shown once. It is never written to the log, never put in the URL, and it
     * is gone on the next request — a credential that survives in a place
     * somebody can go back to is not temporary, whatever it is called.
     */
    public function updatePassword(Request $request, int $user): RedirectResponse
    {
        $person = $this->person($user);

        $data = $request->validate([
            'action' => ['required', 'string', 'in:link,temporary,clear'],
        ]);

        $back = route('app.setup.users.edit', ['user' => $person->Id]).'#password';

        try {
            switch ($data['action']) {
                case 'link':
                    $this->access->sendSetPasswordLink($person);

                    return redirect($back)->with('status', config('mail.default') === 'log'
                        ? 'A link was generated for '.$person->EmailAddress.' — but MAIL_MAILER is `log`, '
                            .'so it went to storage/logs/laravel.log and no mail was sent. It expires in '
                            .PasswordResetService::LIFETIME_MINUTES.' minutes either way.'
                        : 'A set-password link is on its way to '.$person->EmailAddress.'. It expires in '
                            .PasswordResetService::LIFETIME_MINUTES.' minutes.');

                case 'temporary':
                    return redirect($back)
                        ->with('status', $person->UserName.' has a temporary password. Read it to them now — this is the only time it is shown.')
                        ->with('temporary_password', $this->access->setTemporaryPassword($person));

                default:
                    $this->access->resetPassword($person);

                    return redirect($back)->with(
                        'status',
                        $person->UserName.' has no usable password. They get in through a link, and nothing else.'
                    );
            }
        } catch (RuntimeException $e) {
            return redirect($back)->withErrors(['password' => $e->getMessage()]);
        }
    }

    /** The role matrix: every role, and every permission it carries. */
    public function roles(): View
    {
        $roles = Role::query()->acrossBranches()->orderBy('SortOrder')->get();
        $permissions = Permission::query()->acrossBranches()->orderBy('SortOrder')->get();

        $granted = DB::connection(config('agora.connections.app'))
            ->table(config('agora.schema').'.RolePermission')
            ->get()
            ->groupBy('RoleId')
            ->map(fn ($rows) => $rows->pluck('PermissionId')->flip());

        return view('core::setup.roles.index', [
            'roles' => $roles,
            'permissions' => $permissions->groupBy('Module'),
            'granted' => $granted,
        ]);
    }

    /**
     * Run one card's save, and turn a refusal into a message on the field it
     * belongs to rather than a 500.
     *
     * UserAccessService refuses three things — locking yourself out of this
     * screen, deactivating your own account, setting a home branch the person
     * is not granted. All three are decisions rather than faults, so they come
     * back to the form the way a validation failure does. `#card` puts the
     * reader back at the card they pressed Save on, since the edit screen is
     * taller than a laptop.
     *
     * @param  callable(): string  $write
     */
    private function save(User $person, string $card, callable $write): RedirectResponse
    {
        $back = route('app.setup.users.edit', ['user' => $person->Id]).'#'.$card;

        try {
            $status = $write();
        } catch (RuntimeException $e) {
            return redirect($back)->withInput()->withErrors([$card => $e->getMessage()]);
        }

        return redirect($back)->with('status', $status);
    }

    private function person(int $id): User
    {
        return User::query()->acrossBranches()->findOrFail($id);
    }

    /**
     * Everything both screens need to describe one person's access.
     *
     * Composed once because the view and the edit screens must not answer the
     * same question two different ways — the read-only page saying somebody
     * holds a permission while the editor shows the box unticked is the kind of
     * disagreement nobody reports and everybody stops trusting.
     *
     * @return array<string, mixed>
     */
    private function accessState(User $person): array
    {
        $rolePatterns = $this->permissions->rolePatternsFor($person);

        $grantedBranchIds = UserBranch::query()->acrossBranches()
            ->where('UserId', $person->Id)
            ->pluck('BranchId')
            ->map(fn ($id) => (int) $id)
            ->all();

        $directIds = UserPermission::query()->acrossBranches()
            ->where('UserId', $person->Id)
            ->pluck('PermissionId')
            ->map(fn ($id) => (int) $id)
            ->all();

        /** @var Collection<int, Permission> $permissions */
        $permissions = Permission::query()->acrossBranches()->orderBy('SortOrder')->get();

        return [
            'roles' => Role::query()->acrossBranches()->orderBy('SortOrder')->get(),
            'held' => UserRole::query()->acrossBranches()
                ->where('UserId', $person->Id)
                ->get()
                ->keyBy('RoleId'),
            'grantedBranches' => Branch::query()->acrossBranches()
                ->whereIn('BranchId', $grantedBranchIds ?: [0])
                ->ordered()
                ->get(),
            'grantedBranchIds' => $grantedBranchIds,
            'branchCount' => count($grantedBranchIds),

            /*
             * Every permission, with WHY the person holds it. `viaRole` is
             * matched against the role half alone, so a direct grant that a
             * role already carries can be seen for what it is — redundant —
             * rather than looking like the only thing keeping the door open.
             */
            'permissionRows' => $permissions
                ->map(fn (Permission $p) => [
                    'permission' => $p,
                    'direct' => in_array((int) $p->Id, $directIds, true),
                    'viaRole' => $this->permissions->anyMatches($rolePatterns, $p->Code),
                ])
                ->groupBy(fn (array $row) => $row['permission']->Module),
            'directCount' => count($directIds),
            'effective' => $permissions
                ->filter(fn (Permission $p) => $this->permissions->userHas($person, $p->Code))
                ->groupBy('Module')
                ->map(fn (Collection $rows) => $rows->pluck('Code')->all())
                ->all(),
        ];
    }
}
