#!/usr/bin/env bash
#
# Finish the Product deploy: seeders, caches, rollup, and prove the URL.
#
# deploy-product.sh got as far as the migrations and then failed on its own
# seeder step: `php artisan db:seed --class='Modules\\Product\\...'` died with
# "Cannot declare class ... because the name is already in use". The escaping
# survives bash and ssh but Laravel then both autoloads the class AND requires
# its file, so it is declared twice.
#
# The fix is to stop hand-rolling it. `seed:master` is this project's own
# command and exists for exactly this: it runs each seeder once and records it
# in agora.SeedMaster, so a class already in the ledger is skipped without
# being invoked. Checked before running — the live ledger holds 16 classes and
# the only four in the repo that are not in it are this module's.
#
set -euo pipefail
cd "$(dirname "$0")/.."

HOST=root@agora.ceratine.com
REMOTE=/var/www/agora

say() { printf '\n\033[1m==> %s\033[0m\n' "$1"; }

say "Seeders, through the ledger"
ssh "$HOST" "cd $REMOTE && php artisan seed:master --force"

say "Caches and php-fpm"
ssh "$HOST" "cd $REMOTE \
  && php artisan config:cache && php artisan route:cache && php artisan view:cache \
  && systemctl restart php8.3-fpm"

# Without this every line on the listing reads "never counted".
say "The last-counted rollup"
ssh "$HOST" "cd $REMOTE && php artisan agora:refresh-count-stats"

say "Is it actually there?"
for path in /login /app/master/stock /app/master/critical; do
  printf '%-26s -> HTTP %s\n' "$path" \
    "$(curl -s -o /dev/null -w '%{http_code}' --max-time 25 "https://agora.ceratine.com$path")"
done
echo
echo "302 on the /app paths is CORRECT when signed out — it is the redirect to"
echo "/login. 404 means the route is not there. 500 means it is there and broken."
