# The rules

The engineering rules for this repository, and the ones that cannot be broken.

> This is the shareable half of the working rulebook. The full `CLAUDE.md` is
> not in this repository — it carries the customer's connection detail and the
> project-manager working notes, neither of which belongs in a shared repo. If
> you are working from a clone, everything you need is here; if you need the
> rest, ask Ryan.

[`development.md`](development.md) is how the work goes.
[`feature-rules.md`](feature-rules.md) is what
every screen owes. This is what must not be done to any of it.

## ⚠️ THE RULE ABOVE ALL

**Agora writes to its own database. The customer's three are read-only, and
they are live.**

Revised 4 Sep 2026, when Ryan gave Agora a database of its own. Before that,
Agora's objects lived in a schema inside PumpIT and every migration was a change
to the production ERP. That is no longer true — but the customer's databases are
still production, and `PumpIT` is a 249 GB live system that Zululand Retail &
Petroleum trades on today.

1. **Nothing in the customer's databases is ever written.** Not `dbo`, not any
   schema, not by a model, not by a migration. `PumpIT` is the old system being
   replaced; `MIST_Import` holds POS reports and data; `Alteryx` holds reporting
   extracts. All three are READS. The legacy estate is reached through
   `agora.vw_*` views, which name it across databases
   (`[PumpIT].dbo.SS_Branch`) because Agora lives elsewhere now.
2. **`migrate:fresh` is forbidden against any real instance.** Locally, against
   the Docker container, it is merely a bad habit. Against the customer's
   Agora database it destroys users, menus and eventually cashups, with no
   restore. Forward-only.
3. **The customer's instance is in SIMPLE recovery.** No transaction-log chain,
   so no point-in-time restore. A bad migration cannot be rewound to the
   minute — only to whenever the last full backup happened to run, which nobody
   has confirmed. Assume you cannot undo it.
4. **Dry-run destructive DDL first.** T-SQL DDL is transactional: wrap it in a
   transaction, run it, inspect `sys.tables`, roll back. Better still, build it
   into a throwaway schema and diff — see [`development.md`](development.md).
5. **Develop against the local container, not the customer's instance.**
   `scripts/local-sql.sh up`. The suite runs in 2 seconds there against 27 on
   the remote instance, and a mistake costs nothing.

## The second rule: verify with real data

Ryan reads SQL output and spots fan-out, wrong types and unprefixed names
immediately. "The seeder ran" and "it compiles" are not verification. After any
change touching SQL: run the query, look at the rows, check the count, then say
it works. `php artisan agora:db-check --anchors` is the cheapest proof the
connections are real.

## Databases

| Connection | Database | Role | Access |
|---|---|---|---|
| `agora` *(default)* | Agora | **Agora's own database** — everything it writes | Read/write, `agora` schema only |
| `pumpit` | PumpIT | The old system being replaced | **Read-only** |
| `mist_import` | MIST_Import | POS reports and data | **Read-only** |
| `alteryx` | Alteryx | Reporting extracts | **Read-only** |

The roles are named in `config/agora.php` as `app`, `erp`, `pos_landing` and
`reporting` — use those rather than connection names, and note that `app` and
`erp` are now different databases. Anything that reads the legacy estate wants
`erp`; anything of Agora's own wants `app`, which is the Laravel default.

**Agora sits on the same instance as PumpIT**, and must: `agora.vw_*` views
reach the legacy estate by three-part name, which is same-instance only. It is
created `COLLATE Latin1_General_CI_AS` to match PumpIT — a different collation
makes every cross-database string comparison throw *Cannot resolve collation
conflict*.

The customer's databases share one login, which is **`sysadmin` on the
instance**. The guard is discipline, not the grant — nothing about the access
level makes a write to their data acceptable. Credentials are in `.env`, which
is not in this repository; ask Ryan.

Locally, `scripts/local-sql.sh up` gives you an `Agora` database and a `PumpIT`
stub in Docker. The host `mssql-server` package cannot be used on Ubuntu 24.04 —
`sqlservr` links `liblber-2.5.so.0` and there is no `libldap-2.5-0` candidate.

`encrypt=yes` **and** `trust_server_certificate=true` are both required — ODBC
Driver 18 validates by default and the instance's certificates are self-signed.

## Architecture in one screen

- **Laravel 13, PHP 8.3, sqlsrv.** Bootstrap 5 under the Agora token theme,
  vanilla JS, ApexCharts, SweetAlert2, Vite. No React/Vue/Alpine/jQuery.
- **HMVC modules** under `Modules/{Name}`, discovered by `module.json`.
  `php artisan agora:make-module {Name} --slot=NN` scaffolds the whole shape.
  Views are `view('{alias}::path')`; routes land under `/app` automatically.
  **Never add a route to `routes/web.php`** — it loads before every module and
  silently wins.
- **Stored procedures carry the business logic** (`agora.usp_{Module}_{Verb}{Object}`),
  deployed by `v1__NNp_{module}_procs.php` migrations from
  `Modules/{M}/Database/Procedures/*.sql`, one `CREATE OR ALTER` per file.
  PHP calls them through `App\Support\ProcedureService`. Eloquent is for reads
  and simple master CRUD. **If a rule exists in both a proc and PHP, the proc is
  right and the PHP is the bug.**
- **A refusal is `THROW 51000, 'AGORA:{Code}:{message}', 1`** — that becomes an
  `AgoraProcException` with the code intact. Anything else is a fault and comes
  out as a `QueryException`.
- **`BranchId` leads everything.** First column of every clustered key, first
  argument of every proc, and applied automatically by `BranchScope`. Rows that
  are not site-specific carry the group entity's id (**2**, Zululand Petroleum)
  — **never NULL**, which is how legacy rows became unreportable.
- **Every unique key includes `BranchId`.** `MigrationHelper::naturalKey()`
  refuses one that does not; a key without it is what made legacy lookups fan
  out 4–22×.
- **No DB-level foreign keys inside `Schema::create`** — one
  `v1__95_{module}_foreign_keys.php` per module, because the reference graph has
  cycles SQL Server rejects.
- **Migration filenames carry the module's slot**: `v1__NN_{module}_tables.php`,
  where NN is its place in domain order — Core 01, Masters 02, People 03,
  Partners 04, Product 05, DayEnd 10, Fuel 11, Cash 12, Recon 13, Stock 14,
  Purchasing 15, Utilities 16, Assets 17, Exceptions 20, Imports 21, Reports 22,
  Trade 23, Exco 24, Email 30, Help 31; `NNp` deploys that module's procedures,
  and 95 is its foreign keys. **A change to an existing table is a new lettered
  file** (`v1__12a_…`), never an edit to the create — PumpIT is production from
  day one, so there is no fresh install to re-run.
- **A module's migrations should read as creates, not as a pile of diffs.**
  While a table still holds only rows our own seeders wrote, a lettered
  follow-on may be **folded back into the create** and its row deleted from
  `agora.Migration` — that is what happened to Core's 01a–01d on 4 Sep 2026
  (Ryan: *"lets ensure the migrations are all creates and no alters"*), after
  proving the consolidated create builds byte-for-byte the live schema. Fold
  only when three things hold: the change has not left our hands, nothing in
  the table is business data, and the equivalence is **proved** — build the
  create into a throwaway schema inside a transaction, diff its columns and
  indexes against the live ones, roll back. Never drop a live `agora` table to
  find out. Once a table carries data the business cares about, the lettered
  rule above is the only way.
- **`MigrationHelper` is how a table is made.** `table()` composes `branchKey()`
  and `addAuditColumns()`; `money()` `litres()` `pct()` fix the decimal
  precision (never float — a float column does not add up to what the till
  said); `addRowVersion()` and `addSoftDeletes()` for editable and master rows;
  `naturalKey()` refuses a unique key without `BranchId`, and so does
  `scripts/check-migrations.sh` before it ever runs. `recordVersion()` stamps
  `agora.SchemaVersion` so the database can say what it is running.
- **Seeds run through the ledger, like migrations.** `php artisan seed:master`
  (and `db:seed`, which delegates to it) runs each seeder once and records the
  class in `agora.SeedMaster`; a class already in the ledger is skipped without
  being invoked. **There is no version** — a seeder is a one-shot, so changing
  what was seeded means writing another seeder, exactly as it does for a
  migration. The one thing a seeder declares about itself is
  `public int $seedOrder` (default 50), because the dependencies are real:
  `UserSeeder` looks the admin role up by code, so `RoleSeeder` runs first.
  Only a successful run is recorded — a seeder that throws leaves no row and is
  retried on the next run. `--forget=Name` and `--rollback-batch` reopen the
  gate; neither undoes what a seeder wrote.
- **Navigation is database-driven** (`agora.MenuSection` / `agora.MenuItem`,
  nested to any depth through `ParentId`). Each module ships a `MenuSeeder`
  calling `MenuService::item()`. **Never list a link in a blade file.**
- **Everything is a component.** No screen writes its own KPI, table or nav
  markup. See [`components.md`](components.md); build the component if
  it is missing, and add it to that file.

## Testing

Full detail in [`testing.md`](testing.md). The short version:

- `composer check` = pint → phpstan (level 6) → check-migrations → check-procs →
  phpunit. `composer check-fast` drops the slow two. Run `check` before calling
  anything done.
- Browser tests are Playwright, `tests/e2e`, two projects (`desktop`, `mobile`
  at 375x812). They are NOT in `composer check` — they need a server and a
  browser, and that is Ryan's call.
- **Targeted runs only** — `php artisan test --filter=ClassName::method`. Ryan is
  on solar and usually working on the machine at the same time; he runs full
  suites himself when it suits him.
- **Never `RefreshDatabase`.** It would drop the customer's estate. `phpunit.xml`
  deliberately does not fall back to sqlite: a test that passes against sqlite
  proves nothing about a T-SQL schema and cannot run a procedure at all.
- A test that writes cleans up in `tearDown()` and prefixes what it creates with
  `TEST-`. Read-only tests are strongly preferred.

## Working on this repository

- Concise, outcome-first, constructive pushback welcome. No fluff, no emoji.
- **Do not commit unless Ryan says so** — apply changes to the working tree.
  When he does ask, say in the message how the change was verified, and say
  plainly when it was **not** verified against a database.
- **Never deploy without an explicit go-ahead** — state what, where and why,
  then wait.
- Unknowns go to him as a short list. An honest "unsure" costs a minute; an
  invented value costs an incident.

## Where the answers are

| Question | Read |
|---|---|
| How the work goes — clone to running, then a feature end to end | [`development.md`](development.md) |
| What every feature must do — databases, procedures, grids, forms, views | [`feature-rules.md`](feature-rules.md) |
| Which components exist | [`components.md`](components.md) |
| Test layout, fixtures, the browser suite | [`testing.md`](testing.md) |
| Full architecture, module-by-module data model, build order | [`AGORA_ARCHITECTURE_AND_BUILD_PLAN.md`](AGORA_ARCHITECTURE_AND_BUILD_PLAN.md) |
| Working from GitHub with no database | [`../pm_tasks.md`](../pm_tasks.md) |
