# Agora mockup inventory (from agoraretailconsole.html)

Brand: AGORA — "All Group Operations, Reconciliation & Analysis" — owner Zululand Retail & Petroleum — was PumpIT.
Meta: 31 branches (25 sites + 6 admin entities), FY2027 (Jul–Jun), ABSA bank, DB server 105.247.172.179, v2026.8.6.1.
IA: four sections — Today / Trade / Control / Setup; two workspaces — Head Office / Branch; 5 roles — Executive, Finance, Operations, Branch manager, Auditor (read-only).
Global context bar: Region / Brand / Format / Branch (branch WS only) / Period + "as at".
Chrome: appbar (wordmark, workspace switch, primary nav w/ mega menus, Ctrl-K palette, exceptions bell w/ badge, theme toggle, avatar), crumb + context filters, main, footer, tooltip, extract drawer (CSV/copy), command palette.
Theme: light + dark tokens (paper/surface/ink/line/brand/s1..s5/good/warn/serious/crit/chrome), fonts Barlow Condensed + IBM Plex Sans + IBM Plex Mono.
Mobile: essentially none in the mockup (3 tiny media queries) — GAP.

## Views (routes)
- #/signin — role picker + live system state (loads failed, exceptions open, Z-reads unallocated, PRs awaiting)
- #/design — "why the redesign" (IA principles, naming)
- #/dash, #/dash/league, #/dash/mix — group trading position (KPIs, daily chart, margin chart, mix donut, bridge), league table, PC contribution
- #/site/<name> — site scorecard
- #/exco[/sheet] — Weekly Exco Trading Pack: 36 sheets (Group Summary, Fuel, Tank Recon by Site/Tank/Daily, Shop and GP, Lubes (+5 lube sheets), QSR, Virtual Sales, Stock, Cashback and Discounts, Cashback Register, Rung Up For A Cent, Pump Attendants, Attendant lubes vs till, Attendant Exceptions, Cost Anomalies (+detail), Meter by Grade/Pump, Exceptions, 9 site tabs) + "what the pack corrects" (Howto)
- #/control — exception register (sev, cat, site, title, detail, value, unit, report link, age; owner by category; KPIs; control desks)
- #/console — Branch console "Today at <branch>": 7-step day-close checklist (Import POS files, Pump readings, Fuel input, Z-read allocation, Stock recon areas, Daily banking, Drop safe), KPIs, "needs a decision", open exceptions for this branch
- #/lib[/cat[/report]] — new report library: 14 categories, 97 reports, each with scope, description, "was" (legacy names), tag (queue/new/merged/renamed), live link
- #/report/cat/<cat> — legacy catalogue 23 cats / 161 reports (kept for reference)
- #/report/<id> — 6 runnable reports w/ params + SQL: exco02, cashback, pumpvar, stockrecon, gp, banking
- #/ho — Head-office hub: Banking & Reconciliation (KPIs + 23 functions grouped Import / Banking / Reconcile / Masters / Monitoring / Reporting / Correction)
- #/ho/import/<src> — one generic import screen (drop file → Raw / Stripped / Imported tabs → load) for ABSA MarkOff, Zapper, Yumbi, Infinity, Cash Bags/Cash Devices/Smart, NAMOS, Bank Statements
- #/ho/statements — bank statement management
- #/recon/absa?t= (ABSA, FNB, Yumbi, Infinity, Fleet Card) and #/recon/cashbags?t= (Cash Bags, Cash Machine, Smart ATM, Direct Deposits) — two-pane reconcile workbench: captured vs bank, tick both sides, auto-match (batch no, then amount within 3 days), process batch, extract
- #/ho/reports — 5 tabs: Cash Short Deductions Per Employee (R3 leniency), Stock Loss By Employee Summary/Detail, Non-Integrated POS Deductions Summary/Detail

## Operations screens (#/op/*)
importpos, pump (Pump Reading Capture — mech/elec/POS, >15 L flagged), fuelinput (volume, dip, deliveries → days cover, order-now flags), zread (ZREAD Allocation — system proposes shift+employee with confidence certain/likely/review + evidence; auto-allocate; commit), stockrecon (overview), stockexc (day/night variance), balancing (amended vs original), areadash (area locks), preprod (bulk pre-production yield: beef/chicken/bakery), shiftvar, monthend, banking (daily banking / cashup: Z vs declared legs), cashups, dropsafe (bag, reason, >R2000 reason + manager enforced, collected), staffshorts (reason codes + approval trail), waste (qty, reason, approval), utilities (meter readings; zero-usage flag), pr (purchase requests header+lines, CA/CR numbering, expense cash/credit types, quote, justification, drawer "new PR"), prapprove (approval by band & delegate, escalation), importdash (overnight loads by branch/source), namos (capture NAMOS day end), cleareod, homonthend, dbf (manual day-end capture).

## Masters (#/master/*)
branch, brand, region, class (format), area (recon counting areas — 20), recon (criteria), employee (283; posts), stock (product master by location WINBRANCH/AURA; issue mult, produce, area, price type, pos, mon), meter (74; types B/L/R/S), creditor (2,931), debtor (412; limits), expense (119 codes; GL, VAT, asset flag, approver), profitcentre (19), stdcat (54 standard categories → GLs), catgl (1,248 POS category → PC, supplier GL, sales GL, min/max GP, min/max cover), critical (51 lines), virtualstock (airtime/lotto/electricity/vouchers), fuelmatrix (551 rows: site, period, grade, cost, sell, margin, fleet), user, usertype, sysdef, errorlog, assetgroup, assettype, assetowner.

## Business tools (#/tool/*)
assets (register: cost, depreciation, NBV, condition), movement (transfer/disposal/write-off/return), repair (job cards, supplier, fault, comeback within 30 days).

## Datasets in PIT (→ candidate domain tables)
sites, admin, prices, daily, exceptions, cashback (1,069 fills), pumpvar (112), stockrecon (191), gp (178), banking (168), employees (283), areas (20), stockmaster (1,011), zread (2,356), stockexc (204), balancing (480), areadash (331), preprod (105), preproditems (7), dropsafe (156), staffshorts (243), hodeduct (108), stockloss (28), nonintpos (29), purchreq (1,659), meters (74), utilities (1,134), bankdetail (394), waste (73), creditors (2,931), critical (51), profitcentres (19), fuelmatrix (551), catgl (1,248), debtors (412), recon.absa{cap,bank}, recon.cashbags{cap,bank}, importlog (127), stdcat (54), expenses (119), stocklossdetail (131), nonintposdetail (75), posts (12), marginhistory (18), exco (36 sheets), brand, ia, library (14 cats / 97 reports).

## Source systems (POS / feeds)
WinBranch (shop POS + stock), ARCH (OK stores), AURA (QSR / franchise), NAMOS (Total Hluhluwe day-end), GAAP, Pilot (forecourt), Hipos; tender feeds: ABSA MarkOff, FNB, Zapper, Yumbi, Infinity, Fleet Card, Cash bags / cash devices / Smart ATM, Direct deposits, Bank statements.

## Components used
appbar+mega nav, workspace switch, context filter bar, crumb, page-head (eyebrow/h1/p/actions), kpi strip (stripe/lbl/val/cmp + sparkline), card (card-h/card-b/flush), grid2/grid3, dataGrid (sortable, numeric cols, wide cols, row click, limit + extract note, footer), params (filter form) + runbar (Execute/Extract/status), statstrip, tabs, chip (good/warn/serious/crit/neutral), delta (up/dn/flat), note, emptystate, exception list (ex/bar/body), drawer (extract), palette (Ctrl-K), tooltip, charts (daily bars, margin line, mix donut, bridge/waterfall, sparkline, mini bar, multi-line, diverging bars), libcard/libmeta (report library), tree (legacy catalogue), sqlbox (show SQL), rolebtn, signin-state, fmtable (fuel matrix), two-pane recon workbench, PR header+lines drawer.
