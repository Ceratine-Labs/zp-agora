# Developing Agora

How the work actually goes: clone to running app, then a feature end to end.

This is the *how*. The rules are in [`rules.md`](rules.md), what every
feature must do is in [`feature-rules.md`](feature-rules.md), and the *what* is
in [`AGORA_ARCHITECTURE_AND_BUILD_PLAN.md`](AGORA_ARCHITECTURE_AND_BUILD_PLAN.md).
Nothing here restates any of them — where a rule matters to a step, it is named
and linked, not copied.

**Read [`feature-rules.md`](feature-rules.md) before you scope a feature**, not
while you build it. Grids alone owe typed header filters, CSV export, a branches
selector, a detail panel and persisted per-user layout, and finding that out in
step 6 is how an estimate doubles.

---

## The three facts that change how you work here

1. **Agora writes to its own database; the customer's three are read-only and
   live.** `Agora` is the primary — everything Agora owns is in it. `PumpIT` is
   the old system being replaced, `MIST_Import` holds POS reports and data, and
   `Alteryx` holds reporting extracts; all three are reads, always. Read
   [`rules.md`](rules.md) → *THE RULE ABOVE ALL* before you run anything.
2. **The business rules live in stored procedures, not in PHP.** A service that
   writes calls `agora.usp_*`. If a rule exists in both places, the procedure is
   right and the PHP is the bug.
3. **Everything is a component.** No screen writes its own KPI, table or nav
   markup. Check [`components.md`](components.md) first; build the component if
   it is missing, and add it to that file.

---

## From clone to running

```bash
composer install
npm install
cp .env.example .env && php artisan key:generate

# Set AGORA_DB_PASSWORD, then bring up the SQL Server you develop against.
# It creates the Agora database and the PumpIT stub the views need.
scripts/local-sql.sh up

# Fill the three customer connection blocks. Those passwords live encrypted in
# ZP-NQL: DatabaseConnection::where('name','PumpIT')->first()->getDecryptedPassword()

php artisan agora:db-check --anchors
php artisan agora:init-schema           # once per database: the agora schema
php artisan migrate
php artisan agora:sync-local-branches   # the real 31 branches into the stub
php artisan seed:master
```

**Why a container and not the host package.** `mssql-server` is installed on
this machine and cannot start: `sqlservr` links `liblber-2.5.so.0`, Ubuntu 24.04
ships OpenLDAP 2.6, and `libldap-2.5-0` has no candidate in the archive. That is
a Microsoft/Ubuntu version mismatch rather than a configuration error, so the
container is the supported route.

**Why a PumpIT stub.** `agora.vw_*` views name the legacy estate across
databases — `[PumpIT].dbo.SS_Branch` — and three-part naming is same-instance
only. Without a local PumpIT beside the local Agora, every view is broken. It is
also why **Agora must sit on the same instance as PumpIT** in production.

`agora:db-check` is the first command on a fresh clone and after any change to a
connection. `--anchors` counts five known tables, so it proves the query came
back rather than that the config parsed. Expect `PumpIT` at ~590 `dbo` tables
and `dbo.SS_Branch` at 31 rows; anything else means you are pointed somewhere
unexpected, and you should stop.

```bash
npm run build
./dev.sh          # serve + vite + log tail, one Ctrl-C stops all three
```

`dev.sh` runs `agora:db-check` before it serves and refuses to start if a
connection is down — a dead connection here looks like a hundred unrelated
errors later.

Two `.env` details bite on a fresh machine:

* `PUMPIT_DB_ENCRYPT=yes` **and** `PUMPIT_DB_TRUST_SERVER_CERTIFICATE=true` are
  both required. ODBC Driver 18 validates certificates by default and the
  instance's are self-signed. The same pair applies to `AGORA_DB_*`.
* The Agora database must be `COLLATE Latin1_General_CI_AS`, matching PumpIT.
  Get this wrong and every cross-database string comparison throws *Cannot
  resolve collation conflict* — `scripts/local-sql.sh` sets it for you.
* `AGORA_E2E_PASSWORD` (16 characters minimum) is what creates the Playwright
  account. Leave it unset and both the fixture seeder and the browser specs
  skip rather than fail.

---

## The loop

| You want to | Run |
|---|---|
| Serve everything | `./dev.sh` |
| See what a migration would send | `php artisan migrate --pretend` |
| Apply migrations | `php artisan migrate` |
| Seed what has not been seeded | `php artisan seed:master` |
| See the seed ledger | `php artisan seed:master --status` |
| Run one test | `php artisan test --filter=ClassName::method` |
| Run the gates | `composer check` (or `check-fast`) |
| Look at every component | `http://127.0.0.1:8123/dev/theme` (local + testing only) |

**Targeted test runs only.** Ryan is on solar and usually on the machine at the
same time; he runs full suites when it suits him. Same for static analysis —
`vendor/bin/phpstan analyse Modules/Cash` rather than a cold pass over the tree.

---

## Building a feature, end to end

The worked example is a cash capture screen in a new `Cash` module. Every step
is a real file in the house shape.

### 1. Scaffold the module

```bash
php artisan agora:make-module Cash --slot=12 --requires=Core
```

`--slot` is the module's place in domain order ([`rules.md`](rules.md) lists
them: Core 01,
Masters 02, … Cash 12). It becomes the migration filename prefix, so slots are
what keep twenty modules' migrations in a sane order. You get the manifest,
provider, config, routes, controller, service, migration, menu seeder, view and
lang file.

Routes are registered for you: `Routes/web.php` lands under `/app` with the
`web` stack and an `app.` name prefix — **do not repeat the prefix in the file**,
and never add a route to `routes/web.php`, which loads before every module and
silently wins. A page that must sit outside `/app` (a sign-in, a callback) goes
in `Routes/root.php`.

### 2. The table

`Modules/Cash/Database/Migrations/v1__12_cash_tables.php`:

```php
MigrationHelper::table('DropSafeBag', function (Blueprint $table) {
    $table->string('BagNumber', 30);
    $table->date('BusinessDate');
    MigrationHelper::money($table, 'Amount');
    $table->string('ReasonCode', 20)->nullable();
    $table->bigInteger('DroppedByEmployeeId');
    $table->dateTime('CollectedAt', 0)->nullable();
});
MigrationHelper::naturalKey('DropSafeBag', ['BranchId', 'BagNumber', 'BusinessDate']);
MigrationHelper::rowVersion('DropSafeBag');
```

What `MigrationHelper` is doing for you, and why it refuses things:

* `table()` supplies the spine — `BranchId` first in the clustered key, a
  `BIGINT IDENTITY` `Id`, the stamped audit columns — and **refuses any schema
  but `agora`**. A migration that forgets its prefix would otherwise create a
  table in `dbo`, in production, on the first run.
* `naturalKey()` **refuses a unique key without `BranchId`**. A key on the
  business columns alone is what let one branch's row block another's in the
  legacy estate. `scripts/check-migrations.sh` catches it before it ever runs.
* `money()` `litres()` `pct()` fix the decimal precision. Never `float`: a
  column of floats does not add up to what the till said.
* No foreign keys inside the create. They go in one
  `v1__95_cash_foreign_keys.php` per module, because the reference graph has
  cycles SQL Server rejects.

**A change to an existing table is a new lettered file** (`v1__12a_…`), never an
edit to the create. The one exception, and its conditions, are in
[`rules.md`](rules.md) —
it is not a habit.

Before you run it against the real database, look at the SQL:

```bash
php artisan migrate --pretend
```

For destructive DDL, prove it in a transaction and roll back — see
[Working against a live database](#working-against-a-live-database) below.

### 3. The procedure

`Modules/Cash/Database/Procedures/usp_Cash_DropBag.sql`, one `CREATE OR ALTER`
per file, named `usp_{Module}_{Verb}{Object}`:

```sql
CREATE OR ALTER PROCEDURE agora.usp_Cash_DropBag
    @BranchId    INT,
    @BagNumber   NVARCHAR(30),
    @Amount      DECIMAL(18,2),
    @ReasonCode  NVARCHAR(20) = NULL,
    @UserId      INT
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF @Amount > 2000 AND @ReasonCode IS NULL
        THROW 51000, 'AGORA:REASON_REQUIRED:A drop over R2,000 needs a reason.', 1;

    INSERT agora.DropSafeBag (BranchId, BagNumber, Amount, ReasonCode, CreatedAt, CreatedBy)
    VALUES (@BranchId, @BagNumber, @Amount, @ReasonCode, SYSDATETIME(), @UserId);

    SELECT Ok = CAST(1 AS BIT), Code = 'OK', Message = 'Bag recorded.', Id = SCOPE_IDENTITY();
END
```

Three contracts to keep:

* **`@BranchId` leads every signature.** It leads every clustered key too.
* **A refusal is `THROW 51000, 'AGORA:{Code}:{message}', 1`.** That becomes an
  `AgoraProcException` with `->code()` intact, so a controller branches on a
  stable code rather than on English prose. Anything without the `AGORA:`
  prefix stays a `QueryException` — a deadlock is not a business rule.
* **A writer returns one status row** `(Ok BIT, Code, Message, Id)`.
  `ProcedureService::write()` raises `Ok = 0` as a refusal, so the caller does
  not have to remember which of two refusal shapes a given proc uses.
* The procedure sets `CreatedAt` / `CreatedBy` from its `@UserId`. **PHP does
  not stamp audit columns on a proc write.**

`agora:make-module` does **not** create the `Procedures` directory — a module
with no procedures should not carry an empty one. Make it, then deploy with a
one-line migration, `Modules/Cash/Database/Migrations/v1__12p_cash_procs.php`:

```php
return new class extends ProcedureMigration {};
```

Every `.sql` in the module's `Procedures` directory is then sent on the next
`migrate`, each as its own batch. `scripts/check-procs.sh` refuses a file that
is not a single `CREATE OR ALTER` in the `agora` schema, one that writes without
`SET XACT_ABORT ON`, and one that no migration in its module deploys.
`ProcedureMigration` itself throws if the directory turns out to be empty — an
empty one is almost always a moved directory rather than an intention.

### 4. The service

Business logic lives here; controllers stay thin.

```php
class CashService
{
    public function __construct(protected ProcedureService $procedures) {}

    public function dropBag(int $branchId, string $bagNumber, float $amount, ?string $reason, int $userId): object
    {
        return $this->procedures->write('usp_Cash_DropBag', [
            'BranchId' => $branchId,
            'BagNumber' => $bagNumber,
            'Amount' => $amount,
            'ReasonCode' => $reason,
            'UserId' => $userId,
        ]);
    }
}
```

Parameters are **named, always** — positional binding against a procedure means
a reordered signature sends the wrong value to the wrong argument and nothing
errors. Reads use `call()` for the first result set or `callSets()` for all of
them; a reader returning a header set and a lines set loses the second one if
you use `fetchAll()` thinking. `->on('mist_import')` binds the same service to
another connection.

**A writer owns its atomicity; do not reach for a savepoint.** `write()` opens a
transaction when there is none and **joins** the caller's when there is one.
That is deliberate: a writer sets `XACT_ABORT ON`, so its `THROW` dooms the
whole transaction, and SQL Server will not roll a doomed transaction back to a
savepoint. Composing two writes inside your own `DB::transaction()` is
therefore fine — it is all-or-nothing, which is what you wanted — and the
refusal still arrives as an `AgoraProcException` with its code intact.

### 5. The controller

```php
public function store(DropBagRequest $request, CashService $cash): RedirectResponse
{
    try {
        $cash->dropBag(...);
    } catch (AgoraProcException $e) {
        return back()->withErrors(['amount' => $e->getMessage()]);
    }

    return redirect()->route('app.cash.dropsafe')->with('status', 'Bag recorded.');
}
```

Catch `AgoraProcException` and turn it into something the user can act on. Do
**not** catch `QueryException` here — that is a fault, and it belongs in the log
and on the error page, not dressed up as a validation message.

### 6. The screen

Views are `view('cash::dropsafe.index')`. Compose components; write no markup a
component already owns. A screen with a grid on it owes everything in
[`feature-rules.md` §3](feature-rules.md) — that is the section to have read
before the estimate, not after:

```blade
<x-app-shell title="Drop safe">
    <x-page-head eyebrow="Cash" title="Drop safe" blurb="Bags dropped today, and what is still to collect." />

    <div class="kpi-strip">
        <x-kpi label="Dropped today" :value="\App\Support\Format::R($total)" note="{{ $count }} bags" />
    </div>

    <x-card title="Today's bags">
        {{-- grid --}}
    </x-card>
</x-app-shell>
```

Numbers go through `App\Support\Format` server-side and `resources/js/format.js`
client-side. **The two must agree character for character** — `/dev/theme`
renders every case side by side and `tests/e2e/format.spec.js` fails if a row
disagrees. Never use a locale formatter: PHP's intl and JavaScript's `Intl`
disagree with each other for `en-ZA`, on both the group and the decimal
separator.

### 7. The menu

Navigation is database-driven. **Never list a link in a blade file.** The
module's `MenuSeeder` calls `MenuService`:

```php
MenuService::item('branch', 'today', [
    'path' => 'close-the-day/drop-safe',
    'parent' => 'close-the-day',
    'label' => 'Drop safe',
    'route' => 'app.cash.dropsafe',
    'sort' => 60,
]);
```

`item()` upserts on `(workspace, section, path)`, so re-seeding never duplicates
and a relabel is an update. A parent is addressed by its own `path`, which lets
a module hang an item under another module's heading without knowing its key —
and seeding a child before its parent throws, deliberately.

### 8. Getting the seeder to run

`seed:master` gates on the class name, like `migrate` gates on a filename: in
the ledger means done, absent means run it. The only thing a seeder declares is
where it sits:

```php
public int $seedOrder = 30;   // default 50
```

There is no version. **Changing what a seeder wrote means writing another
seeder** — the same discipline the migrations follow. Only a successful run is
recorded, so a seeder that throws leaves no row and is retried next time.

```bash
php artisan seed:master --status
php artisan seed:master --only=CashDropSafeSeeder
php artisan seed:master --forget=CashDropSafeSeeder   # reopens the gate; does NOT undo what it wrote
```

A short name that matches more than one seeder is **refused**, not guessed at —
and since every module ships a `MenuSeeder`, that is the normal case rather than
a corner. Name the module when it happens:

```bash
php artisan seed:master --forget='Modules\Cash\Database\Seeders\MenuSeeder'
```

Nothing stands between a newly added seeder and production, so write seeders as
upserts keyed on a natural key, and read one before you add it.

### 9. Tests

Detail is in [`testing.md`](testing.md). The three that catch people out:

* **Never `RefreshDatabase`.** It would drop the customer's estate. There is no
  sqlite fallback either — a test that passes against sqlite proves nothing
  about a T-SQL schema and cannot run a procedure at all.
* A test that writes cleans up in `tearDown()` and prefixes what it creates with
  `TEST-`. Read-only tests are strongly preferred.
* Branch `999` (`TEST-Playwright`, inactive and non-trading) exists so a
  write-path test has somewhere that is not a real site.

---

## Reading data: which of the three ways

| You are reading | Use | Why |
|---|---|---|
| An Agora table | Eloquent on a `BaseModel` | Branch scoping is automatic |
| A legacy PumpIT table | `agora.vw_*` view | Aliases `SSBranchId` → `BranchId`, hides the `_OLD` / `_DEFUNCT` twins, brackets reserved words, applies the dedupe rule — and names the database, since it is not ours |
| Anything with a rule in it | `ProcedureService::call()` | The rule is in the procedure, and duplicating it in PHP creates a second answer |

`BaseModel` gives you PascalCase timestamps (`CreatedAt` / `UpdatedAt`), `Id` as
the key, a schema-qualified table name, and `RowVer` guarded because the
database owns it. **Do not write `where('BranchId', …)` by hand** — `BranchScope`
applies it, and a manual clause fights it. For a genuinely estate-wide read
(a group league table), say so out loud with `->acrossBranches()`.

If a query returns nothing and you expected rows, `BranchScope` is the first
suspect: rows carrying the group entity's id (**2**, Zululand Petroleum) are
always visible, everything else depends on the workspace and the user's grants.

---

## Working against a live database

**The technique for anything destructive:** T-SQL DDL is transactional, so wrap
it, run it, inspect `sys.tables`, roll back.

```php
DB::beginTransaction();
try {
    $migration->up();
    // inspect sys.columns / sys.indexes here
} finally {
    DB::rollBack();
}
```

`SET LOCK_TIMEOUT 5000` first, so a schema lock on a production table fails fast
instead of queueing behind live traffic.

To compare what a migration *would* build against what is already there without
touching a live object, build it into a throwaway schema inside that
transaction: `CREATE SCHEMA agora_check`, point `config(['agora.schema' => …])`
at it, run `up()`, diff `sys.columns` and `sys.indexes` against `agora`, roll
back. That is how Core's consolidated create was proved.

**"The seeder ran" and "it compiles" are not verification.** After any change
touching SQL: run the query, look at the rows, check the count, then say it
works. Ryan reads SQL output and spots fan-out, wrong types and unprefixed names
immediately.

---

## When it goes wrong

| Symptom | Cause | Fix |
|---|---|---|
| `Invalid object name 'agora.Foo'` | The migration has not run, or you are on the wrong connection | `php artisan migrate:status`, then `agora:db-check` |
| `SQLSTATE[08001]` / certificate error | ODBC 18 validating a self-signed certificate | `*_DB_ENCRYPT=yes` **and** `*_DB_TRUST_SERVER_CERTIFICATE=true` |
| Route 404s and the file looks right | A route in `routes/web.php`, which loads before every module and wins | Move it into the module's `Routes/web.php` |
| `View [foo::bar] not found` | The module is not booting | Check `module.json` exists and names the provider. Locally the module list is re-scanned every request; on staging or live it is cached, so delete `bootstrap/cache/agora-modules.php` there |
| A menu item does not appear | Seeded but its parent was not, or the seeder is already in the ledger | `seed:master --status`, then `--forget=` |
| A seeder edit has no effect | It ran once; the ledger gates on the class name | Write another seeder, or `--forget=` while developing |
| `QueryException` where you expected a refusal | The procedure `THROW`s without the `AGORA:` prefix | Fix the procedure's message; the prefix is the contract |
| `Cannot roll back trans2. No transaction or savepoint of that name was found.` | A writer's `THROW` doomed a transaction something tried to roll back to a savepoint | `write()` joins an open transaction rather than nesting; if you see this, something else opened the savepoint |
| `--forget` or `--only` appears to do nothing | The short name matched two seeders and was refused | Read the warning — it lists the candidates. Pass the fully-qualified name |
| A write succeeded but `CreatedBy` is null | PHP wrote the row instead of the procedure | Transactional writes go through `ProcedureService` |
| Rows exist in SSMS but not in the app | `BranchScope` | Check the workspace and the user's branches, or `->acrossBranches()` |
| `composer check` fails on a model property | Larastan cannot see the schema | Add the `@property` docblock to the model — the doc is the fix, not an ignore |

---

## Before you call it done

* `composer check` passes (pint → phpstan → migrations → procs → phpunit).
* The SQL was run and the **rows were looked at**, not just the migration.
* Anything a screen renders comes from a component, and a new component is in
  [`components.md`](components.md).
* No link was hand-written into a blade nav file, and no route went into
  `routes/web.php`.
* A test names what it proves, cleans up after itself, and is targeted.
* The task has an outcome, a QA entry and a stopped timer in the project
  manager.

---

## Where the answers are

| Question | Read |
|---|---|
| The rules, and the ones that cannot be broken | [`rules.md`](rules.md) |
| Full architecture, module-by-module data model, build order | [`AGORA_ARCHITECTURE_AND_BUILD_PLAN.md`](AGORA_ARCHITECTURE_AND_BUILD_PLAN.md) |
| Which components exist | [`components.md`](components.md) |
| Test layout, fixtures, the browser suite | [`testing.md`](testing.md) |
| The customer instance, join maps, what ZP-NQL already solved | [`reference/ZP-NQL-REFERENCE.md`](reference/ZP-NQL-REFERENCE.md) |
| The design the screens are built from | `reference/agoraretailconsole.html` |
| Commands and the day-to-day loop | [`README.md`](../README.md) |

---

*Walked end to end on 4 Sep 2026 against a throwaway `Walkthrough` module —
scaffolded, all nine steps followed as written, then deleted. It found two real
defects, both since fixed: `ProcedureService::write()` losing a refusal and
stranding the connection when called inside a caller's transaction, and
`seed:master` silently picking one of two seeders sharing a short name. Nothing
from the walk persisted: the table and procedure were proved inside a
transaction and rolled back.*
