#!/usr/bin/env bash
# Customer-managed roles are supported (Ryan, 23 Oct 2026). Keep access reads
# centralised and prevent module seeders from granting to historical roles.
# Pass file paths for a focused gate; no arguments checks the full application.
set -uo pipefail
cd "$(dirname "$0")/.."

fail=0
say() { printf '  %s\n' "$1"; fail=1; }

# The names that mean "the role layer", as they appear in PHP and in SQL.
PATTERN='UserRole::|RolePermission::|RoleMenuItem::|agora\.UserRole|agora\.RolePermission|agora\.RoleMenuItem|\[UserRole\]|\[RolePermission\]|\[RoleMenuItem\]'

if [ "$#" -gt 0 ]; then
    candidates=$(printf '%s\n' "$@")
else
    candidates=$(find Modules app resources -name '*.php' -o -name '*.blade.php' 2>/dev/null | sort)
fi
files=$(printf '%s\n' "$candidates" \
    | grep -v '/Database/Seeders/' | grep -v '/Database/Migrations/' \
    | grep -vE '/Models/(Role|RolePermission|RoleMenuItem|UserRole|User)\.php$' \
    | grep -vE '/(Services/RoleAccessService|Http/Controllers/RoleAdminController)\.php$')

# Comments may describe historical tables. Only executable references count.
hits=''
if [ -n "$files" ]; then
    hits=$(grep -nE "$PATTERN" $files 2>/dev/null | grep -vE ':[0-9]+:[[:space:]]*(\*|//|/\*)')
fi

if [ -n "$hits" ]; then
    while IFS= read -r hit; do
        say "${hit%%:*}:$(echo "$hit" | cut -d: -f2): reads roles outside the managed-role service. Use RoleAccessService to exclude historical grants."
    done <<< "$hits"
fi

# A seeder is exempt from the rule above because the old ones have to be, but a
# NEW one granting to roles is the actual trap this script exists for. A seeder
# that writes RolePermission and is not one of the known historical set is
# almost certainly a module seeder copied from a starter.
KNOWN='RolePermissionSeeder|RoleSeeder|RoleLandingSeeder|RetireRolesSeeder|StockMasterPermissionSeeder|StockMasterEditWidenedSeeder|StockReconPermissionSeeder|ReconCriteriaEditPermissionSeeder|E2eFixtureSeeder'

for seeder in $(printf '%s\n' "$candidates" | grep '/Database/Seeders/'); do
    name=$(basename "$seeder" .php)

    if grep -qE "^($KNOWN)$" <<< "$name"; then
        continue
    fi

    if grep -E 'RolePermission|RoleMenuItem|UserRole' "$seeder" 2>/dev/null \
       | grep -qvE '^[[:space:]]*(\*|//|/\*)'; then
        say "${seeder}: grants to role tables. Register catalogue permissions only; customers assign managed-role grants."
    fi
done

if [ "$fail" -eq 0 ]; then
    count=$(echo "$files" | wc -l)
    echo "check-roles: ${count} file(s) OK — managed-role access stays centralised."
fi

exit $fail
