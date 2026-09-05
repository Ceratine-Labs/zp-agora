# The data grid

`<x-data-grid>` and everything behind it. Read this before building a screen
that shows rows — which, in this system, is nearly all of them.

[`feature-rules.md`](feature-rules.md) §3 is the contract; this is how it is
met, and where each rule lives in the code.

---

## Row for `docs/components.md`

Lane B owns `docs/components.md`, so the rows below are here for the reviewer to
merge rather than added there directly. Two go in **Shipped** (and
`<x-data-grid>` + `<x-drawer>` come out of **Not built yet**), one goes in the
**JavaScript** table.

| Component | Props | What it is | Notes |
|---|---|---|---|
| `<x-data-grid>` | `grid` (an `App\Grid\GridResult`), `branches`; `actions` and `bulk` slots | Every user-facing result set: typed header filters, server-side sort and paging, CSV/XLSX extract, a per-user column chooser, footer totals, mobile cards | **Replaces `<x-table>` for anything with filters, export or persistence** — it composes `<x-table>` internally, so the scroll container, the procedure name (§3.4) and the row count are the same code. A grid is declared once as a `GridDefinition` and registered in `config/grids.php`; the screen passes one prop. The component never queries — `GridService` builds the result |
| `<x-drawer>` | `id`, `title`, `note`, `copy`, `wide`; `actions` and `meta` slots | A panel from the right, over the page, for anything a screen must show without taking the reader's place | General-purpose, not the grid's. Opened by `data-drawer-open="{id}"` on any button; Escape, the scrim and Close all shut it, and focus returns to whatever opened it. The extract is its first caller |

| Module | What it does |
|---|---|
| `data-grid.js` | The column chooser, drag-to-resize, text size, and persisting all three to `agora.UserGridColumn` on a debounce; plus the drawer's open/close/copy and the grid's loading state. Deliberately **not** sorting, paging, filtering, row expansion or bulk selection — those are links, a form, `row-detail.js` and `check-all.js` |

---

## The shape

```
GridDefinition          what this grid IS — key, title, columns, source
   │                    one class per grid, registered in config/grids.php
   ├── GridColumn[]     the catalogue: key, label, format, sort, visible, wide…
   ├── GridFilter[]     derived from the catalogue unless overridden
   └── GridSource       ProcedureSource | EloquentSource

GridService             request + saved layout + defaults → GridQuery → GridResult
   ├── GridQuery        the cleaned question. Also what the extract re-runs
   ├── GridColumnState  the whitelist over agora.UserGridColumn
   └── GridResult       everything <x-data-grid> renders, and nothing it fetches

<x-data-grid>           the shell: structure only
   └── grid/_cells      the cell CONTENT — per grid, overridable
```

## Declaring a grid

```php
// Modules/Cash/Grids/DropSafeGrid.php
class DropSafeGrid extends GridDefinition
{
    public function key(): string   { return 'app.cash.dropsafe'; }
    public function title(): string { return 'Drop safe'; }

    public function columns(): array
    {
        return [
            new GridColumn(key: 'BagNo',  label: 'Bag',    mono: true, sort: 'BagNo'),
            new GridColumn(key: 'Amount', label: 'Amount', format: 'money', sort: 'Amount', total: true),
            new GridColumn(key: 'Status', label: 'Status', format: 'chip',  sort: 'Status'),
        ];
    }

    public function source(): GridSource
    {
        return new ProcedureSource('usp_Cash_GridDropSafe');
    }
}
```

then one line in `config/grids.php`:

```php
'app.cash.dropsafe' => Modules\Cash\Grids\DropSafeGrid::class,
```

and the controller:

```php
return view('cash::dropsafe.index', [
    'grid' => $this->grids->build($this->registry->findOrFail('app.cash.dropsafe'), $request, $request->user()?->Id),
    'branches' => $branches,   // null in the branch workspace
]);
```

```blade
<x-card :title="$grid->definition->title()" flush>
    <x-data-grid :grid="$grid" :branches="$branches" />
</x-card>
```

## The GridKey

**The route name**, qualified where a screen carries two grids:
`app.cash.dropsafe` and `app.cash.dropsafe:bags`. Never the controller class —
one controller serves several screens and a class rename silently orphans
everyone's saved layout (feature-rules §3.6).

`GridRegistry` refuses a definition whose own `key()` disagrees with the line it
is registered on, so that mistake fails on the first request rather than on the
day somebody notices their columns stopped persisting.

The qualification also namespaces the query string: a grid keyed
`…:dayclose` reads `dayclose_sort`, `dayclose_page`, `dayclose_f[Status][q]`.
An unqualified key keeps the short names, because those are the URLs people
paste.

## The column catalogue

| Field | What it decides |
|---|---|
| `key` | the column in the result set, or the model attribute |
| `label` | the heading |
| `format` | alignment, which `Format` call writes it, and the header filter's type |
| `sort` | the `@SortColumn` value. **Absent means not sortable**, and the header renders as text rather than as a link that does nothing |
| `visible` | shown before the user has chosen otherwise |
| `wide` | prose: wraps, claims a minimum width |
| `mono` | a reference, a code, an id |
| `filter` | override the derived filter type, or `'none'` for no filter |
| `options` | the tick list for a set filter, capped at 500 |
| `total` | carried into the footer total row |

**Formats:** `text` `mono` `chip` `bool` `date` `datetime` `number` `money`
`rk` `lk` `litres` `cpl` `pct` `delta`.

The shell puts `num`, `mono` and `wide` on the cells — `num` and `mono` are
`<x-table>`'s own classes, because the grid composes that table rather than
restating it. The mockup's `dataGrid` called them `n` and `w`, and those have no
rule in this stylesheet at all: a money column carrying `n` renders left
aligned. `GridColumn::cellClasses()` is the one place that decides.

This is a **superset of `<x-reports::cell>`'s vocabulary**, with the same names
and the same meanings — `text` `number` `money` `litres` `date` `datetime`
`bool` `chip` all behave identically. A report moving onto the grid keeps its
column types unchanged. The additions are the tile and margin formats the build
plan names for T014.

## Where the paging happens

**In the procedure**, and in the query builder for an Eloquent source. PHP sends
eight parameters and receives at most one page.

This follows feature-rules **§B** — "the grid procedure does the filtering,
sorting and paging; the header filter sends parameters and the procedure
answers". Built to it as a proposal; **Ryan settled it on 5 Sep 2026** and it
is now the rule.

What it costs: the procedure re-runs its whole query for every page. The set is
built, counted, and one page is taken; page 40 of a 10 000-row answer costs the
same as page 1. Measured against a 10 000-row procedure on the local container:

| page | size | rows | total | ms |
|---|---|---|---|---|
| 1 | 50 | 50 | 10 000 | 815 (cold) |
| 40 | 50 | 50 | 10 000 | 236 |
| 100 | 50 | 50 | 10 000 | 321 |
| 200 | 50 | 50 | 10 000 | 259 |

Flat, which is the point: nothing degrades as the user pages in.

## Header filters and the ninth parameter

The eight-parameter template has nowhere to put a per-column filter, and §3.1
requires one. A grid procedure that wants header filters declares a **ninth**
parameter, `@FiltersJson NVARCHAR(MAX)`, and reads it with `OPENJSON`. The
definition's source says whether its procedure has one:

```php
new ProcedureSource('usp_Cash_GridDropSafe', acceptsFilters: true)
```

A procedure that does not accept it **may not declare header filters** —
`ProcedureSource` throws rather than dropping them, and rather than filtering in
PHP. Filtering in PHP would put the matching semantics in two places, which is
the trap §3.2 says ZP paid for.

The JSON is an array of `{column, type, op, value}` or `{column, type, in: […]}`.
An Eloquent source answers the same shapes in the query builder.

## The extract

`GridExtract::run()` re-calls **the same source with the same `GridQuery`**, page
1, page size opened to the ceiling. Not a re-implementation of the filtering,
and not a loop over pages.

* The visible columns travel in the URL (`columns=Site,Amount,Status`), because
  the server does not otherwise know what the chooser has been doing since the
  page loaded. An unknown name is dropped; a list that names nothing real falls
  back to the catalogue.
* Above **100 000 rows** the extract is refused and the user is sent to the
  export centre. The size is probed before the rows are fetched, so an
  over-ceiling answer is never materialised.
* **Numbers export as numbers and dates as date serials**, not as the formatted
  text on the screen. A money column sums in the workbook it lands in and a date
  column sorts as a date; that is the whole reason somebody asks for the file.
  Chips, booleans and text export as the words a person reads.
* CSV is streamed with a UTF-8 BOM (Excel on Windows reads a BOM-less UTF-8 CSV
  as Windows-1252, and the site names in this estate then arrive as mojibake).
* XLSX is written by `App\Grid\Export\XlsxWriter`, which has **no dependency** —
  an .xlsx is a zip of a few XML parts and PHP ships `ZipArchive`. The sheet is
  written row by row to a temporary file, so the rows never all exist in PHP at
  once. Measured: 10 000 rows × 4 columns = 214 KB in ~1.2 s (1.0 s of XML,
  0.3 s of deflate). **That projects to roughly twelve seconds at the 100 000
  ceiling**, which is inside a request but not comfortably — see the open
  questions.

## The user's layout

Stored in `agora.UserGridColumn`, unique on `(BranchId, UserId, GridKey)`,
payload in `ColumnsJson`. Read and written **across branches**, like
`UserPreference`: a layout belongs to the person, not to the site they are
looking at. The row still carries the group entity's `BranchId`, never NULL.

Everything below is feature-rules §3.6, which is ZP's list of bugs that actually
happened:

* **Whitelisted.** Only `column_order`, `hidden`, `widths`, `text_size`,
  `page_size`, `sort`, `dir` survive `GridColumnState::sanitise()`. A test
  asserts an `evil` key does not.
* **Widths bounded** to 60–640px (`config/grids.php`). 5px and 5000px are both
  rejected.
* **An empty widths map is stored as ABSENT, not `{}`.** §3.6 words this as
  "stores NULL"; a key that is not in the JSON is the same thing to every reader
  of it, and it is what makes "reset the widths" reach auto layout.
* **An empty layout deletes the row** rather than storing `{}`, for the same
  reason one level up.
* **Fixed table layout** is applied from the server whenever the user has saved
  any width at all (`table.dg.is-fixed`), not only during a drag. Under auto
  layout a saved pixel width is a suggestion.
* **A column re-shown after a resize has no width of its own** and inherits auto
  sizing, rather than collapsing to zero. The widths map only ever holds columns
  that had one.
* **Persisted on a debounce** (500 ms) and on pointer-up, never per drag frame.
* **A column added to the catalogue after the user last chose keeps its own
  default.** The saved `hidden` list could not have mentioned it, and reading
  that absence as "shown" would switch on every column deliberately shipped off,
  for everybody who had ever opened the chooser.
* **A corrupt layout gives the default grid**, not a 500. It is a convenience.

## What the grid does NOT implement

Deliberately, because something else in the repo already owns it:

| Behaviour | Owner |
|---|---|
| Row expansion to a detail fragment | `row-detail.js` — the grid emits `data-row-detail` on the table and `data-detail-url` on rows when `detailMode()` is `expand`, and stays out of the way. It fetches once, keeps the result, leaves clicks on inner links alone, and takes **HTML** back rather than JSON |
| The select-all box and its indeterminate state | `check-all.js`. The grid's JS only counts what is ticked, for the selection bar |
| Sorting and paging | Links. The server sorts and pages, so a header is an `<a>` and the browser does the rest. Nothing re-sorts rows in the DOM |
| Filtering | The scope form. The filter inputs sit in the table and belong to it through `form="…"`, so the grid narrows with no JavaScript at all |
| Alerts and confirmations | `window.Agora.notify` (SweetAlert2). Resetting a layout asks first |
| Number formatting | `App\Support\Format` and `resources/js/format.js` |

**§3.5's right-hand detail panel is not built.** `row-detail.js`'s own reasoning
— that the answer to "which lines" belongs directly under the total it explains
— is a good argument, and shipping a second fetch-and-render for the side-panel
form would be exactly the duplication that comment exists to prevent. If the
panel is wanted as well as the expansion, it belongs in `row-detail.js` as a
second render target, not in the grid. Flagged for Ryan.

## The cells partial

The shell owns structure; the partial owns cell content. The default is
`grid._cells`, which writes every column from its declared format. A grid that
needs more ships its own and names it:

```php
public function cellsPartial(): string { return 'cash::grid._cells'; }
```

```blade
@switch($column->key)
    @case('BagNo')
        {{-- §3.7: a reference value navigates. A route change, not a modal —
             and from here, because which values name another record is the
             screen's business and the shell does not know. --}}
        <a href="{{ route('app.cash.dropsafe.show', $row->BagId) }}">{{ $row->BagNo }}</a>
        @break
    @default
        @include('grid._cell', ['row' => $row, 'column' => $column])
@endswitch
```

## Mobile

The card list is rendered **alongside** the table and the stylesheet shows one or
the other at 720px. Server-side device detection is T016 and does not exist yet,
and a viewport is a spectrum anyway — a narrow desktop window is the same
problem as a phone.

A card carries the first three **visible** columns on its face and the rest
behind a `<details>`. Which three is the user's: the chooser sets the order and
the visibility and the cards take the first three of what survives. A chooser
change reaches the table immediately and the cards on the next load, because
"the first three of what is visible" is a derivation the client would have to
redo rather than toggle.

## Development surface

`/app/dev/grids` — local and testing only. Two grids on one page: one over
`usp_Reports_GridDayClose` (the same procedure the Reports module renders through
`<x-table>`) and one over Eloquent. Same component, same props. It is also where
`tests/e2e/grid.spec.js` runs.

## Tests

| Where | What |
|---|---|
| `tests/Feature/Grid/GridDefinitionTest` | the catalogue, the filters, the whitelist, the register. No database writes |
| `tests/Feature/Grid/GridProcedurePagingTest` | 10 000 real rows out of a procedure: paging, numeric sort, the extract, the ceiling |
| `tests/Feature/Grid/GridStateTest` | the persistence round trip. The one test that writes; cleans up in `tearDown` |
| `tests/Feature/Grid/GridScreenTest` | the rendered markup — procedure name, counts, sort links, filter row, extract URL |
| `tests/Feature/Grid/BigSetFixture` | the throwaway 10 000-row procedure. Created and dropped by the test; **not** a module procedure, because nothing deploys it |
| `tests/e2e/grid.spec.js` | desktop and mobile: sort, filter, hide, reorder, resize-and-reload, extract, selection, both themes |

## Open questions

1. ~~**Proposed §B is still a proposal.**~~ **Settled 5 Sep 2026** — the
   procedure filters, sorts and pages. feature-rules §B carries the ruling.
2. **XLSX at the ceiling.** ~12 s projected for 100 000 rows. CSV streams fine at
   that size; XLSX probably wants a lower threshold of its own, or to go to the
   export centre earlier. Needs a number from Ryan rather than a guess.
3. ~~**`@FiltersJson` is an extension to the eight-parameter template.**~~
   **Accepted 5 Sep 2026.** The template in feature-rules §2 is now nine
   parameters, with `@FiltersJson` documented as opt-in there. A procedure
   without it stays valid and simply carries no header filters; a source built
   for one that lacks it throws. Still unexercised end to end — no procedure in
   the repo declares it yet.
4. **`<x-reports::cell>` should be repointed at `App\Grid\StatusTone`.** The
   keyword→tone table now exists in both; the Reports copy came first and this
   one was lifted from it. That file belongs to another lane, so it is named
   here rather than edited.
5. **The §3.5 side panel**, above.
