# PumpIT columns a report must not filter on

Four legacy columns carry an obvious name and no data. Each is still exposed
through an `agora.vw_*` view, so the answer to "why does nothing say closed"
is visible in the data, but a report that filters or values on one returns
nothing, or zero, and says nothing while doing it.

These are **observations on a date, not proof the column can never change.**
Re-measure before relying on one (queries below, SELECT only, `pumpit`
connection).

| Column | 4 Sep 2026 | 12 Nov 2026 | Use instead | Named in |
|---|---|---|---|---|
| `BRN_DailyBanking.Posted` | 0 on all 164,019 rows | 0 on all 174,438 rows (none NULL) | `RCN_ReconImports.DayBalance` (`vw_ReconImports`) for "the day is closed"; Agora's own `agora.Cashup.Status` for "confirmed" | `vw_DailyBanking` comment (Reports `v1__22_reports_views.php`), `v1__12a_cash_cashup.php`, `usp_Cash_ConfirmCashup`, `usp_Reports_GridOpenCashups` |
| `RCN_ReconImports.SiteClosedOff` | recorded as "13 rows ever, all 2023"; 0 across 2026 | **23 rows ever** (16 in 2023, 7 in 2024; ReconDate 2023-06-21 to 2024-06-06, none created since 4 Sep); 0 of 5,273 rows in 2026 | `RCN_ReconImports.DayBalance` (4,307 of 5,273 rows in 2026) | `vw_ReconImports` comment, `usp_Reports_GridDayClose` (returned as `DeadSiteClosedOff`), `usp_Reports_GridOpenCashups` |
| `RCN_ReconImports.ReadyToImport` | 0 across 2026 | 0 of 5,273 rows in 2026 | nothing; no live equivalent. `HOImport` is the nearest step and is itself barely used | `vw_ReconImports` comment, `usp_Reports_GridDayClose` (`DeadReadyToImport`) |
| `RCN_ReconImports.PastelImport` | 0 across 2026 | 0 of 5,273 rows in 2026 | nothing; no live equivalent | `vw_ReconImports` comment, `usp_Reports_GridDayClose` (`DeadPastelImport`) |
| `BRN_FuelType.CostPrice`, `.SellingPrice` | 0 on all 41 rows | 0 on all 42 rows | `BRN_FuelPrice` (`vw_FuelPrice`): one row per price change per branch and fuel type; the price on a day is the latest row on or before it. 76,513 of 76,545 rows carry a selling price | `vw_FuelPrice` comment, `usp_Reports_GridFuelInput` |

Notes:

- The "13 rows, all 2023" figure in the `usp_Reports_GridDayClose` header and
  the `vw_ReconImports` comment does not match the 12 Nov count of 23 rows
  reaching into June 2024. No row was created after 4 Sep, so the column is
  still dead; the older figure looks mis-measured, not overtaken.
- The 2026 window of `RCN_ReconImports` includes 100 rows dated after the day
  of measurement (13 to 30 Nov 2026), presumably pre-created ledger rows. They
  do not change any conclusion above.
- `Posted` on `BRN_Transaction` (cash purchase requests) is a different, live
  column that Cash writes; this note is about `BRN_DailyBanking.Posted` only.

## Re-measuring

```sql
SELECT COUNT(*) AS total_rows,
       SUM(CASE WHEN ISNULL(CONVERT(int, Posted), 0) <> 0 THEN 1 ELSE 0 END) AS posted_nonzero
FROM dbo.BRN_DailyBanking WITH (NOLOCK);

SELECT YEAR(ReconDate) AS yr, COUNT(*) AS n
FROM dbo.RCN_ReconImports WITH (NOLOCK)
WHERE ISNULL(CONVERT(int, SiteClosedOff), 0) <> 0
GROUP BY YEAR(ReconDate);

SELECT COUNT(*) AS rows_2026,
       SUM(CASE WHEN ISNULL(CONVERT(int, ReadyToImport), 0) <> 0 THEN 1 ELSE 0 END) AS ready_to_import,
       SUM(CASE WHEN ISNULL(CONVERT(int, PastelImport),  0) <> 0 THEN 1 ELSE 0 END) AS pastel_import,
       SUM(CASE WHEN ISNULL(CONVERT(int, DayBalance),    0) <> 0 THEN 1 ELSE 0 END) AS day_balance
FROM dbo.RCN_ReconImports WITH (NOLOCK)
WHERE ReconDate >= '2026-01-01' AND ReconDate < '2027-01-01';

SELECT COUNT(*) AS total_rows,
       SUM(CASE WHEN ISNULL(CostPrice, 0)    <> 0 THEN 1 ELSE 0 END) AS cost_nonzero,
       SUM(CASE WHEN ISNULL(SellingPrice, 0) <> 0 THEN 1 ELSE 0 END) AS sell_nonzero
FROM dbo.BRN_FuelType WITH (NOLOCK);
```
