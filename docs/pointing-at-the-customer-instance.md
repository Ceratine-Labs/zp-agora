# Pointing Agora at the customer's instance

Written 4 September 2026, the day Ryan created the Agora database on
ZP-MIST-SVR. Until then every local checkout read a four-row PumpIT stub in
Docker, which is why the recon screens showed R15,137.40 against a business
that trades in hundreds of millions.

**This is a checklist for a deliberate act, not a default.** A local checkout
pointed here has `APP_ENV=local`, `APP_DEBUG=true` and every other sign of
development, and is one `artisan` command away from the customer's production
server. Read the whole page before running anything.

---

## Why Agora has to live on their instance

`agora.vw_*` reaches the legacy estate by **three-part name** —
`[PumpIT].dbo.SS_Branch` — and three-part naming is same-instance only. A
linked server would need four-part names and is not supported here. So Agora's
own database sits beside PumpIT, MIST_Import and Alteryx on ZP-MIST-SVR, and
reads across to them.

It must be created `COLLATE Latin1_General_CI_AS`, matching PumpIT. A different
collation makes every cross-database string comparison throw *Cannot resolve
collation conflict*, and the recon views compare strings on every row.

---

## The order

`customer-db-host` and `customer-db-login` below are placeholders. Obtain the
real values from the deployment's private `.env` or the approved encrypted
connection store; keep them out of tracked documents and reference assets.
`python3 scripts/check-customer-connection-details.py` enforces this policy for
Git-tracked `docs/` files and runs in both Composer checks. Its `--self-test`
option verifies rejection of unsafe examples without connecting to a database.

```bash
# 1. Point the APP connection at the instance. The other three already do.
#    Only AGORA_DB_* moves — PUMPIT_DB_*, MIST_DB_* and ALTERYX_DB_* are
#    already remote and stay read-only.
AGORA_DB_HOST=customer-db-host
AGORA_DB_DATABASE=Agora
AGORA_DB_USERNAME=customer-db-login
AGORA_DB_PASSWORD=…
AGORA_DB_ENCRYPT=yes
AGORA_DB_TRUST_SERVER_CERTIFICATE=true   # both are required — ODBC 18 validates
                                          # by default and the certificate is
                                          # self-signed

# 2. Prove all four connections answer before writing anything.
php artisan agora:db-check --anchors

# 3. The agora schema, which cannot be a migration — Laravel creates its
#    ledger at agora.Migration BEFORE the first migration runs.
php artisan agora:init-schema          # prompts; it is the first write

# 4. Forward-only. NEVER migrate:fresh, and never --pretend as a substitute
#    for reading the migration.
php artisan migrate

# 5. Seeds. Read the warnings below FIRST.
php artisan seed:master
```

---

## What `seed:master` will do to a real instance

The ledger runs each seeder once and records it. Against the customer's Agora
database, in order:

| Seeder | Writes | Fine? |
|---|---|---|
| `BranchSeeder` (10) | `agora.Branch` from `PumpIT.dbo.SS_Branch` | Yes — a copy of their own branch list |
| `RoleSeeder` (20) | `agora.Role` | Yes |
| Core `MenuSeeder` (30) | `agora.MenuSection` / `MenuItem` | Yes |
| `UserSeeder` (40) | **a real administrator credential** | Intended, but know that it happens. The password comes from `config('agora.admin')`; set it deliberately |
| Recon `MenuSeeder` (45) | menu entries | Yes |
| `E2eFixtureSeeder` (50) | **skipped** — see below | — |

**`E2eFixtureSeeder` now refuses a non-local target.** It creates a working
sign-in credential and a fake branch 999, and its only guard used to be
`APP_ENV != production` — which stopped meaning anything the moment a local
checkout could point here. It reads the app connection's host and skips with a
warning. `tests/Feature/Seeding/E2eFixtureGuardTest.php` holds that both ways.

---

## What stays true

- **Nothing in PumpIT, MIST_Import or Alteryx is ever written.** Agora writes
  to its own database only. The recon previews are `SELECT`-only and reach the
  legacy estate through `agora.vw_*`.
- **PumpIT and the MIST databases are in SIMPLE recovery; Agora is in FULL**,
  with ZP's daily full backup (about 03:58) and a daily log backup at 22:15
  (`scripts/sql/agora-log-backup-job.sql`). A restore to the minute has never
  been tested, so treat a bad migration as permanent.
- **`customer-db-login` is `sysadmin` on the instance.** The guard is discipline, not the
  grant.

## Going back to the local container

Put `AGORA_DB_HOST=127.0.0.1` back and everything returns to the Docker stub —
it keeps its own `agora.Migration` ledger, so nothing needs re-running. The
suite is 2 seconds there against 27 remote, so develop locally and point here
to look at real figures.

`scripts/local-sql.sh status` says which databases the container is holding.
