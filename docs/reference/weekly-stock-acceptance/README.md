# Weekly stock acceptance evidence — 26 October 2026

The workflow has been extended and tested locally. **This is a documented current-source reconciliation, not certification that the saved master book can be reproduced exactly.** The PM parent remains in review. No commit, push, deployment, customer data repair or live investigation assignment was performed.

## Evidence and scope

- `comparison.csv` identifies the workbook sheet/row, site, area, product, field, expected value, actual value, result and explanation for every comparison.
- `manifest.json` identifies the saved workbook, SHA-256, reporting dates, source/result files and their hashes. Raw captures are retained under `storage/app/weekly-workbook-evidence/2026-10-26/`, outside the tracked application source.
- Engen Bethlehem One Plus: all 203 weekly recon cases from the captured current source, plus its period/control registers and scorecard. Baobab Convenience Centre: B1, SBBB and V7 only; its subset must not be treated as a whole-site scorecard.
- Weekly baseline: **14–20 September 2026**. Controls/costing: **1–21 September 2026**. Count recency reporting date: **23 September 2026**. Both compared sites' supplied recon reaches 21 September; the weekly calculation stops at 20 September.
- Inputs were read from current customer sources without writes. Snapshot/case calculations ran through the actual local SQL procedures inside transactions that were rolled back. Separate HTTP tests exercise authorization, assignment, approval, reopening and export.

The original September source exports were not found. A current stock-master date cannot recreate a historic nil-count proof: for example, BIOPLUS `6009695584936` was treated as counted on 18 September in the saved book, while the captured current master date is 21 October. Agora leaves the September count unproven. The CSV records this as a difference, not a zero or a pass.

## Representative results

The captured comparison contains **2,360 field checks: 1,924 matches and 436 differences**. Focused verification passes **15 test methods / 374 assertions**.

| Saved-book case | Expected | Actual current-source result | Interpretation |
|---|---|---|---|
| Item Register row 477, Bethlehem RFG423 | B1; recon 0; count −8; selling exposure R251.92 | Same | Classification and quantities agree. |
| Potatoes & Buns row 5, Baobab B1 | 1,250 received/issued; 5/5 deliveries | Same | Period totals and delivery matching agree. |
| Potatoes & Buns row 6, Baobab SBBB | 270 received/issued; 4/4 deliveries | Same | Period totals and delivery matching agree. |
| Potatoes & Buns row 7, Baobab V7 | 600 received; 317.32 issued; 282.68 unissued; 0/6 deliveries matched | Same | All 26 saved delivery/detail rows also agree on received, compared issue and issue-without-delivery flags. No recovery amount is inferred. |
| Site Scorecard row 8, Bethlehem | 62 investigation lines; R5,027.69 selling exposure | 202 lines; R4,348.70 | Current count proof and several recon quantities differ. This scorecard is not equivalent to the saved baseline. |

Quantity/rand comparisons allow 0.011 for the historical report's rounding; unit costs allow 0.000001. Text matches exactly. These are **field checks**, not independent cases or an equivalence percentage. The script stops each summary table before subsequent tables on the same worksheet and separately compares delivery details.

## Implemented rules

The snapshot retains its raw source JSON, source availability, weekly and control cutoffs, reporting date and derived case evidence. Subsequent investigation actions cannot rewrite that evidence. New snapshots retain earlier snapshots for review. Distinct control periods do not silently supersede one another.

Count-to-count windows exclude the prior count date and include the current count date, combine product quantities across its counting areas, and mark a window that starts before available recon as partial. Period totals use the final window's unit cost, following the customer's engine; they are not a sum of differently priced windows. Cost precedence is count-source cost, DET sales-cost fallback, then the explicitly labelled `selling / 1.15 × 0.7` estimate. WinBranch uses the current report-1307 master cost; Aura uses report-1308's actual purchase cost over 180 days, not the count screen price; Pilot retains its prep-sheet unit cost. Missing count operands remain unknown. Conflicting DET categories remain unavailable rather than guessed.

Weekly selling-price exposure, period stock variance at cost and signed charges/recoveries are separate fields and totals. Virtual/meter lines are excluded from physical-stock loss costing. Shared whole-site counts are not added repeatedly to weekly monetary totals. A very large E2 count is excluded from exposure in the same manner as the pack's suspected count-capture rule.

Opening/closing breaks, balancing breaches, issue failures, count compliance/recency, period items, daily recipe comparisons and receipt controls have actionable cases. Receipt period totals are independent of same-day/next-day delivery matching; issues without a receipt today or yesterday remain visible. Missing daily recipe data remains unavailable instead of being silently filled with zero.

Management actions reference their supporting cases. The book's preventive controls—zero-count exports, NAMOS count links where relevant, virtual lines, issue packs, opening handover, staff identity and count reason codes—also get assignable records. They retain the book's target wording and responsible role; actual owner and due date are explicit investigation inputs. These tasks record follow-up; they do not implement changes in the customer's ERP. All-site policies are instantiated per site rather than merged into one all-site action. Cross-site patterns use complete permitted snapshots, original whole-product quantities, the three-site/75%/3% rule and median; links lead to the relevant site cases for action. The pattern display itself is not a separate signed register row.

Closing requires an owner, due date, finding, evidence reference, outcome, explicit recovery amount, recovery evidence when positive, correction evidence/reason, completed action/date and head-office sign-off. Opening/closing and balancing controls require a recorded correction. A management action cannot close while a supporting case is unsigned. Separate approve/reopen permissions apply; ordinary save cannot close. Approval captures actor/time/closed date. Reopening preserves the prior signed record, removes the current sign-off and reopens any signed management action that depended on that case. Optimistic version checks reject stale writes.

## Verification and limits

`verification.json` records the focused passing test results and scoped static checks. Tests cover actual snapshot SQL, HTTP creation with unavailable sources, review, assignment, closure, export, signed-history preservation, management approval dependencies, branch isolation, date boundaries, cost fallback/paid cost, virtual exclusions, shared counts, daily recipe gaps and receipt timing. Only the named methods were run, one at a time, against disposable local fixtures.

Pint and PHPStan were scoped to the changed weekly PHP/tests. Migration, procedure, component/view and business-date rules were checked against the touched files. Component-catalogue regeneration was excluded because no component was changed. No full suite, whole-tree static analysis, production smoke test, browser interaction test or deployment was run. Approval/reopen permissions are seeded without automatically granting them to production users.

Remaining acceptance work is explicit: the captured sources do not prove exact September whole-workbook equivalence; changed recon quantities/costs and unavailable historical nil counts remain differences; the all-nine-site saved cross-pattern baseline has not been replayed. Generated management actions preserve the control workflow but not every historical narrative/deadline as a one-for-one row. A separate signed cross-site register would be additional work if required beyond the linked site cases. None of these is converted into an assertion that the parent is complete.

## Reproduce locally

With the retained captures and local Agora database, run `php docs/reference/weekly-stock-acceptance/replay.php 13` and then the same command with `23`. The script refuses a non-loopback app database, creates only a temporary test actor and rolls back every snapshot/case write. It performs no customer source reads. Then run `python3 docs/reference/weekly-stock-acceptance/reconcile.py` to regenerate the comparison and manifest. The saved workbook remains untouched.
