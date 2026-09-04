# The rules every feature must satisfy

What a screen has to do here, whatever it is about. Read this **when a feature
is requested**, before scoping it — half of these change the estimate, and all
of them are cheaper to design in than to retrofit.

`docs/development.md` is *how* you build; this is *what you must build*.
`docs/components.md` is what exists to build it with.

> Rules 1–4 below are Ryan's, dictated 4 Sep 2026, and are not up for
> negotiation without him. Anything under **Proposed** at the end is mine and
> can be struck out.

---

## 1. The databases

| Database | What it is | Access |
|---|---|---|
| **Agora** | **The primary database.** Everything Agora owns lives here | Read/write |
| **PumpIT** | The old system we are replacing | **Read-only** |
| **MIST_Import** | Reports and data | **Read-only** |
| **Alteryx** | Reporting extracts | **Read-only** |

Agora is not a guest in PumpIT any more. It reads the old estate through
`agora.vw_*` views, which name it across databases (`[PumpIT].dbo.SS_Branch`),
and it writes to nothing but its own.

---

## 2. Procedures

**All procedures live on the Agora database**, in the `agora` schema, named
`usp_{Module}_{Verb}{Object}`.

**Every user-facing grid is powered by a procedure.** Not a query builder chain,
not an Eloquent scope — a procedure. The reason is the customer: they are good
with their database, and a procedure is a thing they can open, read and change
without waiting for a release. That is a feature, not a workaround.

**The line is exposure.** If a person sees it, it comes out of a procedure. If
it is machinery — a lookup the UI never renders, a value some internal process
carries — it does not need wrapping. Do not wrap a config read in T-SQL to
satisfy a rule that was about the customer's visibility.

**Procedures are maintained through migrations wherever possible.** A `.sql`
file per procedure under `Modules/{M}/Database/Procedures`, deployed by
`v1__NNp_{module}_procs.php`. One `CREATE OR ALTER` per file.

### The grid procedure template

Every grid procedure takes the same shape, so the grid component can call any of
them without knowing which:

```sql
CREATE OR ALTER PROCEDURE agora.usp_{Module}_Grid{Object}
    @BranchIds   NVARCHAR(MAX) = NULL,   -- CSV of branch ids; NULL = every branch in scope
    @DateFrom    DATE          = NULL,
    @DateTo      DATE          = NULL,
    @Search      NVARCHAR(200) = NULL,   -- the grid's global filter
    @SortColumn  NVARCHAR(80)  = NULL,
    @SortAsc     BIT           = 1,
    @Page        INT           = 1,
    @PageSize    INT           = 50
AS
BEGIN
    SET NOCOUNT ON;

    -- Result set 1: the page of rows.
    -- Result set 2: one row, (TotalRows BIGINT), so the grid can say
    --               "showing 50 of 12,480" and decide about the export cap.
END
```

A grid procedure **reads and nothing else**. It carries a header comment saying
what it is for and who reads it, because the customer opens these in SSMS and a
procedure with no explanation is a procedure they will not touch.

---

## 3. Grids

Every grid, every module.

### 3.1 Inline column header filters, typed to the value

Each column carries its own filter in the header, and the control matches what
the cell renders — text gets contains/equals, numbers get comparators
(`>`, `>=`, `<`, `<=`, `=`, `!=`), dates get a range, a short value set gets a
tick-list.

ZP's two filter shapes are the ones to carry over: `{q, eq}` for a
comparator/substring/exact match, and `{in: [...]}` for the Excel-style set
filter, **capped at 500 values** so a filter cannot become a query of its own.

### 3.2 Export to CSV, with a ceiling

Every grid exports: **all rows**, or **a date range**. Above **100 000 rows** the
grid refuses and sends the user to the **Export centre**, which also produces
CSV but does it out of the request cycle.

The export must be **what the user is looking at** — their filters, their sort,
their column order, their visible columns — not the raw result set. ZP does this
by carrying the view state in the export URL and re-applying it server-side; a
malformed state degrades to an unfiltered export rather than failing the
download.

> **The trap ZP paid for, and we should design out:** in ZP the filter and
> aggregate semantics exist twice, once in PHP and once in the JavaScript
> renderer, with a comment begging the next person to keep them in lockstep.
> Agora's grid procedure already does the filtering and sorting, so the export
> should re-call the procedure with the same parameters rather than re-implement
> the matching. One set of semantics, in T-SQL.

### 3.3 A branches selector on head-office result sets

Every report and result set in the head-office workspace carries the
**branches** component — a select that picks one, several, or all branches, with
its options scoped to what the user is granted and what the screen is about.

In the branch workspace it does not appear: `BranchContext` has already pinned
the site.

### 3.4 A grid says which procedure it came from

The procedure name is visible on the grid — a small line or a header control.
The customer reads it and goes straight to the thing they can edit, and a
support conversation starts at the right object instead of a screenshot.

### 3.5 Row click opens a detail panel inside the grid

Clicking a row opens a panel on the **right-hand side of the grid**:

1. **Record information at the top** — what this row is, in a line.
2. **An action panel** — edit / view / delete, each behind its permission
   (see §4).
3. **The detail** — the rest of what is worth knowing about the row.

It is a panel inside the grid, not a route change. Rule 3.7 is the route change.

### 3.6 The user owns the layout, and it persists

Column **order**, column **widths** and **text size** are the user's, per grid,
and survive a reload and a new session. They are stored in
**`agora.UserGridColumn`** — `(BranchId, UserId, GridKey)` unique, payload in
`ColumnsJson`.

`GridKey` is the **route name** (`app.cash.dropsafe`), and a screen with two
grids qualifies it (`app.cash.dropsafe:bags`). Not the controller class: one
controller serves several screens, and a class rename would silently orphan
everyone's saved layout.

**What ZP learned the hard way, all of which applies here:**

* **Whitelist what is stored.** ZP's endpoint accepts `sort`, `page_size`,
  `column_order`, `hidden`, `widths` and drops everything else — a test asserts
  an `evil` key does not survive. A user-controlled JSON blob written straight
  into a column is a payload for whatever reads it later.
* **Bound the widths.** ZP rejects 5px and 5000px. A width that arrives out of
  range is a bug or a fiddle, and either way it produces an unusable grid the
  user then cannot fix.
* **An empty widths map must store NULL, not `{}`.** "Reset column widths" has
  to fall back to auto layout; storing an empty map replays the old widths on
  the next load.
* **Fixed table layout is what makes a pixel width mean anything.** Under auto
  layout the browser re-divides the space and the saved width is a suggestion.
* **A column brought back after a resize must not render at zero.** The widths
  map is seeded from the columns visible at the time, so a re-shown column has
  no width of its own and collapses. ZP has a browser test for exactly this;
  Agora should have one too.
* **Persist on a debounce, not on every drag frame**, and prove it survives a
  reload in a browser test. A unit test cannot see whether the save actually
  fired.

### 3.7 A title or reference value navigates

Clicking a title, a document number, a bag number, a branch name — anything that
names another record — goes to **that resource's view endpoint**. Not a modal,
not a filter: a route change to `app.{module}.{resource}.show`.

A reference that leads nowhere should not be rendered as a link.

---

## 4. Forms

**Edit, save and delete each sit behind their own permission, for every resource
in every module.** Not one `module.write` covering the lot: `cash.dropsafe.edit`
is a different grant from `cash.dropsafe.delete`, and the Auditor role exists to
prove the difference.

The check happens in three places and all three are required — the route
middleware, the button (a control the user cannot use is not rendered), and the
procedure, which is the only one that cannot be bypassed.

**Delete is never allowed on a transactional entry.** A cashup, a Z-read
allocation, a drop-safe bag, a staff short — these are reversed, corrected or
cancelled, and the trail stays. Master data soft-deletes; transactions do not
delete at all.

---

## 5. The view screen

Every view screen carries:

* **Back to the index** it came from.
* **Edit**, where the user has the permission.
* **Delete**, where the user has the permission *and* the resource is not
  transactional.

---

## Proposed — mine, not Ryan's

Struck through nothing yet; these are for the conversation, not the rulebook.

### A. The one that needs a decision: who owns a procedure after go-live?

Rule 2 says the customer can change procedures directly, and rule 2 also says
procedures are deployed by migrations with `CREATE OR ALTER`. **Those two
collide on the next deploy** — our migration will silently overwrite whatever
they changed, and they will not find out until the number is wrong again.

Three ways out, and it is your call:

1. **Ours.** Procedures are code; the customer proposes changes and we ship
   them. Simple, and it takes away the thing you just gave them.
2. **Seeded once.** A grid procedure is deployed if it does not exist and left
   alone after that. Their edits stick; our improvements never arrive.
3. **Checksummed.** The deploy records a hash of what it wrote. On the next
   deploy, a procedure whose body no longer matches is **skipped and reported**,
   not overwritten. They keep their edits, we find out immediately, and someone
   decides per procedure.

I would build 3. It is perhaps a day, and it is the only one where nobody loses
work silently.

### B. Where filtering happens

Rule 3.1 (typed header filters) and rule 3.2 (100 000-row ceiling) are in
tension if the grid filters in the browser: at that size the page is already
gone before a filter is typed. I would say the grid procedure does the
filtering, sorting and paging — the header filter sends parameters and the
procedure answers — and client-side filtering is only ever an optimisation on a
page already in hand. Worth agreeing explicitly, because it decides the shape of
every grid procedure we write.

### C. Things a grid needs that are not on the list

* **A loading state.** These procedures run over big tables; a grid that goes
  blank for four seconds reads as broken.
* **An empty state and an error state**, both as deliberate as the happy path.
  `<x-empty-state>` exists.
* **"Showing 50 of 12,480."** The user must know when they are looking at part
  of the answer — it is also what makes the 100 000 ceiling comprehensible when
  they hit it.
* **The scope belongs in the URL.** A grid's branch and date range travel in the
  query string, so a link pasted into WhatsApp opens what the sender was looking
  at. The rulebook already says this about screens; grids are where it bites.
* **Numbers go through `Format`.** A missing figure is `—`, never `R0.00`.

### D. Permission slug shape

`{module}.{resource}.{action}` — `cash.dropsafe.view`, `cash.dropsafe.edit`,
`cash.dropsafe.delete`. It makes the Auditor role expressible as `*.view` plus
`audit.*`, which is what the plan already says it is. T008 needs this decided
before it starts.

### E. What "transactional" means, written down

So the delete rule is decidable without a conversation each time: **a row that
records something that happened at a point in time, carrying a business date and
an amount, a volume or a count.** Those are reversed. Everything else — a
branch, a product, a reason code, a user — is master data and soft-deletes.

### F. Forms and refusals

A `FormRequest` validates the **shape** — required, numeric, in range. The
**rule** stays in the procedure. When the procedure refuses, the
`AgoraProcException` maps onto a field error using its code, so the user sees
"a drop over R2,000 needs a reason" against the reason field rather than a 500.

### G. Every rule here should end up in a check

The mechanical ones can be: a grid component that renders without a `GridKey`, a
route with no permission on it, a delete button on a transactional resource, a
grid whose procedure is missing. Prose is not a check, and this document is
currently all prose.

---

## Where the answers are

| Question | Read |
|---|---|
| How the work goes, clone to shipped feature | [`development.md`](development.md) |
| Which components exist | [`components.md`](components.md) |
| The rules that cannot be broken | [`rules.md`](rules.md) |
| Full architecture and build order | [`AGORA_ARCHITECTURE_AND_BUILD_PLAN.md`](AGORA_ARCHITECTURE_AND_BUILD_PLAN.md) |
| ZP's grid, which these rules are drawn from | `~/Development/ZP/Zulu Petroleum` — `app/Support/GridViewState.php`, `app/Models/UserGridPreference.php`, `tests/Feature/UserGridPreferencesTest.php`, `tests/e2e/grid-column-resize.spec.js` |
