# pm_tasks — work that needs no database

For an agent working this repo from GitHub, with **no SQL Server and no
connection to the customer's instance**. Everything below can be built, checked
and reviewed without one.

Ryan reconciles this file into the Ceratine project manager afterwards. If you
close something here, say so in the commit message and tick it below — do not
try to reach the project-manager API, and do not invent task ids.

---

## Read these first, in this order

| # | File | Why |
|---|---|---|
| 1 | `docs/rules.md` | The rules. The first section is the one that matters. |
| 2 | `docs/feature-rules.md` | What every screen owes — grids, forms, views. Read before scoping. |
| 3 | `docs/development.md` | How the work goes, and a feature end to end. |
| 4 | `docs/components.md` | What exists to build with. |
| 5 | *Playing back what you did*, below | How your work reaches the project manager. Read before you finish anything. |

---

## ⚠️ Gotchas — read every one before you touch anything

### The database

1. **`php artisan test` does not fail without a database. It HANGS.** There is
   no sqlite fallback and that is deliberate — `phpunit.xml` says so at length.
   With no reachable server the sqlsrv driver sits there retrying until
   something kills it. **Do not run the suite.** Use `composer check-fast`.
2. **Do not "fix" this by adding a sqlite connection to `phpunit.xml`.** A test
   that passes against sqlite proves nothing about a T-SQL schema and cannot run
   a stored procedure at all, which is where every business rule lives. This is
   the single most damaging change you could make here, and it will look
   helpful.
3. **Never run `migrate`, `migrate:fresh`, `db:seed` or `seed:master`.** No
   database, and `migrate:fresh` is forbidden against a real one, always.
4. **Do not edit `.env`.** It is not in the repo, and the values you would be
   guessing at reach a live customer system.
5. Agora writes only to its **own** database. `PumpIT`, `MIST_Import` and
   `Alteryx` are the customer's and are **read-only, forever**, including in
   anything you write.

### What to run instead

First, in a fresh clone — nothing below works without these, and `.env` is not
in the repository:

```bash
composer install
npm install
cp .env.example .env && php artisan key:generate
```

Leave the connection blocks in `.env` empty. You have no database and the
values would be guesses at a live customer system. The commands below do not
need them.

```bash
composer check-fast              # pint + check-migrations + check-procs — no DB needed
scripts/check-pm-response.sh     # your pm_response.md entry will replay cleanly
vendor/bin/pint                  # fix formatting
vendor/bin/phpstan analyse Modules/Core --memory-limit=512M   # scope it, do not sweep the tree
npm run build
php artisan route:list           # works without a DB
```

All of the above were run with every connection pointed at a dead port to
confirm they pass with no database. `php artisan test` was the only casualty.

### The code

6. **Never add a route to `routes/web.php`.** It loads before every module and
   silently wins. Module routes go in `Modules/{M}/Routes/web.php`, which is
   registered under `/app` with an `app.` name prefix — do not repeat the prefix
   in the file.
7. **Never hand-write a navigation link in a blade file.** The menu is database
   driven; a module ships a `MenuSeeder` that calls `MenuService::item()`.
8. **Everything is a component.** No screen writes its own KPI, table or nav
   markup. Check `docs/components.md`; if it is missing, build it and add a row
   to that file.
9. **Columns are PascalCase** (`BranchId`, `CreatedAt`), the key is `Id`, and
   `BranchId` leads every clustered key. Models extend `BaseModel`, which
   applies branch scoping — **do not write `where('BranchId', …)` by hand**.
10. **No database-level foreign keys** inside a create. They live in one
    `v1__95_{module}_foreign_keys.php` per module.
11. **A migration is a create, not a pile of alters.** A change to an existing
    table is a new lettered file (`v1__12a_…`). The conditions under which a
    follow-on may be folded back are in `docs/rules.md`; you almost certainly do not
    meet them.
12. **Business rules go in stored procedures**, called through
    `ProcedureService`. A refusal is `THROW 51000, 'AGORA:{Code}:{message}', 1`.
    You can write and review procedures without a database — you just cannot
    deploy them.
13. **Numbers go through `App\Support\Format` and `resources/js/format.js`, and
    the two must agree character for character.** Never a locale formatter: PHP
    and JavaScript disagree for `en-ZA` on both separators. A missing figure is
    `—`, never `R0.00`.
14. **Both themes, always.** Colours come from the tokens in
    `resources/scss/_tokens.scss`. No hex in a component.

### Committing

15. **Never commit `.env`**, `tests/e2e/.auth`, or anything under
    `/storage`. They are gitignored; keep it that way.
16. Conventional, plain commit messages describing the change and how it was
    verified. Say **"not verified against a database"** where that is the case —
    it is expected here, and pretending otherwise is worse than saying it.
17. **Every finished unit of work gets an entry in `pm_response.md`** before you
    call it done — see *Playing back what you did* below. That file is the only
    way what you did reaches the project manager.

---

## Playing back what you did

You cannot reach the project manager from here, so **record what you did in
`pm_response.md`** and Ryan asks a session with a token to replay it. That
replay creates the tasks, logs the time, files the QA entries, writes the
outcomes and posts the comments — so an entry that is thin becomes a PM record
that is thin, and nobody can reconstruct it later.

### How

* One fenced ```yaml block per **finished unit of work**, appended to
  `pm_response.md`. Not a diary — write the entry when the work is done.
* **Append only.** Never edit or renumber an entry that is already there. Got
  something wrong? Add a new entry that says so.
* Everything outside the yaml blocks is for humans and is ignored by the replay.
* The example block in `pm_response.md` is `entry: 0` — copy its shape and
  delete it when you write your first real one.
* **Run `scripts/check-pm-response.sh` before you commit the entry.** It parses
  the file the way the replay will and refuses a missing `outcome` on a done
  task, a `qa` block with an empty report, zero minutes, a `blocked` with no
  reason, a duplicate entry number and an invented `pm_task_id`. It needs no
  database and no network.

### The fields, and why each one is there

| Field | Required | Why the project manager needs it |
|---|---|---|
| `entry` | yes | Sequential, never reused. It is how a replay says what it has already done. |
| `kind` | yes | `task` for work; `note` for something that is not a unit of work. |
| `title` | yes | Becomes the task title. Write it as the thing you did, not the area you were in. |
| `type` | yes | `feature` `bug` `improvement` `task` `investigation`. Drives which rules the PM enforces. |
| `priority` | yes | `low` `medium` `high` `critical`. |
| `status` | yes | `done` `in_progress` `blocked`. |
| `covers` | yes | Task numbers from **The work** below that this closes. `[]` if none. |
| `minutes` | yes | **A done task is refused without a time entry.** Honest wall-clock, not an estimate of what it should have taken. |
| `outcome` | when done | **A done task is refused without one.** What changed, with paths; how it was verified; what you left behind. |
| `qa` | when done | Refused without it for `feature`/`bug`/`improvement`/`task`. See below. |
| `commits` | yes | sha + message per commit, so the PM record points at the diff. |
| `files` | yes | path + one line of *why*. A manifest, not a diff. |
| `comments` | no | Anything worth saying that is not the outcome. `internal: true` keeps it off the customer portal — leave it true unless you are certain. |
| `decisions` | no | Only a real architectural choice, with `title`, `decision`, `rationale`. A naming preference is not a decision. |
| `follow_ups` | no | Becomes an inbox item for Ryan to triage. Use it for what you noticed and did not do. |
| `blocked_by` | when blocked | One sentence: what stopped you, what you tried, what would unblock it. |

### The QA entry is evidence, not a claim

`qa.report` carries **the commands you ran and what they printed**. Pasted
output, not a description of it. "Tested and working" is worth nothing to
whoever reads it in three months.

**Set `verified_without_database: true` and say so in the report.** It is
expected here and it is not a failing — but a QA entry that quietly implies a
full verification is worse than one that admits its limits. `composer
check-fast`, `npm run build` and `php artisan view:cache` are the evidence
available to you; the suite is not.

If something did not work, `status: failed` with the real error. A failed QA
entry that leads to a fix is a good record. A passed one that was not true is
the thing that costs a day later.

### What not to do

* **Do not invent project-manager task ids.** Leave `pm_task_id` out entirely
  unless Ryan gave you one; the replay creates the task.
* **Do not try to reach the project-manager API.** There is no token here, and
  a 401 loop is not a task.
* **Do not squash a day into one entry** because it is tidier. One unit of work,
  one entry — that is what makes the time and the QA mean anything.
* **Do not write an entry for work you did not finish** unless `status` says so.

---

## The work

Ordered so nothing later is blocked by something earlier. Each task says how to
prove it without a database.

### 1 · Component library v1 — the gallery and the missing components

**This is the biggest unblocker in the repo.** Plan task T013, marked critical.
Until it lands, every screen from T011 onward hand-writes markup the rulebook
forbids.

`docs/components.md` lists nine shipped components and a "Not built yet"
section. Build them, each rendering in both themes, each fed by props only —
**a component never queries the database**, which is exactly why this is doable
here.

Wanted: `page-head` (exists), `kpi`, `card`, `chip`, `delta`, `note`,
`empty-state`, `tabs`, `params`/`runbar`, `statstrip`, `checklist`,
`decision list`, `exception list`, `proposal`, `lib-card`.

* Add each to the gallery at `resources/views/dev/styleguide.blade.php`
  (`/dev/theme`, local and testing only).
* Add a row per component to `docs/components.md`. A component that is not in
  that file does not exist.
* **Prove it:** `npm run build`, `composer check-fast`, and the gallery blade
  compiles (`php artisan view:cache`).

### 2 · The grid component — markup, behaviour, and the parts that need no data

`docs/feature-rules.md` §3 is the contract, and it is long. Build against a
props contract — the grid takes rows, columns and a procedure name; it does not
fetch.

Doable with no database:

* Typed inline header filters (§3.1) — text, number with comparators
  (`>`, `>=`, `<`, `<=`, `=`, `!=`), date range, and the capped set filter.
* The right-hand detail panel on row click (§3.5) — record info, an action panel
  for edit/view/delete, then the detail.
* Column re-order and drag-to-resize (§3.6), plus text size. **Read the ZP
  lessons in §3.6 before starting** — whitelisting, width bounds, storing NULL
  rather than `{}` on reset, fixed table layout, and the re-shown column that
  collapses to zero.
* Click-through on titles and reference values (§3.7).
* Loading, empty and error states.
* "Showing 50 of 12,480."

Leave for later (needs data): the persistence round trip to
`agora.UserGridColumn`, and export.

* **Prove it:** the gallery renders a grid with fixture rows in both themes, at
  desktop and 375 px.

### 3 · The branches selector component

`docs/feature-rules.md` §3.3 — every head-office result set carries it. One,
several or all branches; options arrive as a prop, so it is buildable here. It
does not appear in the branch workspace, where `BranchContext` has already
pinned the site.

### 4 · Grid procedure template + the checksum question

`docs/feature-rules.md` §2 carries the template signature. Write it as a real
`.sql` file for one module with the header comment the customer will read, and
its `v1__NNp_…_procs.php`. `scripts/check-procs.sh` validates it **without a
database** — that is the whole proof for this task.

Do **not** implement the procedure-ownership checksum (Proposed §A) — Ryan has
not chosen between the three options yet.

### 5 · Format parity and the number rules

`App\Support\Format` and `resources/js/format.js` are a matched pair.
`tests/e2e/format.spec.js` fails if any row disagrees — it needs a browser but
not a database, so it may be runnable depending on the environment. If it is
not, at minimum read both files side by side and confirm every case in
`docs/components.md` → Numbers agrees.

### 6 · Mobile pass on the shell

Plan task T016. `resources/views/layouts/mobile.blade.php`, bottom nav, top bar
with branch and date, no mega menus. No horizontal scroll at 375 px. All markup
and CSS; no data required.

### 7 · Documentation follow-ups

* `docs/components.md` gains a row per component built in task 1.
* `docs/feature-rules.md` §G says the mechanical rules should become checks.
  Candidates that need no database: a grep for `class="kpi"` outside a
  component; a check that a module with a `Routes/web.php` has a `MenuSeeder`; a
  check that no blade hand-writes a nav link.

---

## Do NOT start these

They need a database, a browser against a live app, or a decision Ryan has not
made.

| Not now | Why |
|---|---|
| T007 Users, authentication, role landing | Needs the database; it is next when Ryan is back |
| T008 RBAC | Blocked on the permission slug shape — see below |
| Anything writing to `agora.UserGridColumn` | Needs a database to prove the round trip |
| Grid CSV export | Needs the grid procedure and real row counts |
| The procedure-ownership checksum | Ryan is choosing between three options |
| Deleting anything in `PumpIT.agora` | Ryan's call, and it holds real seeded rows |

---

## Open questions Ryan is holding

Do not guess at these; the answers change the shape of the work.

1. **Procedure ownership after go-live.** Grid procedures are deployed with
   `CREATE OR ALTER` from migrations, *and* the customer is meant to edit them
   directly — the next deploy silently reverts their work. Options: ours /
   seeded once / checksummed-and-skipped.
2. **Where filtering happens.** Typed header filters and a 100 000-row ceiling
   are in tension if the grid filters client-side. Proposed: the procedure
   filters, sorts and pages; the header sends parameters.
3. **Permission slug shape.** Proposed `{module}.{resource}.{action}`. T008
   cannot start until this is fixed.
4. **`PumpIT.agora`** — the ten tables and their seeded rows from before Agora
   had its own database. Untouched, and Ryan decides.

---

## State of the repo at this commit

* Core is one create migration (`v1__01_core_tables.php`) plus one procedure
  deploy. Ten tables, one view.
* Agora runs on **its own database**; `pumpit`, `mist_import` and `alteryx` are
  read-only sources. Locally that is `scripts/local-sql.sh up` (Docker), which
  you will not have.
* Seeds run through `agora.SeedMaster`, gated on the class name like migrations.
  A seeder declares one thing: `public int $seedOrder`.
* 47 tests pass against a real database. **They cannot run here.**
