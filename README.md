# Agora

*All Group Operations, Reconciliation & Analysis* — the retail and petroleum
operations system for **Zululand Retail & Petroleum**, successor to PumpIT.

The job is the metronomos': the dip must tie to the pump, the Z-read to the
cashup, the declaration to the bank. 31 branches, 25 of them trading.

> **Read [`CLAUDE.md`](CLAUDE.md) before you run anything.** Agora's development
> database is the customer's production database. `migrate:fresh` is forbidden,
> and nothing in the `dbo` schema is ever altered.
>
> Then read [`docs/development.md`](docs/development.md) — clone to running app,
> and a feature end to end: module, migration, procedure, service, screen, menu,
> seeder, test — and [`docs/feature-rules.md`](docs/feature-rules.md) before you
> scope anything, because it is what every screen owes.

## Stack

Laravel 13 · PHP 8.3 · SQL Server 2017 (`sqlsrv` + ODBC Driver 18) · Bootstrap 5
under the Agora token theme · vanilla JS · Vite · ApexCharts · SweetAlert2.

## Getting started

```bash
composer install
npm install
cp .env.example .env && php artisan key:generate

# Set AGORA_DB_PASSWORD to something strong, then bring up the local SQL Server
# that Agora develops against — it creates the Agora database and a PumpIT stub.
scripts/local-sql.sh up

# Fill in the three customer connection blocks in .env. Those passwords live
# encrypted in ZP-NQL:
#   DatabaseConnection::where('name','PumpIT')->first()->getDecryptedPassword()

php artisan agora:db-check --anchors        # prove all four connections
php artisan agora:init-schema               # once: the agora schema
php artisan migrate
php artisan agora:sync-local-branches       # real branch rows into the stub
php artisan seed:master

npm run build
./dev.sh
```

`agora:db-check` is the first thing to run on a fresh clone and after any change
to a connection. It prints each database, the login, the table and procedure
counts, the recovery model and the schema breakdown — "the config looks right"
is not the same fact as "the query came back".

## Layout

```
app/
  Console/Commands/     agora:db-check · agora:init-schema · agora:make-module
  Models/BaseModel      PascalCase timestamps, Id key, branch global scope
  Support/
    BranchContext       which sites this request is looking at
    ProcedureService    the only way to call a stored procedure
    Database/           MigrationHelper — the shape every agora table shares
    Modules/            HMVC discovery and boot ordering
Modules/Core/           identity, branches, the database-driven menu, the shell
resources/
  scss/                 tokens (from the mockup), bootstrap bridge, shell, components
  js/components/        mega-menu, theme
  views/components/     the shared component library
docs/                   the build plan, the reference notes, the component index
```

## Commands

| Command | What it does |
|---|---|
| `php artisan agora:db-check [--anchors]` | Prove every customer connection |
| `php artisan agora:init-schema` | Create the `agora` schema (once per database) |
| `scripts/local-sql.sh {up\|down\|status\|destroy}` | The local SQL Server Agora develops against |
| `php artisan agora:sync-local-branches` | Copy real branch rows into the local PumpIT stub |
| `php artisan agora:make-module Name --slot=NN` | Scaffold a module in the house shape |
| `php artisan migrate --pretend` | See the SQL a migration would send, without sending it |
| `php artisan seed:master [--status]` | Run each seeder that has not run here, recorded in the ledger |
| `php artisan test --filter=Class::method` | **Targeted** test runs only |
| `composer check` | pint → phpstan → migrations → procs → the suite |
| `./dev.sh` | Serve, Vite, and the log tail together |

## Adding a module

```bash
php artisan agora:make-module Cash --slot=12 --requires=Core
```

You get the full §3.2 skeleton: manifest, provider, config, routes (web + api),
controller, service, a versioned migration, a menu seeder, a view and a lang
file. Routes land under `/app/cash` with the standard middleware; views resolve
as `view('cash::index')`. Fill in the migration and the menu seeder — do not
hand-write a nav link, and do not add a route to `routes/web.php`.
