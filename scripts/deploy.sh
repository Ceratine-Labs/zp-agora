#!/usr/bin/env bash
#
# Deploy Agora to agora.ceratine.com.
#
#   scripts/deploy.sh              build, ship, migrate, seed, cache, restart
#   scripts/deploy.sh --no-migrate ship without touching the database
#   scripts/deploy.sh --dry-run    show what would be shipped and stop
#   scripts/deploy.sh --allow-dirty  ship uncommitted code too (named go-ahead only)
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
ALLOW_DIRTY=0

for arg in "$@"; do
    case "$arg" in
        --no-migrate) MIGRATE=0 ;;
        # --itemize-changes because a bare --dry-run prints nothing at all:
        # it would "show what would be shipped" by showing an empty screen.
        --dry-run)    DRY="--dry-run --itemize-changes" ;;
        # Ship uncommitted code on purpose — only on a named go-ahead for
        # exactly that work (the live-mirror + overlay deploys).
        --allow-dirty) ALLOW_DIRTY=1 ;;
        *) echo "unknown option: $arg" >&2; exit 2 ;;
    esac
done

# SHIP A COMMIT, NOT A WORKING TREE. rsync sends the tree as it is at the
# moment it runs. On 7 Oct 2026 a deploy approved for one commit (09aafc3)
# was dry-run clean, and minutes later shipped another session's unfinished
# edits to the stock recon preview procedure, which had appeared in the same
# checkout in between — and ran their migration on live. It was restored
# within minutes and nothing ran on it, but the dry-run had proved nothing.
# So a real deploy refuses while any code is modified or untracked; commit
# it, stash it, or deploy from a clean worktree of the commit
# (git worktree add). A dry run says so and carries on.
dirty=$(git status --porcelain -- Modules app bootstrap config database lang resources routes scripts public \
            composer.json composer.lock package.json package-lock.json vite.config.js 2>/dev/null || true)
if [ -n "$dirty" ]; then
    if [ -n "$DRY" ]; then
        echo "==> NOTE: uncommitted code in this checkout — a real deploy would refuse:" >&2
        echo "$dirty" >&2
    elif [ "$ALLOW_DIRTY" -ne 1 ]; then
        echo "Refusing to deploy: uncommitted code in this checkout would ship with it:" >&2
        echo "$dirty" >&2
        echo "Commit or stash it, deploy from a clean worktree, or pass --allow-dirty on a go-ahead for exactly this." >&2
        exit 1
    fi
fi

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

# Two kinds of rule below are not --exclude, because an exclude is wrong for
# both.
#
#   P /public/build/assets/*   protects the PREVIOUS build's files from
#       --delete. Every file in there is named by its content hash, and every
#       one can be fetched late by a page that is already open: app-*.js
#       imports sweetalert2, tom-select (and its CSS) and apexcharts only when
#       a confirm, a select or a chart first needs one, and the CSS pulls its
#       fonts on first use. Delete the old names and a clerk who loaded the
#       page before the deploy gets a failed dynamic import on their next
#       confirm dialog, until they reload. sweetalert2.all-*.js changes name on
#       every deploy that touches app.js, because it imports app-*.js by hash.
#       P only stops a delete, so the new build still ships in full, and
#       manifest.json sits outside assets/, so it is replaced and the server
#       only ever points at the new names. Used by hand for the 23 Sep 2026
#       15:34 deploy. Nothing prunes the old names: they accumulate, roughly
#       140 KB a deploy that changes JS and 440 KB one that also changes SCSS.
#   H /tests/  H /playwright-report/   keep Playwright's saved sign-in
#       (tests/e2e/.auth/user.json, a signed-in session cookie that .gitignore
#       already treats as a credential) and its report off the box. The
#       server runs --no-dev, so tests/ is dead weight there: PHPUnit is not
#       installed, autoload-dev is not in the autoloader, and seed:master
#       reads database/seeders and Modules/*, never tests/. H (hide) and not
#       --exclude, because an exclude also shields the server's copy from
#       --delete, and every deploy before this one already put user.json
#       there. Hide stops it being sent AND lets --delete remove the copy that
#       is already on the server, so the first deploy after this change
#       deletes tests/ from the box. That is intended.
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
    --filter 'P /public/build/assets/*' \
    --filter 'H /tests/' \
    --filter 'H /playwright-report/' \
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
