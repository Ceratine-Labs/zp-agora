---
name: zrp-stock-recon-weekly
description: Weekly ZRP stock recon investigation for the convenience sites and Baobab Inn, built only from PumpIT reports. Use when the week's PumpIT exports are uploaded, or when asked to run the stock recon, build the master book, or write the weekly recon findings.
---

# ZRP weekly stock recon

This skill turns the week's recon, stock take, purchase and recipe exports into the ZRP Stock Recon Master Book: a diagnosed reason for every recon line, the shift-level evidence (opening vs closing, balancing breaches, issue-sheet failures), the standing controls (potatoes and buns, count compliance, count recency, recipe vs recon), a live site scorecard and a control-action list. Scope: convenience sites and Baobab Inn. Fuel is excluded. OK Stores are not covered.

## Step 1 - check the uploads

The files are normally in `/mnt/user-data/uploads`. Every input is a PumpIT report; no Google Sheet or manual list is used. Match the uploads against `reference/required_reports.md`. Each report is found by a phrase in its file name, so the exported file names must not be changed.

| Phrase in the file name | PumpIT report | Covers |
|---|---|---|
| Stock_Recon_Detail | Stock Recon Detail - Amended & Original QTY | All sites (WinBranch, Aura, Pilot, NAMOS) |
| Winbranch_Stock_Take | 1. Winbranch Stock Take Detail | WinBranch shops |
| Last_Stock_Take | Stock Recon Items with last stock take date - Winbranch sites | WinBranch shops |
| stk_01 | 1. Aura stk 01 stock take register allsites | Aura QSRs |
| Aura_09 | Aura 09 supplier grv DETAILED purchases allsites | Aura QSRs |
| Omni_Supplier | 1. Omni Supplier Transactions Detail - CONV Shops | WinBranch shops |
| DET_Sales | DET Sales Profit Rpt - Category GP Loss Growth | WinBranch shops |
| ALL_SITES_STOCK | ALL SITES STOCK SOH COVER GP - Master | WinBranch shops |
| Raw_Material_Consumption | Conveneice - Raw Material Consumption | WinBranch shops |
| Stock_take_VAR | Stock take VAR per product per day DETAIL | Pilot - Baobab Inn |
| Purchases_per | Purchases per day per supplier per product | Pilot - Baobab Inn |
| ACT_against_THEO | Consumption per product per day ACT against THEO | Pilot - Baobab Inn |

If any of these is missing, stop. Tell the user which report is missing, by its PumpIT name. Do not run with a gap and do not estimate the missing data. The run can technically go ahead without "Last_Stock_Take", "Raw_Material_Consumption" and "ACT_against_THEO", but then the not-counted figures are overstated and the Recipe vs Recon tab is incomplete. Only run without them if the user confirms.

The issue multiples are bundled in `reference/issue_multiples.xlsx`. If head office supplies an updated workbook (.xlsx with a "Proposed Multiples" tab and "Multiples" in the name), it replaces the bundled copy.

How the weekly line list is built: one line for each site, recon area and item on the Stock Recon Detail in the week. Each line carries:
- the recon variance for the week, balanced;
- the whole-site stock take variance in the week: WinBranch detail (S/TAKE), the Aura stk 01 register, or the Pilot daily counts summed over the week. NAMOS has no count link;
- the storeroom (stock take - recon);
- the previous week's stock take variance.

## Step 2 - run

```bash
pip install pandas openpyxl xlrd --break-system-packages -q
python <skill-folder>/scripts/run_weekly.py --input /mnt/user-data/uploads --out /mnt/user-data/outputs
```

`--week-from YYYY-MM-DD` forces the week (a Monday). By default it is the last full Monday-Sunday week in the Stock Recon Detail. The run takes 1-3 minutes and writes:

- `ZRP_Stock_Recon_Master_Book_<week>.xlsx` - the working book
- `summary_<week>.json` - the headline figures

Then recalculate the formulas if a recalc tool is available (for example the xlsx skill's `recalc.py`), because the Site Scorecard uses live COUNTIFS/SUMIFS.

## Step 3 - check before reporting

- Read the printed output. It shows the week, the recon cut-off per site and any site-days held back.
- A site held back for more than one day, or a site with no recon lines in the week, is a data problem. Report it first; it is not a finding. The usual cause is balancing or POS posting not finished when the Stock Recon Detail was exported.
- Open the Findings tab and check that the figures agree with `summary_<week>.json`.
- Never state a cause the evidence does not show. The Reason column says what the numbers support; where it says "investigate", the answer comes from the site, not from Claude.

## Step 4 - report

Give the user the book, then a short brief for the Ops team, built only from `summary_<week>.json` and the Findings tab:

1. Where the loss is: the storeroom (whole-site count short, recon balanced) against the recon point, by site, with rand values.
2. What people are doing: openings below the previous closing and how they were repaired, balancing breaches, issues only captured at balancing.
3. The standing controls: potatoes and buns issued on receipt, count compliance ("x of N lines"), items not counted for over 30 days.
4. What is not a loss: paperwork, storeroom overs (invoices), system and recipe faults.
5. Actions, with a site and an owner, taken from the Control Actions tab.

Style: formal, direct and concise. Report every count against its base ("110 of 277 lines"). Weekly and shift values are at selling price; period stock take values are at cost. Say which applies.

## The rules the engine applies (do not override)

1. Recon variance = POS - (opening + issued - closing). Negative = short.
2. Storeroom = site stock take - recon. A negative storeroom is a loss outside the recon: an issue not written up, or the key holder. A positive storeroom is not a loss: it is stock the book does not know about (an invoice not captured, captured short or late, or a credit on kept stock).
3. The opening must equal the previous shift's closing. Where it does not, the OPENING is amended; a closing is never changed to match. Changing the closing is reported as manipulation.
4. Balancing: an over may be balanced against a short shift, and a short may shrink. An over may never become a short, a short may never grow, and the latest closing count stands.
5. An over within 10% of a whole issue multiple is an uncaptured issue.
6. Steers potatoes (V7) and buns (B1, SBBB) are issued in full on the day of delivery: issue = GRV.
7. Recipes already include yield, so a shortfall against recipe consumption is never a yield effect.
8. The same item short in the same direction at three or more sites points to a recipe, pack or link fault, not theft.
9. An item with no stock take line in the week is checked against its last stock take date. The WinBranch stock take detail leaves out counts with no variance, so an in-week last stock take date counts as counted and balanced.
10. Each site is analysed only up to its last complete recon day: both shifts on at least 95% of lines, closings in, POS posted.
11. Aura QSR lines are counted from the Aura stk 01 register; Baobab Inn from the Pilot daily counts.
12. Virtual lines (airtime, electricity, lotto, refill water) are not stock.

The Scenario Matrix tab in the book explains every scenario code, its accountability and the ERP control that prevents it.
