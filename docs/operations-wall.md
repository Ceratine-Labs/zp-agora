# Operations Wall

`/app/operations-wall` is a read-only, automatically refreshed TV view. It rotates every25 seconds by default. TV settings choose one or many permitted sites, screens, rotation speed and optional two-minute return from detail. Settings belong to the account on that browser. Division groupings are deliberately deferred until the customer confirms membership.

Pause stops rotation, not refresh. Full screen is optional. Cards open an in-wall detail panel; source links lead to the existing filtered report. Automatic rotation cycles later pages on later visits so alerts beyond the first six are not stranded. The overview orders critical signals before warnings. Counts are signals, not unique incidents or sums of mixed units.

The optional **Hide zero values** checkbox hides cards whose underlying numeric value is exactly zero, including zero-stock warnings. It combines with **Attention only**, applies to View all and rotation, and is saved for this account on this browser. Missing readings and nonzero values remain visible. Summary metrics continue to describe all signals; the card count and pagination describe the filtered view.

Critical-item source links preserve the selected site, POS system and exact POS code. The destination's out-of-stock headline reuses the grid's validated query and filters through `usp_Product_GridCriticalLines`. Array and CSV site selections are equivalent; rejected selections remain empty, and branch workspaces stay pinned.

## Live sources and meaning

- `usp_Operations_Snapshot`: yesterday in SAST, selected active trading sites only. Fuel loss needs adjacent daily dips; cover requires seven complete daily observations. Daily meter differences use the established15L caution. Shop/QSR compare recorded day-end turnover with four matching weekdays. Cash keeps shortages and overs separate. Stock shows ten largest loss codes per site/location plus whole-site totals, using amended quantities after good waste at selling price.
- `usp_Operations_TradingChecks`: current recorded critical-line POS stock and below-cost pricing, with unknown source refresh time stated. It does not invent historical stock or days-cover. Monthly budgets produce a labelled calendar-day target. Identical duplicates collapse, conflicting duplicates are withheld; absent differs from explicit zero. Retail/liquor budget originally assigned to site9 is mapped to site15 per the verified Exco extract. Daily turnover is not compared to budget until its VAT basis is verified.
- `usp_Operations_Sites`: only active, trading, nondeleted sites, constrained by user grants and the pinned branch workspace.

Warnings from the customer overview remain provisional and visible in drilldown: tank loss0.25%/0.40% of throughput, turnover10%/20% below the four-weekday baseline, cash shortageR200/R500. Cash10:00/11:00 flags concern unresolved captured cash-ups, not verified submission/review timestamps. The disputed75/95-second attendant alarms are not enabled. SQL owns these rules.

Missing imports/readings are unknown, never an all-clear. Unconnected item-sales gaps, expiry, historical price changes, actual/theoretical QSR GP, service times, upselling, Wacky Wednesday availability, food-safety checks, rating tasks, action-pack progress and count schedules remain explicitly listed under Source coverage.

## Access and loading

Page and snapshot require `operations.wall.view` and wall menu access. Each returned source also checks its existing report menu entry or Product/StockRecon/Cash permission. URL-based report entries are checked directly; checking their shared route name alone would miss the tick. Cash-ups use the current Daily Banking screen's `cash.cashup.view` permission and `app.cash.banking` menu entry; drilldown uses its `branch_id` and `date` parameters. An administrator assigns the wall permission/menu to intended accounts. The installation does not assign access to existing users.

Explicit site IDs are intersected with the current scope. Invalid selections stay empty. Branch users stay pinned. Cached raw results are keyed by explicit sorted site IDs and business date, with permissions filtered again on every response. A60-second file cache and query lock avoid a separate database scan per TV. Responses are private/no-store; authentication failures clear browser figures, while transient network errors keep a visibly stale last snapshot. HTTP failures do not become healthy zeros.

## Installation and rollback

Deploy the entire Operations module and its JS/SCSS entry wiring, component catalogue, and rebuilt Vite assets. Apply `v1__25_operations_budget_view` then `v1__25p_operations_procs`. The critical-item drilldown fix also requires `v1__05pc_product_critical_exact`, which updates only the read-only `usp_Product_GridCriticalLines` procedure. Seed only `MenuSeeder` (catalogue and menu metadata; no UserPermission/UserMenuItem changes). Refresh module/config/route/view caches and PHP opcache. No customer tables or dependency packages are changed.

The budget migration creates only an Agora read view. If the configured reporting database/table is absent, its typed empty view yields unavailable targets; a present incompatible schema refuses installation. Reapply its up method when connecting a previously absent source.

For rollback, restore the backed-up source and manifest and refresh caches/FPM. New read-only procedures/view may remain. Hide the new menu entries if retiring the screen; never remove customer business data.

## Verification

Focused tests cover selected/granted/pinned scopes, missing feeds, fuel gaps, weekday baselines, top-ten stock aggregation and existing-report parity, stock/POS keys, budget duplication and site remapping, cache presentation permissions, and installation without user access changes. Frontend tests cover preferences, scope URLs, severity, rotation and later-page cursors. Run these touched tests only; the full application suite is intentionally outside this change.
