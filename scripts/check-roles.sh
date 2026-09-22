#!/usr/bin/env bash
#
# Roles are retired. This keeps them retired.
#
# On 22 September 2026 Ryan took the role layer out of Agora: every person's
# access is granted to them by name in agora.UserPermission, and
# RetireRolesSeeder copied what each role carried onto the people who held it.
# agora.Role, UserRole, RolePermission and RoleMenuItem were NOT dropped —
# v1__01g says why — so the tables are still there, still populated, and still
# joinable. That is exactly the shape of thing that grows back.
#
# The way it grows back is not a screen. It is a module permission seeder: the
# five that exist all end in "and grant it to these roles", because that was
# the right thing to write in August. Copy one as a starter — which is what the
# rulebook tells you to do — and you have written rows nothing reads, granting
# access nobody gets, in a seeder that reports success.
#
# So: no file OUTSIDE the exempt list below may name the role tables.
#
# EXEMPT, and each for a reason:
#   Database/Seeders/       the historical one-shots that built the role data.
#                           They are in agora.SeedMaster and never run again.
#                           RetireRolesSeeder reads the tables ON PURPOSE —
#                           copying out of them is its whole job.
#   Database/Migrations/    the migrations that created the tables, and the one
#                           that retired them.
#   Models/Role*.php        the model classes for tables that still exist.
#   Models/UserRole.php     same.
#   Models/User.php         carries the deprecated role() relation, kept so the
#                           flatten stays checkable against its source.
#   tests/                  fixtures name the old roles as access PROFILES,
#                           which is deliberate — the boundary a test asserts
#                           did not change, only where the grants come from.
#
# If you are here because this failed on a seeder you just wrote: grant the
# permission, then tick it for the people who need it on Setup → Users and
# access. There is no longer a way to give a permission to a group. That is the
# trade Ryan made knowingly, and it is written up in docs/rules.md.

set -uo pipefail
cd "$(dirname "$0")/.."

fail=0
say() { printf '  %s\n' "$1"; fail=1; }

# The names that mean "the role layer", as they appear in PHP and in SQL.
PATTERN='UserRole::|RolePermission::|RoleMenuItem::|agora\.UserRole|agora\.RolePermission|agora\.RoleMenuItem|\[UserRole\]|\[RolePermission\]|\[RoleMenuItem\]'

files=$(find Modules app resources -name '*.php' -o -name '*.blade.php' 2>/dev/null \
        | grep -v '/Database/Seeders/' \
        | grep -v '/Database/Migrations/' \
        | grep -v '/Models/Role.php' \
        | grep -v '/Models/RolePermission.php' \
        | grep -v '/Models/RoleMenuItem.php' \
        | grep -v '/Models/UserRole.php' \
        | grep -v '/Models/User.php' \
        | sort)

if [ -z "$files" ]; then
    echo "check-roles: nothing to check."
    exit 0
fi

# Comment lines are dropped: the services and the controller EXPLAIN that they
# no longer read agora.UserRole, and a check that failed on its own
# documentation would teach people to stop writing the documentation.
hits=$(grep -nE "$PATTERN" $files 2>/dev/null | grep -vE ':[0-9]+:[[:space:]]*(\*|//|/\*)')

if [ -n "$hits" ]; then
    while IFS= read -r hit; do
        say "${hit%%:*}:$(echo "$hit" | cut -d: -f2): reads the retired role layer. Access is granted per person in agora.UserPermission (22 Sep 2026)."
    done <<< "$hits"
fi

# A seeder is exempt from the rule above because the old ones have to be, but a
# NEW one granting to roles is the actual trap this script exists for. A seeder
# that writes RolePermission and is not one of the known historical set is
# almost certainly a module seeder copied from a starter.
KNOWN='RolePermissionSeeder|RoleSeeder|RoleLandingSeeder|RetireRolesSeeder|StockMasterPermissionSeeder|StockMasterEditWidenedSeeder|StockReconPermissionSeeder|ReconCriteriaEditPermissionSeeder|E2eFixtureSeeder'

for seeder in $(find Modules -path '*/Database/Seeders/*.php' | sort); do
    name=$(basename "$seeder" .php)

    if echo "$name" | grep -qE "^($KNOWN)$"; then
        continue
    fi

    if grep -E 'RolePermission|RoleMenuItem|UserRole' "$seeder" 2>/dev/null \
       | grep -qvE '^[[:space:]]*(\*|//|/\*)'; then
        say "${seeder}: grants to ROLES, which nothing reads any more. Grant the permission, then tick it per person on Setup → Users and access."
    fi
done

if [ "$fail" -eq 0 ]; then
    count=$(echo "$files" | wc -l)
    echo "check-roles: ${count} file(s) OK — the role layer stays retired."
fi

exit $fail
