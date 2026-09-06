<?php

namespace Modules\Core\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;

/**
 * What a person may do.
 *
 * THE WILDCARD IS EXPANDED AT THE GRANT, NOT AT THE CHECK. A role is granted
 * `cash.*.view` once; the service turns a person's grants into a set of
 * patterns and matches the asked-for code against them. The alternative —
 * storing every concrete permission a wildcard implies — has to be re-run every
 * time a module adds a screen, and silently under-grants until somebody
 * remembers. Matching at the check costs a few string comparisons on a set that
 * is never large.
 *
 * A pattern is three dot-separated segments and `*` matches one whole segment:
 *
 *   *.*.*            everything (Admin)
 *   cash.*.*         everything in the cash module
 *   *.*.view         read anything (the Auditor's first half)
 *   audit.*.*        anything in audit (the Auditor's second half)
 *   cash.dropsafe.*  every action on one resource
 *
 * `*` alone is accepted as shorthand for `*.*.*` because it is what people type.
 *
 * THE CACHE IS PER USER AND CLEARED ON A GRANT CHANGE, not on a TTL. A stale
 * permission set is a person seeing a screen after their access was removed,
 * which is the one kind of staleness that is not acceptable here.
 */
class PermissionService
{
    private const CACHE_PREFIX = 'agora.permissions.user.';

    /** @var array<int, array<int, string>> */
    private array $memo = [];

    /** Every pattern this user holds, through every role granted to them. */
    /** @return array<int, string> */
    public function patternsFor(User $user): array
    {
        $id = (int) $user->getKey();

        if (isset($this->memo[$id])) {
            return $this->memo[$id];
        }

        $schema = config('agora.schema');

        /** @var array<int, string> $patterns */
        $patterns = Cache::rememberForever(
            self::CACHE_PREFIX.$id,
            function () use ($id, $schema): array {
                $rows = DB::connection(config('agora.connections.app'))->select("
                    SELECT DISTINCT p.[Code]
                    FROM [{$schema}].[UserRole] ur
                    JOIN [{$schema}].[RolePermission] rp ON rp.[RoleId] = ur.[RoleId]
                    JOIN [{$schema}].[Permission] p ON p.[Id] = rp.[PermissionId]
                    WHERE ur.[UserId] = ?
                ", [$id]);

                return array_values(array_map(
                    static fn (object $r): string => strtolower((string) $r->Code),
                    $rows
                ));
            }
        );

        return $this->memo[$id] = $patterns;
    }

    /** Does this user hold the given permission? */
    public function userHas(User $user, string $code): bool
    {
        $code = strtolower(trim($code));

        foreach ($this->patternsFor($user) as $pattern) {
            if ($this->matches($pattern, $code)) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<int, string>  $codes */
    public function userHasAny(User $user, array $codes): bool
    {
        foreach ($codes as $code) {
            if ($this->userHas($user, $code)) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<int, string>  $codes */
    public function userHasAll(User $user, array $codes): bool
    {
        foreach ($codes as $code) {
            if (! $this->userHas($user, $code)) {
                return false;
            }
        }

        return $codes !== [];
    }

    /**
     * Does one granted pattern cover one asked-for code?
     *
     * Both are normalised to three segments first, so `cash.*` and `cash.*.*`
     * mean the same thing and a two-part slug written by mistake still behaves
     * the way its author expected rather than never matching.
     */
    public function matches(string $pattern, string $code): bool
    {
        $p = $this->segments($pattern);
        $c = $this->segments($code);

        foreach ([0, 1, 2] as $i) {
            if ($p[$i] !== '*' && $p[$i] !== $c[$i]) {
                return false;
            }
        }

        return true;
    }

    /** Drop a user's cached set — call it whenever their grants change. */
    public function forget(User|int $user): void
    {
        $id = $user instanceof User ? (int) $user->getKey() : $user;

        unset($this->memo[$id]);
        Cache::forget(self::CACHE_PREFIX.$id);
    }

    /** Drop every cached set. For a role's permissions changing under people. */
    public function forgetAll(): void
    {
        $this->memo = [];

        $schema = config('agora.schema');
        $ids = DB::connection(config('agora.connections.app'))
            ->select("SELECT DISTINCT [UserId] FROM [{$schema}].[UserRole]");

        foreach ($ids as $row) {
            Cache::forget(self::CACHE_PREFIX.(int) $row->UserId);
        }
    }

    /**
     * Three lower-case segments, whatever arrived.
     *
     * `*` becomes `*.*.*`; `cash.*` becomes `cash.*.*`. Anything longer is
     * truncated rather than rejected — a slug is a routing decision, and
     * throwing here would turn a typo in a blade `@can` into a 500 on a page
     * that would otherwise simply deny.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function segments(string $value): array
    {
        $parts = explode('.', strtolower(trim($value)));

        return [
            $parts[0] ?? '*',
            $parts[1] ?? '*',
            $parts[2] ?? '*',
        ];
    }
}
