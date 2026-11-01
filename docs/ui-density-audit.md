# Agora application density audit

30 October 2026 · Adjudicated source audit of the current working tree, including existing uncommitted work.

**Verdict: retain Agora’s visual identity and replace its spacious template layout with a consistent work-first layout.** Make data screens fluid, start statistical groups closed, compact repeated scope/card chrome, and use short display headings with full names retained elsewhere. The biggest gains come before and around the table; its ordinary rows are already compact.

The immediate implementation sequence is shared shell and spacing → statistics disclosure and heading metadata → Original vs amended variance and Stock Recon pilots → every remaining view family below. This document is the completed audit and implementation specification. The makeover has not been applied. A small development-gallery fixture defect encountered during verification has been repaired locally.

## Coverage and evidence

Three independent Sol reviewers covered the shared UI, reconciliation/reporting, and other modules. Root checked the material claims against current source, reconciled conflicting suggestions, reviewed relevant PM knowledge-base entries, and measured the existing component gallery. Findings describe this checkout, not an assertion that every live route has been visually tested.

There are **207 Blade templates: 150 in all 10 modules, plus 57 shared templates**. These include partials, components, mobile views, galleries, authentication, mail and PDF output; they are not 207 distinct pages. [The complete inventory](audits/ui-density-2026-10-30/inventory.json) records every path, classification, component use and source hash. The application has exactly 10 module manifests. Exco and Branch console currently use Core’s landing stub; they are included, not missing modules.

| Territory | Templates | Covered surfaces |
|---|---:|---|
| StockRecon | 36 | Centre, run/chain/proposals, exceptions/duplicates/global, variance, water meters, groups, counting areas, weekly investigation, helper, tutorial and their fragments |
| Recon | 28 | Index/dashboard, area centre and lazy panes, config/runs/all sites, standalone match/suggest/run, group, trace, batch/reversal and detail fragments |
| Reports | 6 | Library, generic report, audit page/change and cell/before-after components |
| Cash | 37 | Banking/cashups, ZREAD, drop safe, till balance, staff shorts, pump shortages, deductions, four mobile surfaces, fragments and mail |
| Core | 22 | Dashboard, Exco/console stub, users/roles, authentication, galleries, component wrappers and mail |
| Assets | 10 | Register, repairs, movements, masters, grid cells and dialog forms |
| Debtors | 5 | Account list, statement, preview, PDF and mail |
| Product | 3 | Stock list/detail and critical lines |
| Operations | 2 | Wall and board component |
| Utilities | 1 | Readings/capture screen |
| Shared | 57 | Shell, cards, stats, parameters, tables/data-grid, filters, chooser, mobile cards, extracts and other reusable components |

Comment-stripped lexical inventory found 20 statstrip calls in 18 files, 11 KPI-strip calls in 10 files, 66 app-shell callers, 27 data-grid callers and 83 table calls in 44 files. Counts include gallery examples and shared components, and do not equal runtime panel counts. The Operations wall also creates metrics in JavaScript, so a Blade tag search alone cannot define the migration scope.

### Rendered baseline

The unauthenticated local `/dev/components` gallery uses controller fixtures and existing built assets. Dark-theme observations:

| Observation | Actual result |
|---|---|
| Desktop viewport | 1920 × 1080; 15px vertical scrollbar |
| Default shell | 1560px outer width; left boundary 172.5px; 22px inner padding; content begins at 194.5px |
| Four KPI tiles | 100.48px high on desktop; 390.75px high at 375 × 812 |
| Four-item stat strip | 85.03px high on desktop; 161.06px high at 375 × 812 |
| Mobile shell | 360px available width with 14px internal side padding |

[Desktop screenshot](audits/ui-density-2026-10-30/gallery-desktop-dark.png) · [Mobile screenshot](audits/ui-density-2026-10-30/gallery-mobile-dark.png). These show existing gallery components, not a makeover prototype or an authenticated business screen. The mobile screenshot shows part of the stack; its full height is the DOM measurement above. Assets were not rebuilt for this audit.

The source explains the gutters: [normal shell](/home/ryan/Development/ZP/Agora/resources/scss/_shell.scss:180) caps at 1560px; [wide shell](/home/ryan/Development/ZP/Agora/resources/scss/_shell.scss:205) uses `min(2200px, max(1560px, 100vw - 96px))` plus inner padding. Ignoring scrollbars, that means 202px per side on an ordinary 1920px screen, 70px in wide mode, and 202px again for wide mode at 2560px. These last figures are CSS calculations, not additional browser measurements.

## Accepted design standard

### 1. Use the available width for work

Use a fluid shell for operational screens, with **16px desktop gutters and 12px below the mobile breakpoint** as implementation targets. Scope, main and footer must share the same edge; changing only main recreates a previously documented alignment problem. Long forms, prose, training material and message previews keep their own bounded reading measure inside the shell. Mega-menu columns keep a readable width and deliberately aligned padding.

At an idealized 1920px viewport without a scrollbar, this increases ordinary content width from 1516px to 1888px, recovering 372px. Wide-mode content gains 108px. Those are geometric targets, not a promise that every table will fit: saved widths, long content and filters still determine overflow.

Preserve palette, Barlow/Plex typography, status colours, original/amended tones, borders, navigation model and number formatting. Retain local table scrolling for genuinely wide results. Do not hide essential columns automatically or clip item names and money to manufacture a fit.

### 2. Group statistics behind one closed disclosure

Add a small semantic statistics wrapper built on the existing [native collapsible card](/home/ryan/Development/ZP/Agora/resources/views/components/card.blade.php:41), with a flat variant for use inside an existing result card. Example summary: **Statistics · 6 measures**. Put related tiles/figures inside one region, not one disclosure per tile. Keep existing drilldown links and value semantics.

**Start statistics closed on each full page entry and when a lazy fragment is first inserted.** Omit `open` and `remember`; [disclosure.js](/home/ryan/Development/ZP/Agora/resources/js/components/disclosure.js:24) otherwise restores a previously saved open state. While the user stays on the page, opening the region must survive local table sorting/filtering where the wrapper is not replaced. Closing it must not change selections or form payloads. This gives “collapsed by default” an explicit, testable meaning.

Errors, refusals, unknown/stale sources, active scope and current action state remain visible. In particular, the [manual-match strip](/home/ryan/Development/ZP/Agora/Modules/Recon/Resources/views/partials/match-pane.blade.php:79) contains live selected-bank, selected-deposit and difference values. Move that operational feedback to a compact line beside Match while folding statistical presentation. Keep ZREAD staged counts, stock selected/held counts and cashup variance/status beside their actions. Never make opening Statistics a prerequisite for understanding a commit.

Apply the same default to optional dashboard and wall summary metrics. Keep the wall’s primary alert cards, stale/source-gap indicators and Pause visible; maintain its reading-at-distance typography. A TV-sized card is not automatically decorative statistics. Gallery demonstrations can explicitly show open variants so the library remains useful.

### 3. Compact the surrounding structure

Proposed shared values: main top gap 12px; block gaps 10–12px; card heading padding 8px × 12px; body padding 10–12px; compact-filter label clearance 4–6px. Use flush cards for tables. Replace repeated title/scope/legend/action blocks with one title-and-actions row and one chosen-scope line. Let input groups wrap instead of wasting a row for one button.

The current stack combines [22px main padding](/home/ryan/Development/ZP/Agora/resources/scss/_shell.scss:217), [18px page/card gaps](/home/ryan/Development/ZP/Agora/resources/scss/_components.scss:4), 14px card bodies and [large grid scope spacing](/home/ryan/Development/ZP/Agora/resources/scss/_grid.scss:39). Reduce this before changing data text. [Ordinary table styling](/home/ryan/Development/ZP/Agora/resources/scss/_components.scss:411) already uses 13px text and 4px vertical padding; Compact and Large user choices already exist.

Retain full-size capture controls and usable touch targets. Selected scope folds to a line containing the actual site/date/filter; unchosen scope and validation failures open the relevant controls. Primary forms, results, permission warnings and confirmation reasons are not candidates for blanket collapse.

### 4. Separate compact headings from full labels

Add optional `compactLabel` metadata to GridColumn and equivalent metadata to generic Reports columns. Use a shared header rendering pattern for manual tables. Keep `label` canonical: column chooser, filters, mobile card descriptions and CSV/XLSX headers retain the full name. Keep the compact header’s full accessible name and a keyboard/touch-accessible explanation; a hover-only tooltip is insufficient. Aim for approximately 11px visual headings, but honour user-selected text sizes.

This is necessary because [GridColumn](/home/ryan/Development/ZP/Agora/app/Grid/GridColumn.php:62), [desktop headings](/home/ryan/Development/ZP/Agora/resources/views/components/data-grid.blade.php:180), [CSV](/home/ryan/Development/ZP/Agora/app/Grid/Export/CsvWriter.php:35) and [XLSX](/home/ryan/Development/ZP/Agora/app/Grid/Export/XlsxWriter.php:159) currently share `label`. [Reports has a separate renderer](/home/ryan/Development/ZP/Agora/Modules/Reports/Resources/views/show.blade.php:102); a data-grid-only change misses it and the manual tables.

Manual-table JavaScript must carry both names too: [table-tools reads the visible header text](/home/ryan/Development/ZP/Agora/resources/js/components/table-tools.js:404) and [replaces its children with a sort button](/home/ryan/Development/ZP/Agora/resources/js/components/table-tools.js:483). Preserve full-label metadata through generated sort/filter controls; adding an accessible span only in Blade would be lost.

Never rename column keys, GridKeys, sort/filter parameters, URLs, input names or procedures for presentation. Preserve existing widths, order, visibility and text-size preferences. Do not reset people’s layouts during rollout.

| Full meaning | Compact display | Constraint |
|---|---|---|
| Original value | ORG VAL | User’s requested example; retain currency context |
| Amended value | AMD VAL | Distinguish amended from proposed |
| Original variance / Amended variance | ORG VAR / AMD VAR | Full name says quantity variance; keep UOM available |
| Moved by balancing | VAL Δ | Explain amended value minus original value |
| Original open / issued / close / POS | ORG OPEN / ORG ISS / ORG CLOSE / ORG POS | Use on raw comparison columns when chosen |
| Amended open / issued / close / POS | AMD OPEN / AMD ISS / AMD CLOSE / AMD POS | Do not call saved proposal values AMD |
| Proposed open / close | PROP OPEN / PROP CLOSE | Proposal basis remains explicit |
| Live at preview | LIVE … | Explain that “live” is the recorded preview basis, not a fresh value |
| Quantity / Reference / Counting area | QTY / REF / COUNT AREA | Leave already-short Site, Date, Item, Till, Shift and Status intact |
| Previous reading / Current reading | PREV READ / CUR READ | Preserve litres on meter headings or table context |
| Unit cost / Depreciation / Accumulated depreciation | UNIT COST / DEPR / ACC DEPR | Do not merge cost, selling exposure and recovery |
| Sell including VAT / Cost excluding VAT | SELL INCL / COST EXCL | Full labels retain VAT basis |
| Staff code / Proposed person / Proposed shift | EMP CODE / PROP PERSON / PROP SHIFT | Keep identifiers and selected person readable |

Prefer a meaningful two-line heading when an abbreviation would obscure responsibility, clock basis, monetary sign or business meaning. Do not automatically shorten every word, use cryptic codes for statuses, or remove units.

## View family disposition

Priority means implementation order within the makeover, not a claim of a production incident. All module counts above reconcile to the inventory; components/partials inherit the disposition of their host family unless explicitly noted.

| Priority and family | Concrete change | Preserve / source anchor |
|---|---|---|
| P1 Original vs amended variance | Fold six summary figures; compact ORG/AMD headings; fluid shell | Already wide; raw quantities already hidden by default. [View](/home/ryan/Development/ZP/Agora/Modules/StockRecon/Resources/views/variance.blade.php:24), [columns](/home/ryan/Development/ZP/Agora/Modules/StockRecon/Grids/StockReconVarianceGrid.php:75). Full-set totals, scope filters and colour meaning stay unchanged. |
| P1 Stock centre, run, proposals, chain and metric drilldown | Fold proposal/chain stats; reduce heading/scope/warning-stack gaps; shorten table headings | [Proposals](/home/ryan/Development/ZP/Agora/Modules/StockRecon/Resources/views/panes/proposals.blade.php:116) already show chains with hidden member-shift inputs. Retain these inputs, per-chain actions and reset’s separate form. Metric “before” is not automatically staff original. |
| P1 Stock exceptions, duplicates, global results | Flatten redundant filter framing; fold duplicate statistics; retain case/status count in summary | [Duplicate cases](/home/ryan/Development/ZP/Agora/Modules/StockRecon/Resources/views/partials/duplicates.blade.php:60) currently auto-open when cases exist. Deep links must reveal their case; hold/release/withdraw reasons remain explicit. Global master groups already fold. |
| P1 Stock weekly registers and case detail | Fluid results; compact site scorecard; separate identity/status/actions from closed figures/evidence/history; source JSON behind named details | [Weekly detail](/home/ryan/Development/ZP/Agora/Modules/StockRecon/Resources/views/weekly/detail.blade.php:6) has 26 initial facts plus evidence tables/cards. Keep source coverage, shared-count exclusions, cost versus exposure/recovery, expected version and closure permissions visible where relevant. |
| P1 Stock every-site groups | Replace nested cards per site with compact site/state/count/action rows | [Group](/home/ryan/Development/ZP/Agora/Modules/StockRecon/Resources/views/group.blade.php:5). Preserve progress/live announcements, resume/retry hooks and per-site POST fallback. |
| P1 Bank run, suggestions, groups and dashboard | Fold descriptive stat/KPI groups; compact chosen scope; unify standalone and centre widths | [Run result](/home/ryan/Development/ZP/Agora/Modules/Recon/Resources/views/partials/result.blade.php:31). Keep filtered commit counts, suggestion tiers/reasons and sequential processing. Charts retain resize handling. |
| P1 Bank manual match | Fold descriptive statistics; compact live totals beside Match; preserve split and reason panel | [Match pane](/home/ryan/Development/ZP/Agora/Modules/Recon/Resources/views/partials/match-pane.blade.php:79). Existing JS depends on stat positions/classes; migrate selectors deliberately, not by moving arbitrary pieces. |
| P1 Cash banking, ZREAD, drop safe, staff shorts, till balance | Five chosen-scope cards become compact scope lines; fold summaries; history starts closed; selected record/form remains primary | [Banking scope/summary](/home/ryan/Development/ZP/Agora/Modules/Cash/Resources/views/banking/index.blade.php:82), [ZREAD actions](/home/ryan/Development/ZP/Agora/Modules/Cash/Resources/views/zread/index.blade.php:112). Keep staged counts, cashup agreement, source/destination till, amount and reasons. |
| P2 Cash deductions and grid-only pages | Fold per-tab summaries; shorten crowded tabs/headers; apply shared spacing to cashups, bags, pump-shortages and short lists | [Deductions](/home/ryan/Development/ZP/Agora/Modules/Cash/Resources/views/deductions/index.blade.php:47). Keep payroll exclusions, export scope and lock confirmations. Grid-first pages need no new wrapper. |
| P2 Cash mobile pages | Show chosen scope in summary; keep Show inside its expanded controls; compact repeated record facts | [Mobile banking](/home/ryan/Development/ZP/Agora/Modules/Cash/Resources/views/mobile/banking/index.blade.php:48). All four mobile families covered; preserve proposal evidence and action context. |
| P2 Stock water meters | Fold six stats; compact reading/dispensed/POS headings | [Water meters](/home/ryan/Development/ZP/Agora/Modules/StockRecon/Resources/views/water-meters.blade.php:22). Already wide; preserve litres, missing readings and sign. Do not relabel volume differences as monetary losses. |
| P2 Stock counting areas | Fluid index; compact identity/classification facts; close history; avoid placing lengthy detail above the edit form | [Edit](/home/ryan/Development/ZP/Agora/Modules/StockRecon/Resources/views/counting-areas/edit.blade.php:5). Preserve expected-version/food checks and explicit classification confirmation. |
| P2 Stock helper and tutorial | Helper context/secondary evidence folds; tutorial keeps reading measure and chapter interactions | [Helper](/home/ryan/Development/ZP/Agora/Modules/StockRecon/Resources/views/helper.blade.php:51). Tutorial custom CSS requires its own review; it is not a dense data-entry grid. |
| P2 Bank index, area/config/runs/all, batch, trace and rule editing | Compact outer chrome and chosen scope; shorten tables; retain already-closed trace/provenance sections | [Area](/home/ryan/Development/ZP/Agora/Modules/Recon/Resources/views/area.blade.php:5) already makes relevant panes wide. Keep lazy hydration, selected tab/URL scope, signed reversal and permission gates. |
| P2 Reports library, generic reports and audit detail | Compact library entries; chosen Scope line; fluid results; apply header metadata independently of data-grid | [Report renderer](/home/ryan/Development/ZP/Agora/Modules/Reports/Resources/views/show.blade.php:48). Generic reports are paginated: never add client-side whole-set filtering. Audit context already uses compact tables and closed raw data; preserve before/after precision. |
| P2 Product stock and critical lines | Fold top figures; remove duplicated presentation of cost/sell facts; keep identity/Edit first | [Stock detail](/home/ryan/Development/ZP/Agora/Modules/Product/Resources/views/stock/show.blade.php:52), [critical](/home/ryan/Development/ZP/Agora/Modules/Product/Resources/views/critical/index.blade.php:40). Stock list already grid-first; retain override/missing-source and price-disagreement states. |
| P2 Assets register, repairs, movements and masters | Compact valuation date; fold valuation/repair KPIs; histories close with counts; shorten long masters headings | [Register](/home/ryan/Development/ZP/Agora/Modules/Assets/Resources/views/register/index.blade.php:63), [masters](/home/ryan/Development/ZP/Agora/Modules/Assets/Resources/views/masters/index.blade.php:77). Movement list already grid-first; supplier tally/depreciation already fold; dialogs stay complete. |
| P2 Debtors list, statement and preview | Fold balances/ageing; chosen Period line; move postings up; compact recipient/history sections | [Statement](/home/ryan/Development/ZP/Agora/Modules/Debtors/Resources/views/show.blade.php:92). Keep balance nature and send context; email preview keeps its own readable white rendering surface. |
| P2 Core dashboard and user/role administration | Fold workspace/account stats; compact section headers/read-only lists; keep account editing navigable | [User wrapper](/home/ryan/Development/ZP/Agora/Modules/Core/Resources/views/setup/users/_page.blade.php:22). Preserve section-anchor opening, independent saves, inherited/direct access distinction and shown-once password. |
| P2 Utilities | Fold the three monthly KPI tiles; apply shared shell/table/header rhythm; retain existing chosen-scope pattern | [Readings](/home/ryan/Development/ZP/Agora/Modules/Utilities/Resources/views/index.blade.php:101). Required opening/closing/capture fields, units and source-clock meaning stay explicit. |
| P2 Operations wall | Fold optional summary metrics; compact redundant control/status lines; keep primary alerts and TV-readable sizing | [Board](/home/ryan/Development/ZP/Agora/Modules/Operations/Resources/views/components/board.blade.php:21). Already wide. Preserve Pause, connection/stale/error/source gaps, selected sites and settings. |
| P2 Authentication and unfinished destinations | Reduce hero padding on narrow/short screens; keep login/OTP/reset form easy to reach; retain readable desktop form width | [Signin layout](/home/ryan/Development/ZP/Agora/Modules/Core/Resources/views/components/signin-layout.blade.php:39). Exco/console remain honest unavailable stubs, not invented dashboards. |
| Separate format: mail/PDF and developer gallery | Exclude mail/PDF from screen disclosures and shorthand; show representative open/closed gallery variants | Mail must work without JS; PDF page margins have a different purpose. Authentication/gallery wrappers are included in inventory, not silently omitted. |

## Adjudication and rejected shortcuts

| Reviewer proposition | Verdict and reason |
|---|---|
| Shared shell and pre-table chrome are the first intervention | Accepted. Source caps and gallery measurements support it. |
| Collapse every card / every statstrip globally | Rejected. Only statistical presentation folds; primary work, transaction feedback and warnings need explicit treatment. Reuse the card primitive with a semantic wrapper. |
| Persist statistical expansion because card memory already exists | Rejected for this requirement. Saved-open state would undermine the requested default; no statistics memory on page entry. |
| Simply rename `label` to the abbreviation | Rejected. It also changes exports, chooser, filters and mobile cards. Use display metadata. |
| Shrink all fonts and table rows | Rejected. Existing rows are already compact; preserve Large text and edit/touch usability. |
| Original-vs-amended still needs wide mode and removal of default raw quantities | Rejected as outdated. Both changes already exist. Finish its summary and heading treatment. |
| “Opening vs Amended” and weekly Opening vs Closing are one screen | Rejected. The requested comparison maps to Original vs amended variance; the weekly register is a separate family. Both are covered. |
| Grouped headers cannot work with table-tools | The row-zero parsing claim is stale: table-tools reads the last header row. However, sticky offsets currently handle only the second row; adding group + column + generated-filter rows requires cumulative offsets and span verification. Do not claim complete grouped-header support. |
| Blanket TV exception for all large metrics | Narrowed. Retain primary alert presentation/readability; optional summary metrics still follow the closed-default request. |
| Folding panels will speed up database queries | Rejected. Hidden server-rendered content has already been queried; lazy data loading is separate work and not claimed here. |
| Convert every result to data-grid now | Rejected. Stock commit tables need full-set selection semantics, and generic Reports has separate server pagination. Visual consistency does not justify changing those contracts. |

## Implementation sequence and acceptance

1. **Shared foundation:** fluid operational shell, spacing tokens and statistics wrapper; update ComponentCatalogue and regenerate its documentation when contracts change. Validate both normal/wide ancestors, scope/main/footer alignment, menu, closed/no-JS/keyboard disclosure and short-screen layout.
2. **Heading contract and pilots:** optional compact label plus full accessible meaning across data-grid, manual headers and Reports. Pilot Original vs amended variance, Stock Recon proposals and a bank run/centre fragment. Check old saved layouts, sorts/filters, mobile cards, chooser and CSV/XLSX full headers.
3. **Apply the family matrix:** work from P1 through P2, including weekly/counting-area/water/helper/group screens and JavaScript wall metrics. Remove duplicate chrome, not business context. Test selected records, errors, deep links and lazy inserts as each family is converted. For grouped headings, verify all sticky rows: the current first-row measurement and second-row-only CSS offset do not cover a generated third filter row.
4. **Focused acceptance:** compare the same scope and saved layout at 1440×900, 1920×1080 and 375×812 in both themes, plus 200% zoom for controls/readability. Record content edges, first primary-row position, stats closed/open height, actual visible rows and page overflow. A desktop operational shell should meet the 16px gutter target; folding stats must move work upward without altering totals or selection. Do not claim a universal row-count gain without these measurements.
5. **Scoped regression:** after actual implementation, run the affected component/gallery tests, build once for changed assets, generated component check and only the affected shell/grid/recon/mobile specs. Verify filters deliberately exclude rows from submissions while pagination/group collapse does not. Do not blindly run write-path tests against the customer-connected configuration. No full suite or whole-tree static analysis is required by this audit.

## Verification and local repair

The gallery initially returned **HTTP 500**, with `Undefined property: stdClass::$ReconBatchNo` at `two-pane-recon.blade.php:175`. The eight demo bank/deposit rows lacked their current batch fields and `IsOutstanding`, so rendering failed and sample rows would otherwise default to nonselectable. Only [StyleguideController fixtures](/home/ryan/Development/ZP/Agora/Modules/Core/Http/Controllers/StyleguideController.php:114) changed: bank `ReconBatchNo=0`, deposit `ReconBatchNoPumpIT=0`, and `IsOutstanding=true`. Rating 3/10; this repairs developer examples, not customer data or business behavior. No new test was needed because the existing gallery render test catches the defect.

| Claim | Check | Actual output |
|---|---|---|
| Inventory covers every Blade template | `rg --files Modules resources/views -g '*.blade.php'` plus comment-stripped component inventory | 207 templates; 150 module + 57 shared; module counts above; every path in inventory.json |
| Gallery renders after fixture repair | `php artisan test --filter='GalleryTest::test_the_gallery_renders'` | `passed`, 1 test, 3 assertions |
| Fixture style is valid | `vendor/bin/pint --test Modules/Core/Http/Controllers/StyleguideController.php` | `passed` |
| Current gallery is visible | Open local `/dev/components` in browser after repair | “Components · Agora”; KPI/statstrip sections rendered; measured values and screenshots above |
| Existing compact work was not overlooked | Targeted `git log` and current source inspection | `322717c` original-vs-amended report; `2c9bb10` earlier heading-width work; current variance raw quantities hidden and wide mode present |

No authenticated business-page visual pass, light-theme screenshot pass, live database/procedure checks, performance benchmark, build, full test suite or whole-tree analysis was run. Proposed layout targets remain to be proven during implementation. Existing uncommitted product work was preserved. No commit, push or deployment was made.
