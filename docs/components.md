# Component library

Every screen composes components; no screen writes its own KPI, table or nav
markup (plan §3.8). **Check this file before building anything** — if what you
need is not here, build it, then add a row.

This file says what EXISTS. [`feature-rules.md`](feature-rules.md) says what a
grid, a form and a view screen must DO — read that before scoping a feature, and
build the component to satisfy it.

Rules that apply to all of them:

- A component owns markup and behaviour. **A component never queries the
  database** — data arrives as props from the controller, the service or a view
  composer.
- Every component renders correctly in both themes and takes its colours from
  the tokens in `resources/scss/_tokens.scss`. No hex in a component.
- Shared components live in `resources/views/components` and are used as
  `<x-name>`. Module-specific ones live in
  `Modules/{M}/Resources/views/components` and are used as `<x-{alias}::name>`.

## Shipped

| Component | Props | What it is | Notes |
|---|---|---|---|
| `<x-app-shell>` | `title`, slot | The whole page: head, app bar, scope bar, main, footer | Fed by `ShellComposer`, so a page using it needs no nav data of its own |
| `<x-app-bar>` | `sections`, `workspace`, `workspaces` | Chrome bar: brand, workspace switch, section buttons, tools | Renders one `.mega` panel per section |
| `<x-menu-branch>` | `item`, `depth` | One menu node, drawn at whatever depth it sits | **Recursive.** Depth 1 = column heading, 2 = link, 3+ = a `<details>` group that expands in place. No ceiling on depth |
| `<x-scope-bar>` | `branches`, `branchId`, `workspace` | Branch + "as at" selector, submitted into the URL | Scope is a parameter, not a report — the URL carries it so a link travels |
| `<x-page-head>` | `eyebrow`, `title`, `blurb`, `actions` slot | Page title block | |
| `<x-card>` | `title`, `sub`, `flush`, `collapsible`, `open`, `actions` slot | Bordered panel | `flush` removes body padding, for a grid that goes edge to edge. `collapsible` makes the head a `<details>` toggle — the browser supplies keyboard behaviour and it works with no JS; `open` says which way it starts. A card carrying provenance rather than the answer starts closed |
| `<x-kpi>` | `label`, `value`, `note`, `tone` | One figure | Sits inside a `.kpi-strip` grid |
| `<x-chip>` | `tone` | Status pill | Tones: `neutral good warn serious crit` |
| `<x-empty-state>` | `text` | "Nothing here yet" line | |
| `<x-notice>` | `tone` (`warn`\|`stop`\|`info`), `title`, `collapsible`, `open` | Something the reader must take in before the figures below mean what they look like — a refusal, a step deliberately not taken, a count that is evidence | Not an error page. `collapsible` folds the reasoning behind the headline; the headline stays visible, because nobody should have to open something to learn a problem exists. `<details>`, so no JS |
| `<x-statstrip>` | `stats` | A row of figures describing the result set below it | Not `<x-kpi>`: a KPI is a headline about the business, a stat is about this answer. Lives inside the card it describes |
| `<x-table>` | `procedure`, `count`, `total`, `empty`, `dense`; `head` slot | A rendered result set. Extra attributes land on the `<table>`, so `data-row-detail` reaches `row-detail.js` | **Not `<x-data-grid>`** — no header filters, no export, no column persistence. It does carry the parts of feature-rules §3 that apply to any table: own scroll container, names its procedure (§3.4), says its row count, deliberate empty state |
| `<x-field>` | `name`, `label`, `help`, `type`, `choices`, `value`, `min`, `max` | One labelled control with its explanation attached | `type="bool"` renders a checkbox with a hidden 0 beside it. A plain `<select>` stays plain — `select.js` only claims `select[data-select]` |
| `<x-reports::cell>` | `type`, `value` | One table cell, formatted from the column's declared type (`text` `number` `money` `litres` `date` `datetime` `bool` `chip`) | Module-scoped. Every figure goes through `App\Support\Format`, never `number_format`; a missing value is an em dash, never `R0.00`. `chip` maps a procedure's Status wording to a tone by keyword, so a new phrase falls to neutral rather than to a fatal |

## Numbers

`App\Support\Format` and `resources/js/format.js` are a matched pair and must
agree character for character — `/dev/theme` renders every case side by side and
`tests/e2e/format.spec.js` fails if any row disagrees.

| Call | Output | For |
|---|---|---|
| `n(1234567.891, 2)` | `1 234 567.89` | any number |
| `R(1234.5)` | `R1 234.50` | money in full |
| `Rk(2208437)` | `R2.21m` | money on a KPI tile |
| `Lk(847300)` | `847k L` | volume on a tile |
| `litres(12480.5)` | `12 480.500 L` | a dip or a meter, to the millilitre |
| `pct(12.44)` | `12.4%` | a percentage |
| `cpl(175.25)` | `175.2500 c/ℓ` | fuel margin, four places |
| `delta(4.23)` | `▲ +4.2%` | a movement; under 0.05 reads flat, no arrow |
| `deltaTone(v, invert)` | `up` / `dn` / `flat` | which direction is good — the caller decides |

**The format is stated, not delegated to a locale.** Asked for `en-ZA` and
1234567.891, PHP's intl returns `1,234,567.89` and JavaScript's `Intl` returns
`1 234 567,89` — a different group separator *and* a different decimal
separator. Using the locale would have produced exactly the inconsistency this
pair exists to prevent, on screens that mix server- and client-rendered figures.

The grouping character is an ordinary space, not a non-breaking one, so a figure
copied out of a grid pastes into a spreadsheet as a number. Numeric cells carry
`white-space: nowrap` instead.

A missing figure is `—`, never `R0.00`.

## Libraries

All three load on first use, not on every page — the sign-in screen needs none
of them.

| Module | Wraps | Loads when |
|---|---|---|
| `components/notify.js` | SweetAlert2 | an alert, confirm or toast actually fires. Exposed as `window.Agora.notify`. Nothing calls native `alert`/`confirm`/`prompt` |
| `components/select.js` | TomSelect **+ its bootstrap5 stylesheet** | the page has a `select[data-select]`. A plain `<select>` is left alone — the native control beats a library on a phone. **The stylesheet is not optional**: TomSelect hides the original control with a *class*, so without it the native `<select multiple>` stays on the page at full size with an orphaned search box beneath it. The bootstrap5 skin is the one to load, because it reads `--bs-*` and `_bootstrap-bridge.scss` already points those at Agora's tokens |
| `components/chart.js` | ApexCharts | the page has a `[data-chart]`. Colours are read from the theme tokens at draw time, so a chart follows the light/dark switch |

## Stylesheets

`resources/scss/app.scss` is the cascade, and the order in it is deliberate.
Two partials sit after `components`:

| Partial | What it is |
|---|---|
| `_select.scss` | The Agora skin over TomSelect's bootstrap5 sheet — the chip, the dropdown, and the pieces the Bootstrap bridge does not reach. Its stylesheet is imported from `select.js` and therefore lands **after** `app.css`, so overrides here win on specificity rather than on order: the match highlight is `.highlight.highlight` for exactly that reason, and `!important` is deliberately not used |
| `_reports.scss` | The Reports module's screens. Almost everything they need is already shared — the catalogue reuses `.area-grid` / `.area-card`, the scope form reuses `.field-row` / `.form-actions`. What is here is the pager, the sort arrow and the two extra lines a report card carries |

There is no module-stylesheet convention in the build yet; when there is one,
`_reports.scss` moves into `Modules/Reports`.

## Not built yet

Named here so the next session does not invent a second version of one. Each is
owned by a task in the build plan.

`<x-data-grid>` + `GridDefinition` (T014 — `<x-table>` is the interim, and a screen
needing filters/export/persistence waits for the grid rather than growing them there) · `<x-chart>` over ApexCharts (T015) ·
`<x-tabs>` · `<x-params>` + `<x-runbar>` (T061) · `<x-drawer>` extract panel ·
`<x-palette>` Ctrl-K search (T012) · `<x-checklist>` day-close steps (T029) ·
`<x-exception-list>` / `<x-exception-row>` (T058) · `<x-decision-list>` ·
`<x-two-pane-recon>` (T045) · `<x-proposal>` confidence + evidence (T036) ·
`<x-delta>` · `<x-tip>` · `<x-lib-card>` (T061) ·
`<x-workspace-switch>` as its own component (currently inline in the app bar).

## JavaScript

One module per behaviour under `resources/js/components`, imported by
`resources/js/app.js`. Vanilla — no framework.

| Module | What it does |
|---|---|
| `format.js` | The number formats, twinned with `App\Support\Format` |
| `confirm-form.js` | A form that asks before it submits. Opt in with `data-confirm` (plus `data-confirm-text`, `data-confirm-action`, `data-confirm-danger`). Goes through `window.Agora.notify.confirm`, so it is the same SweetAlert2 dialog as every other confirmation — nothing calls `window.confirm` |
| `row-detail.js` | Expanding a table row to the detail behind it. Opt in with `data-row-detail` on the table and `data-detail-url` on a row; everything else is derived. Click or Enter, fetched once and kept, and the server returns **HTML** — the number formats live in `Format` and rebuilding them in JS is how the two drift. Clicks on a link or control inside the row are left alone, so a reference that navigates (§3.7) still navigates |
| `mega-menu.js` | Opens one section panel at a time; Escape, scrim and outside-click close it. Nesting inside a panel is `<details>`, so the browser supplies keyboard behaviour. Hover deliberately does **not** open a panel |
| `theme.js` | Light/dark toggle. Three states — an unstamped document follows the system. Stored in a cookie so the server can stamp `<html>` and avoid a flash, **and** posted to `agora.UserPreference` so the choice follows the person to their next device |
| `select.js` · `chart.js` · `notify.js` | The three library wrappers above |
