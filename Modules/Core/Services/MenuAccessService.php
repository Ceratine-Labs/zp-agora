<?php

namespace Modules\Core\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\MenuItem;
use Modules\Core\Models\Role;
use Modules\Core\Models\RoleMenuItem;
use Modules\Core\Models\User;
use Modules\Core\Models\UserMenuItem;

/**
 * Which entries of the menu a person may see, and reach.
 *
 * THE TICK IS THE ACCESS, not a decoration on it. Hiding a link while the URL
 * behind it still answers is the kind of control that looks like access
 * control until somebody types the address — so the same answer that filters
 * the mega panel also stands in front of the route, through
 * EnforceMenuAccess.
 *
 * AN EMPTY SET IS THE WHOLE MENU. Nobody holds a grant on the day this ships
 * and reading a grant-list literally would blank the navigation for all 88
 * people at once; agora.UserBranch already settles this question the same way
 * and the screen says so in words. The moment one entry is ticked for a person
 * — or for any role they hold — the ticks become the whole of what they see.
 *
 * TWO THINGS CAN HIDE AN ENTRY and they are not the same thing:
 *
 *   the tick      this service. The customer's own decision about who sees
 *                 what, made on the Users and Roles screens.
 *   PermissionCode  the software's. An item that names a permission the person
 *                 does not hold is hidden whether or not it is ticked, because
 *                 the route behind it already refuses them through `can:` and
 *                 a link that 403s is worse than no link. Only 4 items set one
 *                 today; every item can.
 *
 * THE SET IS CLOSED UPWARDS. Ticking "Pump readings" without also ticking the
 * "Capture" heading it hangs under would otherwise hide the entry by hiding
 * its column. A heading is therefore kept when anything under it is kept, and
 * dropped when nothing is — so a column never renders empty.
 */
class MenuAccessService
{
    private const CACHE_PREFIX = 'agora.menu.grants.user.';

    public function __construct(private PermissionService $permissions) {}

    /** @var array<int, array<int, int>> */
    private array $memo = [];

    /**
     * Every menu item id this person is ticked for, through their roles and
     * directly. An EMPTY array means no restriction — the whole menu.
     *
     * @return array<int, int>
     */
    public function grantedItemIds(User $user): array
    {
        $id = (int) $user->getKey();

        if (isset($this->memo[$id])) {
            return $this->memo[$id];
        }

        /** @var array<int, int> $ids */
        $ids = Cache::rememberForever(
            self::CACHE_PREFIX.$id,
            fn (): array => array_values(array_unique(array_merge(
                $this->roleItemIdsFor($user),
                $this->userItemIdsFor($user),
            )))
        );

        return $this->memo[$id] = $ids;
    }

    /**
     * The half that comes from the roles this person holds.
     *
     * Public and uncached, like PermissionService::rolePatternsFor, so the
     * edit screen can show whether a personal tick is doing anything or is
     * already covered by a role.
     *
     * @return array<int, int>
     */
    public function roleItemIdsFor(User $user): array
    {
        $schema = config('agora.schema');

        return $this->ids(DB::connection(config('agora.connections.app'))->select("
            SELECT DISTINCT rmi.[MenuItemId]
            FROM [{$schema}].[UserRole] ur
            JOIN [{$schema}].[RoleMenuItem] rmi ON rmi.[RoleId] = ur.[RoleId]
            WHERE ur.[UserId] = ?
        ", [(int) $user->getKey()]));
    }

    /**
     * The half ticked against this person by name.
     *
     * @return array<int, int>
     */
    public function userItemIdsFor(User $user): array
    {
        return UserMenuItem::query()->acrossBranches()
            ->where('UserId', (int) $user->getKey())
            ->pluck('MenuItemId')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * What one role is ticked for.
     *
     * @return array<int, int>
     */
    public function roleItemIds(Role $role): array
    {
        return RoleMenuItem::query()->acrossBranches()
            ->where('RoleId', (int) $role->getKey())
            ->pluck('MenuItemId')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** Is this person restricted at all, or do they see the whole menu? */
    public function isRestricted(User $user): bool
    {
        return $this->grantedItemIds($user) !== [];
    }

    /**
     * Does the tick allow this item? Says nothing about its PermissionCode —
     * `mayView()` is the answer that considers both.
     */
    public function isTicked(User $user, int $itemId): bool
    {
        $granted = $this->grantedItemIds($user);

        return $granted === [] || in_array($itemId, $granted, true);
    }

    /**
     * May this person see this entry — the tick AND the permission it names.
     *
     * Used by the renderer for one node at a time and by EnforceMenuAccess for
     * the item behind a route, so the panel and the URL cannot disagree.
     */
    public function mayView(User $user, MenuItem $item): bool
    {
        return $this->permits($user, $item) && $this->isTicked($user, (int) $item->Id);
    }

    /** The item's own PermissionCode, if it names one. */
    public function permits(User $user, MenuItem $item): bool
    {
        $code = trim((string) $item->PermissionCode);

        return $code === '' || $this->permissions->userHas($user, $code);
    }

    /**
     * Filter a flat set of items to what this person may see, closed upwards.
     *
     * Takes the whole workspace's items rather than one node, because the
     * upward closure needs the parent chain: an entry is kept when it is
     * allowed, and a heading is kept when anything under it is.
     *
     * @param  iterable<int, MenuItem>  $items
     * @return array<int, bool> keyed by item id
     */
    public function keepMap(User $user, iterable $items): array
    {
        /** @var array<int, MenuItem> $byId */
        $byId = [];
        /** @var array<int, array<int, int>> $children */
        $children = [];

        foreach ($items as $item) {
            $id = (int) $item->Id;
            $byId[$id] = $item;
            $children[(int) ($item->ParentId ?? 0)][] = $id;
        }

        $keep = [];

        // Depth-first, so a node is decided after everything under it.
        $decide = function (int $id) use (&$decide, &$keep, $byId, $children, $user): bool {
            if (isset($keep[$id])) {
                return $keep[$id];
            }

            $item = $byId[$id];
            $kids = $children[$id] ?? [];
            $anyChild = false;

            foreach ($kids as $childId) {
                $anyChild = $decide($childId) || $anyChild;
            }

            $allowed = $this->mayView($user, $item);

            // A heading exists to hold things. Kept when something under it is
            // kept; dropped when nothing is, so no column renders empty. A
            // leaf — a link, or a placeholder for a screen not built yet —
            // stands on its own answer.
            return $keep[$id] = $kids === []
                ? $allowed
                : ($anyChild || ($allowed && $item->isLink()));
        };

        foreach (array_keys($byId) as $id) {
            $decide($id);
        }

        return $keep;
    }

    /**
     * May this person reach the screen behind a named route?
     *
     * The same answer EnforceMenuAccess gives, kept here so the guard against
     * an administrator ticking themselves off their own screen asks exactly
     * what the middleware will ask a moment later.
     *
     * A route no menu entry names is reachable — nobody ticked anything about
     * it, and `can:` is what guards it.
     */
    public function canReachRoute(User $user, string $routeName): bool
    {
        $items = $this->itemsForRoute($routeName);

        if ($items === []) {
            return true;
        }

        foreach ($items as $item) {
            if ($this->mayView($user, $item)) {
                return true;
            }
        }

        // Last, not first: this is the only branch that reads agora.Role, and
        // asking it up front would put that read on EVERY request instead of
        // on the handful that are about to be refused.
        return in_array($routeName, $this->alwaysAllowedRoutes(), true);
    }

    /**
     * The menu entries pointing at one route.
     *
     * ONE ROUTE CAN BE SEVERAL ENTRIES — `app.dashboard` is reached from "Day
     * close status" at head office and "My day" at a site — and holding either
     * is enough, because the person demonstrably has a way to that screen.
     *
     * The route-to-id map is cached forever and dropped whenever the menu is
     * written, because it only changes when a seeder runs, which is a deploy.
     *
     * @return array<int, MenuItem>
     */
    public function itemsForRoute(string $routeName): array
    {
        /** @var array<int, int> $ids */
        $ids = Cache::rememberForever(
            'agora.menu.route.'.$routeName,
            fn (): array => MenuItem::query()->acrossBranches()
                ->where('RouteName', $routeName)
                ->where('IsActive', true)
                ->pluck('Id')
                ->map(fn ($id) => (int) $id)
                ->all()
        );

        if ($ids === []) {
            return [];
        }

        return MenuItem::query()->acrossBranches()->whereIn('Id', $ids)->get()->all();
    }

    /**
     * The routes a signed-in person may always reach, whatever the ticks say.
     *
     * Somebody who may sign in has to land somewhere: agora.Role.LandingRoute
     * is where they are sent and the dashboard is where everything else falls
     * back to. Enforcing the ticks over those would answer a correct sign-in
     * with a 403 and no way forward, which is a lockout rather than a
     * restriction. They are hidden from the menu like anything else — this
     * only stops the door being locked from the inside.
     *
     * @return array<int, string>
     */
    public function alwaysAllowedRoutes(): array
    {
        /** @var array<int, string> $landings */
        $landings = Cache::rememberForever('agora.menu.landing.routes', fn (): array => array_values(array_filter(
            Role::query()->acrossBranches()->pluck('LandingRoute')->map(fn ($r) => (string) $r)->all(),
            fn (string $r): bool => $r !== ''
        )));

        return array_values(array_unique(array_merge(['app.dashboard'], $landings)));
    }

    /**
     * Tick a set of entries against one person. REPLACES the set, like every
     * other card on the edit screen — a tick taken away is the change that
     * matters.
     *
     * @param  array<int, int>  $itemIds
     * @return array<int, int>
     */
    public function setForUser(User $person, array $itemIds): array
    {
        $valid = $this->existingItemIds($itemIds);
        $branchId = (int) config('agora.group_branch_id');

        DB::connection(config('agora.connections.app'))->transaction(function () use ($person, $valid, $branchId) {
            UserMenuItem::query()->acrossBranches()->where('UserId', $person->Id)->delete();

            foreach ($valid as $itemId) {
                UserMenuItem::query()->acrossBranches()->create([
                    'BranchId' => $branchId,
                    'UserId' => (int) $person->Id,
                    'MenuItemId' => $itemId,
                    'CreatedAt' => now(),
                ]);
            }
        });

        $this->forget($person);

        return $valid;
    }

    /**
     * The same for a role, which is where this is normally set.
     *
     * Every person holding the role is dropped from the cache, because a role
     * changing under somebody is exactly the staleness that leaves them
     * looking at a menu they were just taken off.
     *
     * @param  array<int, int>  $itemIds
     * @return array<int, int>
     */
    public function setForRole(Role $role, array $itemIds): array
    {
        $valid = $this->existingItemIds($itemIds);
        $branchId = (int) config('agora.group_branch_id');

        DB::connection(config('agora.connections.app'))->transaction(function () use ($role, $valid, $branchId) {
            RoleMenuItem::query()->acrossBranches()->where('RoleId', $role->Id)->delete();

            foreach ($valid as $itemId) {
                RoleMenuItem::query()->acrossBranches()->create([
                    'BranchId' => $branchId,
                    'RoleId' => (int) $role->Id,
                    'MenuItemId' => $itemId,
                    'CreatedAt' => now(),
                ]);
            }
        });

        $this->forgetRoleHolders($role);

        return $valid;
    }

    /** Drop one person's cached set — call it whenever their ticks change. */
    public function forget(User|int $user): void
    {
        $id = $user instanceof User ? (int) $user->getKey() : $user;

        unset($this->memo[$id]);
        Cache::forget(self::CACHE_PREFIX.$id);
    }

    /** Drop the cached set of everybody holding this role. */
    public function forgetRoleHolders(Role $role): void
    {
        $schema = config('agora.schema');

        $ids = DB::connection(config('agora.connections.app'))->select(
            "SELECT DISTINCT [UserId] FROM [{$schema}].[UserRole] WHERE [RoleId] = ?",
            [(int) $role->getKey()]
        );

        foreach ($ids as $row) {
            $this->forget((int) $row->UserId);
        }
    }

    /**
     * Only ids that are real menu entries, in the order they were given.
     *
     * @param  array<int, int|string>  $itemIds
     * @return array<int, int>
     */
    private function existingItemIds(array $itemIds): array
    {
        $wanted = array_values(array_unique(array_map('intval', $itemIds)));

        if ($wanted === []) {
            return [];
        }

        $exists = MenuItem::query()->acrossBranches()
            ->whereIn('Id', $wanted)
            ->pluck('Id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_filter($wanted, fn (int $id): bool => in_array($id, $exists, true)));
    }

    /**
     * @param  array<int, object>  $rows
     * @return array<int, int>
     */
    private function ids(array $rows): array
    {
        return array_values(array_map(
            static fn (object $r): int => (int) $r->MenuItemId,
            $rows
        ));
    }
}
