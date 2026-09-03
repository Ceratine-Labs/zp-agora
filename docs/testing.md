# Testing Agora

Three layers, each answering a different question:

| Layer | Command | Answers |
|---|---|---|
| Unit (`tests/Unit`) | `php artisan test --filter=ClassName` | Does this class behave? No database, no HTTP |
| Feature (`tests/Feature`) | `php artisan test --filter=ClassName::method` | Does the request work, against the real T-SQL database? |
| Browser (`tests/e2e`) | `npx playwright test tests/e2e/login.spec.js --project=desktop` | Does a person actually get through this in a browser? |

## Two rules that override everything else

**1. Targeted runs only.** Run the spec, filter or path you touched — never the
whole suite, and never a repo-wide static-analysis pass, unless Ryan asks. He is
on solar/off-grid power and usually working on the machine at the same time; a
full run costs him real power and a usable computer. He runs full suites himself
when it suits him. If broad verification genuinely matters, say so and let him
schedule it.

```bash
php artisan test --filter=ProcedureServiceTest::test_it_calls_a_procedure_with_named_parameters
npx playwright test tests/e2e/navigation.spec.js --project=desktop
```

**2. The database is the customer's production database.** Everything follows
from that:

- **Never `RefreshDatabase`.** It would drop the customer's estate. `phpunit.xml`
  deliberately has no sqlite fallback — a test that passes against sqlite proves
  nothing about a T-SQL schema and cannot run a stored procedure at all.
- **Prefer read-only tests.** Almost everything worth asserting can be asserted
  against data that is already there.
- **A test that writes** writes into the `agora` schema, never `dbo`; uses the
  `TEST-` fixtures below; and cleans up in `tearDown()`. Prefix anything it
  creates with `TEST-` so a leftover row is obvious in a listing.

## The fixtures

`Modules\Core\Database\Seeders\E2eFixtureSeeder` creates exactly two rows, and
refuses to run when `APP_ENV=production`:

| Fixture | What | Why it is safe |
|---|---|---|
| `TEST-playwright@agora.local` | A user on the **read-only auditor** role | No suite signs in as a real person, and this account cannot approve, capture or post |
| Branch `999` — `TEST-Playwright` | A branch, `IsTrading = false` **and** `IsActive = false` | The scope bar lists trading, active sites only, so it never appears in the UI — but a future write-path test has somewhere safe that is not a real site |

```bash
# Set AGORA_E2E_PASSWORD in .env (16 characters minimum — the seeder refuses less), then:
php artisan db:seed --class="Modules\Core\Database\Seeders\E2eFixtureSeeder"
```

Leave `AGORA_E2E_PASSWORD` unset and the seeder skips; the browser specs skip
with it rather than failing, so a checkout without the variable is not broken.

## Browser tests

```bash
npx playwright test tests/e2e/login.spec.js --project=desktop   # 1440x900
npx playwright test tests/e2e/shell.spec.js  --project=mobile   # 375x812
npx playwright test tests/e2e/navigation.spec.js --project=desktop --headed --debug
```

`playwright.config.js` ships with **one worker, no retries, no video, and traces
only on failure**. Parallel browsers against one PHP dev server and a database
across the internet is a way to time out, not a way to go faster.

The `webServer` block starts `php artisan serve` and reuses one that is already
up. It deliberately does **not** run Vite: **run `npm run build` yourself** after
changing anything under `resources/`, so a stale asset build is visible rather
than papered over.

### Fixtures

`tests/e2e/support/fixtures.js` exports one that matters:

- **`signedIn`** — a page already authenticated as the Playwright account. It
  signs in through the real form rather than injecting a session, because the
  sign-in path is the thing most likely to break and a fixture that skipped it
  would hide exactly that.

Every page, signed in or not, **fails its test on a console error or an uncaught
exception**. A silent JS error is how a menu stops opening while every assertion
still passes.

### The browser build

Playwright wants the exact chromium it shipped with and downloads ~380 MB on
first use. `tests/e2e/support/chromium.js` prefers that build when it is present
and otherwise falls back to the newest one already in `~/.cache/ms-playwright`,
so a fresh checkout on a metered or solar-powered connection is not held up.

That fallback is a real compromise: a browser two releases behind can differ on
new CSS and new APIs. It is right for a smoke suite and wrong for chasing a
rendering bug. To get the matched build:

```bash
npx playwright install chromium
```

Once it is there the fallback stops applying, with no config change.

## `composer check`

```bash
composer check        # the full gate
composer check-fast   # pint + the two script checks, no phpstan, no tests
```

`check` runs, in order: **pint** (style) → **phpstan** (level 6, larastan, over
`app` `Modules` `database` `tests`) → **check-migrations** → **check-procs** →
**phpunit**.

`check-fast` is the one to run while you are still typing; `check` is the one to
run before you say something is done.

It does **not** run Playwright. Browser tests need a server and a browser, and
Ryan decides when to spend that.

The scripts under `scripts/` exist because these are mechanical rules, and a
mechanical rule belongs in a check rather than in a document someone has to
remember. Each prints the file and the line it is unhappy about.

`check-migrations.sh` enforces: the filename pattern; `MigrationHelper::table()`
rather than a bare `Schema::create()` (the helper is what refuses any schema but
`agora`); foreign keys only in a `v1__95_{module}_foreign_keys.php`; no `enum`
columns; no reference to `migrate:fresh`; and no schema builder or DDL aimed at
`dbo`. That last one strips comments first — several migrations legitimately
*mention* the legacy table they were copied from, and the rule is about what the
migration does, not which words it contains.

`check-procs.sh` enforces: `CREATE OR ALTER` (so re-deploying is safe), the
`agora.` qualification, one procedure per file (T-SQL needs each in its own
batch), that some migration in the module actually deploys the directory, and
that a procedure which writes sets `XACT_ABORT ON`.

**Both were tested against deliberately broken files**, not just against passing
ones. A check that has never failed is not known to work — the `dbo` rule was
originally written with a malformed bracket expression and passed every file
vacuously until it was pointed at a file that should have failed.
