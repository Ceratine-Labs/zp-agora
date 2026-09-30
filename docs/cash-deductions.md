# Cash shorts and deductions (T041)

Cash → Deductions (`/app/cash/deductions`). Five tabs that are PumpIT's
head-office payroll reports, row for row, and a Payroll tab that builds a pay
period's schedule into Agora and takes the payroll file from it. Read this
before changing any figure on those screens, or the rule that decides what is
deducted.

## Where each tab comes from

| Tab | Agora procedure | Legacy procedure it ports |
|---|---|---|
| Cash short deductions | `usp_Cash_GridDeductionCashShorts` | `sp_RPT_CashShortDeductionPerEmployee` |
| Stock loss — summary | `usp_Cash_GridStockLossSummary` | `sp_RPT_EmployeeStockLossReportPAYROLL` |
| Stock loss — detail | `usp_Cash_GridStockLossDetail` | `sp_RPT_EmployeeStockLossReportPAYROLLDETAIL` |
| Non-integrated POS — summary | `usp_Cash_GridNonIntPosSummary` | `sp_RPT_NonIntegratedPOSDeductionsPAYROLL` |
| Non-integrated POS — detail | `usp_Cash_GridNonIntPosDetail` | `sp_RPT_NonIntegratedPOSDeductionsPAYROLLDETAIL` |
| Payroll | `usp_Cash_GridDeductionSchedule`, `usp_Cash_BuildDeductions`, `usp_Cash_ExportPayroll` | `sp_RPT_EmployeeStockLossReportPAYROLLDETAIL_LeniencySummary` for the stock allowance |

The figures are computed once, in two fillers the grids and the build share:
`usp_Cash_DeductionCashShorts` (fills the caller's `#CashShort`) and
`usp_Cash_DeductionStockLoss` (fills `#StockLoss`, `@Kind` stock or nonint,
`@Grain` day or shift). Change a rule there and every screen and the payroll
file move together.

**Proved:** Engen Bethlehem (branch 23), August 2026 — all five tabs identical
to the legacy procedures on live, and the build's 22 stock-loss lines identical
to `LeniencySummary`. The payroll file totalled R9,148.08, the Deducted figure.

## The rules

- **Cashier short** = the cashup's legs added up, less the Z reading
  (`agora.vw_CashupTotals.Variance`). Worse than the leniency (R3.00,
  `cash.deductions.cash_leniency`) is deducted **in full**; at or inside it is
  written off. There is no partial rule. The legacy tab lists only what is
  deducted; the stat strip counts what was written off.
- **Pump attendant short** = a line on the cashup's pump-shortages leg
  (`BRN_DailyBankingEmployees`), deducted in full.
- **Cash-bag short** = a staff short a recon match raised (`agora.StaffShort`,
  Source `recon`), approved, not declined, deducted in full. It is never posted
  onto a cashup (PM decision 01a0e5b5), so it is read directly. If one ever
  is, the pump-shortages line carries it and this source drops it, so it is
  never counted twice.
- **Stock loss** = `(−open − issued + close + POS) × selling price` per
  counting area and shift, shared equally among the people signed on to the
  area. The legacy filters hold: the person must have a post
  (`BRN_EmployeePost`), the item must be in `STK_StockMaster`, and the High
  Shrinkage and Lotto/Electricity/Airtime areas are left out.
- **Non-integrated POS** means what PumpIT means by it: the
  Lotto/Electricity/Airtime areas, one person's share of R1.00 or more. The
  build plan once described it as tender shortfalls (Zapper, Yumbi and so on).
  The legacy report the tab must match does not cover those.
- **Stock allowance** (Payroll only) is set per person per area group:
  `days in the period × the group's LossGraceDaily`. A total inside it is
  written off; a total beyond it is deducted in full, not the excess.

## The schedule and the file

- `usp_Cash_BuildDeductions` builds one site's period into `agora.DeductionRun`
  and `agora.Deduction`, one row per source line and person (natural key
  BranchId, RunId, Source, SourceKey, EmployeeCode). It refuses a period that
  has not ended, one longer than 62 days, or one overlapping a run already
  exported. Rebuilding the same period marks the old run superseded and keeps
  it.
- `usp_Cash_ExportPayroll` returns one line per person per site (positive
  rand: CashShort, StockLoss, NonIntegrated, Total) and marks the runs
  exported. Once exported, a period cannot be rebuilt.
- **The CSV layout is provisional** until ZP names their payroll system's.
- Nothing here writes PumpIT.

## Write once, flow everywhere (T042)

A short is keyed once. It is raised, approved and posted onto the cashup it
belongs to. The post writes one pump-shortages line, which moves the cashup's
variance off the cashier and onto the person short. The schedule reads that
line as `pump_short`, and the file reads the schedule. Nothing copies the short
along the way, so there is no event and no listener to keep in step. The one
row is read three times.

The gap it leaves: **an approved short that is never posted is on no cashup,
so nobody is deducted for it.** PumpIT had the same gap: 28 shorts worth
R11,488 from March to August 2026, none of them in June. The Cash and Payroll tabs say so before a
period is built (`usp_Cash_GetUnpostedStaffShorts`) and link to those shorts,
filtered to Approved. Cash-bag shorts are left out of that count because they
reach payroll directly.

The plan's `TillShortGuard` was dropped (PM decision 01a0e420). Every "Till
Balance Expense" purchase request is the legacy till-balance posting, and none
was raised by hand.

## Tests

`tests/Feature/Cash/DeductionsTest.php` — a branch-999 fixture where each rule
has a case: a short on each side of the leniency, one exactly at it, a cash-bag
short on and off a cashup, one stock group written off and one deducted, and
the Lotto share. The last test renders all six tabs, then builds and exports
through the routes.

`tests/Feature/Cash/WriteOnceTest.php` counts it: one short makes one
pump-shortages line and moves the variance by exactly its amount. It makes one
deduction row, on the attendant, with none left on the cashier, and one payroll
line. Posting it twice is refused. Left unposted, the cashier is deducted
instead, never both. A cash-bag short reaches the file once and touches no
cashup.
