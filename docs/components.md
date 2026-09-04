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
| `<x-card>` | `title`, `sub`, `flush`, `actions` slot | Bordered panel | `flush` removes body padding, for a grid that goes edge to edge |
| `<x-kpi>` | `label`, `value`, `note`, `tone` | One figure | Sits inside a `.kpi-strip` grid |
| `<x-chip>` | `tone` | Status pill | Tones: `neutral good warn serious crit` |
| `<x-empty-state>` | `text` | "Nothing here yet" line | |

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
| `components/select.js` | TomSelect | the page has a `select[data-select]`. A plain `<select>` is left alone — the native control beats a library on a phone |
| `components/chart.js` | ApexCharts | the page has a `[data-chart]`. Colours are read from the theme tokens at draw time, so a chart follows the light/dark switch |

## Not built yet

Named here so the next session does not invent a second version of one. Each is
owned by a task in the build plan.

`<x-data-grid>` + `GridDefinition` (T014) · `<x-chart>` over ApexCharts (T015) ·
`<x-tabs>` · `<x-params>` + `<x-runbar>` (T061) · `<x-drawer>` extract panel ·
`<x-palette>` Ctrl-K search (T012) · `<x-checklist>` day-close steps (T029) ·
`<x-exception-list>` / `<x-exception-row>` (T058) · `<x-decision-list>` ·
`<x-two-pane-recon>` (T045) · `<x-proposal>` confidence + evidence (T036) ·
`<x-statstrip>` · `<x-delta>` · `<x-note>` · `<x-tip>` · `<x-lib-card>` (T061) ·
`<x-workspace-switch>` as its own component (currently inline in the app bar).

## JavaScript

One module per behaviour under `resources/js/components`, imported by
`resources/js/app.js`. Vanilla — no framework.

| Module | What it does |
|---|---|
| `format.js` | The number formats, twinned with `App\Support\Format` |
| `mega-menu.js` | Opens one section panel at a time; Escape, scrim and outside-click close it. Nesting inside a panel is `<details>`, so the browser supplies keyboard behaviour. Hover deliberately does **not** open a panel |
| `theme.js` | Light/dark toggle. Three states — an unstamped document follows the system. Stored in a cookie so the server can stamp `<html>` and avoid a flash, **and** posted to `agora.UserPreference` so the choice follows the person to their next device |
| `select.js` · `chart.js` · `notify.js` | The three library wrappers above |
