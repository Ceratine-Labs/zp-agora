#!/usr/bin/env bash
#
# Deploy Agora to agora.ceratine.com.
#
#   scripts/deploy.sh              build, ship, migrate, seed, cache, restart
#   scripts/deploy.sh --no-migrate ship without touching the database
#   scripts/deploy.sh --dry-run    show what would be shipped and stop
#
# This exists because the exclude list is not something to retype from memory.
# Two of the entries below have already caused an incident:
#
#   bootstrap/cache/*   holds Laravel's discovered-package manifest. A local
#                       checkout has dev dependencies; the server runs
#                       --no-dev. Shipping the local manifest tells the server
#                       to register providers whose classes are absent, and the
#                       whole application 500s — including artisan, so the
#                       usual fixes do not run either. Took the site down on
#                       6 Sep 2026.
#   CLAUDE.md           carries the customer's database host and login, and the
#                       dispatch API's working detail. Gitignored, so it is in
#                       a working checkout but not in a clone — which is
#                       exactly how it gets rsynced onto a web server by
#                       accident. It did, on the first deploy.
#
# And --no-owner --no-group, which is a third incident and was documented in
# docs/deployment.md on 8 Sep 2026 without ever reaching this script — so the
# next deploy through here would have reproduced it. `rsync -a` implies -o -g,
# and run as root it applies the SOURCE's numeric owner: for the length of a
# deploy every file it touched is owned by a uid that does not exist on the
# box. A changed Blade cannot then be compiled — tempnam() fails, PHP falls
# back to the system temp directory, and Laravel turns the warning into a
# ViewException naming an innocent template. Three 500s across thirteen
# seconds on 8 Sep 2026, and an hour chasing a template that was fine.
#
# Read docs/deployment.md before changing anything here.
set -euo pipefail
cd "$(dirname "$0")/.."

HOST="${AGORA_DEPLOY_HOST:-root@agora.ceratine.com}"
REMOTE="${AGORA_DEPLOY_PATH:-/var/www/agora}"
MIGRATE=1
DRY=""

for arg in "$@"; do
    case "$arg" in
        --no-migrate) MIGRATE=0 ;;
        --dry-run)    DRY="--dry-run" ;;
        *) echo "unknown option: $arg" >&2; exit 2 ;;
    esac
done

# Refuse to deploy a tree that does not pass its own gates. A deploy is not
# the place to find out that pint or a procedure check fails.
if [ -z "$DRY" ]; then
    echo "==> composer check-fast"
    composer check-fast
fi

echo "==> building assets"
rm -rf public/build public/hot
npm run build >/dev/null
[ -f public/build/manifest.json ] || { echo "no build manifest — the build failed silently" >&2; exit 1; }

echo "==> shipping to ${HOST}:${REMOTE}"
rsync -az --no-owner --no-group --delete $DRY \
    --exclude '.git' \
    --exclude 'node_modules' \
    --exclude 'vendor' \
    --exclude '.env' \
    --exclude '.env.*' \
    --exclude 'public/hot' \
    --exclude '.claude' \
    --exclude 'test-results' \
    --exclude 'CLAUDE.md' \
    --exclude 'CERATINE_API_INSTRUCTIONS.md' \
    --exclude 'pm_tasks.md' \
    --exclude 'pm_response.md' \
    --exclude 'storage/logs/*' \
    --exclude 'storage/framework/cache/data/*' \
    --exclude 'storage/framework/sessions/*' \
    --exclude 'storage/framework/views/*' \
    --exclude 'bootstrap/cache/*' \
    ./ "${HOST}:${REMOTE}/"

if [ -n "$DRY" ]; then
    echo "==> dry run, nothing changed"
    exit 0
fi

echo "==> installing and rebuilding on the server"
# shellcheck disable=SC2087
ssh "$HOST" bash -s <<REMOTE_SCRIPT
set -euo pipefail
cd ${REMOTE}
export COMPOSER_ALLOW_SUPERUSER=1
composer install --no-dev --optimize-autoloader --no-interaction --quiet
chown -R www-data:www-data ${REMOTE}
chmod -R 775 storage bootstrap/cache

# Always rebuilt on the server, never shipped.
rm -f bootstrap/cache/packages.php bootstrap/cache/services.php
php artisan package:discover --quiet

if [ ${MIGRATE} -eq 1 ]; then
    echo "--- pending migrations ---"
    php artisan migrate:status | grep -i pending || echo "  none"
    php artisan migrate --force
    php artisan seed:master
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache
chown -R www-data:www-data bootstrap/cache
: > storage/logs/laravel.log
systemctl restart php8.3-fpm
REMOTE_SCRIPT

echo "==> smoke test"
FAIL=0
for path in / /login; do
    code=$(curl -s -o /dev/null -w '%{http_code}' "https://agora.ceratine.com${path}" || true)
    printf '  %-10s %s\n' "$path" "$code"
done

# Three requests, not one: the first deploy shipped a bug that only appeared on
# the CACHE HIT, so a single request looked perfect.
for _ in 1 2 3; do curl -s -o /dev/null https://agora.ceratine.com/login || true; done

LINES=$(ssh "$HOST" "wc -l < ${REMOTE}/storage/logs/laravel.log")
echo "  laravel.log ${LINES} lines"
if [ "${LINES}" -ne 0 ]; then
    echo "  ^ NOT CLEAN — read the log before calling this done" >&2
    FAIL=1
fi

exit $FAIL
