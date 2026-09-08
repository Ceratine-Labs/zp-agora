<?php

namespace Modules\Core\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Models\Branch;
use Modules\Core\Models\Permission;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Models\UserBranch;
use Modules\Core\Models\UserPermission;
use Modules\Core\Models\UserRole;
use RuntimeException;
use Throwable;

/**
 * Who someone is, what they may do, and which sites they may see.
 *
 * EVERY SET IS REPLACED, NEVER DIFFED. The screen submits the whole set it
 * owns, and this writes exactly that. A grant REMOVED is the change that
 * matters, and a diff that only ever adds is the classic way access
 * accumulates until everybody is an administrator.
 *
 * WHY PHP AND NOT A PROCEDURE. The rulebook puts business logic in
 * `agora.usp_*` and it is right to; this is master CRUD over four grant
 * tables with no arithmetic and no money in it, and the role writer this grew
 * out of was already PHP. Splitting one screen's four Save buttons across two
 * languages would be worse than either choice on its own. If a grant ever
 * needs an audit trail the customer can query, that is the moment it becomes
 * a procedure — and the moment agora.UserActivity grows a row per change.
 *
 * THE CACHE IS DROPPED ON EVERY WRITE. PermissionService caches forever and
 * clears on a grant change rather than on a TTL, because a stale permission
 * set is somebody still seeing a screen after their access was taken away.
 */
class UserAccessService
{
    public function __construct(
        private PermissionService $permissions,
        private PasswordResetService $resets,
    ) {}

    /**
     * The roles this person holds, and which one they land from.
     *
     * `agora.User.RoleId` is the denormalised pointer `User::landingRoute()`
     * reads; it follows the primary grant here so the two cannot disagree.
     *
     * @param  array<int, int>  $roleIds
     * @return array{roles: array<int, int>, primary: int|null}
     */
    public function setRoles(User $person, array $roleIds, ?int $primaryId, ?User $actor = null): array
    {
        $valid = $this->existingIds(Role::query()->acrossBranches(), $roleIds);
        $primary = in_array((int) $primaryId, $valid, true) ? (int) $primaryId : ($valid[0] ?? null);
        $branchId = $this->groupBranchId();

        try {
            DB::connection($this->connection())->transaction(function () use ($person, $valid, $primary, $branchId, $actor) {
                UserRole::query()->acrossBranches()->where('UserId', $person->Id)->delete();

                foreach ($valid as $roleId) {
                    UserRole::query()->acrossBranches()->create([
                        'BranchId' => $branchId,
                        'UserId' => $person->Id,
                        'RoleId' => $roleId,
                        'IsPrimary' => $roleId === $primary,
                        'CreatedAt' => now(),
                    ]);
                }

                $person->forceFill(['RoleId' => $primary])->saveQuietly();

                $this->permissions->forget($person);
                $this->refuseSelfLockout($person, $actor);
            });
        } finally {
            // In the finally rather than after the commit: the check above
            // reads the written-but-uncommitted rows and caches them, and a
            // rollback must not leave that reading behind.
            $this->permissions->forget($person);
        }

        return ['roles' => $valid, 'primary' => $primary];
    }

    /**
     * The permissions granted to this person directly, beside their roles.
     *
     * Additive only — there is no deny row, and v1__01d says why.
     *
     * @param  array<int, int>  $permissionIds
     * @return array<int, int>
     */
    public function setPermissions(User $person, array $permissionIds, ?User $actor = null): array
    {
        $valid = $this->existingIds(Permission::query()->acrossBranches(), $permissionIds);
        $branchId = $this->groupBranchId();

        try {
            DB::connection($this->connection())->transaction(function () use ($person, $valid, $branchId, $actor) {
                UserPermission::query()->acrossBranches()->where('UserId', $person->Id)->delete();

                foreach ($valid as $permissionId) {
                    UserPermission::query()->acrossBranches()->create([
                        'BranchId' => $branchId,
                        'UserId' => $person->Id,
                        'PermissionId' => $permissionId,
                        'CreatedAt' => now(),
                    ]);
                }

                $this->permissions->forget($person);
                $this->refuseSelfLockout($person, $actor);
            });
        } finally {
            $this->permissions->forget($person);
        }

        return $valid;
    }

    /**
     * The sites this person may see.
     *
     * AN EMPTY SET MEANS EVERY BRANCH, not none. Head office users are granted
     * nothing individually — granting them all 31 rows would have to be
     * maintained as sites open — so absence of rows is the grant, and both
     * `BranchContext::maySee()` and `BranchScope` read it that way. The screen
     * says so in words, because it is the one rule here that is not obvious.
     *
     * Saving grants also settles the two derived facts the rest of the
     * application reads, so the nav cannot disagree with this screen:
     *
     *   UserType      one grant is a site, more than one is head office, none
     *                 is undecided. That is vw_LegacyUser's own rule
     *                 (v1__01c), read off SS_UserBranches for all 88 people —
     *                 keeping it here means the derivation does not fork the
     *                 day somebody is granted a branch by hand.
     *
     *   HomeBranchId  a person granted exactly one site works AT that site, so
     *                 it is set to it. A home branch that is no longer granted
     *                 is cleared rather than left pointing at a site they can
     *                 no longer open — ResolveBranchContext puts the whole
     *                 request in the branch workspace off this column, so a
     *                 stale value pins somebody to a site the scope bar will
     *                 not offer.
     *
     * @param  array<int, int>  $branchIds
     * @return array<int, int>
     */
    public function setBranches(User $person, array $branchIds): array
    {
        $valid = $this->existingIds(
            Branch::query()->acrossBranches()->withoutTrashed(),
            $branchIds,
            'BranchId'
        );

        DB::connection($this->connection())->transaction(function () use ($person, $valid) {
            UserBranch::query()->acrossBranches()->where('UserId', $person->Id)->delete();

            foreach ($valid as $branchId) {
                // The row's own BranchId IS the branch being granted, which is
                // why UserBranch is always read with acrossBranches(): the
                // global scope would otherwise filter a user's grants by the
                // grants it is trying to read.
                UserBranch::query()->acrossBranches()->create([
                    'BranchId' => $branchId,
                    'UserId' => $person->Id,
                ]);
            }

            $home = $person->HomeBranchId === null ? null : (int) $person->HomeBranchId;

            $home = match (true) {
                // Granted nothing is not "works everywhere and also at site 3":
                // UserType goes NULL here, and a home site left behind would
                // still force the branch workspace through
                // ResolveBranchContext — the grid would then show a person with
                // no type who is nonetheless pinned to a site. This card owns
                // both derived facts, so it settles both.
                $valid === [] => null,
                count($valid) === 1 => $valid[0],
                $home !== null && ! in_array($home, $valid, true) => null,
                default => $home,
            };

            $person->forceFill([
                'UserType' => match (true) {
                    $valid === [] => null,
                    count($valid) === 1 => 'branch',
                    default => 'ho',
                },
                'HomeBranchId' => $home,
            ])->saveQuietly();
        });

        return $valid;
    }

    /**
     * The person themselves: their name, how they are reached, and whether
     * they may sign in at all.
     *
     * The password is deliberately absent. An administrator setting somebody
     * else's password by hand is the thing `User::UNUSABLE_PASSWORD` exists to
     * make impossible — "reset it and let them choose" is the only path, and it
     * is what `makePasswordUnusable()` is for.
     *
     * @param  array{UserName?: string, EmailAddress?: string|null, UserCode?: string|null, IsActive?: bool, IsLocked?: bool, HomeBranchId?: int|null}  $fields
     */
    public function updateDetails(User $person, array $fields, ?User $actor = null): void
    {
        if ($actor !== null && (int) $actor->Id === (int) $person->Id) {
            // Deactivating or locking yourself is the one change nobody can
            // undo from the screen they made it on.
            if (array_key_exists('IsActive', $fields) && ! $fields['IsActive']) {
                throw new RuntimeException('You cannot deactivate your own account. Ask another administrator.');
            }

            if (array_key_exists('IsLocked', $fields) && $fields['IsLocked']) {
                throw new RuntimeException('You cannot lock your own account. Ask another administrator.');
            }
        }

        // A home branch the person is not granted would pin them, through
        // ResolveBranchContext, to a site the scope bar refuses to offer.
        if (array_key_exists('HomeBranchId', $fields) && $fields['HomeBranchId'] !== null) {
            $allowed = $person->allowedBranchIds();

            if ($allowed !== [] && ! in_array((int) $fields['HomeBranchId'], $allowed, true)) {
                throw new RuntimeException('That site is not one this person is granted. Grant it first, then set it as their home.');
            }
        }

        $person->forceFill($fields)->save();
    }

    /** Put this account beyond signing in until the person resets it themselves. */
    public function resetPassword(User $person): void
    {
        $person->makePasswordUnusable()->save();
    }

    /**
     * Mail this person a link to set their own password.
     *
     * Goes through the SAME channel as "Forgot your password?" rather than a
     * second admin-only one: one token table, one lifetime, one consume path,
     * and a link that behaves identically however it was asked for.
     *
     * The one thing that differs is candour. `PasswordResetService::request()`
     * is deliberately mute about whether the address matched, because on a
     * public form that answer is a staff directory with a submit button. Here
     * the caller is an administrator looking at the person's row — they
     * already know the account exists — so a missing address is reported
     * plainly instead of being swallowed as a lie.
     */
    public function sendSetPasswordLink(User $person): void
    {
        $email = trim((string) $person->EmailAddress);

        if ($email === '') {
            throw new RuntimeException(
                'This person has no email address, so there is nowhere to send a link. '
                .'Give them one above, or set a temporary password instead.'
            );
        }

        try {
            // reportFailures: the public form has to stay mute about delivery,
            // an administrator staring at the row does not. Telling them "sent"
            // when the SMTP server refused the login sends them off to wait for
            // a mail that is never coming.
            $this->resets->request($email, null, reportFailures: true);
        } catch (Throwable $e) {
            // Deliberately NOT $e->getMessage(): a transport error names the
            // SMTP account and sometimes the server's own reply, and neither
            // belongs on a screen. The detail is in storage/logs/laravel.log,
            // where somebody fixing it will look anyway.
            throw new RuntimeException(
                'The mail server refused the message, so nothing was sent. The link itself is valid, and '
                .'the reason is in the application log. Set a temporary password instead, or fix the mail settings.'
            );
        }
    }

    /**
     * Give this person a temporary password, and hand it back ONCE.
     *
     * Why this exists when I argued against it. An administrator who types a
     * colleague's password knows a credential that colleague will keep using,
     * and `User::UNUSABLE_PASSWORD` exists to make that impossible. But the
     * 88 people migrated out of SS_Users have no working password and the
     * link is the only other way in — so on a system whose mail is not yet
     * sending, refusing this would mean nobody can be let in at all.
     *
     * The compromise is that the administrator never CHOOSES it. It is
     * generated, returned once for reading down a phone, never written to a
     * log or a flash message that outlives the redirect, and it arrives with
     * MustChangePassword set — so RequirePasswordChange holds the person on
     * the change-password screen until they replace it with one only they
     * know. What the administrator briefly knows expires at the person's
     * first sign-in.
     *
     * @return string the plain password, which is not stored anywhere else
     */
    public function setTemporaryPassword(User $person): string
    {
        // Symbols off: this gets read down a phone or copied out of a chat,
        // and a policy-satisfying password nobody can transcribe is a support
        // call. Length carries the strength instead, and it lives minutes.
        $plain = Str::password(20, symbols: false);

        $person->forceFill([
            'PasswordHash' => $plain,
            'MustChangePassword' => true,
            'PasswordChangedAt' => now(),
        ])->save();

        return $plain;
    }

    /**
     * Refuse a change that would leave the person who made it unable to make
     * the next one.
     *
     * Not a nicety: `setup.users.edit` is the only route to this screen, and an
     * administrator who removes their own last grant of it has locked the
     * business out of its own user administration with no way back except a
     * seeder and a deploy. Checked INSIDE the transaction, after the write,
     * because "what would they hold afterwards" is a question only the written
     * state can answer — the wildcard expansion runs over the rows, not over
     * the payload — and throwing there is what rolls the change back.
     */
    private function refuseSelfLockout(User $person, ?User $actor): void
    {
        if ($actor === null || (int) $actor->Id !== (int) $person->Id) {
            return;
        }

        if ($this->permissions->userHas($person->fresh() ?? $person, 'setup.users.edit')) {
            return;
        }

        throw new RuntimeException(
            'That change would remove your own access to this screen. Another administrator has to make it.'
        );
    }

    /**
     * The ids from `$ids` that actually exist, de-duplicated and in the order
     * the database holds them.
     *
     * A posted id that matches no row is dropped rather than refused: the form
     * is a checkbox list rendered from the same table, so an unknown id is a
     * stale tab or a hand-edited payload, and neither deserves a 500.
     *
     * @param  Builder<covariant \App\Models\BaseModel>  $query
     * @param  array<int, mixed>  $ids
     * @return array<int, int>
     */
    private function existingIds($query, array $ids, string $column = 'Id'): array
    {
        $wanted = array_values(array_unique(array_map('intval', array_filter($ids, 'is_numeric'))));

        if ($wanted === []) {
            return [];
        }

        return $query->whereIn($column, $wanted)
            ->pluck($column)
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function groupBranchId(): int
    {
        return (int) config('agora.group_branch_id');
    }

    private function connection(): string
    {
        return (string) config('agora.connections.app');
    }
}
