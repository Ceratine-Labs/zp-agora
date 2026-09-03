# Agora — Architecture & Build Plan (v1)

**Project:** Agora — *All Group Operations, Reconciliation & Analysis* — the Laravel 13 / T-SQL successor to PumpIT for Zululand Retail & Petroleum (Zulu Petroleum, "ZP").
**Prepared:** 3 September 2026, for Ryan (lead developer) → Opus (task creation on the Ceratine project manager) → the build swarm.
**Companion files:** `agora-tasks.json` (the same milestones / epics / tasks as machine-readable records for the PM API), `mockup-inventory.md` (what the Agora mockup contains, screen by screen).
**Target UI:** `agoraretailconsole.html` — the Agora retail console concept build. Every screen in it is accounted for in §5 and §6.

---

## 0. How to use this document

This is two things at once: the **architectural listing** (§1–§4: the rules every task obeys) and the **ordered task plan** (§6: what to build, in what order, with dependencies and acceptance criteria).

**For Opus creating the PM structure** (project manager API in `~/Development/ZP/Zulu Petroleum/CERATINE_API_INSTRUCTIONS.md`; a new PM project for Agora is assumed — see §8 decision D-01):

1. Create the **three milestones** in §6 (M1 → M3) and the **epics** under each (`epic_id` nested via `milestone_id`).
2. Create every task in §6 as `type=feature|task|improvement` with the `priority` given, `llm_model` as given (default `opus`), `epic_id` set, and `sort_order` = the task number (T001 = 1 …) so the today-queue follows build order.
3. Put the task's *Deliverables* and *Acceptance* text into the task `description`; put the *Notes for the builder* into `instructions`; put `depends_on` as the first line of `description` ("Blocked by: T003, T004") — the PM has no dependency field, so the sort order and this line carry the ordering.
4. Do not create subtasks up front; the builder decomposes each task on pickup (investigation-first ritual). Where a task lists *Suggested subtasks*, those are hints, not records.
5. The **Architecture rules (§3)** should be filed as a KB document on the project, and every task description should end with "Conventions: see Agora KB → Architecture rules §3.x" so a fresh session lands on the rules.
6. `agora-tasks.json` carries exactly the same records with the API field names already mapped; prefer it as the source when scripting the creation.

**For a builder picking up a task:** read §1 (decisions), §3 (rules), the task's epic intro, then the task. Verify against the real PumpIT database before declaring anything done (the ZP-NQL rulebook's "check the DB means run the query" applies unchanged).

---

## 1. Decisions already taken (Ryan, 3 Sep 2026)

| # | Decision | Consequence |
|---|---|---|
| 1 | **Agora points at the PumpIT database and evolves it in place.** | PumpIT (SQL Server, `105.247.172.179`, 170 GB, compat level 130, `Latin1_General_CI_AS`) is the ERP's main database and stays so. Agora's versioned migrations add its own schema (`agora`), tables and procedures *into* PumpIT. Legacy `dbo` tables (`BRN_*`, `STK_*`, `RCN_*`, `SS_*`, `MIST_Trans_*`, `DBF_*`) are reused, wrapped in views, and refactored table-by-table as each screen is rebuilt. Nothing in `dbo` is dropped in v1. |
| 2 | **MIST_Import stays the landing zone for every POS family** (WINBRANCH, ARCH, AURA, PILOT, NAMOS, GAAP — and PumpIT's own data). | Agora *reads* MIST_Import cross-database; it does not replace the importers in v1. Rebuilding the importers under Agora ownership is a late, optional epic (E19). Agora does own the **monitoring** of the loads from day one (E14). |
| 3 | **Laravel 13, HMVC modules, T-SQL stored procedures carry the business logic.** | Controllers and services orchestrate; the rules, the numbers and the writes live in `agora.usp_*` procedures deployed by migrations. PHP never re-implements a rule a proc owns. |
| 4 | **`BranchId` is the leading index on every table.** | Every new table carries `BranchId INT NOT NULL` first in its clustered key; every proc takes `@BranchId` first; every query is branch-scoped by a global scope. Legacy `SSBranchId` is the same id space (`SS_Branch.SSBranchId = MIST_BranchId`, verified 27 Aug 2026) and is exposed as `BranchId` through views — see §3.3. |
| 5 | **Front end: Bootstrap 5 under the Agora token theme, vanilla JS, ApexCharts, TomSelect, SweetAlert2, Vite.** | Same toolchain as Ceratine and ZP-NQL, so components port both ways. No React/Vue/Alpine/jQuery. The mockup's light/dark tokens, fonts and component vocabulary become the design system. |
| 6 | **Mobile-friendly from the start, with mobile detection and custom mobile views** where the desktop screen cannot be represented. | `is_mobile()` + view resolver in the base (T016); every capture screen ships a mobile blade (E18); "clean over capable". |
| 7 | **Migrations iterate by version; seeds run through a seed master; everything is a component; static HTML is avoided.** | §3.3, §3.5, §3.8. |
| 8 | **RBAC is role → permission**, email + email templates carry a **module tag**. | §3.6, §3.7. |

---

## 2. The target system (from the mockup)

**Brand.** *Agora* — "All Group Operations, Reconciliation & Analysis" — owner Zululand Retail & Petroleum; was PumpIT. Tagline: *the whole estate, in one market square*. The system's job is the metronomoi's: the dip must tie to the pump, the Z-read to the cashup, the declaration to the bank.

**Estate.** 31 branches in `SS_Branch`: 25 trading/mothballed sites (Engen, Total, Caltex, OK, Wimpy, Baobab formats — Highway One Stop, Service Station, Rural/Urban/Township formats, OK Grocer/Liquor, QSR standalone, Hospitality) plus 6 administrative entities (AJLG Properties, Zululand Petroleum, Arcum Venandi, Jakarie Vulstasie, Thokozile Trust, Zickyza Properties). Profit centres: ULP 95, ULP 93, Diesel 50/500/10, Conv Shop, Lubes, Virtual Sales, Maxis, LRP, Discount, OK Shop, OK Liquor, Wimpy, Steers, Debonairs, Barcelos, Hospitality, EXPENSE_INVOICE. Financial year July–June (FY2027 = Jul-26 → Jun-27).

**Information architecture — four sections, not the legacy six menus.**

| Section | Blurb | Legacy equivalent |
|---|---|---|
| **Today** | The work that has to happen before this day can be closed. | Branch Functions (capture screens), Import POS files |
| **Trade** | How the group, a division and a site are actually trading. | Reporter, Exco pack |
| **Control** | Everything that does not reconcile, and who owns it. | Head Office Functions (banking, recon, deductions) |
| **Setup** | The rules the rest of the system obeys. | Standard Masters, System Masters, Business Tools |

**Two workspaces** — *Head Office* (all sites; scope bar Region / Brand / Format / Period) and *Branch* (one site; scope bar adds Branch). **Five roles** with their own landing page: Executive (→ Exco pack), Finance (→ Control), Operations (→ Trading position), Branch manager (→ Today at my branch), Auditor (→ Control, read-only).

**Eleven design principles the mockup states, which the build treats as requirements:** organise by the day not the module; the home page is a work queue not a menu; the system proposes and the person confirms (Z-read allocation, bank matching, expense coding, variance reasons — with evidence and a confidence, auto-accepting above a threshold the business sets); close the day, do not fill in forms (one guided flow, live status, a reason kept for every exception); an exception is an object with a life (raised → owned → actioned → cleared, with age, escalation and audit); one record, many views (one stock count, saved views — not 16 reports); scope is a parameter not a report (one report, scope param, saved default per role — 97 reports replace 161); every number carries its provenance (click a figure → source load, corrections); write once, flow everywhere (a till short reaches cashup, deduction schedule and payroll file from one capture); approval by band and delegate with escalation; search beats navigation (Ctrl-K, deep links, recents); name things after the question they answer.

**Global chrome.** App bar (wordmark, workspace switch, primary nav with mega menus, Ctrl-K search, exceptions bell with badge, theme toggle, avatar), breadcrumb + scope bar with "as at", main, footer (DB / user / version), tooltip, extract drawer (CSV / copy), command palette. Fonts: Barlow Condensed (display), IBM Plex Sans (text), IBM Plex Mono (codes). Colour tokens: paper/surface/ink/line, brand teal, five series colours s1–s5, good/warn/serious/crit with bg + ink variants, chrome navy; complete dark palette.

---

## 3. Architecture rules (the listing every task obeys)

### 3.1 Runtime and stack

- **PHP 8.3, Laravel 13.x**, `sqlsrv` + `pdo_sqlsrv` + msodbcsql18 (already proven on the ZP-NQL live box). App DB connection `pumpit` (SQL Server). Secondary read-only connections `mist_import`, `alteryx`, `fuelsheet` (three-part names cross-database on the same instance; a connection per database so credentials can differ).
- **Queues:** database driver; one `agora-default` queue worker and one `agora-imports` worker (long jobs). **Scheduler:** Laravel scheduler for exception evaluation, day-close reminders, report schedules, load monitoring. **Websockets:** Reverb, optional in v1 (live day-close status is the first consumer).
- **Front end:** Vite, Bootstrap 5.3 (grid, forms, modals, offcanvas, dropdowns) with Bootstrap's own variables remapped to the Agora tokens; vanilla JS ES modules per component; ApexCharts; TomSelect; SweetAlert2 (no native alert/confirm); IntroJS optional for tours. Fonts self-hosted under `public/fonts` (no Google Fonts in production).
- **PDF/extract:** CSV and XLSX extract on every grid (PhpSpreadsheet); PDF via dompdf only where a document needs it (v1: none required).
- **Environments:** local (Ubuntu, Docker SQL Server 2019/2022 with a restored PumpIT backup, `./dev.sh`); staging (a PumpIT restore on a ZP-controlled instance — decision D-02); live (the PumpIT instance itself). Deploys follow the ZP ritual: rsync excluding `public/hot`, `php artisan migrate --force`, `seed:master`, `systemctl restart php8.3-fpm`, deployment row logged.

### 3.2 Repository layout (HMVC)

```
Agora/
├── app/                      # framework glue only: Console, Providers, Support, Http/Middleware (shared)
│   └── Support/              # MigrationHelper, ProcedureService, Seeding/, Device/, Grid/, Reports/
├── Modules/
│   ├── Core/                 # branches, users, roles, permissions, menus, settings, audit, seed master, components
│   ├── Masters/              # profit centres, categories & GP bands, areas, expense codes, tills, shifts, fuel types, meters
│   ├── People/               # employees, posts, employee areas
│   ├── Partners/             # suppliers (creditors), account customers (debtors)
│   ├── Product/              # stock master, critical lines, virtual stock items, fuel matrix & prices
│   ├── DayEnd/               # day-end spine, day-close state & checklist, branch console, calendar
│   ├── Fuel/                 # pump readings, fuel input, tank recon, meter checks, price history
│   ├── Cash/                 # Z-reads & allocation, cashups / daily banking legs, till balance, drop safe, staff shorts, deductions
│   ├── Recon/                # bank statements, imports (tender files), reconcile workbench, auto-match
│   ├── Stock/                # stock counts (recon), exceptions, balancing, area locks, pre-production, waste, month end
│   ├── Purchasing/           # purchase requests, approval engine (bands, delegates, escalation)
│   ├── Utilities/            # meters & readings
│   ├── Assets/               # register, movement, repair
│   ├── Exceptions/           # exception register, rules, lifecycle, escalation
│   ├── Imports/              # overnight-load monitoring over MIST_Import + PumpIT logs (later: the importers themselves)
│   ├── Reports/              # report registry, runner, library, saved views, schedules, extract, execution log
│   ├── Trade/                # trading dashboards, league table, PC contribution, site scorecard, budgets
│   ├── Exco/                 # weekly Exco pack (36 sheets) ported from ZP-NQL
│   ├── Email/                # mailer, outbox, email templates with module tag, scheduled sends
│   └── Help/                 # in-app help articles per screen, report descriptions
├── resources/views/{layouts,components,mobile}
├── database/{migrations (framework only), seeders/DatabaseSeeder.php (orchestrator)}
├── scripts/                  # check-migrations.sh, check-seeder-versions.sh, restore-pumpit.sh, deploy/
├── CLAUDE.md · EXTENDED.md   # the project rulebook (this §3, condensed) and recipes
└── docs/                     # architecture, data model, proc catalogue, join notes carried over from ZP-NQL
```

Module skeleton (Ceratine's, kept verbatim so a Ceratine builder is at home): `Config/config.php`, `Database/{Migrations,Seeders,Procedures}`, `Http/{Controllers,Controllers/Api,Middleware,Requests}`, `Models`, `Services`, `Grids`, `Reports`, `Events`, `Listeners`, `Providers/{Module}ServiceProvider.php`, `Routes/{web,api}.php`, `Resources/{views,views/mobile,lang}`, `module.json`. View namespace `module::path`. `php artisan agora:make-module {Name}` scaffolds all of it.

### 3.3 Database conventions

**Schemas.** `dbo` = legacy PumpIT, read and written only through the views and procs below until a table is formally migrated. `agora` = everything Agora creates: tables, views, procedures, functions, types. `cashup` = the customer's 2026 auto-recon pilot family; **not read by Agora** (mid-rebuild in production, known-wrong — see `docs/exco-cash-banking-scope.md`).

**Every `agora` table:**

| Column | Rule |
|---|---|
| `BranchId INT NOT NULL` | First column of the clustered index. FK to `agora.Branch`. Global (non-branch) rows use the group entity's `BranchId` (Zululand Petroleum's id), never NULL — a NULL branch is how legacy rows became unreportable. |
| `Id BIGINT IDENTITY` | Surrogate key. Clustered PK `(BranchId, Id)`. |
| Natural key | `UNIQUE` on the business key **including `BranchId`** — exactly PumpIT's own pattern (`BRN_DailyBanking` = `SSBranchId, TillNo, ShiftNo, TransactionDate`). A key without the branch column is the bug that made lookups fan out 4–22× in PumpIT. |
| `CreatedAt DATETIME2(0)`, `CreatedBy INT`, `UpdatedAt`, `UpdatedBy` | Stamped by the proc, never by PHP. |
| `RowVer ROWVERSION` | Optimistic concurrency on every editable row. |
| `DeletedAt`, `DeletedBy` | Only on tables whose model soft-deletes (masters). Transactions are never deleted, they are reversed. |
| Naming | PascalCase tables and columns, matching the estate (`agora.StaffShort`, `TransactionDate`). Laravel models map `CREATED_AT`/`UPDATED_AT` constants via `BaseModel`. Singular table names. No `enum` columns — small reference tables or `TINYINT` + check constraint. No `NVARCHAR(MAX)` where a length is known; JSON payloads in `NVARCHAR(MAX)` with `ISJSON()` check. |
| Money / litres | `DECIMAL(18,2)` money, `DECIMAL(18,3)` litres, `DECIMAL(9,4)` c/ℓ and percentages. Never float. |
| Foreign keys | Enforced, `ON DELETE NO ACTION`, in one `v1__95_{module}_foreign_keys.php` per module (Ceratine's rule: never inside `Schema::create`, because the reference graph has cycles). |

**Legacy tables.** Each is reached through an `agora.vw_{Name}` view that (a) aliases `SSBranchId AS BranchId`, (b) hides `_OLD` / `_DEFUNCT` / `PREPROD_` / `STUBBER_` twins, (c) brackets reserved-word columns (`BRN_Pump.[Order]`, the FoxPro `DESC` columns), and (d) applies the dedupe rule the join map recorded (e.g. `ARCH_vw_dwh_products WHERE baseitem = 1`). Eloquent models for legacy data point at the view; writes go through procs only. When a screen is rebuilt, its table is either **adopted** (kept in `dbo`, view retained, proc-owned writes) or **migrated** (new `agora` table + one-off `agora.usp_Migrate_{Name}` + the view repointed) — the task says which.

**Migrations iterate by version.**

- One base file per module: `Modules/{M}/Database/Migrations/v1__NN_{module}_tables.php`, `NN` = the module's slot in domain order (Core 01, Masters 02, People 03, Partners 04, Product 05, DayEnd 10, Fuel 11, Cash 12, Recon 13, Stock 14, Purchasing 15, Utilities 16, Assets 17, Exceptions 20, Imports 21, Reports 22, Trade 23, Exco 24, Email 30, Help 31; 95 = foreign keys).
- Feature sets after the base land as lettered files (`v1__12a_cash_drop_safe.php`); a fix that must reach an existing database is always a new lettered file, never an edit to a create (there is no fresh-install luxury here — PumpIT is production from day one).
- **Procedures are deployed by migrations too**: `v1__NNp_{module}_procs.php` runs every `CREATE OR ALTER PROCEDURE` in `Modules/{M}/Database/Procedures/*.sql` (one file per proc, idempotent, so re-running is safe and `git diff` shows the change). `down()` drops nothing on live; it is documented as a no-op.
- Product version starts at **v1.0**; schema changes after the baseline bump the minor (`v1.1`, `v1.2` …) recorded in `agora.SchemaVersion` by the migration that introduces them. Major bumps are reserved for breaking rewrites.
- `scripts/check-migrations.sh` (composer check stage): filename pattern, no `Schema::table` on another file's table, no `enum`, no duplicate creates, every new table has `BranchId` first, every proc file has a matching migration.
- **Never `migrate:fresh` against anything but the local Docker restore.** Live and staging are forward-only.

### 3.4 T-SQL procedure layer

- **Naming:** `agora.usp_{Module}_{Verb}{Object}` — `usp_Cash_ProposeZreadAllocation`, `usp_Cash_CommitZreadAllocation`, `usp_Recon_ProcessBatch`, `usp_DayEnd_GetCloseStatus`, `usp_Report_DayCloseStatus`. Reports are `usp_Report_{Code}`; exception rules are `usp_Exception_{Domain}`; migrations of legacy data are `usp_Migrate_{Table}`.
- **Signature:** `@BranchId INT` first (0 = all branches only where the proc is documented as estate-wide), `@UserId INT` on every writer, dates as `DATE`/`DATETIME2`, line sets as JSON `NVARCHAR(MAX)` read with `OPENJSON`, `@AsOf DATETIME2 = NULL` on readers that support "as at".
- **Body:** `SET NOCOUNT ON; SET XACT_ABORT ON;` — writers wrap in `BEGIN TRAN … COMMIT` inside `BEGIN TRY / CATCH` and `THROW` with a structured message (`51000, 'AGORA:{Code}:{human message}'`) that PHP maps to a validation error. No dynamic SQL. No cursors where a set will do. Stamp `CreatedBy/UpdatedBy` from `@UserId`. Write the audit row inside the same transaction (`agora.usp_Audit_Write`).
- **Results:** readers return one or more result sets with stable column names (the grid definition binds to them); writers return one row `(Ok BIT, Code NVARCHAR(40), Message NVARCHAR(400), Id BIGINT)`.
- **Proposals:** anything that "proposes" returns `(…, Confidence NVARCHAR(10) — certain|likely|review, Evidence NVARCHAR(400))` and reads the auto-accept threshold from `agora.Setting`.
- **Legacy procs** (`dbo.sp_*`, 190-plus of them) are documentation for the intended join and the intended rule; they are never called by Agora, and the useful ones are ported into `agora.usp_*` with the proc file header citing the original. The ZP-NQL `docs/relationships/pumpit-joins.json` rejected-paths list is law: never join a per-branch lookup on its bare entity column.
- **PHP side:** `App\Support\ProcedureService::call('agora.usp_X', [...])` (prepared statement, named params, result sets → collections, structured THROW → `AgoraProcException`) and `ProcedureService::write(...)` (same, in a DB transaction that the proc joins). Services call procs; controllers call services; Blade renders components. Eloquent is used for **reads** and for **simple master CRUD** (masters still stamp audit via the `Auditable` trait); every transactional write and every business rule is a proc. If a rule exists in both places, the proc is right and the PHP is a bug.

### 3.5 Seed master

Ceratine's ledger, unchanged in shape: `agora.SeedMaster (Module, Seeder, Version, Batch, RecordsCreated/Updated/Skipped, Status, ErrorMessage, ExecutedAt, CompletedAt, DurationMs, IsDemoData)`. `SeedMaster::seed($module, $version, fn() => [...], $seederName)` runs a callback at most once per `(Module, Seeder, Version)`; `seedDemo()` versions per company/day. `DatabaseSeeder` is the only orchestrator: phases **system** (permissions, roles, settings, menus, reference lists, email templates, report registry) → **reference data from PumpIT** (branch, profit centre, category, area, expense masters read from the legacy tables — idempotent upserts) → **demo** (only when `app.demo` is true; a `Demo` company that mirrors a real branch shape). ZP-NQL's `seed:master` refinement is adopted on top: auto-discovered catalogue, risk classes (`safe · review · clobbers · destructive`), version = fingerprint of the seeder payload, `--status / --allow / --forget`, and **only `safe` seeders ever run unattended in a deploy**. A commit that adds a permission slug must bump that seeder's version (`scripts/check-seeder-versions.sh`).

### 3.6 Identity and RBAC

- `agora.User` (migrated from `SS_Users`, 85 rows; password reset on first login), `agora.Role`, `agora.Permission` (slug `module.action`: `cash.allocate_zread`, `recon.process_batch`, `reports.run`, `setup.manage_users`, `*.view`), `agora.RolePermission`, `agora.UserRole`, `agora.UserBranch` (from `SS_UserBranches`, 1,063 rows), `agora.UserPreference` (theme, default scope, saved column layouts, landing page).
- `PermissionService::userHas/Any/All`, route middleware `can:slug`, Blade `@can`. Wildcards allowed in role seeders (`cash.*`). Auditor role is a role whose permissions are all `*.view` plus `audit.*` — read-only is a permission set, not a flag.
- Branch scoping: `BranchContext` (current workspace HO/Branch, current branch, scope filters) resolved per request; `BelongsToBranch` global scope on every branch model; a user sees only `UserBranch` rows; HO roles carry the "all branches" grant. Procs still receive `@BranchId` explicitly — the scope is enforced twice.
- Legacy `BRN_ZUserRights` (3,334 rows) is read once by the migration seeder to propose role membership, then retired.

### 3.7 Email and templates

- Mailer + queue + `agora.EmailLog` (to, subject, template code, module tag, status, error, sent at, related entity) and `agora.EmailOutbox` for retries; attachments (CSV/XLSX extracts).
- `agora.EmailTemplate (BranchId — group entity for global, ModuleTag NVARCHAR(40), Code NVARCHAR(80) UNIQUE per branch, Subject, BodyHtml, BodyText, VariablesJson, IsActive, Version)`. **Code = `{module}.{event}`** and `ModuleTag` = the module — `purchasing.pr_submitted`, `purchasing.pr_approved`, `purchasing.pr_escalated`, `cash.staff_short_raised`, `dayend.close_reminder`, `exceptions.escalation`, `reports.scheduled`, `core.password_reset`. Each module ships its templates in its seeder; the template admin filters by module tag; variables come from a per-module `VariableRegistry` (`{{branch.name}}`, `{{pr.number}}`, `{{pr.amount}}`) and are validated at save. A branch-level override of a global template is a row with the branch's id and the same code.
- Sending is always `EmailService::send('purchasing.pr_approved', $target, $vars)` — never an inline Mailable with hard-coded copy.

### 3.8 Theme and component library

**Tokens** (from the mockup, verbatim): `--paper --surface --surface-2 --surface-3 --ink --ink-2 --muted --line --line-soft --brand --brand-soft --brand-ink --s1..--s5 --good/--warn/--serious/--crit (+ -bg, -ink) --grid --axis --shadow --shadow-pop --chrome --chrome-2 --chrome-3 --chrome-ink --chrome-muted --chrome-line --sand --r`. Light on `:root`, dark under `prefers-color-scheme: dark` guarded by `:root:not([data-theme="light"])`, and again under `:root[data-theme="dark"]`. Bootstrap's `--bs-*` variables are remapped to these tokens in one `theme/bootstrap-bridge.scss`; no Bootstrap colour utility is used directly in a view.

**Everything is a component.** No screen writes its own KPI markup, table markup or nav markup. Blade components under `resources/views/components` (shared) and `Modules/{M}/Resources/views/components` (module-specific), each with a JS module in `resources/js/components/{name}.js` where behaviour is needed, and a page in the component gallery (`/dev/components`, dev-only) showing every variant and prop.

| Component | Props (v1) | Mockup origin |
|---|---|---|
| `<x-app-shell>` / `<x-app-bar>` / `<x-mega-menu>` / `<x-workspace-switch>` / `<x-scope-bar>` / `<x-crumb>` | workspace, sections, badge providers | appbar, mega, ctx, crumb |
| `<x-page-head>` | eyebrow, title, blurb, actions slot | page-head |
| `<x-kpi>` + `<x-kpi-strip>` | label, value, compare, tone, stripe, sparkline data, href | kpi / kpis |
| `<x-card>` (head, sub, body, flush) | title, sub, actions | card |
| `<x-chip>` | tone good/warn/serious/crit/neutral, text | chip |
| `<x-delta>` | value, invert, suffix, dp | delta |
| `<x-note>`, `<x-empty-state>`, `<x-eyebrow>` | text/icon | note, emptystate |
| `<x-tabs>` | items, active, persist key | tabs |
| `<x-params>` + `<x-runbar>` | filter definition (branch, date, range, select, number), Execute/Extract/status | params, runbar |
| `<x-statstrip>` | stats[] | statstrip |
| `<x-data-grid>` + `GridDefinition` | grid key, rows, cells partial, actions, expand, bulk, sort, limit, extract, footer | dataGrid (sortable, numeric, wide, row click, limit note) |
| `<x-chart>` | type daily-bars / line / donut / bridge / diverging / sparkline / mini-bar, series, tokens | chartDaily, chartMargin, chartMix, chartBridge, chartLines, chartDiverge, sparkline, miniBar |
| `<x-drawer>` | title, note, body, copy | drawer (extract) |
| `<x-palette>` | search registrar | pal |
| `<x-tip>` | html | tip |
| `<x-checklist>` | steps[] (title, detail, done, href) | day-close steps |
| `<x-exception-list>` / `<x-exception-row>` | items with sev/cat/site/age/value | ex / exlist |
| `<x-decision-list>` | items with who/what/amount/href | "needs a decision" |
| `<x-two-pane-recon>` | left/right rows, selection, totals | recon workbench |
| `<x-proposal>` | confidence, evidence, accept/override | Z-read allocation |
| `<x-role-card>`, `<x-system-state>` | | signin |
| `<x-lib-card>` | report name, desc, scope, was[], tag | libcard |
| `<x-sqlbox>` (dev/audit only) | proc, params | sqlbox |

Rules: components own markup and behaviour, pages own composition; a component never queries the database (data arrives as props from the controller/service); every component renders in both themes and has a mobile mode or a documented mobile replacement.

### 3.9 Mobile

- Detection: `App\Support\Device\DeviceDetector` — `Sec-CH-UA-Mobile` client hint first, UA fallback (`mobiledetect/mobiledetectlib`), overridable by a user preference ("Desktop site" / "Mobile site") stored in `UserPreference` and a `?device=` query for testing. `DeviceContext` middleware sets `request()->device()` (`mobile | tablet | desktop`) and the `is_mobile()` helper.
- Resolution: controllers return `view(pick('cash::zread.index'))`; `pick()` returns `cash::mobile.zread.index` when the device is mobile and that blade exists, else the desktop blade. Shared components handle their own mobile mode (`<x-data-grid>` becomes a card list of the grid's first three columns + expand; `<x-params>` becomes a bottom sheet; `<x-kpi-strip>` scrolls horizontally; mega menus become a bottom nav of the four sections + branch picker).
- "Clean over capable": on mobile drop drag/drop, hover tooltips, wide grids, two-pane workbenches (recon becomes a single list with a match sheet); every capture screen (pump readings, fuel input, drop safe, waste, utilities, staff short, PR raise, stock count, Z-read confirm) gets a purpose-built mobile blade in E18, and the branch console is mobile-first.
- Layout: `resources/views/layouts/mobile.blade.php` (bottom nav, top bar with branch + date, no mega menus); viewport tested at 375×812 and 768×1024 in Playwright with the mobile preset.

### 3.10 Navigation

Database-driven, like Ceratine: `agora.MenuSidebar (workspace)`, `agora.MenuSection (Today/Trade/Control/Setup, plus the branch workspace's Today/Stock/My site/Assets)`, `agora.MenuItem (section, column heading, label, route, params, permission, badge provider class, sort, mobile flag)`. `MenuService::seedSidebar / seedSection / seed(item)` API; each module ships a `MenuSeeder`. Badge providers return the counts the mockup shows next to items (`Exception register · 12`, `Purchase approvals · 607`). The command palette's registrar indexes menu items, report definitions (with their legacy "was" names), branches, masters, suppliers, products. Every screen has a canonical URL that carries its scope (`/cash/zread?branch=8&from=2026-08-21`), so a WhatsApp message can carry a screen.

### 3.11 Reporting engine

`agora.ReportDefinition (Code, Name, Description, Category, ScopeJson — which of branch/region/brand/format/date/range/month/fy/threshold/source it takes —, ProcName, ColumnsJson, DefaultSort, Tags: queue|new|merged|renamed, LegacyNamesJson, Permission)`, `agora.ReportSavedView (User, Report, ParamsJson, ColumnsJson, IsDefaultForRole)`, `agora.ReportSchedule`, `agora.ReportExecutionLog`. One runner: parameters form from `ScopeJson` → `usp_Report_{Code}` → `<x-data-grid>` with extract → execution logged. The library page is the 14 categories × 97 reports of the mockup; the legacy catalogue (`SS_Report`, 906 rows incl. history; 161 live) is kept as a read-only reference with a "now called" link. A report that is a **queue** (`Unallocated Z-reads`, `Open cashups`, `Deposits with no home`, `Out of stock now`, `Load errors` …) is the same definition with `Tags=queue` and a row action that opens the working screen filtered.

### 3.12 Exception engine

`agora.Exception (BranchId, Category — Fuel|Stock|Gross Profit|Banking|Buying|Pricing|Masters|Loads —, Severity critical|serious|warning|good, Title, Detail, Value, Unit, Fingerprint UNIQUE, SourceReport, SourceParamsJson, OwnerRole, OwnerUserId, Status raised|owned|actioned|cleared, RaisedAt, OwnedAt, ActionedAt, ClearedAt, EscalatedAt, Age computed)`, `agora.ExceptionRule (Code, Domain, ProcName, Schedule, Severity thresholds, OwnerRole, EscalateAfterDays)`, `agora.ExceptionEvent` (lifecycle audit). `usp_Exception_{Domain}` procs run on the scheduler, emit candidate rows with a fingerprint; the engine upserts (raises new, keeps age on existing, clears the ones that no longer match with a reason "cleared by data"). Owner map from the mockup: Fuel/Stock → Operations, Gross Profit/Pricing → Trading, Banking/Buying → Finance, Masters → IT & Masters. Escalation emails through `exceptions.escalation`.

### 3.13 Imports and loads (monitoring in v1, importers later)

Sources and where they land today: WinBranch DBF (shop POS + stock), ARCH (OK stores), AURA (QSR / franchise), Pilot (forecourt), NAMOS (Total Hluhluwe day-end, `SS_Branch.POSType = 'NAMOS'`), GAAP → `MIST_Import.dbo.*` families; tender feeds ABSA MarkOff, FNB, Smart ATM, Cash devices / cash bags (Deposita), Zapper, Yumbi, Infinity, Fleet Card, direct deposits, bank statements → `PumpIT.dbo.BRN_*` and `RCN_BankStatementLinesPumpIT`. Load evidence lives in `SS_ErrorLog` (972k rows), `SS_ImportFuelInputLog`, `RCN_ReconImports`, `SS_JobLog`, `SS_POSDBFZIP` and the `_DASHBOARD_DailyX_{POS}_{SALES|CUSTOMER|SUPPLIER}` occurrence tables. E14 builds the overnight-load status over those; E19 (optional) moves the parsers under Agora.

### 3.14 Audit

`agora.AuditLog (BranchId, UserId, Action, Entity, EntityId, BeforeJson, AfterJson, At, Ip, Device)` written by procs (`usp_Audit_Write`) and by the `Auditable` trait on masters; `agora.UserActivity` (sign-ins, screens opened, report runs); `agora.ApprovalLog` (every approval, decline, override with who and when). The four Audit-trail reports of the library read these and nothing else.

### 3.15 Testing and quality gates

`composer check` = pint → phpstan (module-scoped) → `check-migrations.sh` → `check-seeder-versions.sh` → `check-procs.sh` (every `.sql` under `Database/Procedures` is referenced by a migration and parses) → phpunit **targeted** (`--filter`) → Playwright **targeted** (spec + project). Ryan's standing rule: targeted runs only, never a full suite unless he asks (solar power). Every task's acceptance names the spec it adds. Feature tests run against the local Docker restore; a `TestBranch` (id 999) is created by the test seeder so nothing touches real branches.

### 3.16 What Agora does not do in v1

No POS. No general ledger (the GL codes on categories and expenses feed the accounting export, as today). No payroll (it produces the deduction file). No importer rewrite (E19 is optional). No changes to the `cashup` schema pilot. No writes to MIST_Import, Alteryx or FuelSheet.

---

## 4. Data model — module by module

Legend: **adopt** = keep the `dbo` table, wrap in `agora.vw_*`, writes via proc · **migrate** = new `agora` table + `usp_Migrate_*` · **new** = has no legacy equivalent.

| Module | Tables (new / adopted / migrated) | Key procs |
|---|---|---|
| Core | `agora.Branch` (**migrate** from `SS_Branch` 31 rows: Name, Region, Brand, Format/Class, Status, POSType, IsAdminEntity, IsActive), `Region`, `Brand`, `Format` (**new**, derived), `User` (**migrate** `SS_Users`), `Role`, `Permission`, `RolePermission`, `UserRole`, `UserBranch` (**migrate** `SS_UserBranches`), `UserPreference`, `Setting` (**migrate** `SS_SysDefaults` + `BRN_Config`), `MenuSidebar/Section/Item`, `AuditLog`, `UserActivity`, `SeedMaster`, `SchemaVersion`, `UniqueNumber` (**migrate** `SS_UniqueNumber`) | `usp_Core_NextNumber`, `usp_Audit_Write`, `usp_Core_UserLanding` |
| Masters | `ProfitCentre` (19), `StandardCategory` (54 → GLs), `CategoryGlCode` (**adopt** `SDK_CategoryGLCode` 815: POS category → PC, supplier GL, sales GL, MinGP/MaxGP, MinCover/MaxCover), `ReconArea` (**adopt** `STK_Area` 239), `ReconCriteria` (`SS_ReconCriteria` 68, `BRN_AutoReconCriteria` 133), `ExpenseCode` (**adopt** `BRN_Expenses` 3,839: GL, VAT, asset flag, approver), `TransactionType` (`BRN_TransactionType` 54), `Till`, `Shift` (3), `FuelType` (`BRN_FuelType`, per branch), `Tank` (`BRN_Tank`), `Pump` (`BRN_Pump` 350), `UtilityMeter` (`BRN_UtilityMeter` 100), `BankAccount` (`BRN_BankAccountSetup`), `MerchantTerminal` (`SS_Branch_ABSA_MerchantNo`, `_SMARTATM_TerminalNo`, `_CASHDEVICE_TerminalNo`) | `usp_Masters_*` CRUD validators |
| People | `Employee` (**adopt** `BRN_Employee` 1,685: Title/Initials/FirstName/Surname/NickName, post, active), `EmployeePost` (12 posts), `EmployeeArea` (`BRN_EmployeeArea`) | |
| Partners | `Supplier` (**adopt** `BRN_Suppliers` 74,822 — stock/cash flags, contact, dept, location), `Customer` (**adopt** `BRN_Customers` 776 — limits, cell) | `usp_Partners_NextSupplierCode` |
| Product | `StockItem` (**adopt** `STK_StockMaster` 7,186 by branch/location: UOM, issue multiple, produce flag, area, price type, factor, POS code, monitored, pre-production type), `CriticalLine` (`STK_StockMasterCritical` 1,284), `VirtualStockItem` (`BRN_POSVirtualStockItems`), `FuelPrice` (**adopt** `BRN_FuelPrice` 61,336: per branch/grade/period cost, sell, margin, fleet), `DoeFuelMatrix` (`BRN_MonthlyDOEFuelPricingMatrix`) | `usp_Product_PriceHistory` |
| DayEnd | `DayEnd` (**adopt** `BRN_DayEnd` 31,837: date, type, number), `EodDate` (`BRN_EODDates`), `DayClose` (**new**: BranchId, TradingDate, Step, Status, Reason, ClosedBy, ClosedAt), `Calendar` (`ZRP_Calendar` 4,018) | `usp_DayEnd_GetCloseStatus`, `usp_DayEnd_Close`, `usp_DayEnd_Reopen`, `usp_DayEnd_EnsureRecord` (port of `sp_CheckAndInsertDayEndRecord`) |
| Fuel | `PumpReading` (**adopt** `BRN_PumpReadings` 321,719: mech/elec per pump per date), `FuelInput` (**adopt** `BRN_FuelInput` 36,816: volume, dip, delivery per grade per date), `FuelInputImportLog` (`SS_ImportFuelInputLog`), reads `MIST_Import WINBRANCH_FC_TRANS` (fills), `DBF_FC_TEOD` (POS EOD volumes), FuelSheet `DailyDeliveryReconciliation` | `usp_Fuel_PrepareReadings` (port `sp_CaptureMechReading`), `usp_Fuel_SaveReadings`, `usp_Fuel_PrepareInput` (port `sp_CaptureFuelInput`), `usp_Fuel_SaveInput`, `usp_Fuel_MeterCheck`, `usp_Fuel_TankRecon`, `usp_Fuel_DaysCover` |
| Cash | `Zread` (source: `MIST_Import DBF_P3TRANS_ZREAD` 141k; **new** `agora.ZreadAllocation`: BranchId, Location, Eod, Till, Shift, EmployeeCode, Confidence, Evidence, AllocatedBy/At), `Cashup` (**adopt** `BRN_DailyBanking` 140,151 header), tender legs (**adopt** the `BRN_DailyBanking{ABSA,FNB,SmartATM,CashBags,Deposita,Customers,Expenses,Suppliers,Infinity,Yumbi,YumbiVoucher,DirectDeposits,FleetCard,Employees}` family — all keyed `SSBranchId, TillNo, ShiftNo, TransactionDate`, `ReconBatchNoPumpIT` is the live recon stamp, plain `ReconBatchNo` is always 0), `TillBalance` (**adopt** `BRN_TillBalance` 18,948), `DropSafe` (**adopt** `BRN_DropSafe` 31,398 + `_Collection`), `StaffShort` (**adopt** `BRN_StaffShorts` 3,791 → **new** `StaffShortReason` codes), `Deduction` (**new**: per employee per shift: cashier short, pump short, leniency, deduction, head) | `usp_Cash_ProposeZreadAllocation`, `usp_Cash_CommitZreadAllocation`, `usp_Cash_GetCashup`, `usp_Cash_SaveCashupLeg`, `usp_Cash_ConfirmCashup`, `usp_Cash_CloseCashup`, `usp_Cash_SaveTillBalance` (port `sp_InsertTillBalance`), `usp_Cash_SaveDropSafe`, `usp_Cash_CollectDropSafe`, `usp_Cash_RaiseStaffShort`, `usp_Cash_ApproveStaffShort`, `usp_Cash_BuildDeductions` (port `sp_DeductionSummaryReport`, `sp_EmployeeCashLossReport`, `sp_EmployeeStockLossReport`, `sp_RPT_EmployeeStockLossReportPAYROLLDETAIL_LeniencySummary`), `usp_Cash_PayrollFile` |
| Recon | `BankStatementLine` (**adopt** `RCN_BankStatementLinesPumpIT` 286,899: Type channel, IDState, ReconState, ReconBatchNo), `ReconImport` (**adopt** `RCN_ReconImports`), `ReconBatch` (**new**), `ReconMatch` (**new**: batch ↔ captured leg ↔ bank line, method auto/manual, confidence), tender raw tables (**adopt** `BRN_ABSAMarkoff` 480,844, `BRN_SmartATM`, `BRN_CashDevices`, `BRN_Infinity`, `BRN_Yumbi`, `BRN_YumbiVoucher`) | `usp_Recon_Identify` (channel classification), `usp_Recon_GetSides`, `usp_Recon_ProposeMatches` (rewrite of `sp_AUTOReconcile_{ABSA,FNB,CashBags,CashMachine,SmartATM}_BankRecon` with the NULL-unsafe compare and the batch-total compare fixed), `usp_Recon_ProcessBatch` (port `sp_GenerateReconBatchNo` + stamping), `usp_Recon_Unmatch`, `usp_Recon_Summary` |
| Stock | `StockRecon` (**adopt** `STK_StockRecon` 250,746 header: branch/date/shift/area), `StockReconLine` (**adopt** `STK_StockReconLine` 6.2M: opening/issued/closing/computer, amended vs original), `StockReconRequest/LineRequest/Employees` (**adopt**), `ReconAreaLock` (**adopt** `RCN_ReconArea` 122,932), `PreProduction*` (**adopt** `PREPROD_Type/Product/Recipe/RawMaterial/StockRecon/StockReconLine`), `Waste` (**new** `agora.Waste` from the capture proc's shape), `MonthEnd` (**new**) | `usp_Stock_PrepareCount` (port `sp_CaptureStockRecon`, `sp_CaptureStockReconRequest`, the `sp_JSON_ProcessStockReconRequestJSON` family), `usp_Stock_SaveCount`, `usp_Stock_LockArea`, `usp_Stock_Balancing`, `usp_Stock_ShiftVariance`, `usp_Stock_CountExceptions`, `usp_Stock_PreProduction`, `usp_Stock_SaveWaste` (port `sp_CaptureWaste`), `usp_Stock_MonthEnd`, `usp_Stock_ClearEod` (port `sp_ClearEODData`, guarded) |
| Purchasing | `PurchaseRequest` (**adopt** `BRN_Transaction` 48,245 / `BRN_TransactionLine` 109,371, TypeCode CA/CR), `ApprovalBand` (**new**: min/max value, approver role/user, delegate, escalate after n days), `ApprovalDelegate` (**new**), `ApprovalLog` (**new**) | `usp_Purchasing_NextNumber` (port `sp_GetNextPurchaseRequestNumber`), `usp_Purchasing_Save`, `usp_Purchasing_Submit`, `usp_Purchasing_Decide`, `usp_Purchasing_Escalate`, `usp_Purchasing_LoadApprovedToBanking` (port `sp_Load_ApprovedPurchaseRequest`) |
| Utilities | `UtilityReading` (**adopt** `BRN_UtilityTransaction` 82,204: open/close/prepaid/usage per meter per date) | `usp_Utilities_PrepareReadings` (port `sp_CaptureUtilityTransaction`), `usp_Utilities_Save`, `usp_Utilities_ZeroUsage` |
| Assets | `Asset` (**adopt** `ASSET_AssetType` 431 + the register tables), `AssetMovement`, `AssetRepair` (**adopt** `ASSET_AssetRepair`, empty), `AssetGroup/Type/Owner` | `usp_Assets_Depreciate`, `usp_Assets_Comebacks` |
| Exceptions | `Exception`, `ExceptionRule`, `ExceptionEvent` (**new**; `BRN_Exceptions` is empty and retired) | `usp_Exception_Upsert`, `usp_Exception_{Fuel,Stock,Margin,Banking,Buying,Pricing,Masters,Loads}` |
| Imports | `LoadRun` (**new**: source, branch, file, rows, status, message, started/finished — projected from `SS_ErrorLog`, `SS_ImportFuelInputLog`, `RCN_ReconImports`, `SS_JobLog`, `SS_POSDBFZIP`, `_DASHBOARD_DailyX_*`) | `usp_Imports_OvernightStatus`, `usp_Imports_LoadErrors` |
| Reports | `ReportDefinition`, `ReportSavedView`, `ReportSchedule` (**migrate** `SS_ReportScheduler` 104), `ReportExecutionLog`, `LegacyReport` (**adopt** `SS_Report` read-only) | `usp_Report_*` (one per definition — 97) |
| Trade | `Budget` (**adopt** `RCN_SalesBudgets` — the natural system of record, currently **empty**; Alteryx_Budget paste covers Jul-25..Jun-26 for 16 of 31 sites), `BudgetLine` (**new**) | `usp_Trade_GroupPosition`, `usp_Trade_League`, `usp_Trade_PcContribution`, `usp_Trade_SiteScorecard`, `usp_Trade_BudgetVsActual` |
| Exco | `ExcoSnapshot` (**new**: cached pack JSON per period) reading Alteryx `zp_exco_fact / zp_exco_fuel_daily / zp_exco_budget`, FuelSheet, PumpIT | ported `ExcoPackService` / `ExcoSourceService` + `usp_Exco_*` where a read moves into SQL |
| Email | `EmailTemplate`, `EmailLog`, `EmailOutbox` (**new**) | |
| Help | `HelpArticle`, `HelpScreenLink` (**new**) | |

**Branch id bridge.** `agora.Branch.BranchId` **is** `SS_Branch.SSBranchId` **is** `MIST_BranchId` (18 of 19 pack sites verified by name; 30 + 31 = "Teds Convenience Centre" + "Wimpy Ladysmith" are carried as one site in the Exco pack only). The `cashup` schema already uses `BranchId`. ARCH data in MIST_Import needs the pair `(MIST_BranchId, branch)` (e.g. id 15 spans branches 2551 and 2552); the ARCH views carry both.

---

## 5. Coverage matrix — mockup screen → module → task

| Mockup route / screen | Module | Task(s) |
|---|---|---|
| `#/signin` role landing + system state | Core | T007, T008 |
| App bar, mega menus, workspace switch, scope bar, crumb, theme toggle | Core / components | T006, T009, T011 |
| Ctrl-K palette | Core | T012 |
| `#/console` Today at branch (7-step day close, KPIs, decisions, open items) | DayEnd | T029, T030 |
| Day close status (HO) | DayEnd | T031 |
| `#/op/pump` Pump reading capture | Fuel | T032 |
| `#/op/fuelinput` Fuel input capture | Fuel | T033 |
| `#/master/fuelmatrix` Fuel matrix + price history | Product / Fuel | T025, T034 |
| Pump meter check, tank reconciliation, pump readings, fills, slow-to-pay, attendant performance, diesel cashback, taxi litres | Fuel / Reports | T035, T063 |
| `#/op/zread` ZREAD allocation (propose / auto / commit) | Cash | T036 |
| `#/op/banking`, `#/op/cashups`, Daily banking manager's view | Cash | T037 |
| Pump shortages / till balance | Cash | T038 |
| `#/op/dropsafe` | Cash | T039 |
| `#/op/staffshorts` | Cash | T040 |
| `#/ho/reports` (5 tabs: deductions, stock loss by employee, non-integrated POS) | Cash | T041 |
| Write-once till short → cashup + deduction + payroll | Cash | T042 |
| `#/ho/import/<src>` generic import screen | Recon | T043 |
| `#/ho/statements` bank statement management, deposits with no home | Recon | T044 |
| `#/recon/absa`, `#/recon/cashbags` two-pane workbench | Recon | T045 |
| Auto recon | Recon | T046 |
| `#/ho` Banking & Reconciliation hub (KPIs + 23 functions) | Recon | T047 |
| `#/op/stockrecon`, `stockexc`, `balancing`, `areadash`, `shiftvar` | Stock | T048, T049 |
| `#/op/preprod` pre-production / yield | Stock | T050 |
| `#/op/waste` | Stock | T051 |
| `#/op/monthend`, `homonthend`, `cleareod`, `dbf` (manual day-end), `namos` capture | Stock / DayEnd | T052 |
| `#/op/pr` purchase requests + new PR drawer | Purchasing | T053 |
| `#/op/prapprove` approval (bands, delegates, escalation) | Purchasing | T054, T055 |
| `#/op/utilities` | Utilities | T056 |
| `#/tool/assets`, `movement`, `repair` + `#/master/assetgroup`, `assettype`, `assetowner` | Assets | T057 |
| `#/control` exception register, bell, my queue, control desks | Exceptions | T058, T059 |
| `#/op/importdash`, `#/op/importpos`, `#/master/errorlog` overnight loads, import POS files (status of the MIST_Import load for a branch/date), load errors | Imports | T060 |
| `#/lib` report library (14 cats / 97 reports), `#/report/cat` legacy catalogue, `#/report/<id>` runnable reports, extract drawer, saved views | Reports | T061–T067 |
| `#/dash`, `#/dash/league`, `#/dash/mix`, sites not trading | Trade | T068 |
| `#/site/<name>` scorecard | Trade | T069 |
| `#/exco[/sheet]` weekly Exco pack (36 sheets, howto) | Exco | T070 |
| Budget against actual, budgets | Trade | T071 |
| `#/master/branch`, brand, region, class | Core | T021 |
| `#/master/area`, recon, profitcentre, stdcat, catgl, expense, meter | Masters | T022 |
| `#/master/employee` | People | T023 |
| `#/master/creditor`, debtor | Partners | T024 |
| `#/master/stock`, critical, virtualstock, fuelmatrix | Product | T025 |
| `#/master/sysdef` | Core | T026 |
| `#/master/user`, usertype, users & access | Core | T028, T072 |
| Audit trail reports (user activity, master data changes, report usage, approvals & overrides) | Core / Reports | T010, T027, T066, T073 |
| `#/design` why-the-redesign page, help | Help | T074 |
| Mobile (absent from the mockup) | all | T016, T075 |

## 6. Build order — milestones, epics, tasks

Estimates: S ≤ half a day · M 1–2 days · L 3–5 days · XL 1–2 weeks (one builder, swarm-parallel where dependencies allow). `model` is the PM's `llm_model`; `sonnet` marks tasks that are mostly porting with a clear reference. **Every task ends with the same two lines for the PM:** *Verify against the local PumpIT restore and paste the rows in the QA entry* · *Conventions: Agora KB → Architecture rules §3.*

**Critical path:** T001 → T002 → T003 → T004 → T007 → T008 → T009 → T011 → T013 → T014 → T021 → T022 → T029 → T030 (first customer-visible screen) → T036 → T037 → T043 → T045 → T048 → T053 → T054 → T058 → T061 → T062… → T070 → T077 → T078. Everything else hangs off these and can run in parallel lanes: **Lane A** (shell/components: T006 T011–T017), **Lane B** (identity/email: T007–T010, T018–T020), **Lane C** (masters: T021–T028), then per-epic lanes in M2 (Fuel, Cash, Recon, Stock, Purchasing, Utilities/Assets, Exceptions, Imports) and the report batches in M3.


### Agora M1 — Platform foundation

Repo, database conventions, seed master, RBAC, audit, navigation, component library, mobile base, email, core masters. Everything later stands on this. Nothing customer-visible beyond sign-in, Setup and the shell.


#### E01 · Project bootstrap & environments

*A Laravel 13 repo that boots against a local restore of PumpIT, with the conventions from §3 written into its CLAUDE.md before any feature lands.*


##### T001 · Bootstrap the Agora repo (Laravel 13, sqlsrv, HMVC skeleton, rulebook)

`epic E01` · `type feature` · `priority critical` · `model opus` · `estimate M` · `sort 1`  
**Blocked by:** —


**Deliverables**

- Laravel 13 app in ~/Development/ZP/Agora (PHP 8.3, composer, sqlsrv + pdo_sqlsrv + msodbcsql18 verified), git initialised, .env.example with `pumpit`, `mist_import`, `alteryx`, `fuelsheet` connections
- `Modules/` HMVC loader: module.json manifest, `{Module}ServiceProvider` auto-registration, view namespace `module::`, per-module routes/migrations/seeders/lang; `php artisan agora:make-module {Name}` scaffolder producing the §3.2 skeleton
- `App\Support\ProcedureService` (call / write, named params, multi result sets, structured THROW → `AgoraProcException`), `BaseModel` (PascalCase timestamps, `BelongsToBranch` global scope, `Auditable` hook points)
- CLAUDE.md + EXTENDED.md for the project carrying §1 and §3 of the plan (condensed), the ZP-NQL rules that still apply (check-the-DB-means-run-the-query, targeted test runs, no commits without Ryan, deploy ritual), README, dev.sh

**Acceptance**

- `php artisan agora:make-module Demo` produces a module that boots, routes, renders a view and runs an empty migration
- `ProcedureService::call('dbo.sp_GetBranchTills', ['BranchId'=>8])` returns rows from the local PumpIT restore (T002) and a deliberately failing proc surfaces as `AgoraProcException` with the proc's message
- phpunit `Support/ProcedureServiceTest` green

**Notes for the builder:** Copy the module loader and MigrationHelper shapes from Ceratine (~/Development/SAAS/ceratine) rather than inventing new ones; adjust for SQL Server (identity, rowversion, schema-qualified names). Postgres-only helpers (jsonb, partitions) do not come across.


##### T002 · Local SQL Server dev environment with a PumpIT restore

`epic E01` · `type feature` · `priority critical` · `model opus` · `estimate M` · `sort 2`  
**Blocked by:** —


**Deliverables**

- Docker compose profile `mssql` (SQL Server 2022, Linux) with a persisted volume; `scripts/restore-pumpit.sh` restoring the latest PumpIT `.bak` from ~/zp-backups into `PumpIT`, plus `MIST_Import` (or a trimmed subset — see notes), logins `agora_app` (db_owner on PumpIT for migrations) and `agora_ro` (db_datareader on MIST_Import / Alteryx / FuelSheet)
- `php artisan agora:db-check` printing each connection, database, schema counts (dbo 573 / cashup 17 on PumpIT), and the row counts of five anchor tables (BRN_DailyBanking, STK_StockReconLine, RCN_BankStatementLinesPumpIT, SS_Branch, SS_Users)

**Acceptance**

- Restore script is idempotent and documented; `agora:db-check` matches the counts recorded in the plan within the backup's date
- The `agora` schema does not exist yet after restore (proves T003 creates it)

**Notes for the builder:** PumpIT is 170 GB; the restore must be selective if disk is short — SDK_ActionLog_DEFUNCT (100M rows, 17 GB) and the DBF_INVHISTF_CUSTOMER family (68M rows, 33 GB) can be truncated in the local copy by the script with a --slim flag. Never point local at 105.247.172.179 for writes.


##### T003 · Versioned migration convention, MigrationHelper and the `agora` schema

`epic E01` · `type feature` · `priority critical` · `model opus` · `estimate M` · `sort 3`  
**Blocked by:** T001, T002


**Deliverables**

- `v1__NN_{module}_tables.php` naming with the slot map from §3.3; `MigrationHelper::branchKey($table)` (BranchId first + Id identity + clustered PK), `addAuditColumns`, `addTimestamps`, `addSoftDeletes`, `addRowVersion`, `money()`, `litres()`, `pct()` column helpers, `naturalKey($table, [...])` that refuses a key without BranchId
- `v1__01_core_tables.php` creating schema `agora`, `agora.SchemaVersion`, `agora.SeedMaster`, `agora.Branch` (empty, filled by T022), and the `agora.vw_Branch` view over `SS_Branch` aliasing SSBranchId → BranchId
- Proc deployment: `v1__NNp_{module}_procs.php` base class that runs every `Modules/{M}/Database/Procedures/*.sql` (CREATE OR ALTER, one proc per file, header comment citing the legacy proc it ports)
- `scripts/check-migrations.sh` and `scripts/check-procs.sh` wired into `composer check`

**Acceptance**

- `php artisan migrate` on the local restore creates the schema and tables; running it twice is a no-op; `composer check` fails on a fixture migration that omits BranchId or names an enum column
- `agora.SchemaVersion` holds `1.0` after the base migration

**Notes for the builder:** Forward-only: no migrate:fresh outside the Docker restore, ever. Document the rule in CLAUDE.md in bold.


##### T004 · Seed master ledger, orchestrator and `seed:master` command

`epic E01` · `type feature` · `priority high` · `model opus` · `estimate M` · `sort 4`  
**Blocked by:** T003


**Deliverables**

- `agora.SeedMaster` model + `SeedMaster::seed()` / `seedDemo()` helpers (Ceratine shape), `DatabaseSeeder` with the system → reference-from-PumpIT → demo phases, `App\Support\Seeding\{SeederCatalog,SeedRunner,SeederEntry}` ported from ZP-NQL (auto-discovery, risk classes safe/review/clobbers/destructive, payload fingerprint versions), `php artisan seed:master --status|--allow|--forget`
- `scripts/check-seeder-versions.sh` (a permission/role slug change must bump the seeder version)

**Acceptance**

- Running `seed:master` twice creates nothing the second time and the ledger shows skipped rows with versions; a seeder classified `clobbers` is refused without --allow
- phpunit `Seeding/SeedMasterTest`

**Notes for the builder:** Only `safe` seeders may run in a deploy. Reference-from-PumpIT seeders are upserts keyed on the natural key and are `safe` by construction.


##### T005 · Quality gates: composer check, phpstan, pint, phpunit (sqlsrv), Playwright

`epic E01` · `type feature` · `priority high` · `model sonnet` · `estimate S` · `sort 5`  
**Blocked by:** T001


**Deliverables**

- `composer check` = pint → phpstan (level 6, module-scoped analyse) → check-migrations → check-seeder-versions → check-procs → targeted phpunit; phpunit.xml pointing at the Docker restore with a `TestBranch` (id 999) created by the test seeder; playwright.config with desktop and mobile (375×812) projects and a login fixture
- A `docs/testing.md` page stating the targeted-run rule (Ryan is on solar; never a full suite unless asked)

**Acceptance**

- `composer check` green on the bootstrap; one Playwright smoke spec signs in and lands on the role landing page in both projects


##### T006 · Asset pipeline and the Agora token theme (light/dark) over Bootstrap 5

`epic E01` · `type feature` · `priority high` · `model opus` · `estimate M` · `sort 6`  
**Blocked by:** T001


**Deliverables**

- Vite build with Bootstrap 5.3 SCSS, `theme/tokens.css` (the mockup's :root tokens, dark under prefers-color-scheme guarded by `:root:not([data-theme=light])` and again under `[data-theme=dark]`), `theme/bootstrap-bridge.scss` remapping `--bs-*` to tokens, self-hosted Barlow Condensed / IBM Plex Sans / IBM Plex Mono, ApexCharts + TomSelect + SweetAlert2 registered as ES modules, theme toggle persisted in UserPreference
- Typography, number formatting helpers (`R`, `Rk`, `Lk`, `pct`, `delta` as PHP + JS twins with en-ZA locale)

**Acceptance**

- A styleguide page (/dev/theme) renders every token swatch and the type scale in both themes; Playwright screenshot in light and dark shows no unstyled Bootstrap colour

**Notes for the builder:** Take the token values from agoraretailconsole.html verbatim; do not redesign the palette. Prefer UTF-8 glyphs for arrows/states.


#### E02 · Identity, RBAC & audit

*Users migrated from SS_Users, role → permission, branch access, the audit tables every later proc writes to.*


##### T007 · Users, authentication and the role landing page

`epic E02` · `type feature` · `priority critical` · `model opus` · `estimate M` · `sort 7`  
**Blocked by:** T003, T006


**Deliverables**

- `agora.User` (migrated from SS_Users by `usp_Migrate_Users`: code, name, email, user type HO/Branch, active, last sign-in; passwords reset on first login), session auth, password policy, forgot-password via `core.password_reset` template (T019), sign-in page in the mockup's shape (wordmark, expansion, tagline, live system-state panel: loads failed, exceptions open, Z-reads unallocated, PRs awaiting — each a badge provider), landing route by role
- `agora.UserActivity` sign-in rows

**Acceptance**

- 85 users migrated with a report of unmatched/duplicate emails; sign-in works for a migrated user after reset; a Branch-manager lands on /console, an Executive on /exco (stubbed routes acceptable until those epics land)
- Playwright `auth.spec`

**Notes for the builder:** The system-state panel reads through the same badge providers the bell and the menus use (T011) — build the provider contract here and reuse it.


##### T008 · RBAC: roles, permissions, PermissionService, middleware, seeders

`epic E02` · `type feature` · `priority critical` · `model opus` · `estimate M` · `sort 8`  
**Blocked by:** T004, T007


**Deliverables**

- `agora.Role`, `Permission`, `RolePermission`, `UserRole`; `PermissionService::userHas/Any/All` with wildcard expansion; `can:` middleware and `@can`; `PermissionsSeeder` (slugs `module.action`, seeded per module via a `ModulePermissions` contract) and `RolesSeeder` for Executive, Finance, Operations, Branch manager, Auditor (all `*.view` + `audit.*`), Admin
- Role editor screen (Setup → Users and access → Roles): matrix of module × action with wildcard rows

**Acceptance**

- A route guarded by `can:cash.allocate_zread` returns 403 for Auditor and 200 for Branch manager; changing a slug without bumping the seeder version fails `composer check`
- phpunit `Core/PermissionServiceTest`

**Notes for the builder:** Read BRN_ZUserRights (3,334 rows) once in a migration seeder to propose initial role membership; write the proposal to a report Ryan can review, do not auto-assign anything above Branch manager.


##### T009 · Branch access, BranchContext, workspaces and the scope bar

`epic E02` · `type feature` · `priority critical` · `model opus` · `estimate M` · `sort 9`  
**Blocked by:** T008


**Deliverables**

- `agora.UserBranch` migrated from SS_UserBranches (1,063 rows); `BranchContext` (workspace HO|Branch, current branch, region/brand/format/period filters, 'as at') resolved per request from route → preference → default; `BelongsToBranch` global scope; `EnsureBranchAccess` middleware; `<x-scope-bar>` and `<x-workspace-switch>` components bound to the context; canonical URLs carry scope
- `agora.UserPreference` (theme, workspace, default branch, default scope, landing)

**Acceptance**

- A Branch user cannot reach another branch by editing the URL (403 + audit row); an HO user's scope bar filters the branch list on region/brand/format; the choice persists across sign-ins
- phpunit `Core/BranchContextTest`

**Notes for the builder:** The scope is enforced twice: PHP global scope AND the @BranchId every proc receives. Never rely on one.


##### T010 · Audit trail: AuditLog, UserActivity, ApprovalLog, Auditable trait, usp_Audit_Write

`epic E02` · `type feature` · `priority high` · `model opus` · `estimate M` · `sort 10`  
**Blocked by:** T003, T007


**Deliverables**

- `agora.AuditLog`, `agora.UserActivity` (sign-in, screen opened, report run), `agora.ApprovalLog`; `usp_Audit_Write` used by every writer proc; `Auditable` trait on masters (before/after JSON diff); `RecordsActivity` middleware; retention setting
- Admin viewer (Setup → Governance → Audit trail) with the grid component filters: user, branch, entity, date

**Acceptance**

- Editing a branch name writes a before/after row; a proc write writes its row inside the same transaction (rollback removes both); opening /cash/zread writes a screen-opened row
- phpunit `Core/AuditTest`


#### E03 · Shell, navigation & component library

*The Agora theme, the app shell, DB-driven menus, Ctrl-K, and the component vocabulary of §3.8 — including the data grid and charts — plus the mobile base.*


##### T011 · App shell: app bar, mega menus, crumb, footer, tooltip, drawer, DB-driven navigation

`epic E03` · `type feature` · `priority critical` · `model opus` · `estimate L` · `sort 11`  
**Blocked by:** T006, T009


**Deliverables**

- `agora.MenuSidebar/MenuSection/MenuItem` + `MenuService::seedSidebar/seedSection/seed` + per-module `MenuSeeder` contract; badge provider contract (`BadgeProvider::count(BranchContext): ?string`) with a 60 s cache; the HO sidebar (Today / Trade / Control / Setup with the column headings and items of the mockup's NAV_HO) and the Branch sidebar (Today / Stock / My site / Assets — NAV_BRANCH)
- Components `<x-app-shell>`, `<x-app-bar>`, `<x-mega-menu>`, `<x-crumb>`, `<x-footer>` (DB · user · version · as-at), `<x-tip>`, `<x-drawer>`; layouts `layouts/app` and `layouts/mobile` (T017 fills the mobile one)

**Acceptance**

- Menus render from the database only (no static nav blade); items the user lacks permission for are absent from the DOM; badges show the provider counts; Escape closes any open mega/palette/drawer; keyboard reachable
- Playwright `shell.spec` in both themes

**Notes for the builder:** Item labels, columns and hints come from NAV_HO / NAV_BRANCH in the mockup (lines ~1862–1938) — seed them exactly; routes may be stubs until their epic lands, flagged `coming` so they render disabled rather than 404.


##### T012 · Command palette (Ctrl-K) and the search registrar

`epic E03` · `type feature` · `priority high` · `model opus` · `estimate M` · `sort 12`  
**Blocked by:** T011


**Deliverables**

- `SearchRegistrar` contract each module implements (menu items, report definitions incl. legacy 'was' names, branches, masters, suppliers, products, employees); `<x-palette>` with keyboard navigation, kinds, recents per user, deep links; `/api/search?q=`

**Acceptance**

- Typing a legacy report name ('ZReadings Not Allocated') offers 'now called Unallocated Z-reads'; typing a branch opens its scorecard; recents persist
- Playwright `palette.spec`


##### T013 · Component library v1 (page-head, KPI, card, chip, delta, note, empty-state, tabs, params/runbar, statstrip, checklist, decision list, exception list, proposal, lib-card) + gallery

`epic E03` · `type feature` · `priority critical` · `model opus` · `estimate L` · `sort 13`  
**Blocked by:** T006


**Deliverables**

- Every component in §3.8 except grid, chart, palette, shell and the two-pane workbench, each a Blade component with typed props, both themes, a mobile mode, and a JS module only where behaviour exists (tabs persist, params collapse, runbar status)
- `/dev/components` gallery (dev-only route) rendering every variant with its props table; a `docs/components.md` generated from the gallery

**Acceptance**

- Gallery renders with zero console errors in both themes at desktop and 375 px; Playwright screenshot spec per component; no page in later tasks hand-writes KPI/card/chip markup (lint rule: a grep in composer check for `class="kpi"` outside components)

**Notes for the builder:** Mirror the mockup's markup and class names (page-head, kpi/lbl/val/cmp/stripe, card-h/card-b/flush, chip + dot, delta up/dn/flat, params .f, runbar, statstrip) so the CSS ports 1:1.


##### T014 · Data grid component, GridDefinition, sort/paginate/extract, column chooser

`epic E03` · `type feature` · `priority critical` · `model opus` · `estimate L` · `sort 14`  
**Blocked by:** T013


**Deliverables**

- `<x-data-grid>` (shell: wrapper, header, sort marks, numeric/wide/mono cells, row click, expand row, bulk select, footer totals, limit note 'showing first N — extract to see all', empty state) + `GridDefinition` classes per grid registered in `config/grids.php` (column catalogue: key, label, numeric, wide, format R/Rk/Lk/pct/date/chip, sort value, default visible) + `GridFilter` specs + per-grid `_cells` partial pattern; server-side sort/paginate over proc result sets and Eloquent queries; CSV + XLSX extract through `<x-drawer>` and download; per-user column chooser stored in UserPreference
- Mobile mode: card list of the first three visible columns with expand

**Acceptance**

- A 10,000-row proc result pages, sorts numerically, extracts to XLSX with the visible columns; column choice persists; phpunit `Grid/GridDefinitionTest`, Playwright `grid.spec` desktop + mobile

**Notes for the builder:** Ceratine's data-grid/grid-column-chooser sections in EXTENDED.md are the reference; keep the 'shell owns structure, partial owns cell content' split.


##### T015 · Chart components over ApexCharts (daily bars, line, donut/mix, bridge, diverging, sparkline, mini bar)

`epic E03` · `type feature` · `priority high` · `model opus` · `estimate M` · `sort 15`  
**Blocked by:** T006, T013


**Deliverables**

- `<x-chart type=… :series :options>` with token colours (s1–s5, good/warn/crit), light/dark aware, en-ZA formatting, empty and loading states, `<x-sparkline>` and `<x-mini-bar>` as inline SVG (no library) for KPI cards and grid cells
- Gallery entries for each type with the mockup's data shapes (daily litres + c/ℓ, margin vs budget, PC mix, TO→GP bridge, diverging vs budget)

**Acceptance**

- Every chart type renders in both themes with the right series colours and responds to container resize; Playwright screenshot spec

**Notes for the builder:** Charts receive data as props from the controller; a chart component never fetches. Follow the dataviz skill's form rules (one system, accessible contrast).


##### T016 · Mobile detection, DeviceContext, view resolver and mobile layout

`epic E03` · `type feature` · `priority critical` · `model opus` · `estimate M` · `sort 16`  
**Blocked by:** T011, T013


**Deliverables**

- `DeviceDetector` (Sec-CH-UA-Mobile → UA via mobiledetect → preference override → `?device=`), `DeviceContext` middleware, `is_mobile()` / `device()` helpers, `pick('module::view')` resolver returning `module::mobile.view` when it exists; `layouts/mobile` with bottom nav (Today / Trade / Control / Setup or the branch sections), top bar (branch + date), 'Desktop site' toggle; `<x-scope-bar>` as bottom sheet; grid/KPI/params mobile modes wired
- `docs/mobile.md` with the clean-over-capable rules and the mobile blade checklist every capture task must satisfy

**Acceptance**

- Playwright mobile project: the shell renders with bottom nav at 375×812, the desktop toggle switches and persists, a screen without a mobile blade renders the desktop view inside the mobile layout without horizontal scroll
- phpunit `Device/DeviceDetectorTest` with UA and client-hint fixtures

**Notes for the builder:** Mobile detection is in the base by Ryan's instruction; no screen after this task may be built without deciding its mobile answer (own blade, shared mobile mode, or documented 'desktop only').


##### T017 · Notifications: SweetAlert2 wrappers, flash, exceptions bell

`epic E03` · `type feature` · `priority medium` · `model sonnet` · `estimate S` · `sort 17`  
**Blocked by:** T011


**Deliverables**

- `agora.toast/confirm/error` JS wrappers over SweetAlert2 (no native dialogs), flash → toast bridge, `<x-bell>` bound to the open-exception badge provider, keyboard shortcut to Control

**Acceptance**

- A proc THROW surfaces as a themed error toast with the AGORA code; confirm dialogs are used by every destructive action from here on (grep rule in composer check for `confirm(`)


#### E04 · Email & templates

*Mailer, outbox, log, and email templates tagged by module with a per-module variable registry.*


##### T018 · Mail infrastructure: mailer, queue, EmailLog, EmailOutbox, attachments

`epic E04` · `type feature` · `priority high` · `model opus` · `estimate M` · `sort 18`  
**Blocked by:** T003, T004


**Deliverables**

- Mail config (SMTP from settings), database queue + `agora-default` worker, `agora.EmailLog`, `agora.EmailOutbox` with retry/backoff, `EmailService::send(code, target, vars, attachments)`, CSV/XLSX attachment support, admin log viewer

**Acceptance**

- A test send lands in Mailpit locally, is logged with template code + module tag, and a forced SMTP failure is retried and visible in the outbox screen
- phpunit `Email/EmailServiceTest`


##### T019 · Email templates with module tag, variable registry, admin CRUD, per-module seeders

`epic E04` · `type feature` · `priority high` · `model opus` · `estimate M` · `sort 19`  
**Blocked by:** T018, T013


**Deliverables**

- `agora.EmailTemplate` (§3.7), `VariableRegistry` contract per module, template admin (list filtered by ModuleTag, edit with variable chips, preview with sample values, version history, branch override), `core.password_reset` + `core.welcome` templates seeded; the `EmailTemplateSeeder` pattern every later module follows

**Acceptance**

- Saving a template with an unknown variable is refused; a branch override wins over the global template for that branch; preview renders the sample; Playwright `email-templates.spec`

**Notes for the builder:** Codes are `{module}.{event}`; ModuleTag equals the module key. Modules in E06–E17 each add their templates in their own seeder — list them in the task that owns the event.


##### T020 · Scheduled sends: ReportSchedule foundation and the scheduler

`epic E04` · `type feature` · `priority medium` · `model sonnet` · `estimate S` · `sort 20`  
**Blocked by:** T018


**Deliverables**

- `agora.ReportSchedule` (migrated from SS_ReportScheduler, 104 rows, as inactive until the report exists in Agora), scheduler command `agora:send-scheduled`, `reports.scheduled` template, failure log

**Acceptance**

- A schedule bound to a stub report sends its extract on the cron tick and logs it; failures raise a Loads exception (T058) later


#### E05 · Core masters (Setup)

*Every master the Setup section shows, adopted or migrated from PumpIT with audit and change logs.*


##### T021 · Branch master: migrate SS_Branch, Region/Brand/Format, status, POS type, admin entities

`epic E05` · `type feature` · `priority critical` · `model opus` · `estimate M` · `sort 21`  
**Blocked by:** T003, T010, T013, T014


**Deliverables**

- `usp_Migrate_Branch` (31 rows → `agora.Branch` with Region, Brand, Format, Status Trading|Mothballed|Non-trading, POSType BRANCH|NAMOS, IsAdminEntity, ProfitCentres per branch), `agora.Region/Brand/Format` derived and editable, Branch listing + edit screens (Setup → The business → Branches) with audit, the `vw_Branch` view repointed

**Acceptance**

- 31 branches migrated with the mockup's region/brand/format values reviewed against SS_Branch; Total Hluhluwe shows POSType NAMOS; the six admin entities are flagged and excluded from trading lists by default; Playwright `masters-branch.spec`

**Notes for the builder:** `agora.Branch.BranchId` must equal `SS_Branch.SSBranchId` — never re-number. Teds (30) and Wimpy Ladysmith (31) stay two branches here; the Exco pack merges them.


##### T022 · Reference masters: profit centres, standard categories, category GL codes & GP bands, recon areas & criteria, expense codes, transaction types, tills, shifts, fuel types/tanks/pumps, meters, bank accounts, terminals

`epic E05` · `type feature` · `priority high` · `model opus` · `estimate L` · `sort 22`  
**Blocked by:** T021


**Deliverables**

- Screens + procs/CRUD for every master listed in §4 Masters row: Profit Centre listing ('Fuel is five of them, not one'), Standard Category listing (54 + GLs), Category GL Code listing (adopt SDK_CategoryGLCode 815: PC, supplier GL, sales GL, MinGP/MaxGP, MinCover/MaxCover, active — highlights categories with no band), Recon Area master (STK_Area), Recon Criteria, Expense master (BRN_Expenses: GL, VAT, asset flag, approver), Transaction types, Tills/Shifts per branch, Fuel types/tanks/pumps per branch (BRN_Pump.[Order] bracketed), Utility meters (BRN_UtilityMeter), Bank accounts, merchant/terminal numbers
- Reference seeders (upsert from PumpIT, `safe`) and a `masters.*` permission set

**Acceptance**

- Every master lists with the grid component, filters, extract, and writes an audit row; per-branch masters cannot be listed without a branch in scope; phpunit per proc; Playwright `masters.spec`

**Notes for the builder:** FuelTypeDescription is NOT unique over FuelTypeNo across branches — every fuel-type lookup is per branch. Same for tills and shifts (3 shifts estate-wide; bare ShiftNo joins fan out 21.67×).


##### T023 · People masters: employees, posts, employee areas

`epic E05` · `type feature` · `priority high` · `model sonnet` · `estimate M` · `sort 23`  
**Blocked by:** T021


**Deliverables**

- Employee master (adopt BRN_Employee: split name fields, NickName, post from the 12 posts, active, branch), Employee areas (BRN_EmployeeArea), `usp_People_NextPost` (port sp_GetNextEmployeePost), list/edit/mobile view

**Acceptance**

- 283 active employees list by branch with post filter; a terminated employee stays visible on historic shifts; Playwright `masters-employee.spec`

**Notes for the builder:** No EmployeeName column exists in PumpIT — compose it in the view; keep the parts in the table.


##### T024 · Trading partners: suppliers (creditors) and account customers (debtors)

`epic E05` · `type feature` · `priority medium` · `model sonnet` · `estimate M` · `sort 24`  
**Blocked by:** T021


**Deliverables**

- Supplier master (adopt BRN_Suppliers 74,822: code, name, active, stock/cash flags, contact, dept, location; `usp_Partners_NextSupplierCode`), Customer master (adopt BRN_Customers: limits, cell), list/edit with grid + extract

**Acceptance**

- Supplier search through the palette; duplicate-code guard; Playwright `masters-partners.spec`


##### T025 · Product masters: stock master by location, critical lines, virtual stock items, fuel matrix & price history

`epic E05` · `type feature` · `priority high` · `model opus` · `estimate L` · `sort 25`  
**Blocked by:** T021, T022


**Deliverables**

- Stock master (adopt STK_StockMaster by branch/location WINBRANCH|AURA: UOM, issue multiple, produce flag, area, price, price type, factor, POS code, monitored, pre-production type), Critical lines (STK_StockMasterCritical), POS virtual stock items (BRN_POSVirtualStockItems: airtime/electricity/lotto/vouchers), Fuel matrix (adopt BRN_FuelPrice: per branch/grade/period cost, sell, margin, fleet; DOE matrix) with the price-change log and the mockup's `fmtable` layout

**Acceptance**

- Fuel matrix shows August's two price moves (05 Aug ULP 25.54→25.02, diesels 27.77→29.01; 13 Aug diesels 29.01→29.10) from the real table; stock master lists 7,186 items with location filter; Playwright `masters-product.spec`


##### T026 · System defaults & settings (global / per branch) with thresholds

`epic E05` · `type feature` · `priority high` · `model sonnet` · `estimate S` · `sort 26`  
**Blocked by:** T021, T013


**Deliverables**

- `agora.Setting` (migrated from SS_SysDefaults + BRN_Config: key, branch or group entity, value, type, description), `Settings` facade with branch fallback, Setup screen; seeded thresholds: pump variance 15 ℓ, drop-safe reason threshold R2,000, cash-short leniency R3.00, Z-read auto-accept confidence, recon amount window 3 days, PR approval bands (T054 fills), exception escalation days

**Acceptance**

- A branch override beats the group value; every threshold used later reads through `Settings::for($branchId, 'key')` (grep rule); phpunit `Core/SettingsTest`


##### T027 · Master-data change log wiring and the two audit reports over masters

`epic E05` · `type feature` · `priority medium` · `model sonnet` · `estimate S` · `sort 27`  
**Blocked by:** T010, T022, T025


**Deliverables**

- `Auditable` on every master model; `Master data changes` and `User activity` report definitions stubbed against the audit tables (full engine in T062) shown as grids under Setup → Governance

**Acceptance**

- Changing a category's MinGP shows in the change log with before/after and the user


##### T028 · Users & access screens: user list, roles, branches, last sign-in, user types

`epic E05` · `type feature` · `priority medium` · `model sonnet` · `estimate M` · `sort 28`  
**Blocked by:** T008, T009


**Deliverables**

- Setup → People and assets → Users and access: user grid (code, name, role, user type HO|Branch, branches, last sign-in, active), create/edit/deactivate, role assignment, branch assignment, password reset trigger, 'switch role' preview for admins

**Acceptance**

- Deactivating a user ends their sessions; an HO user with no branch grant sees all branches; Playwright `users.spec`


### Agora M2 — Today & Control (operations core)

The branch day and what does not reconcile: day-close spine and branch console, fuel capture, Z-reads and cashups, drop safe, staff shorts and deductions, bank imports and the reconcile workbench, stock counts and production, purchase requests with approval bands, utilities, assets, the exception register and overnight-load monitoring. This is the milestone that lets PumpIT's branch and head-office functions be switched off.


#### E06 · Day-end spine & branch console

*The trading day as an object with a close state, and the branch home page as a work queue.*


##### T029 · Day-end spine: DayEnd/EOD dates, DayClose state, calendar, usp_DayEnd_*

`epic E06` · `type feature` · `priority critical` · `model opus` · `estimate M` · `sort 29`  
**Blocked by:** T021, T022, T026


**Deliverables**

- Adopt BRN_DayEnd + BRN_EODDates + ZRP_Calendar; new `agora.DayClose` (BranchId, TradingDate, Step 1–7, Status pending|done|closed-with-reason, Reason, ClosedBy/At); procs `usp_DayEnd_EnsureRecord` (port sp_CheckAndInsertDayEndRecord / sp_CheckBranchEODDates), `usp_DayEnd_GetCloseStatus` (computes each step from the data: POS files loaded, pump readings captured, fuel input captured, Z-reads allocated, areas locked, cashups confirmed, bags collected), `usp_DayEnd_Close`, `usp_DayEnd_Reopen`; `dayend.close_reminder` template + scheduler

**Acceptance**

- For a real branch/date on the restore the status proc returns the seven steps with the same outstanding counts a manual query gives (paste both in the QA); closing with steps outstanding requires a reason and writes it; phpunit `DayEnd/CloseStatusTest`

**Notes for the builder:** Step definitions come from the mockup's viewConsole steps. A day cannot be closed silently — the reason is the record.


##### T030 · Branch console — 'Today at <branch>' (day-close checklist, KPIs, decisions, open items) desktop + mobile

`epic E06` · `type feature` · `priority critical` · `model opus` · `estimate L` · `sort 30`  
**Blocked by:** T029, T013, T016


**Deliverables**

- `/console` for the Branch workspace and HO users with a branch in scope: page head (region · brand · format), `<x-checklist>` of the seven steps with live status and deep links, KPI strip (banking variance across cashups, Z-reads not allocated, staff shorts awaiting, purchase requests awaiting), 'Needs a decision' list (staff shorts + PRs), 'Open items for this branch' from the exception register (T058 — stub provider until then), actions Branch scorecard / Open daily banking; mobile blade as the primary design

**Acceptance**

- Every count on the page reconciles to its underlying screen's filtered row count for the same branch/date (QA pastes both); loads in < 1 s on the restore; Playwright `console.spec` desktop + mobile

**Notes for the builder:** This is the first screen a branch manager sees; build the mobile blade first and the desktop from it.


##### T031 · Day close status (head office view) with close-with-reason and live refresh

`epic E06` · `type feature` · `priority high` · `model opus` · `estimate M` · `sort 31`  
**Blocked by:** T029, T014


**Deliverables**

- Today → Day close status: all branches × date grid (steps done/outstanding, holding item, closed-by, reason), filters, extract; HO can close/reopen with reason; optional Reverb push on status change; `Day close status` report definition

**Acceptance**

- Grid totals match `usp_DayEnd_GetCloseStatus` per branch; reopen writes audit + approval log; Playwright `dayclose.spec`


#### E07 · Fuel & forecourt

*Pump readings, fuel input, prices and the wet-stock checks.*


##### T032 · Pump reading capture (mechanical / electronic / POS, > 15 ℓ flag) desktop + mobile

`epic E07` · `type feature` · `priority critical` · `model opus` · `estimate M` · `sort 32`  
**Blocked by:** T029, T022, T014


**Deliverables**

- Adopt BRN_PumpReadings; `usp_Fuel_PrepareReadings` (port sp_CaptureMechReading + sp_CheckCaptureMechReading) returning the pump list with previous closing as opening, `usp_Fuel_SaveReadings` (JSON lines, validation: closing ≥ opening unless meter reset flagged, variance vs electronic and vs POS fills from MIST_Import WINBRANCH_FC_TRANS flagged above the Settings threshold), screen with grid + runbar + mobile capture blade (one pump per card, numeric keypad)

**Acceptance**

- Saving a reading writes the row, the audit, and updates the console step; a 20 ℓ mech-vs-elec gap shows the warn chip; phpunit `Fuel/PumpReadingsTest`; Playwright `pump.spec` desktop + mobile

**Notes for the builder:** Join pumps on (BranchId, PumpNo) — the rejected path BRN_PumpReadings → BRN_Pump on bare PumpNo is the classic fan-out.


##### T033 · Fuel input capture (volume, dip, deliveries → days cover, order flags) + import monitor

`epic E07` · `type feature` · `priority critical` · `model opus` · `estimate M` · `sort 33`  
**Blocked by:** T032


**Deliverables**

- Adopt BRN_FuelInput; `usp_Fuel_PrepareInput` (port sp_CaptureFuelInput reading DBF_FC_TEOD volumes/dips/deliveries), `usp_Fuel_SaveInput`, `usp_Fuel_DaysCover` (dip ÷ day's draw; Order now < 1.5, Order today < 2.5), screen + mobile blade, Fuel input import log (SS_ImportFuelInputLog) monitor tile

**Acceptance**

- A site with 1.2 days cover shows 'Order now' and raises a Fuel exception candidate (T058 consumes); phpunit `Fuel/FuelInputTest`; Playwright `fuelinput.spec`

**Notes for the builder:** The legacy form captured the dip and never did the days-cover sum — that sum is the point of this screen.


##### T034 · Fuel price & margin history screens over the fuel matrix

`epic E07` · `type feature` · `priority medium` · `model sonnet` · `estimate S` · `sort 34`  
**Blocked by:** T025


**Deliverables**

- Fuel and forecourt → Fuel price and margin history: per site/grade/price period cost, pump price, margin, fleet with the multi-line chart (`chartLines`); price-change annotations; report definition `Fuel price and margin history`

**Acceptance**

- Margin history for a site matches BRN_FuelPrice rows for the period; Playwright `fuelmatrix.spec`


##### T035 · Wet-stock checks: pump meter check, tank reconciliation, pump readings, fills, slow-to-pay, attendant performance, diesel cashback, taxi litres

`epic E07` · `type feature` · `priority high` · `model opus` · `estimate L` · `sort 35`  
**Blocked by:** T032, T033, T014


**Deliverables**

- Procs (report definitions bind in T063; until then each has a screen under Fuel and forecourt): `usp_Report_PumpMeterCheck` (mech vs elec vs POS per nozzle), `TankReconciliation` (opening dip + deliveries − closing dip vs POS; per site / tank / day, FuelSheet DailyDeliveryReconciliation where present), `PumpReadings`, `EveryFillInDetail` (WINBRANCH_FC_TRANS), `SlowToPayFills` (threshold param), `PumpAttendantPerformance` (ℓ and R per car, lube attachment, cars per can), `DieselCashbackRegister` (till journal DIESEL CASHBACK lines tied to the diesel fill within 1 ℓ, band tests below-minimum / rate band / no diesel sale / pass), `TaxiAssociationLitres`

**Acceptance**

- Each report's totals reconcile to the Exco pack's Meter by Pump / Tank Recon / Cashback Register sheets for the same period (T071 will read the same procs); extract works; phpunit per proc with a known branch/day

**Notes for the builder:** The mockup's SQL panels (lines ~1077–1200 of the HTML) show the intended shape; the real tables are MIST_Import WINBRANCH_FC_TRANS and PumpIT BRN_PumpReadings/BRN_FuelInput, not the invented dbo.PumpTransaction.


#### E08 · Cash, banking, Z-reads & deductions

*From the till to the drawer to the payslip: Z-read allocation with proposals, cashups with tender legs, till balance, drop safe, staff shorts, deductions and the payroll file.*


##### T036 · Z-read allocation: model, proposer with confidence + evidence, auto-allocate, commit, unallocated queue

`epic E08` · `type feature` · `priority critical` · `model opus` · `estimate L` · `sort 36`  
**Blocked by:** T029, T023, T026, T013, T014


**Deliverables**

- `agora.vw_Zread` over MIST_Import DBF_P3TRANS_ZREAD (branch, location ARCH|WINBRANCH|AURA, date, EOD, sales, terminal, log file, operator user, till, shift) + `agora.ZreadAllocation`; `usp_Cash_ProposeZreadAllocation` (history priors: same till+shift+operator ≥ 75 % → certain; same operator ≥ 60 % → likely; usual operator on that till/shift → review; shift from operator's history ≥ 60 %) returning confidence + evidence text, `usp_Cash_AutoAllocate` (accepts ≥ Settings threshold), `usp_Cash_CommitZreadAllocation`; ZREAD Allocation screen (params, runbar Auto-allocate / Clear proposals / Update ZREAD, grid with `<x-proposal>` cells, only the decision column coloured), `Unallocated Z-reads` queue definition, mobile confirm-only blade

**Acceptance**

- On the restore, for Esikhawini Convenience 21–31 Aug the proposer's 'certain' rows all match the historical allocations where one exists (paste the check); commit writes rows + audit and clears the console step; phpunit `Cash/ZreadProposerTest`; Playwright `zread.spec`

**Notes for the builder:** cashup.ZReading (the 2026 pilot) is NOT the source — 162,870 of its 162,925 rows have NULL AllocatedShiftId. Use MIST_Import's DBF_P3TRANS_ZREAD and, once E08 is live, Agora's own allocation table.


##### T037 · Daily banking / cashups: header + tender legs, Z vs declared, variance, confirm/close, manager's view, open cashups

`epic E08` · `type feature` · `priority critical` · `model opus` · `estimate XL` · `sort 37`  
**Blocked by:** T036, T022, T024


**Deliverables**

- Adopt BRN_DailyBanking + the 14 live `BRN_DailyBanking{Channel}` leg tables through `agora.vw_Cashup*` views (ReconBatchNoPumpIT is the recon stamp; plain ReconBatchNo ignored); `usp_Cash_GetCashup` (port sp_GetDailyBankingWithMOPS: Z read, legs, expenses, pump shortages → ActualTotal, Variance), `usp_Cash_SaveCashupLeg` per channel (JSON), `usp_Cash_ConfirmCashup`, `usp_Cash_CloseCashup` (manager), `usp_Cash_LoadCardAmounts` (port sp_Load_BranchCreditCardAmount) ; Daily Banking screen (till/shift/date, legs as tabs, variance chip, confirm), Cashups list with status, `Daily banking — manager's view` report, `Open cashups` and `Z-reads with no cashup` / `disagree` queues; mobile: read + confirm

**Acceptance**

- For a known branch/day the ActualTotal and Variance equal PumpIT's own figures (paste sp_GetDailyBankingWithMOPS output beside the Agora proc output); confirming updates the console step; phpunit `Cash/CashupTest`; Playwright `banking.spec`

**Notes for the builder:** Eleven detail tables hang off the exact tuple (BranchId, TillNo, ShiftNo, TransactionDate) — every leg join uses all four. Dead channels (Cheques, ManualCard1/2, Zapper=1 row) are hidden by setting.


##### T038 · Till balance & pump shortages by attendant/shift

`epic E08` · `type feature` · `priority high` · `model sonnet` · `estimate M` · `sort 38`  
**Blocked by:** T037


**Deliverables**

- Adopt BRN_TillBalance; `usp_Cash_SaveTillBalance` (port sp_InsertTillBalance + sp_GenerateTillBalanceNo + sp_Insert_TillBalanceTransactions), capture screen (pump attendant short per shift), `Pump shortages` report definition

**Acceptance**

- A pump short saved here appears on the cashup as PumpShortages and on the deduction schedule (T041) without re-keying; phpunit `Cash/TillBalanceTest`


##### T039 · Drop safe: bag drop with reason, > R2,000 rule enforced, collection; mobile

`epic E08` · `type feature` · `priority high` · `model sonnet` · `estimate M` · `sort 39`  
**Blocked by:** T029, T026


**Deliverables**

- Adopt BRN_DropSafe + BRN_DropSafe_Collection; `usp_Cash_SaveDropSafe` (refuses a bag over the threshold without a reason and a manager), `usp_Cash_CollectDropSafe` (collection ref, collected flag); screen + mobile capture; `In the safe` chip; console step

**Acceptance**

- A R2,500 bag without manager name is refused with the AGORA code shown as a toast; collection clears the console step; Playwright `dropsafe.spec` mobile


##### T040 · Staff shorts with reason codes, approval trail and posting

`epic E08` · `type feature` · `priority high` · `model opus` · `estimate M` · `sort 40`  
**Blocked by:** T023, T026, T019


**Deliverables**

- Adopt BRN_StaffShorts; new `agora.StaffShortReason` (seeded: pump short, cashier short, stock loss, other) + free note; `usp_Cash_RaiseStaffShort` (port sp_GenerateStaffShortsNo), `usp_Cash_ApproveStaffShort` / decline with reason, `usp_Cash_PostStaffShort` (port sp_Load_ApprovedStaffShorts); screen (raise, list, approve) + mobile raise; templates `cash.staff_short_raised`, `cash.staff_short_decided`

**Acceptance**

- Raising sends the approver an email; approving posts to deductions (T041); the 'eleven spellings of pump short' become one code with the note kept; Playwright `staffshorts.spec`


##### T041 · Deductions: cash-short deductions (R3 leniency), stock loss by employee, non-integrated POS shortfalls, payroll file

`epic E08` · `type feature` · `priority critical` · `model opus` · `estimate L` · `sort 41`  
**Blocked by:** T037, T038, T040, T014


**Deliverables**

- `agora.Deduction` + `usp_Cash_BuildDeductions` porting sp_DeductionSummaryReport(_Pivot), sp_EmployeeCashLossReport, sp_EmployeeAmountShortReport, sp_EmployeeStockLossReport and sp_RPT_EmployeeStockLossReportPAYROLLDETAIL_LeniencySummary (leniency from Settings; at/inside leniency written off, above deducted in full — no partial rule), non-integrated tender shortfalls (Zapper, Yumbi, Infinity, Smart ATM expected vs settled); Head Office Reports screen with the five tabs (Cash Short Deductions Per Employee, Stock Loss By Employee Summary/Detail, Non-Integrated POS Deductions Summary/Detail) + stat strips; `usp_Cash_PayrollFile` export (CSV in the payroll's layout — confirm with ZP)

**Acceptance**

- For a month on the restore the five tabs match the legacy procs' output row-for-row (paste both); the payroll file totals equal the Deducted stat; phpunit `Cash/DeductionsTest`

**Notes for the builder:** Stock loss by employee depends on T049 (STK_StockReconEmployees, RCN_ReconArea); stub the tab until then and finish it in T049's acceptance.


##### T042 · Write once, flow everywhere: the till-short event reaches cashup, deduction schedule and payroll from one capture

`epic E08` · `type feature` · `priority high` · `model opus` · `estimate M` · `sort 42`  
**Blocked by:** T038, T040, T041


**Deliverables**

- A single `TillShortEvent` domain event raised by till balance / staff short capture; listeners update the cashup's PumpShortages/short leg, create the deduction row and mark the payroll line; a shared `TillShortGuard` rule (refuses a purchase request whose supplier is 'Till Balance Expense' — 477 such requests in the period — with a link to the proper capture) that T053 wires into PR submit

**Acceptance**

- Capturing one short produces exactly one cashup effect, one deduction row and one payroll line (phpunit asserts counts); the PR guard fires with the right message


#### E09 · Bank reconciliation

*One import screen, bank statement management, the two-pane workbench, and an auto-match that proposes with evidence.*


##### T043 · Generic import screen: drop file → parser registry → Raw / Stripped / Imported → load proc; import log

`epic E09` · `type feature` · `priority critical` · `model opus` · `estimate L` · `sort 43`  
**Blocked by:** T029, T014, T026


**Deliverables**

- `Imports/ParserRegistry` with one parser class per source (ABSA MarkOff → BRN_ABSAMarkoff, FNB, Zapper, Yumbi → BRN_Yumbi, Infinity → BRN_Infinity, Cash bags / Cash devices / Smart ATM → BRN_CashDevices / BRN_SmartATM, NAMOS day-end → NAMOS_* , Bank statements → RCN_BankStatementLinesPumpIT), `.xls/.xlsx/.csv` support, one screen `/recon/import/{source}` with drop zone, Raw / Stripped / Imported tabs, load via `usp_Recon_Load{Source}` (ports of sp_Load_BankStatement_DirectDeposits, sp_Insert_MIST_Trans_MOPS_{Channel} …), file retention under storage with hash, `agora.vw_ReconImport` over RCN_ReconImports

**Acceptance**

- Loading last month's ABSA MarkOff file on the restore produces the same row count as PumpIT's own import log for that file; a duplicate file is refused by hash; parse errors show on the Stripped tab with row numbers; phpunit per parser with fixture files

**Notes for the builder:** Get one real sample file per source from ZP before writing a parser (task the request as a subtask). The legacy module has one screen per source — Agora has one screen that knows which parser to use.


##### T044 · Bank statement management: identify to channel, IDState/ReconState, unidentified queue

`epic E09` · `type feature` · `priority high` · `model opus` · `estimate M` · `sort 44`  
**Blocked by:** T043


**Deliverables**

- `agora.vw_BankStatementLine` over RCN_BankStatementLinesPumpIT (Type channel FNB|ABSA|SmartATM|CashMachine|Yumbi|Direct|Debtors|CashDeposit|FleetCard|Infinity|Fuel Debit|UnIdentified, IDState 1|2, ReconState 1|2, ReconBatchNo, Bank); `usp_Recon_Identify` (rules from BRN_AutoReconCriteria / SS_ReconCriteria → channel), statement screen with channel/state filters, `Deposits with no home` (unidentified) queue, `Bank charges by site`, `Bank reconciliation summary` definitions

**Acceptance**

- August 2026 on the restore reproduces the scoping doc's channel × state table (FNB unreconciled 2,701 lines / R88.6m etc. — paste); Fuel Debit is never presented as a failure; channels are never summed into one figure

**Notes for the builder:** Only ABSA is loaded as a bank; FNB card settlements arrive on the ABSA statement. Read docs/exco-cash-banking-scope.md §3 before building the tiles.


##### T045 · Reconcile workbench: two-pane captured vs bank, tick both sides, process batch, unmatch

`epic E09` · `type feature` · `priority critical` · `model opus` · `estimate L` · `sort 45`  
**Blocked by:** T044, T037, T013


**Deliverables**

- `<x-two-pane-recon>` component; `usp_Recon_GetSides` per channel (captured legs from the BRN_DailyBanking{Channel} views with ReconBatchNoPumpIT = 0 vs statement lines with ReconState = 1, filters branch / date range / UnReconciled|Reconciled|All), `usp_Recon_ProcessBatch` (port sp_GenerateReconBatchNo; stamps ReconBatchNoPumpIT on the legs and ReconState/ReconBatchNo on the lines in one transaction, refuses a batch whose two sides differ by more than the tolerance setting), `usp_Recon_Unmatch`, `agora.ReconBatch` + `ReconMatch`; screens `/recon/{absa|fnb|yumbi|infinity|fleetcard}` and `/recon/{cashbags|cashmachine|smartatm|direct}` with stat strip (selected left, selected right, difference), extract; mobile: list + match sheet

**Acceptance**

- Processing a batch on the restore stamps both sides and the statement screen shows the lines as reconciled; unmatch restores both; phpunit `Recon/ProcessBatchTest` incl. the NULL-safe comparison case; Playwright `recon.spec`

**Notes for the builder:** Findings 2 and 3 of docs/pumpit-auto-recon-findings.md (NULL-unsafe compare routing unmatched lines down the matched path; ABSA comparing a bank line to a whole batch total) are regression tests here.


##### T046 · Auto-match engine: propose on batch number, then amount within the window, with confidence; auto-accept threshold

`epic E09` · `type feature` · `priority high` · `model opus` · `estimate L` · `sort 46`  
**Blocked by:** T045


**Deliverables**

- `usp_Recon_ProposeMatches` per channel (batch/merchant reference first, then amount ± tolerance within the Settings day window, then multi-leg sums; confidence certain|likely|review + evidence), 'Auto recon' runbar action rendering proposals as `<x-proposal>` rows for confirm/override, `usp_Recon_AutoAccept` above threshold, scheduler job per night with a summary email (`recon.auto_summary` template)

**Acceptance**

- On July–August 2026 ABSA the engine matches ≥ the legacy sp_AUTOReconcile_ABSA_BankRecon count with zero false matches on a hand-checked sample of 50 (QA lists them); phpunit `Recon/AutoMatchTest`

**Notes for the builder:** Rewrite, do not port: the legacy procs are the documentation of intent, not of correctness.


##### T047 · Banking & Reconciliation hub + cash and banking reports

`epic E09` · `type feature` · `priority high` · `model sonnet` · `estimate M` · `sort 47`  
**Blocked by:** T044, T045


**Deliverables**

- `/recon` hub page (KPIs: imports failed overnight, ABSA lines unreconciled + R, cash bags unreconciled + R, cash short deductions; the 23 functions grouped Import / Banking / Reconcile / Masters / Monitoring / Reporting / Correction); report procs + screens (definitions bind in T062) `Cash declared against cash banked`, `Card takings against settlement`, `Cash devices and Smart ATM`, `Unmatched bank lines` (queue), `Accounts raised at the till`, `Card terminals gone quiet` (ABSA terminals last used)

**Acceptance**

- Hub tiles reconcile to the queues they open; Playwright `recon-hub.spec`


#### E10 · Stock counts & production

*One count, several views; pre-production yield; waste; month end; the correction tools.*


##### T048 · Stock count model: recon header/lines/requests/employees, area locks, capture + JSON request procs

`epic E10` · `type feature` · `priority critical` · `model opus` · `estimate XL` · `sort 48`  
**Blocked by:** T029, T022, T023, T025, T014


**Deliverables**

- Adopt STK_StockRecon, STK_StockReconLine (6.2M), STK_StockReconRequest/LineRequest, STK_StockReconEmployees(+Request), RCN_ReconArea through `agora.vw_StockRecon*`; procs `usp_Stock_PrepareCount` (port sp_CaptureStockRecon, sp_CaptureStockReconRequest, sp_GetAreaStockItems, sp_GetAreaEmployeesAs*), `usp_Stock_SaveCount` (JSON, port of the sp_JSON_ProcessStockReconRequestJSON family with validation), `usp_Stock_AssignAreaEmployees` (port sp_CaptureAreaEmployee), `usp_Stock_LockArea` / unlock with reason, `usp_Stock_QtyVarCheck` (port sp_CheckQtyVarGreaterThanZeroandMoreThanQtyMaxAllowance); Stock count screen (branch/date/shift/area → item grid: opening, issued, closing, computer, variance) and the Area lock dashboard (ref, area, variance, locked/open chip); mobile count capture (area → item cards, keypad)

**Acceptance**

- A count saved on the restore appears in STK_StockReconLine with the same shape PumpIT writes (compare a legacy row); locking an area freezes its lines; the console step 'Stock recon areas' reflects locks; phpunit `Stock/CountTest`; Playwright `stockcount.spec` desktop + mobile

**Notes for the builder:** STK_StockReconLine's key is (BranchId, TransactionDate, ShiftNo, AreaNo, StockItemNo) — all five, always. StockItemNo alone fans out 11.23×.


##### T049 · Stock count views: overview by department, exceptions (day/night), balancing (amended vs original), per-shift variance, count exceptions (merged), counts by area, high-risk categories, loss by department

`epic E10` · `type feature` · `priority critical` · `model opus` · `estimate L` · `sort 49`  
**Blocked by:** T048


**Deliverables**

- Procs + screens (definitions bind in T064): `usp_Stock_Overview` (theoretical vs count by branch/department, valued at cost, share of sales), `usp_Stock_Exceptions` (day/night side by side, short/over/balanced), `usp_Stock_Balancing` (amended open/issued/close/computer vs original — every amendment on the same row as what was first counted; reads _SelectStockReconBalancing shape), `usp_Stock_ShiftVariance`, `usp_Stock_CountExceptions` (ran out / no stock / no issue / incorrect issue / no movement / amended qty — one list with a reason filter, replacing seven reports), `usp_Stock_CountsByArea`, `usp_Stock_HighRiskCategories` (lotto/airtime/electricity, bakery & counter), `usp_Stock_LossByDepartment`; screens for overview / exceptions / balancing / shift variance; stock loss by employee tab of T041 completed

**Acceptance**

- Variance totals equal PumpIT's Stock Variance Summary By Department for a closed count (paste); the balancing screen shows an amended row with its original; phpunit per proc

**Notes for the builder:** Auto-balancing rolls a closing count forward as the next shift's opening — correct default, but every amendment must stay visible or a recon can be balanced into looking correct.


##### T050 · Pre-production and yield (bulk in / used / out, butchery, bakery, chicken)

`epic E10` · `type feature` · `priority high` · `model opus` · `estimate M` · `sort 50`  
**Blocked by:** T048


**Deliverables**

- Adopt PREPROD_Type/Product/Recipe/RawMaterial/StockRecon/StockReconLine(+Employees); `usp_Stock_PrepareProduction` (port sp_CapturePREPROD_Product / sp_CapturePREPROD_PrimalStockRecon / sp_CaptureStockReconLine_PreProduction / sp_CheckStockReconPreProductionCaptured), `usp_Stock_SaveProduction`, `usp_Stock_Yield` (produced ÷ used, trended); screen with type filter (Beef, Chicken, Bulk Bakery), yield column and chart; `Production and yield` and `Raw material loss by rand` definitions; mobile capture

**Acceptance**

- Yield for a week on the restore matches a hand calculation; phpunit `Stock/YieldTest`

**Notes for the builder:** Yield is the number to watch, not production short on its own.


##### T051 · Waste management: capture, reason, approval; mobile

`epic E10` · `type feature` · `priority high` · `model sonnet` · `estimate S` · `sort 51`  
**Blocked by:** T048, T019


**Deliverables**

- New `agora.Waste` (BranchId, date, POS code, item, area, qty good/bad, price, value, reason code, captured by, approved by/at) + `usp_Stock_PrepareWaste` (port sp_CaptureWaste), `usp_Stock_SaveWaste`, `usp_Stock_ApproveWaste`; screen + mobile capture; `stock.waste_awaiting` template; console 'Waste to approve' item

**Acceptance**

- Captured waste reduces the theoretical on the next count (proc test) so it stops reading as theft; Playwright `waste.spec` mobile


##### T052 · Month end (branch + HO), clear EOD data, manual day-end capture (DBF replay), NAMOS capture

`epic E10` · `type feature` · `priority medium` · `model opus` · `estimate M` · `sort 52`  
**Blocked by:** T029, T048, T037


**Deliverables**

- `usp_Stock_MonthEnd` (branch position before close; HO close across the estate once every branch has counted — port sp_InsertCloseOffDayDates / sp_SelectCloseOffDay), `usp_Stock_ClearEod` (port sp_ClearEODData: reverses a day end captured on the wrong date — permission-gated, reason required, audit), manual day-end capture (replay a branch DBF for a failed day; SS_POSDBFZIP), NAMOS day-end capture for Total Hluhluwe (NAMOS_Header/Detail/Payment/Total/Items → the same day-end shape); screens under Today → Capture and Control → Governance

**Acceptance**

- Clear EOD on the restore reverses exactly the rows sp_ClearEODData would (diff the two); month-end close refuses while a branch has an open count; phpunit `Stock/MonthEndTest`

**Notes for the builder:** Total Hluhluwe on NAMOS is why its shop and fuel margin are missing from the convenience pack — bringing it onto the same day-end shape completes the estate view.


#### E11 · Purchase requests & approvals

*Header + lines, numbering, and approval by band and delegate with escalation — the control that clears 607 unanswered requests.*


##### T053 · Purchase requests: header + lines, numbering, types (expense cash / credit), quote, justification, new-PR drawer, mobile raise

`epic E11` · `type feature` · `priority critical` · `model opus` · `estimate L` · `sort 53`  
**Blocked by:** T022, T024, T026, T013, T014, T042


**Deliverables**

- Adopt BRN_Transaction / BRN_TransactionLine (TypeCode CA|CR via BRN_TransactionType) through `agora.vw_PurchaseRequest*`; `usp_Purchasing_NextNumber` (port sp_GetNextPurchaseRequestNumber / SS_UniqueNumber), `usp_Purchasing_Save` (JSON lines: expense code, description, qty, amount, VAT via GetVatDetail port), `usp_Purchasing_Submit`, attachments (quote), justification; PR list with status chips, header + detail pane (mockup viewPurchaseRequests), new-PR drawer, mobile raise blade; `purchasing.pr_submitted` template

**Acceptance**

- Numbering matches the legacy sequence with no gaps/duplicates under concurrent submits (phpunit with parallel transactions); a PR against 'Till Balance Expense' is refused (T042 guard); Playwright `pr.spec` desktop + mobile


##### T054 · Approval engine: value bands, named delegates, escalation after n days, approval log; PR approval screen

`epic E11` · `type feature` · `priority critical` · `model opus` · `estimate L` · `sort 54`  
**Blocked by:** T053, T019, T010


**Deliverables**

- `agora.ApprovalBand` (module, min/max value, approver role/user), `ApprovalDelegate` (from/to/period), `usp_Purchasing_Decide` (approve/decline with reason; band check; delegate resolution), `usp_Purchasing_Escalate` (scheduler: after Settings days → next band / named escalation; `purchasing.pr_escalated` template), ApprovalLog on every decision; PR approval screen (HO: queue by age and value, approve/decline/send back, batch approve within band); reuse for staff shorts and waste (same engine, module = cash|stock)

**Acceptance**

- The 607-request backlog on the restore is presented ranked by age × value and can be cleared in batches within band; an approval outside the user's band is refused; escalation fires on the scheduler and emails; `Approvals and overrides` report reads ApprovalLog; phpunit `Purchasing/ApprovalTest`

**Notes for the builder:** All 119 expense codes route to one approver today. Bands with named delegates and automatic escalation clear the queue without weakening the control.


##### T055 · Load approved requests to daily banking expenses + approvals audit report

`epic E11` · `type feature` · `priority medium` · `model sonnet` · `estimate S` · `sort 55`  
**Blocked by:** T054, T037


**Deliverables**

- `usp_Purchasing_LoadApprovedToBanking` (port sp_Load_ApprovedPurchaseRequest → BRN_DailyBankingExpenses leg), triggered on approval for cash PRs; `Approvals and overrides` definition

**Acceptance**

- An approved cash PR appears as an expense leg on that day's cashup with the PR number as reference; phpunit


#### E12 · Utilities & assets

*Meter readings with the zero-usage flag; the asset register, movements and repair job cards with the comeback rule.*


##### T056 · Utility meters & readings with zero-usage flag, prepaid units, tariff costing; mobile

`epic E12` · `type feature` · `priority high` · `model sonnet` · `estimate M` · `sort 56`  
**Blocked by:** T022, T029


**Deliverables**

- Adopt BRN_UtilityTransaction; `usp_Utilities_PrepareReadings` (port sp_CaptureUtilityTransaction / sp_SelectUtilityTransaction), `usp_Utilities_Save`, `usp_Utilities_ZeroUsage` (a run of zero-usage days flagged on capture → exception candidate), tariff per meter type (B/L/R/S) in Settings; screen + mobile capture; `Utility meter readings` definition

**Acceptance**

- Usage = closing − opening (+ prepaid) and cost at tariff for a month on the restore match a hand check; Playwright `utilities.spec` mobile


##### T057 · Assets: register (cost, depreciation, NBV, condition), movement, repair job cards with the 30-day comeback rule, asset masters

`epic E12` · `type feature` · `priority medium` · `model sonnet` · `estimate L` · `sort 57`  
**Blocked by:** T021, T024, T014


**Deliverables**

- Adopt ASSET_AssetType and the register tables (verify the full ASSET_* list on the restore — only two appear in the top-200 dump); new tables where absent (`agora.Asset`, `AssetMovement`, `AssetRepair`, `AssetGroup/Type/Owner`); `usp_Assets_Depreciate` (straight-line by group), `usp_Assets_Move` (transfer / disposal / write-off / return from repair), `usp_Assets_Comebacks` (second call on the same asset within 30 days); screens Asset register, Asset movement, Asset repair + masters

**Acceptance**

- Comeback flag reproduces the 'supplier forensic' logic on the 1,385 reviewed job cards if they exist on the restore; Playwright `assets.spec`


#### E13 · Exception register (Control)

*An exception is an object with a life. Rules per domain, a fingerprinted register, owners, ages, escalation.*


##### T058 · Exception engine: register, rules, fingerprint upsert, lifecycle, owners, escalation

`epic E13` · `type feature` · `priority critical` · `model opus` · `estimate L` · `sort 58`  
**Blocked by:** T026, T019, T010, T014


**Deliverables**

- `agora.Exception`, `ExceptionRule`, `ExceptionEvent` (§3.12); `usp_Exception_Upsert` (fingerprint: raise new / keep age / clear-by-data); rule procs for the first eight domains with the mockup's categories and owners: `usp_Exception_Buying` (PRs unanswered > n days; value), `Banking` (cashups not confirmed, unmatched lines by age, declared not banked), `Fuel` (days cover, meter gaps > threshold, mech-off days), `Stock` (departments short > threshold, areas unlocked after close, counts not done in n days), `Margin` (below cost, below MinGP, no cost), `Pricing` (same product different cost across sites), `Masters` (categories with no band, products without cost), `Loads` (failed overnight loads); scheduler; `exceptions.escalation` template; lifecycle actions own/action/clear with note; bell badge provider

**Acceptance**

- Running the rules on the restore raises the mockup's headline items (607 PRs awaiting worth R2.2m; FNB unreconciled; etc.) with correct values; re-running does not duplicate and clears what the data no longer supports; phpunit `Exceptions/EngineTest`

**Notes for the builder:** An exception exists only as long as somebody has the report open today — that is why 38 % of card lines are still unmatched after two months. Persist it.


##### T059 · Control screens: exception register (KPIs, by owner, control desks), my queue, branch open items; mobile list

`epic E13` · `type feature` · `priority high` · `model sonnet` · `estimate M` · `sort 59`  
**Blocked by:** T058, T030


**Deliverables**

- `/control` page (page head, KPI strip: open, value at stake, owners, oldest; register list sorted by severity with expand; 'The control desks' links with live counts), `/control/mine`, the branch console 'Open items' provider replaced with the real register, exception detail drawer with lifecycle + link to the source screen filtered; mobile list blade

**Acceptance**

- Counts on the page equal the register's rows for the scope; clicking an item opens its screen with the filter applied; Playwright `control.spec` desktop + mobile


#### E14 · Overnight-load monitoring

*What loaded last night from each POS system, what failed and why — over MIST_Import and PumpIT's own logs.*


##### T060 · Overnight-load status / import dashboard and load errors over MIST_Import + PumpIT logs

`epic E14` · `type feature` · `priority high` · `model opus` · `estimate M` · `sort 60`  
**Blocked by:** T021, T014, T058


**Deliverables**

- `agora.LoadRun` projection (`usp_Imports_OvernightStatus` reading SS_ErrorLog, SS_ImportFuelInputLog, SS_JobLog, RCN_ReconImports, SS_POSDBFZIP and the `_DASHBOARD_DailyX_{POS}_{SALES|CUSTOMER|SUPPLIER}` occurrence tables for import-vs-EOD-vs-Zread differences), `usp_Imports_LoadErrors` (source, branch, level, message); screens Import dashboard (branch × source, loaded at, rows, status, message) and Import error log; `Overnight load status` (queue) and `Load errors` (queue) definitions; Loads exception rule consumes it; sign-in system-state 'loads failed' provider

**Acceptance**

- Last night's status on the restore lists every source per branch with the same failures SS_ErrorLog shows (paste); a failed load raises a Loads exception; Playwright `imports.spec`

**Notes for the builder:** A failed load is what makes a report look wrong the next morning — this is where people check first.


### Agora M3 — Trade, reporting & cutover

The reporting engine and the 97-report library, trading dashboards and site scorecards, the weekly Exco pack, budgets, the mobile pass over every capture screen, governance reports, help content, and the parallel-run / cutover from PumpIT. Optional importer rebuild (E19).


#### E15 · Reporting engine & library

*One runner, 97 definitions, scope as a parameter, saved views, extract, schedules, provenance.*


##### T061 · Reporting engine: ReportDefinition registry, parameter form, runner, extract, saved views, execution log, library page, legacy catalogue

`epic E15` · `type feature` · `priority critical` · `model opus` · `estimate L` · `sort 61`  
**Blocked by:** T014, T012, T020, T010


**Deliverables**

- `agora.ReportDefinition`, `ReportSavedView`, `ReportExecutionLog`, `LegacyReport` view over SS_Report; `ReportRegistry` seeder loading the 97 definitions (name, description, category, scope, tags queue|new|merged|renamed, legacy names) from `Modules/Reports/Config/library.php` — content taken verbatim from the mockup's PIT.library; runner `/reports/{code}` (params from ScopeJson → `usp_Report_{Code}` → grid + extract + saved views + default per role); library page (14 categories, lib-cards with scope/desc/was, search), legacy catalogue page (23 categories / 161 reports, 'now called' links), report usage logging

**Acceptance**

- A definition whose proc is missing renders 'coming' not 500; saved view per role applies on open; palette finds reports by legacy name; Playwright `reports.spec`

**Notes for the builder:** Scope is a parameter, not a report. One definition per question; never a 'Site Select' twin.


##### T062 · Report procs batch 1 — Day end (7) and Cash & banking (10)

`epic E15` · `type feature` · `priority high` · `model opus` · `estimate L` · `sort 62`  
**Blocked by:** T061, T031, T037, T044, T045


**Deliverables**

- `usp_Report_*` for: Day close status, Unallocated Z-reads, Z-reads with no cashup, Z-reads that disagree with the cashup, Open cashups (FY param), Card terminals gone quiet, Overnight load status; Daily banking — manager's view, Cash declared against cash banked, Card takings against settlement, Deposits with no home, Cash devices and Smart ATM, Pump shortages, Accounts raised at the till, Bank reconciliation summary, Unmatched bank lines, Bank charges by site — each with a phpunit fixture comparing a known branch/period to a hand query

**Acceptance**

- All 17 definitions run from the library with extract; queues open the working screen filtered

**Notes for the builder:** Where a screen task already built the proc (T031, T037, T044, T045, T047) reuse it — the definition binds to the existing proc.


##### T063 · Report procs batch 2 — Fuel & forecourt (9) and Sales (6)

`epic E15` · `type feature` · `priority high` · `model opus` · `estimate L` · `sort 63`  
**Blocked by:** T061, T035


**Deliverables**

- Fuel: Tank reconciliation, Pump meter check, Pump readings, Every fill in detail, Slow-to-pay fills, Pump attendant performance, Diesel cashback register, Fuel price and margin history, Taxi association litres (from T035/T034). Sales (MIST_Import sales families + MIST_Trans_Sales, category → PC via SDK_CategoryGLCode with the WINBRANCH mapping gap flagged): Sales by product, Sales by category, Best and worst sellers, Fuel sales by grade, Sales by till, Airtime/lotto/vouchers (virtual, commission)

**Acceptance**

- Sales by category for a month equals the Exco pack's Shop and GP figures for the same site within the pack's documented corrections; per-proc phpunit

**Notes for the builder:** Item → category mapping is unreliable for WINBRANCH (912 QSR/Virtual items leak into shop views — data issues doc #5). Surface the unmapped count on the report rather than hiding it.


##### T064 · Report procs batch 3 — Margin (6), Products & stock (8), Stock counts (6)

`epic E15` · `type feature` · `priority high` · `model opus` · `estimate L` · `sort 64`  
**Blocked by:** T061, T049, T025


**Deliverables**

- Margin: Products under GP floor, Below cost (queue), No cost price, Above band, Margin by product, Category rules. Products & stock: Product master, lubricants (barcode-matched), tobacco, Stock on hand and cover, Out of stock now (queue, critical lines), Lines not counted (days param), Dead and slow-moving stock (L_SOLD not M_SOLD — data issues #4), Same product different cost. Stock counts: Stock count, Loss by department, Count exceptions, Counts by area, High-risk categories, Production and yield

**Acceptance**

- Below-cost list for a site matches a hand query; dead-stock excludes items with a recent last-sold date; per-proc phpunit

**Notes for the builder:** ARCH product enrichment uses `WHERE baseitem = 1` (1:1); never keep-first dedupe — prices genuinely diverge inside a basecode.


##### T065 · Report procs batch 4 — Convenience (6), OK stores (9), Franchise brands (4), People & shifts (6)

`epic E15` · `type feature` · `priority medium` · `model opus` · `estimate L` · `sort 65`  
**Blocked by:** T061, T041, T049


**Deliverables**

- Convenience: Site trading profile, Product profitability, Stock cover and rate of sale, Raw material loss by rand, Count trace header→footer, Franchise kitchen investigation. OK (ARCH): Stock master with rate of sale, Department sales GP & shrinkage, Sales by product per day, Stock variance by product, Loss investigation pack, Till overrides by cashier, Stock movement, Supplier deliveries by line, Price list for KVI review. Franchise (AURA): Brand trading, Menu item performance, Kitchen inputs never relieved, Service and delivery times. People: Roster vs clock-in, Shifts worked, Overtime exceptions, Cash shorts and deductions, Stock loss by person and area, Tender shortfalls by person

**Acceptance**

- Each runs with extract; the ARCH ones use the (MIST_BranchId, branch) pair and never union the _OLD twins (phpunit asserts row counts against the live table only)

**Notes for the builder:** Roster/clock-in/overtime need a roster source that PumpIT does not hold — mark those three definitions 'needs source' and raise the decision (D-06) rather than inventing data.


##### T066 · Report procs batch 5 — Source data (6) and Audit trail (4)

`epic E15` · `type feature` · `priority medium` · `model sonnet` · `estimate M` · `sort 66`  
**Blocked by:** T061, T010, T060


**Deliverables**

- Source data: Sales extract (source param), Stock master extract, Purchase journal, Fuel volumes extract, Category mapping (source category → Agora category → GL), Load errors. Audit: User activity, Master data changes, Report usage, Approvals and overrides

**Acceptance**

- Extracts stream to XLSX above the grid limit; audit reports read only the audit tables


##### T067 · Provenance drill: every total opens its audit (source, load, corrections)

`epic E15` · `type feature` · `priority medium` · `model opus` · `estimate M` · `sort 67`  
**Blocked by:** T061, T060


**Deliverables**

- `drill_chain` on report definitions: click a figure → the rows behind it → the load run they came from → corrections applied (the Exco pack's nine corrections modelled as `agora.Correction` rows); `<x-provenance>` drawer

**Acceptance**

- A group turnover figure drills to site rows, then to the load that supplied them, then shows any correction with its reason; Playwright `provenance.spec`


#### E16 · Trade dashboards, Exco pack & budgets

*The group position, league table, contribution, site scorecard, the 36-sheet Exco pack ported from ZP-NQL, and budgets in their natural home.*


##### T068 · Group trading position dashboard, site league table, profit-centre contribution, sites not trading

`epic E16` · `type feature` · `priority high` · `model opus` · `estimate L` · `sort 68`  
**Blocked by:** T061, T015, T063


**Deliverables**

- `usp_Trade_GroupPosition` (TO/GP vs LY and budget by PC, daily litres + c/ℓ, margin vs budget, mix), `usp_Trade_League` (rank on TO/GP/growth with movement), `usp_Trade_PcContribution`, `usp_Trade_SitesNotTrading`; `/trade` dashboard with the mockup's KPI strip, daily bars, margin line, mix donut, TO→GP bridge, exceptions card; league and mix pages; scope bar drives everything; report definitions Group trading summary, Site league table, Where the profit comes from, Sites not trading, Sites earning below their band, Site rating against incentive threshold (needs audit-score source — D-06)

**Acceptance**

- Group totals equal the Exco pack's Group Summary for the same period; Playwright `trade.spec`

**Notes for the builder:** Sources: PumpIT MIST_Trans_Sales (+ vw_Rev_RawFuelSales derivation of grade), Alteryx zp_exco_fact/fuel_daily/budget as the pack uses; read-only connections.


##### T069 · Site scorecard

`epic E16` · `type feature` · `priority medium` · `model sonnet` · `estimate M` · `sort 69`  
**Blocked by:** T068


**Deliverables**

- `/trade/site/{branch}`: trading profile (TO, GP, growth, stock position), PC breakdown, open exceptions, day-close history sparkline, top movers; mobile blade

**Acceptance**

- Figures match the group dashboard's row for the site; Playwright


##### T070 · Weekly Exco trading pack ported from ZP-NQL (36 sheets, howto, site tabs, cache, export)

`epic E16` · `type feature` · `priority critical` · `model opus` · `estimate XL` · `sort 70`  
**Blocked by:** T061, T015, T035, T044, T049


**Deliverables**

- Port `ExcoPackService` / `ExcoSourceService` and the tab blades from ~/Development/ZP/Zulu Petroleum/app/Modules/Exco into `Modules/Exco` (sources unchanged: Alteryx zp_exco_fact / zp_exco_fuel_daily / zp_exco_budget; FuelSheet wet stock; PumpIT cash & banking; MIST_Import stock, WINBRANCH_FC_TRANS); render with `<x-data-grid>`/`<x-chart>` instead of the NQL views; the 36 sheets in PIT.exco.order (Group Summary … Meter by Pump, Exceptions, 9 site tabs) + 'What the pack corrects'; `agora.ExcoSnapshot` cache with CACHE_VERSION; XLSX export of the pack; `view_exco_pack` permission; Executive landing

**Acceptance**

- Every sheet's figures equal the ZP-NQL pack for the same period (automated diff on the JSON payloads — paste the diff summary); Executive role lands here; Playwright `exco.spec`

**Notes for the builder:** Read the Exco module README's six gotchas first (FUEL money vs litres; TOP(1000) injection; part-day tails; SiteDimension merges Teds+Wimpy). Do not read the cashup schema.


##### T071 · Budgets in their natural home: RCN_SalesBudgets capture/import, pro-rating on trading days, budget vs actual

`epic E16` · `type feature` · `priority high` · `model opus` · `estimate M` · `sort 71`  
**Blocked by:** T068, T014


**Deliverables**

- Adopt RCN_SalesBudgets (empty today) + `agora.BudgetLine`; import from the Alteryx_Budget paste (Jul-25..Jun-26, 16 of 31 sites) and from XLSX; capture screen per site × category × month (TO, GP targets); `usp_Trade_BudgetVsActual` pro-rating per site on trading days; `Budget against actual` definition; feeds the group dashboard and the pack

**Acceptance**

- FY2027 budget loaded for every trading site; pro-rated actual vs budget for August matches the pack's EXCO 02 within rounding; data-issues #1 (budget starts Jul-2025) documented on the screen


#### E17 · Setup completion, governance & help

*Users & access polish, audit-trail reports, in-app help and the redesign notes.*


##### T072 · Users & access polish: delegation, user types, role landing preferences, 'switch role' for admins

`epic E17` · `type feature` · `priority low` · `model sonnet` · `estimate S` · `sort 72`  
**Blocked by:** T028, T054


**Deliverables**

- Delegation UI (approval delegates from T054 surfaced under the user), user type filter, per-user landing override, admin role preview

**Acceptance**

- Playwright `users.spec` extended


##### T073 · Audit-trail reports, report usage, approvals & overrides, retention

`epic E17` · `type feature` · `priority medium` · `model sonnet` · `estimate S` · `sort 73`  
**Blocked by:** T066, T010


**Deliverables**

- Governance page under Setup with the four audit definitions, retention job for UserActivity per Settings, 'reports not opened in a year' view feeding the library clean-up

**Acceptance**

- Retention removes only rows older than the setting; Playwright


##### T074 · In-app help: article per screen, report descriptions, the 'why the redesign' page

`epic E17` · `type feature` · `priority medium` · `model sonnet` · `estimate M` · `sort 74`  
**Blocked by:** T011, T061


**Deliverables**

- `agora.HelpArticle` + `HelpScreenLink`, `<x-help-button>` on every page head, articles seeded per module (what the screen is for, the rule it enforces, the legacy screen it replaces), the `/design` page rendering PIT.ia principles and the naming note; report descriptions from the library

**Acceptance**

- Every routed screen has an article (composer check: route list vs HelpScreenLink seed); Playwright

**Notes for the builder:** Article copy comes from the mockup's page blurbs and notes — they were written for exactly this.


#### E18 · Mobile pass

*A purpose-built mobile blade for every capture screen and the console; device test matrix.*


##### T075 · Mobile pass over every capture screen and the console; device test matrix

`epic E18` · `type feature` · `priority critical` · `model opus` · `estimate L` · `sort 75`  
**Blocked by:** T016, T030, T032, T033, T036, T037, T039, T040, T048, T051, T053, T056, T059


**Deliverables**

- A purpose-built `mobile.*` blade for: console, pump readings, fuel input, Z-read confirm, cashup confirm, drop safe, staff short, waste, utilities, PR raise + approve, stock count, exception list, report card list; shared mobile modes verified on grid/params/KPI/tabs/charts; Playwright mobile project covering each; a device matrix (Android Chrome, iOS Safari, 375 and 768 widths) run once on real devices with screenshots attached to the QA

**Acceptance**

- No horizontal scroll on any screen at 375 px; every capture completes with the on-screen keypad; desktop toggle works everywhere

**Notes for the builder:** Clean over capable: a screen that does less but works beats a broken affordance.


#### E19 · Importers under Agora (optional)

*Move the POS/tender parsers under Agora ownership. Only after M2 is live and only if ZP wants PumpIT's importer retired.*


##### T076 · (Optional) Importers under Agora: WinBranch DBF, ARCH, AURA, Pilot, NAMOS parsers + load procs into MIST_Import; retire PumpIT's importer

`epic E19` · `type feature` · `priority low` · `model opus` · `estimate XL` · `sort 76`  
**Blocked by:** T043, T060


**Deliverables**

- Parsers per POS family writing the same MIST_Import shapes (or Agora-owned landing tables with views), scheduler, per-run LoadRun rows, cut-over plan per source, PumpIT importer switched off source by source

**Acceptance**

- A month of parallel loads produces identical row counts and totals per family (diff report attached); only then is a PumpIT importer retired

**Notes for the builder:** Only after M2 is live and only if ZP wants it. GAAP and NAMOS were out of scope for the join map; NAMOS is in scope here because of Total Hluhluwe.


#### E20 · Parallel run, cutover & decommission

*Report parity against PumpIT, user migration, training, go-live, PumpIT screens switched off.*


##### T077 · Parallel run and report parity against PumpIT

`epic E20` · `type feature` · `priority critical` · `model opus` · `estimate L` · `sort 77`  
**Blocked by:** T062, T063, T064, T065, T070


**Deliverables**

- `agora:parity` command running each ported report and its legacy equivalent (SS_Report SQL where retrievable, or the legacy proc) for the same params and diffing totals; a parity dashboard under Control → Governance; a month of daily parity runs with the differences explained and either fixed or documented as corrections

**Acceptance**

- Parity ≥ 99.5 % of totals on the 40 most-used reports (SS_Report execution log decides which) for one full month, with every residual explained in the parity dashboard


##### T078 · Cutover: user migration completion, training, go-live checklist, PumpIT screens switched off, decommission plan

`epic E20` · `type feature` · `priority critical` · `model opus` · `estimate M` · `sort 78`  
**Blocked by:** T077, T075


**Deliverables**

- Go-live runbook (deploy ritual on the live instance, sqlsrv drivers, backups before migration, `migrate --force`, `seed:master` safe only, php-fpm restart, smoke checks), role-by-role training notes from the help articles, a switch-off order for PumpIT functions (branch capture first, HO recon second, reporting last), decommission of SS_ReportScheduler once Agora schedules run, post-go-live exception review cadence

**Acceptance**

- Live sign-in for the five roles, day-close completed at two pilot branches on Agora, PumpIT capture disabled for those branches, hypercare log kept for two weeks

**Notes for the builder:** Never deploy without Ryan's explicit go-ahead; enumerate users and back up before any destructive step on live (ZP-NQL rule 14 applies).


---

## 7. Gaps in the mockup that this plan fills

The mockup is a concept build on demonstration data. It shows *what* every screen should say; the following it does not show, and the plan supplies:

| Gap | Where the plan answers it |
|---|---|
| **No mobile at all** (three token media queries). Every capture screen is a wide grid. | T016 base, E18 pass, and the rule that every screen task decides its mobile answer. |
| **No back end.** Proposals, auto-match, exception lifecycle and day-close state are computed in the browser over a JSON blob. | §3.4 procs, §3.12 engine, T029, T036, T046, T058. |
| **Invented SQL.** The mockup's SQL panels reference `dbo.PumpTransaction`, `dbo.Cashup`, `dbo.ZReading` in `dbo` — none exist under those names. | §4 maps every screen to the real `BRN_* / STK_* / RCN_*` tables and MIST_Import families; the join-map rules are law (§3.4). |
| **No authentication or RBAC** (role picker "asks for no password"). | E02. |
| **No email, no scheduling, no escalation.** | E04, T054, T058. |
| **Approval bands, delegates and escalation** are described, not modelled. | T054. |
| **Budgets** come from an Alteryx paste covering 16 of 31 sites; `RCN_SalesBudgets` is empty. | T071. |
| **NAMOS site** (Total Hluhluwe) missing from the convenience pack. | T052 NAMOS capture; E19 optional. |
| **Provenance** ("every number carries its provenance") is a principle without a mechanism. | T067, LoadRun (T060), `agora.Correction`. |
| **Import parsers**: one screen, but nothing parses. | T043 with one real sample file per source. |
| **Deductions → payroll** file format unknown. | T041 (confirm layout with ZP — D-05). |
| **Roster / clock-in / overtime** reports have no source in PumpIT. | T065 marks them "needs source" — D-06. |
| **Site rating vs incentive threshold** has no audit-score source. | T068 — D-06. |
| **Settings / thresholds** (15 ℓ, R2,000, R3.00, 3-day window, auto-accept confidence) are hard-coded prose. | T026. |
| **Help content** exists as page blurbs. | T074 turns them into articles. |
| **Legacy user rights** (`BRN_ZUserRights`) vs the five roles. | T008 proposes, Ryan reviews. |
| **`cashup` schema pilot** (2026 auto-recon rebuild in production) is visually similar to Agora's cashup. | Explicitly not read (§3.3); Agora's cashup is the `BRN_DailyBanking` family + its own allocation/recon tables. |

---

## 8. Open decisions and risks

| # | Decision needed | Default the plan assumes | Blocks |
|---|---|---|---|
| D-01 | A **new PM project** for Agora (customer Zulu Petroleum) or tasks under the existing ZP-NQL project. | New project "Agora" with the three milestones; ZP-NQL keeps its own. | Opus task creation |
| D-02 | **Where Agora is hosted** (a ZP box beside the SQL instance, or a Ceratine-managed VPS like zp-db.ceratine.com) and whether a **staging** restore of PumpIT can be provisioned. | Ceratine-managed Ubuntu VPS with sqlsrv drivers, pointed at PumpIT over the same private link ZP-NQL uses; staging = a second database on the same instance restored monthly. | T078, parity |
| D-03 | **Write access to PumpIT** for the `agora_app` login (db_owner on PumpIT; datareader on MIST_Import / Alteryx / FuelSheet). | Granted by ZP's DBA before T003 reaches live; local uses the restore. | first live migrate |
| D-04 | **PascalCase columns** (matches the estate) vs Laravel snake_case. | PascalCase, handled by `BaseModel`. | T003 |
| D-05 | **Payroll file layout** for deductions. | CSV per employee per period; confirm with ZP payroll. | T041 |
| D-06 | Sources for **roster / clock-in / overtime** and **site audit scores**. | Not in v1 unless ZP names a source. | T065, T068 |
| D-07 | **Reverb** live status in v1 or polling. | Polling every 60 s in v1; Reverb behind a flag. | T031 |
| D-08 | **Retire `_OLD` twins from views** entirely (they are frozen duplicates, never union). | Hidden by views; dropping is ZP's call. | — |
| D-09 | Whether E19 (importers) is in the commercial scope. | Optional; not estimated in M1–M3. | E19 |

**Risks.** (1) PumpIT is production from day one — every migration is forward-only and every proc is `CREATE OR ALTER`; a bad migration is a hot-fix migration, never a rollback. (2) The customer builds procs by hand in SSMS against production (`PREPROD_*`, `STUBBER_*`, `_DEFUNCT`, `cashup.*`); Agora pins itself to the stable core and fails loudly on a missing object rather than rendering an empty screen. (3) Lookup fan-out — the single most common PumpIT mistake; the composite-key rule and the rejected-paths list are enforced in review. (4) Data quality (data issues doc): budget starts Jul-2025; fuel-volume feed nearly empty; Zreadings_vs_Cashups dark since Nov-2025; dead stock overstated by M_SOLD; item → category mapping unreliable for WINBRANCH — each surfaces on the relevant screen as a count, never silently. (5) Parity — a month of parallel running (T077) is the only honest go-live gate.

---

## 9. Appendix

### 9.1 Legacy procedure families and where they go

| PumpIT family (dbo) | Count (top-200 sample) | Agora home |
|---|---|---|
| `sp_Capture*` (prepare capture grids: fuel input, mech readings, stock recon, area employees, pre-production, utilities, waste, virtual stock) | 10 | `usp_{Module}_Prepare*` ports (T032, T033, T048, T050, T051, T056) |
| `sp_Check*` (validation: day-end record, EOD dates, capture done, qty variance, terminal numbers, unsplit statement lines) | 16 | folded into the matching `Save*` proc validation |
| `sp_Insert*` / `sp_Load*` (loads into MIST_Trans_*, MOPS per channel, budgets, fuel price, approved PRs/staff shorts, DBF suppliers/debtors) | 40+ | T043 loaders, T055, T040, T025 |
| `sp_AUTOReconcile_{ABSA,FNB,CashBags,CashMachine,SmartATM}_BankRecon` | 7 | T046 rewrite |
| `sp_RPT_*`, `sp_DeductionSummaryReport*`, `sp_Employee*Report`, `sp_DASHBOARD_*_Occurrence`, `HJL_*` | 40+ | E15 report procs, T041, T060 |
| `sp_GetBranch*` / `sp_GetNext*` / `sp_Generate*` (lookups, numbering) | 15 | `usp_Core_NextNumber`, master lookups |
| `sp_AUDIT_Check*` | 13 | Exception rules (Masters/Loads domains) |
| `sp_JSON_*` (stock recon request JSON) | 5 | T048 |
| `GetVatDetail` (269M executions — the hottest object on the instance), `GetShiftNo`, `GenerateCostCode`, `fn_GenerateUniqueNumberFromDateTime` | functions | ported as `agora.fn_*` where used |
| `sp_c#*`, `sp_*diagram*`, `_MyProcs*`, `_WorkingSQL`, `*_DEFUNCT`, `*_Original` | — | not ported |

### 9.2 Report library — the 97 definitions by category (names as the mockup states them)

Exco (10): Group trading summary · Budget against actual · Fuel volume and margin · Where the profit comes from · Stock loss by department · Sites earning below their band · Site league table · Cash and banking exceptions · Sites not trading · Site rating against the incentive threshold.
Day end (7): Day close status · Unallocated Z-reads · Z-reads with no cashup · Z-reads that disagree with the cashup · Open cashups · Card terminals gone quiet · Overnight load status.
Cash and banking (10): Daily banking — manager's view · Cash declared against cash banked · Card takings against settlement · Deposits with no home · Cash devices and Smart ATM · Pump shortages · Accounts raised at the till · Bank reconciliation summary · Unmatched bank lines · Bank charges by site.
Fuel and forecourt (9): Tank reconciliation · Pump meter check · Pump readings · Every fill in detail · Slow-to-pay fills · Pump attendant performance · Diesel cashback register · Fuel price and margin history · Taxi association litres.
Sales (6): Sales by product · Sales by category · Best and worst sellers · Fuel sales by grade · Sales by till · Airtime, lotto and vouchers.
Margin (6): Products selling under their GP floor · Products selling below cost · Products with no cost price · Products earning above their band · Margin by product · Category rules.
Products and stock (8): Product master · Product master — lubricants · Product master — tobacco · Stock on hand and cover · Out of stock now · Lines not counted · Dead and slow-moving stock · Same product, different cost.
Stock counts (6): Stock count · Loss by department · Count exceptions · Counts by area · High-risk categories · Production and yield.
Convenience stores (6): Site trading profile · Product profitability · Stock, cover and rate of sale · Raw material loss by rand · Count trace, header to footer · Franchise kitchen investigation.
OK stores (9): Stock master with rate of sale · Department sales, GP and shrinkage · Sales by product per day · Stock variance by product · Loss investigation pack · Till overrides by cashier · Stock movement · Supplier deliveries by line · Price list for the KVI review.
Franchise brands (4): Brand trading · Menu item performance · Kitchen inputs never relieved · Service and delivery times.
People and shifts (6): Roster against clock-in · Shifts worked · Overtime exceptions · Cash shorts and deductions · Stock loss by person and area · Tender shortfalls by person.
Source data (6): Sales extract · Stock master extract · Purchase journal · Fuel volumes extract · Category mapping · Load errors.
Audit trail (4): User activity · Master data changes · Report usage · Approvals and overrides.

The legacy names each replaces ("was") are in the mockup's `PIT.library` and are seeded into `ReportDefinition.LegacyNamesJson` by T061 so the palette answers old names.

### 9.3 Source documents read for this plan

`agoraretailconsole.html` (the mockup, 5.8 MB, data blob `PIT` with 48 datasets); `~/Development/CLAUDE.md` (report-back conventions); `~/Development/ZP/Zulu Petroleum/{CLAUDE.md, EXTENDED.md, CERATINE_API_INSTRUCTIONS.md, docs/10-architecture.md, docs/20-permissions.md, docs/exco-cash-banking-scope.md, docs/pumpit-auto-recon-findings.md, docs/relationships/HANDOVER.md, docs/relationships/pumpit-joins.json, app/Modules/Exco/README.md, app/Support/Seeding/*}`; `~/Development/ZP/PUMPIT_DATA.txt` (instance profile: 590 tables, 190+ procs, 8 functions, sizes, keys); `~/Development/ZP/data issues-zp.txt`; `~/Development/SAAS/ceratine/{CLAUDE.md, EXTENDED.md (module-structure, migrations, seeders, data-grid, ui-components, permissions, email, mobile, menu-seeders), Modules/Core/Models/SeedMaster.php, database/seeders/DatabaseSeeder.php}`.
