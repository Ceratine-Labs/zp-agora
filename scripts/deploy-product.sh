#!/usr/bin/env bash
#
# Deploy the Product module (T025) to agora.ceratine.com.
#
# WRITTEN BUT NEVER RUN. The session that wrote it could not ssh to the server.
# Everything below is the documented deploy from docs/deployment.md with two
# deliberate narrowings, both explained where they happen. Read it before you
# run it; it touches a live site and a live database.
#
# BEFORE: https://agora.ceratine.com/app/master/stock returns 404.
# AFTER:  it should return 200. The script checks, and says so.
#
#   bash scripts/deploy-product.sh            # the whole thing
#   bash scripts/deploy-product.sh --dry-run  # rsync -n, no server changes
#
set -euo pipefail
cd "$(dirname "$0")/.."

HOST=root@agora.ceratine.com
REMOTE=/var/www/agora
URL=https://agora.ceratine.com/app/master/stock
DRY=${1:-}

say() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }

say "Where we are starting from"
git log -1 --format='HEAD %h %s'
printf 'uncommitted files: %s\n' "$(git status --short | wc -l)"
printf 'live now: %s -> HTTP %s\n' "$URL" \
  "$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$URL")"

# ---------------------------------------------------------------------------
# 1. Build the assets HERE, not on the box.
#
# The undefined-class audit in check-components.sh reads the BUILT css, so a
# stale build does not just ship stale styling — it makes the gate lie.
# ---------------------------------------------------------------------------
say "Building assets"
rm -rf public/build public/hot
npm run build

say "Gates"
vendor/bin/pint --test
./scripts/check-migrations.sh
./scripts/check-procs.sh
./scripts/check-components.sh
./scripts/check-blades.sh

# ---------------------------------------------------------------------------
# 2. Ship it.
#
# Every exclusion is load-bearing and so is --no-owner --no-group: rsync -a
# run as root applies the SOURCE's numeric owner, and this laptop's uid is not
# www-data, so without it every file is owned by a user the box does not have
# until the chown below repairs it — while the site is serving.
# ---------------------------------------------------------------------------
say "rsync${DRY:+ (dry run)}"
rsync -az ${DRY:+--dry-run} --no-owner --no-group --delete \
  --exclude '.git' --exclude 'node_modules' --exclude 'vendor' \
  --exclude '.env' --exclude '.env.*' --exclude 'public/hot' \
  --exclude '.claude' --exclude 'test-results' \
  --exclude 'CLAUDE.md' --exclude 'CERATINE_API_INSTRUCTIONS.md' \
  --exclude 'pm_tasks.md' --exclude 'pm_response.md' \
  --exclude 'storage/logs/*' \
  --exclude 'storage/framework/cache/data/*' \
  --exclude 'storage/framework/sessions/*' \
  --exclude 'storage/framework/views/*' \
  --exclude 'bootstrap/cache/*' \
  ./ "$HOST:$REMOTE/"

if [ -n "$DRY" ]; then
  say "Dry run — nothing on the server was touched."
  exit 0
fi

# ---------------------------------------------------------------------------
# 3. On the server.
#
# NARROWING ONE: `php artisan migrate --force` would run FIVE migrations, not
# two. Three of them are v1__14d_stockrecon_resolved_views,
# v1__14pe_stockrecon_procs and v1__22pa_reports_procs — commit a926f5e of
# 17 Sep, which fix the reports stock-count grid double-counting items carried
# under two POS families and the waste grid doubling the value as well as the
# row count. They are real fixes and they are still pending, but they are not
# this module and sweeping them up inside a stock-master deploy would be
# deploying something nobody asked for. --path deploys exactly ours.
#
#   To send them too, deliberately, run this afterwards:
#     ssh $HOST "cd $REMOTE && php artisan migrate --force"
#
# NARROWING TWO: `seed:master` would run every unrun seeder on the box. The
# four named below are this module's, and they are what makes the screen
# REACHABLE — without them there is no menu entry and nobody holds the
# permission, so a successful deploy still shows nobody anything.
# ---------------------------------------------------------------------------
say "Server: dependencies and ownership"
ssh "$HOST" "cd $REMOTE \
  && export COMPOSER_ALLOW_SUPERUSER=1 \
  && composer install --no-dev --optimize-autoloader --no-interaction \
  && chown -R www-data:www-data $REMOTE \
  && chmod -R 775 storage bootstrap/cache"

say "Server: this module's migrations only"
ssh "$HOST" "cd $REMOTE \
  && php artisan migrate --path=Modules/Product/Database/Migrations/v1__05a_product_critical.php --force \
  && php artisan migrate --path=Modules/Product/Database/Migrations/v1__05pa_product_procs.php --force \
  && echo '--- still pending, on purpose ---' \
  && php artisan migrate:status | grep -i pending || true"

say "Server: seeders, through the ledger"
# WAS: four `php artisan db:seed --class='Modules\\Product\\...'` calls, which
# died with "Cannot declare class ... because the name is already in use" —
# the escaping survives bash and ssh, but Laravel then both autoloads the
# class AND requires its file, declaring it twice.
#
# seed:master is this project's own command and exists for exactly this: each
# seeder runs once and is recorded in agora.SeedMaster, so anything already in
# the ledger is skipped without being invoked. Verified before switching to
# it — the live ledger holds 16 classes, and the only four in the repo that
# are not in it are this module's own.
ssh "$HOST" "cd $REMOTE && php artisan seed:master"

say "Server: caches and php-fpm"
ssh "$HOST" "cd $REMOTE \
  && php artisan config:cache && php artisan route:cache && php artisan view:cache \
  && systemctl restart php8.3-fpm"

# ---------------------------------------------------------------------------
# 4. Build the last-counted rollup, or every line reads "never counted".
# ---------------------------------------------------------------------------
say "Server: the last-counted rollup"
ssh "$HOST" "cd $REMOTE && php artisan agora:refresh-count-stats"

# ---------------------------------------------------------------------------
# 5. Say whether it worked, in the only terms that count.
# ---------------------------------------------------------------------------
say "Is it actually there?"
for path in /login /app/master/stock /app/master/critical; do
  printf '%-24s -> HTTP %s\n' "$path" \
    "$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 "https://agora.ceratine.com$path")"
done
echo
echo "A 302 on the /app paths is correct when you are signed out — it is the"
echo "redirect to /login. A 404 means the route is not there and the deploy"
echo "did not do what it said."
