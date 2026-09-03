# Component library

Every screen composes components; no screen writes its own KPI, table or nav
markup (plan §3.8). **Check this file before building anything** — if what you
need is not here, build it, then add a row.

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
| `mega-menu.js` | Opens one section panel at a time; Escape, scrim and outside-click close it. Nesting inside a panel is `<details>`, so the browser supplies keyboard behaviour. Hover deliberately does **not** open a panel |
| `theme.js` | Light/dark toggle. Three states — an unstamped document follows the system. Stored in a cookie so the server can stamp `<html>` and avoid a flash |
