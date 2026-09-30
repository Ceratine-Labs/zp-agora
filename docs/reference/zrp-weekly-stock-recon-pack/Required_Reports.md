# PumpIT reports for the weekly stock recon run

Every input is a PumpIT report, including the Aura and Pilot reports. No Google Sheet is used: the weekly line list, the balanced shift lines and the last count dates are all built from these reports.

**When to export:** on Tuesday, after balancing has been completed for every site for the whole week and Monday's stock takes have been posted.

**Date range:** from the Monday three weeks before the week under review, to the Monday after it (the count day). For example, for the week of 21-27 September, export 31 August to 28 September. The earlier weeks give every stock take its previous count.

**Do not rename the files.** The run finds each report by a phrase in its file name.

All 12 reports are run from PumpIT. The "System" column shows which point-of-sale system each report covers.

| # | PumpIT report | System | Phrase the run looks for | What it gives |
|---|---|---|---|---|
| 1 | Stock Recon Detail - Amended & Original QTY | All sites | Stock_Recon_Detail | Every shift line: staff count (original) and balanced (amended). It is also the weekly line list and the balancing screen. |
| 2 | 1. Winbranch Stock Take Detail | WinBranch | Winbranch_Stock_Take | Whole-site stock takes (variance lines). |
| 3 | Stock Recon Items with last stock take date - Winbranch sites | WinBranch | Last_Stock_Take | Last count date per recon item. It proves an item counted with no variance. Run it for the week under review. |
| 4 | 1. Omni Supplier Transactions Detail - CONV Shops | WinBranch | Omni_Supplier | GRVs, invoices and credits. |
| 5 | DET Sales Profit Rpt - Category GP Loss Growth | WinBranch | DET_Sales | Unit cost for period values. |
| 6 | ALL SITES STOCK SOH COVER GP - Master | WinBranch | ALL_SITES_STOCK | Last stock take date per item (count recency). Run on the day. |
| 7 | Conveneice - Raw Material Consumption | WinBranch | Raw_Material_Consumption | Recipe explosion of sales (TYPE u), compared with recon POS. |
| 8 | 1. Aura stk 01 stock take register allsites | Aura (QSR) | stk_01 | QSR stock takes. |
| 9 | Aura 09 supplier grv DETAILED purchases allsites | Aura (QSR) | Aura_09 | QSR GRVs (potatoes and buns test). |
| 10 | Stock take VAR per product per day DETAIL | Pilot (Baobab Inn) | Stock_take_VAR | Daily whole-site counts off the prep sheet. |
| 11 | Purchases per day per supplier per product | Pilot (Baobab Inn) | Purchases_per | Purchases per supplier and product. |
| 12 | Consumption per product per day ACT against THEO | Pilot (Baobab Inn) | ACT_against_THEO | Theoretical (recipe) consumption per day, compared with recon POS. |

The issue multiples (pack size per recon item) are bundled with the skill. Head office supplies an updated workbook when the multiples change.

## Known gap in PumpIT

Report 2 has no line for an item counted with no variance. Report 3 covers that gap for the week. The permanent fix is to change report 2 so that it lists every counted line, including those with nil variance. Until then, report 3 is required. Engen Manguzi is not on report 6, so report 3 is its only count date.
