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

## The gallery

**`/dev/components` renders every component below, every variant, and its
props** — local and testing only. `/dev/theme` is the same page at its older
URL, kept because `tests/e2e/format.spec.js` loads it and is the only guard on
the number formats. One page, two URLs; there is deliberately not a second
gallery.

The table below is **generated** from `Modules\Core\Support\ComponentCatalogue`,
which is also what the gallery renders — so the doc and the page cannot drift.
Add a component there, then run:

```bash
php artisan agora:components-doc
```

`scripts/check-components.sh` (in `composer check` and `check-fast`) fails the
build if the file is stale, if a view hand-writes component markup such as
`class="kpi"`, if a blade hand-writes a menu link, or if a module with routes
has no `MenuSeeder`.

## Shipped

<!-- components:shipped -->

### Chrome

The frame around a screen. Fed by ShellComposer, so a page using the shell needs no navigation data of its own.

| Component | Props | What it is | Notes |
|---|---|---|---|
| `<x-app-shell>` | `title` `slot` | The whole page: head, app bar, scope bar, main, footer. | Fed by `ShellComposer`, so a page using it needs no nav data of its own. |
| `<x-app-bar>` | `sections` `workspace` `workspaces` | Brand, workspace switch, section buttons, tools. | Renders one `.mega` panel per section. |
| `<x-menu-branch>` | `item` `depth` | One menu node, drawn at whatever depth it sits. | **Recursive.** Depth 1 = column heading, 2 = link, 3+ = a `<details>` group that expands in place. No ceiling on depth. |
| `<x-scope-bar>` | `branches` `branchId` `workspace` | Branch and "as at" selector, submitted into the URL. | Scope is a parameter, not a report — the URL carries it so a link travels. |
| `<x-workspace-switch>` | `workspaces` `current` `param` `label` | Head office / Branch, as its own component. | Anchors, not buttons: switching workspace changes what the application is about, so it belongs in the URL and the back button should undo it. `fullUrlWithQuery` keeps the branch and date already in scope. |
| `<x-crumb>` | `parts` | Where you are. | A real `<nav>` over an `<ol>`, so a screen reader says "breadcrumb, 3 items" instead of reading the separators. The last part is the current page and carries no link. |
| `<x-page-head>` | `eyebrow` `title` `blurb` `actions` | Page title block. |  |

### Structure

How a screen is divided: panels, figures, tabs.

| Component | Props | What it is | Notes |
|---|---|---|---|
| `<x-card>` | `title` `sub` `flush` `collapsible` `open` `remember` `actions` `foot` | Bordered panel. | `flush` removes body padding, for a table that goes edge to edge. `collapsible` makes the head a `<details>` toggle — the browser supplies the keyboard behaviour and it works with no JS; `open` says which way it starts, and `remember` keeps that choice on this browser. A card carrying provenance rather than the answer starts closed. The older `card-head` / `card-body` class names are still styled. |
| `<x-kpi-strip>` | `slot` | The row of KPI tiles at the top of a screen. | A grid, so four tiles across a desk become two and then one on a phone without anything being hidden. |
| `<x-kpi>` | `label` `value` `unit` `compare` `note` `tone` `stripe` `href` `spark` | One figure, on a tile. | `value` has already been through `App\Support\Format` — the component does not format, because only the caller knows whether the figure is money, litres or a count. `stripe` names a design token and is checked against a list, so an unknown value is dropped rather than interpolated into a style attribute. `href` makes the whole tile a link. |
| `<x-statstrip>` | `stats` | A row of figures describing the result set below it. | Not `<x-kpi>`: a KPI is a headline about the business, a stat is about this answer, and it lives inside the card it describes. The longer `.stat / .stat-label / .stat-value` class names are still styled, so views written against them render unchanged. |
| `<x-tabs>` | `items` `active` `persist` `label` | A strip of tabs, in link mode or panel mode. | Give the items an `href` and it renders anchors and uses no JavaScript at all — the right mode whenever the panes are separate result sets, because a link carries its scope. Leave `href` out, put `<x-tab-panel>` children in the slot, and `tabs.js` adds the two things the platform does not give: memory of the last choice (`persist`) and arrow-key movement across the strip. Behaviour: `tabs.js`. |
| `<x-tab-panel>` | `key` | One pane behind a tab. | Takes `active` off the parent `<x-tabs>` with `@aware`, so a page lists its panes without repeating the selection. The inactive ones carry `hidden` from the server — that is what makes the first paint correct with no JavaScript. |

### Data

Rendering an answer — and rendering the absence of one.

| Component | Props | What it is | Notes |
|---|---|---|---|
| `<x-table>` | `procedure` `count` `total` `empty` `dense` `head` | A rendered result set. | **Not `<x-data-grid>`** — no header filters, no export, no column persistence. It does carry the parts of feature-rules §3 that apply to any table: its own scroll container, the name of its procedure (§3.4), its row count, and a deliberate empty state. Extra attributes land on the `<table>`, so `data-row-detail` reaches `row-detail.js`. Behaviour: `row-detail.js`. |
| `<x-chip>` | `tone` `dot` | Status pill. | Tones: `neutral good warn serious crit`. The dot is part of the component rather than something a caller remembers, and it takes `currentColor` — so the pill is still legible to a reader who cannot separate the five tones by hue. `dot="false"` for a chip that is a label rather than a state. |
| `<x-delta>` | `value` `invert` `suffix` `dp` | A movement, with its direction. | Both halves come from `App\Support\Format` — `delta()` writes the text and `deltaTone()` picks the class. `invert` is the point of it: up is good on turnover and bad on shrinkage, and only the caller knows which it is looking at. A null value is an em dash in the flat tone. |
| `<x-empty-state>` | `title` `text` | "Nothing here yet", designed like an answer. | With a `title` it becomes a centred panel with a headline; without one it stays a single paragraph, so it can sit in a table foot or a list without leaving a 44px hole. |
| `<x-sqlbox>` | `procedure` `title` `open` | The procedure behind a screen, shown. | feature-rules §3.4 in its strong form. It does **not** read the database — the body is a prop, because a component that went and fetched `sys.sql_modules` would run a query against a 249 GB production server on every page load. Collapsed by default: on a screen that has an answer, the SQL is provenance rather than the point. |

### Parameters

Asking for the arguments, and running the thing.

| Component | Props | What it is | Notes |
|---|---|---|---|
| `<x-field>` | `name` `label` `help` `type` `choices` `value` `min` `max` | One labelled control with its explanation attached. | `type="bool"` renders a checkbox with a hidden 0 beside it. A plain `<select>` stays plain — `select.js` only claims `select[data-select]`. This is the full-size form field; `<x-param>` is the compact one that goes in a parameter grid. |
| `<x-params>` | `legend` `summary` `collapsible` `open` `remember` | The parameter block above a report or a grid. | A responsive grid rather than a form layout, because the count varies from two to eight and both have to look deliberate. `collapsible` is a `<details>`, not a click handler; `remember` is what `disclosure.js` keys the open state on, since a report someone runs every morning should not fold itself away again each time. Behaviour: `disclosure.js`. |
| `<x-param>` | `name` `label` `type` `choices` `value` `disabled` `searchable` `placeholder` `min` `max` `step` `help` | One control inside a parameter block. | `disabled` is why this exists. A library where every report shows the same parameter set, dimmed where it does not apply, tells the reader which parameters exist; hiding them makes every report look like a different application. The slot overrides the control entirely for the cases the props do not cover. |
| `<x-runbar>` | `action` `type` `status` `busy` `procedure` `disabled` `slot` | The bar that runs the thing, and says that it is running. | On submit `runbar.js` disables the button and swaps the status, because a procedure that takes four seconds gets pressed twice. The button is disabled on the next tick — a submit button disabled inside the handler is dropped from the payload — and released again on `pageshow`, or the back button lands on a permanently dead Execute. How long the run took is the server's to report through `status`; timing it in the browser measures the round trip and calls it the query. Behaviour: `runbar.js`. |

### Queues

Work waiting on a person: exceptions, decisions, steps, proposals.

| Component | Props | What it is | Notes |
|---|---|---|---|
| `<x-checklist>` | `steps` `empty` | The steps that have to be done before something can be closed. | An `<ol>`, because the order is real — the Z-reads cannot be allocated before the POS files are imported. Each step carries a chip as well as a mark, because a green circle on its own is not a status anyone can read out. A step with no `href` renders as text rather than a dead link (feature-rules §3.7). |
| `<x-exception-list>` | `empty` `scroll` | The container for a run of exception rows. | Separate from the row so that "nothing is open" is rendered once rather than by every screen. An empty exception register is the good outcome and should read like one. `scroll` caps the height so a branch console can show its open items without pushing the page off the screen. |
| `<x-exception-row>` | `severity` `title` `detail` `category` `site` `age` `owner` `value` `unit` `href` `open` | One row of the exception register. | The mockup toggled a class on click; this is a `<details>`, so it opens on Enter and Space and announces itself as a disclosure. That is the one deviation from the sheet and it costs a single selector. Severity is the register's own vocabulary — critical, serious, warning, good — mapped to a chip tone here so no screen has to know that "warning" wears the warn tint. |
| `<x-decision-list>` | `items` `empty` | Things waiting on a person rather than on the system. | Deliberately not a grid. This is a short list a manager acts on this morning; a sortable table with header filters would put the decision behind a column chooser. |
| `<x-proposal>` | `confidence` `headline` `eyebrow` `evidence` `actions` | Something the system worked out, with how sure it is and why. | Agora proposes and a person disposes, and all three parts of that contract are required: what it proposes, how confident it is, and the evidence. Confidence is a word — certain / likely / review / manual — not a percentage: the customer has no calibrated probability and a number invites it to be trusted. `manual` is a first-class answer, because "no history for this operator ID" is itself information. Accept and override go in the `actions` slot, since they are forms with permissions on them. |
| `<x-lib-card>` | `name` `desc` `scope` `was` `tags` `href` | One entry in the report library. | `was` is the point of it, not a nicety: the library is where the customer finds out that the report they used to run is now called something else, and it is the only bridge between 161 legacy names and the 97 that replace them. `data-s` carries the searchable text so the filter box covers the same fields on every category. |
| `<x-role-card>` | `label` `who` `detail` `href` `type` | A large tappable choice. | An `<a>` when it is a destination and a `<button>` when it submits — never a div with a click handler. Written for the mockup's role picker; the shipped sign-in is email and password, so its first real use is likely to be a branch or a run picker on a phone. |
| `<x-system-state>` | `title` `rows` | What the system knows before anybody has signed in. | Overnight loads, exceptions open, Z-reads unallocated. It sits on the sign-in hero on purpose: the first useful thing Agora can tell a branch manager at 05:00 is whether last night's loads landed, and making them authenticate to find out is a screen designed for the system. Every row carries text as well as a dot. |

### Messages

Something the reader has to take in before the figures mean what they look like.

| Component | Props | What it is | Notes |
|---|---|---|---|
| `<x-notice>` | `tone` `title` `collapsible` `open` | Something the reader must take in before the figures below mean what they look like. | A refusal, a step deliberately not taken, a count that is evidence. Not an error page. `collapsible` folds the reasoning behind the headline and the headline stays visible, because nobody should have to open something to learn a problem exists. `<details>`, so no JS. The mockup's bare `.note` class is `tone="info"` and is still styled for markup ported from the sheet. |
| `<x-tip>` | — | The page's one floating tooltip. | A singleton, because a chart with 31 bars must not mount 31 tooltips and because flipping at the edge needs a measurable element. `data-tip="…"` on anything shows it on hover **and on focus** — the mockup's was hover-only, which no keyboard can reach — and `window.Agora.tip.show(event, html)` is the way a chart draws its own rows into it. Hidden entirely below 720px: a fixed box on a phone covers the answer, and a tooltip only ever repeats something written somewhere permanent. Behaviour: `tip.js`. |

### Module-scoped

Lives in a module rather than the shared library, and is used as <x-{alias}::name>.

| Component | Props | What it is | Notes |
|---|---|---|---|
| `<x-reports::cell>` | `type` `value` | One table cell, formatted from the column's declared type. | Types: `text number money litres date datetime bool chip`. Every figure goes through `App\Support\Format`, never `number_format`; a missing value is an em dash, never `R0.00`. `chip` maps a procedure's Status wording to a tone by keyword, so a new phrase falls to neutral rather than to a fatal. |

Every prop, every variant and the mockup class names each component carries are rendered at `/dev/components`, which is where this table is generated from. Add a component to `Modules\Core\Support\ComponentCatalogue` and run `php artisan agora:components-doc`; `scripts/check-components.sh` fails the build if you forget.

<!-- /components:shipped -->

## Deliberately not components

Written down so nobody builds them a second time after concluding they are
missing.

| Not a component | Use instead | Why |
|---|---|---|
| `<x-note>` | `<x-notice tone="info">` with no title | The mockup's `.note` is a line of brand-tinted context inside a card body. `<x-notice tone="info">` with no title renders exactly that, and the class `.note` is still styled so markup ported straight from the design sheet works. A second component for it would be a second version of one idea |
| `<x-eyebrow>` | the `eyebrow` prop on `<x-page-head>`, or `class="eyebrow"` | A single element with one class and no logic. `.eyebrow` is a global utility in `_library.scss` — it used to be scoped inside `.page-head`, which is why the styleguide's own `<p class="eyebrow">` rendered as plain body text |
| `<x-btn>` | `class="btn"`, `class="btn primary"`, `class="btn sm"` | A button is what a caller puts *into* the `actions` slot of a card, a runbar or a proposal. Wrapping it would mean a component whose whole job is to pass `type`, `disabled` and a click handler through |

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
Three partials sit after `components`:

| Partial | What it is |
|---|---|
| `_library.scss` | The rest of plan §3.8, in the mockup's own class names — buttons, crumb, tabs, params, runbar, delta, checklist, exception list, decisions, proposal, tooltip, library card, sign-in extras, sqlbox, and the gallery's own furniture. Split from `_components.scss` so the two can be reviewed and merged separately, and because everything in it is a direct port from `docs/reference/agoraretailconsole.html`. Every narrow-screen rule is at the bottom of the file rather than scattered, so "what does this look like on a phone" is one place to read |
| `_select.scss` | The Agora skin over TomSelect's bootstrap5 sheet — the chip, the dropdown, and the pieces the Bootstrap bridge does not reach. Its stylesheet is imported from `select.js` and therefore lands **after** `app.css`, so overrides here win on specificity rather than on order: the match highlight is `.highlight.highlight` for exactly that reason, and `!important` is deliberately not used |
| `_reports.scss` | The Reports module's screens. Almost everything they need is already shared — the catalogue reuses `.area-grid` / `.area-card`, the scope form reuses `.field-row` / `.form-actions`. What is here is the pager, the sort arrow and the two extra lines a report card carries |

There is no module-stylesheet convention in the build yet; when there is one,
`_reports.scss` moves into `Modules/Reports`.

## Not built yet

<!-- components:pending -->

Named here so the next session does not invent a second version of one.
The gallery renders a labelled slot for each, so whoever builds it can drop
it into a page that is already the right shape.

| Component | Owned by | What it will be |
|---|---|---|
| `<x-data-grid>` | T014 | The grid with header filters, CSV export to the 100 000-row ceiling, a row-click detail panel and per-user column order, widths and text size. `<x-table>` is the interim; a screen needing any of those waits for the grid rather than growing them there. |
| `<x-drawer>` | T014 | The extract panel — the CSV and the copy-to-clipboard that every grid opens. |
| `<x-chart>` | T015 | ApexCharts behind one component: daily bars, line, donut, bridge, diverging. Colours read from the theme tokens at draw time, so a chart follows the light/dark switch. |
| `<x-sparkline>` | T015 | The 30px trend inside a KPI tile. `<x-kpi>` already has the `spark` slot it goes in. |
| `<x-mini-bar>` | T015 | The in-cell bar with a zero line, for a variance column. |
| `<x-palette>` | T012 | Ctrl-K search over every screen, report and master. |
| `<x-two-pane-recon>` | T045 | The reconcile workbench: captured against bank, tick both sides, auto-match, process the batch. |

<!-- /components:pending -->

## JavaScript

One module per behaviour under `resources/js/components`, imported by
`resources/js/app.js`. Vanilla — no framework.

| Module | What it does |
|---|---|
| `format.js` | The number formats, twinned with `App\Support\Format`. Exposed as `window.Agora.format` — **not** loaded per page with `Vite::asset()`, which needs it to be its own build input and therefore threw "Unable to locate file in Vite manifest" against built assets and took `/dev/theme` down with it. The parity table reads the twin off the shipped bundle, which is also the stronger check: it tests the module the application runs |
| `tabs.js` | Panel-mode tabs only — link mode gets no JavaScript at all. The panes are already correct from the server (`<x-tab-panel>` ships the inactive ones `hidden`), so this adds the two things the platform does not: memory of the last choice, and arrow-key / Home / End movement with one tab stop for the strip |
| `disclosure.js` | Remembers which `<details data-remember="key">` a person left open. Everything foldable in Agora is a native `<details>`; the one thing that is not free is memory, and a report run every morning should not fold its parameters away again each time. `localStorage`, per browser — it has no business meaning and does not belong beside the theme in `agora.UserPreference` |
| `runbar.js` | Stops a four-second procedure being run twice: on submit the bar disables its button, marks itself busy and swaps the status. Disabled on the **next tick**, because a submit button disabled inside the handler is dropped from the payload; released again on `pageshow`, or the back button lands on a permanently dead Execute |
| `tip.js` | The page's one tooltip. `data-tip` on anything shows it on hover **and on focus** — the mockup's was hover-only, which no keyboard can reach — and `window.Agora.tip.show(event, html)` is how a chart draws its own rows into it. Escape and any scroll close it |
| `check-all.js` | A header checkbox that ticks the rows under it |
| `confirm-form.js` | A form that asks before it submits. Opt in with `data-confirm` (plus `data-confirm-text`, `data-confirm-action`, `data-confirm-danger`). Goes through `window.Agora.notify.confirm`, so it is the same SweetAlert2 dialog as every other confirmation — nothing calls `window.confirm` |
| `row-detail.js` | Expanding a table row to the detail behind it. Opt in with `data-row-detail` on the table and `data-detail-url` on a row; everything else is derived. Click or Enter, fetched once and kept, and the server returns **HTML** — the number formats live in `Format` and rebuilding them in JS is how the two drift. Clicks on a link or control inside the row are left alone, so a reference that navigates (§3.7) still navigates |
| `mega-menu.js` | Opens one section panel at a time; Escape, scrim and outside-click close it. Nesting inside a panel is `<details>`, so the browser supplies keyboard behaviour. Hover deliberately does **not** open a panel |
| `theme.js` | Light/dark toggle. Three states — an unstamped document follows the system. Stored in a cookie so the server can stamp `<html>` and avoid a flash, **and** posted to `agora.UserPreference` so the choice follows the person to their next device |
| `select.js` · `chart.js` · `notify.js` | The three library wrappers above |
