# Deploying Agora

Written 6 September 2026, the day Agora first went to
**<https://agora.ceratine.com>**. This is the procedure, not a description of
one — follow it top to bottom and it produces the running site.

---

## The server

| | |
|---|---|
| Host | `agora.ceratine.com` (Ceratine-managed) |
| Access | `ssh root@agora.ceratine.com` — key only, no password |
| Box | `db-intel-zp`, Ubuntu 24.04 |
| Web root | `/var/www/agora`, served from `public/` |
| Stack | PHP 8.3 (`sqlsrv` + `pdo_sqlsrv`), nginx 1.24, php8.3-fpm, composer, node 20 |
| vhost | `/etc/nginx/sites-available/agora.ceratine.com` |
| TLS | Let's Encrypt via certbot, auto-renewing |
| Logs | `/var/log/nginx/agora.{access,error}.log`, `storage/logs/laravel.log` |

**It is the same box as ZP-NQL** (`zp-db.ceratine.com`) and zp-chat. That is
deliberate — the sqlsrv drivers and the private link to the customer's instance
were already there — but it means a mistake here can take out ZP-NQL too. Check
which site you are editing before you reload nginx.

---

## ⚠️ The one thing that will break Agora if you copy the neighbour

`zp-db.ceratine.com`'s vhost contains:

```nginx
location /app {
    proxy_pass http://127.0.0.1:8080;   # ZP-NQL's Reverb websocket
}
```

**Agora's entire application lives under `/app`.** Every module registers its
routes there. Copy that block into Agora's vhost and the whole site is proxied
into a websocket port that is not even running — and it fails looking like a
broken application rather than a broken vhost.

Agora has no Reverb wired. The block is absent on purpose, and the vhost file
says so. Do not add it back without also moving Agora off `/app`.

---

## The deploy

Agora is deployed by **rsync from a local checkout**, not by a git pull — the
server has no `.git`, so nothing on the box can tell you what it is running.
The commit is recorded in the project manager's deployment row instead.

```bash
cd ~/Development/ZP/Agora

# 1. Build the assets locally. The server has node, but building here keeps
#    dev dependencies off the box and the manifest matches what you tested.
rm -rf public/build public/hot
npm run build

# 2. Ship it. Every exclusion below is load-bearing.
rsync -az --delete \
  --exclude '.git' --exclude 'node_modules' --exclude 'vendor' \
  --exclude '.env' --exclude '.env.*' --exclude 'public/hot' \
  --exclude '.claude' --exclude 'test-results' \
  --exclude 'CLAUDE.md' --exclude 'CERATINE_API_INSTRUCTIONS.md' \
  --exclude 'pm_tasks.md' --exclude 'pm_response.md' \
  --exclude 'storage/logs/*' \
  --exclude 'storage/framework/cache/data/*' \
  --exclude 'storage/framework/sessions/*' \
  --exclude 'storage/framework/views/*' \
  ./ root@agora.ceratine.com:/var/www/agora/

# 3. On the server.
ssh root@agora.ceratine.com
cd /var/www/agora
export COMPOSER_ALLOW_SUPERUSER=1
composer install --no-dev --optimize-autoloader --no-interaction
chown -R www-data:www-data /var/www/agora
chmod -R 775 storage bootstrap/cache

php artisan migrate --force      # see the warning below
php artisan seed:master

php artisan config:cache
php artisan route:cache
php artisan view:cache
systemctl restart php8.3-fpm
```

**`CLAUDE.md` and `CERATINE_API_INSTRUCTIONS.md` must never reach the server.**
They carry the customer's database host and login and the dispatch API's working
detail — the very things commit `0dd0f3b` purged from this repository. They are
gitignored, so they are in a working checkout but not in a clone, which is
exactly how they get rsynced by accident. The excludes above are the guard; on
the first deploy they were not there and both files had to be deleted off the
box afterwards.

---

## ⚠️ `migrate` here writes to the customer's instance

The site runs on Ceratine's server, but **its database does not.** Agora's own
database lives on the customer's SQL Server beside PumpIT, because `agora.vw_*`
reaches the legacy estate by three-part name and that is same-instance only.
See [`pointing-at-the-customer-instance.md`](pointing-at-the-customer-instance.md).

So `php artisan migrate --force` on this box is a schema change on the
customer's production server. Before running it:

1. Read the migrations that will run. `php artisan migrate:status` names them.
2. Confirm they are additive. A lettered follow-on should only ever add — every
   `DROP` belongs in `down()`, which forward-only never executes.
3. Never `migrate:fresh`. Not here, not anywhere that is not the local container.
4. Know what `seed:master` will do — the table in
   [`pointing-at-the-customer-instance.md`](pointing-at-the-customer-instance.md)
   lists it seeder by seeder. `UserSeeder` creates a real administrator
   credential if it has not already run.

---

## Production environment

`.env` on the server is derived from the live connection settings, plus:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://agora.ceratine.com
SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync
```

`SESSION_DRIVER` is **`file`, not `database`** — Agora has no `sessions` table
and its migration convention would not create one silently.

The `AGORA_E2E_*` fixture credentials are stripped: they are a working sign-in
and have no business on a live server. `E2eFixtureSeeder` refuses a non-local
target anyway, but the credentials should not be sitting in the file either.

---

## Smoke test

```bash
for p in / /login /app; do
  curl -s -o /dev/null -w "$p %{http_code}\n" https://agora.ceratine.com$p
done
```

Expect `/` → 302 to `/app`, `/login` → 200, `/app` → 302 to `/login` when
signed out. Then check `storage/logs/laravel.log` is **empty** — the first
deploy passed every status check while quietly logging a broken badge provider
on every request, and only the log showed it.

Hit `/login` three times, not once. The bug that got through was on the cache
*hit*, not the miss, so a single request looked perfect.
