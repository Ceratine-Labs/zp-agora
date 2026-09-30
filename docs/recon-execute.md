# Auto reconciliation: Execute as it stands

What Execute writes into the customer's estate, the path a stamp takes, and
what is still with ZP. Read this before changing anything in `Modules/Recon`
that could reach PumpIT.

The first version (4 September 2026) had Execute waiting on three decisions.
Two were settled that week and are under "Decided". The third, ZP's answer on
their own Execute, never came; Execute went live without it. Rewritten from the
code on 24 September 2026.

Background, verified read-only against the customer's instance between 2 and 18
August 2026:
[`pumpit-auto-recon-findings.md`](../../Zulu%20Petroleum/docs/pumpit-auto-recon-findings.md)
and the follow-up
[`pumpit-auto-recon-execute-recommendation.md`](../../Zulu%20Petroleum/docs/pumpit-auto-recon-execute-recommendation.md).

---

## What it writes in PumpIT

`stamp_mode` in `Modules/Recon/Config/config.php` has been `'live'` since
4 September 2026, on Ryan's instruction ("lets enable the execution please").
It is a literal, not an env var, so changing it is a commit with a name on it.
The services pass it to the procedures; no procedure decides for itself.

| Procedures | PumpIT table | Columns written |
|---|---|---|
| `usp_Recon_Commit`, `usp_Recon_ManualMatch`, `usp_Recon_Reverse` | `RCN_BankStatementLinesPumpIT` | ReconState, ReconBatchNo |
| the same three | `BRN_DailyBankingABSA`, `…FNB`, `…Deposita`, `…CashBags`, `…SmartATM` | ReconBatchNoPumpIT |
| `usp_Recon_Commit`, `usp_Recon_ManualMatch` | `SS_UniqueNumber` (SectionId 1) | the batch counter, `NextUniqueNumber` |

This is the bank recon half of CLAUDE.md rule 1 and must stay identical to it.
Anything added needs Ryan's named go-ahead, and a permissions request to ZP
must carry UPDATE on these tables (D-03 in the build plan). Every other recon
procedure writes to Agora or to nothing; `BRN_AutoReconCriteria` is never
written.

`'journal'` runs the same procedures and fills the same ledger, but writes
nothing to PumpIT and uses negative batch numbers instead of the customer's
counter. It is the reviewed worklist offered to ZP on 18 August 2026.

---

## From preview to stamp

**Preview.** `usp_Recon_Preview{ABSA,FNB,CashMachine,CashBags,SmartATM}`,
read-only, recorded as an `agora.ReconRun` with an `agora.ReconRunLine` per
proposal, the procedure and its exact arguments. `WouldReconcile` is 1 only
when both sides exist and balance, so a bank line with no deposit has no path
to a stamp (finding 2).

**Tick.** Only a pending row with `WouldReconcile = 1` can be ticked
(`ReconService::select()`). The run page unticks what `usp_Recon_RunFreshness`
says another Agora run has committed since the preview. That reads Agora's
ledger only; a stamp made by the executable is caught by the commit's re-check
instead.

**Execute.** `usp_Recon_Commit` re-finds both sides of each ticked row through
`usp_Recon_DrillBank` and `usp_Recon_DrillMops`, with the run's own arguments.
A row whose bank lines are no longer `ReconState = 1`, whose deposits are no
longer `ReconBatchNoPumpIT = 0`, or whose sides no longer balance to the cent
is skipped and marked `blocked` with the reason, never stamped over. The rest
take batch numbers from `SS_UniqueNumber` in one `UPDATE … OUTPUT` — the
executable's counter, so the two cannot collide — and the counter, the stamps
and the ledger rows are one transaction.

**Reverse.** `usp_Recon_Reverse` needs a reason. Bank lines go back to the
ReconState and ReconBatchNo recorded in `agora.ReconMatch` (the executable's
batch number, if that is what was there), deposits go back to 0, and only rows
still carrying Agora's batch number are touched. The counter is never wound
back; the proposals return to pending. A cash-bag match's split by cashier is
marked reversed in the same transaction and never deleted. A staff short the
match raised that is still waiting for approval is declined in the same
transaction, through `usp_Cash_DeclineStaffShort`, with a reason naming the
reversal, and its line becomes `cancelled` keeping the short's number (as does
one an approver had already declined, and a `pending` one never raised). A
match whose short has been approved or posted to a cashup is refused
(`SHORT_RAISED`) until the short is dealt with in Cash (Ryan, 27 Sep 2026, PM
decision 01a0e388).

Three other ways in, through the same guards and ledger:

- **Manual match.** `usp_Recon_GetSides` lays out both sides;
  `usp_Recon_ManualMatch` stamps the pairing and records it as a run, so
  Reverse and Trace work on it unchanged. A pairing that does not balance needs
  a reason. On CashBags the match also carries the split by cashier the clerk
  confirmed (each bag's cashier is its drop-safe row's): the procedure derives
  it pro rata on the banked amount and refuses one that does not add up to the
  bags and the variance (`ALLOCATION_INCOMPLETE`). The other four areas send
  none. The confirmed split is kept in `agora.ReconAllocation`, one row per
  cashier line, inside the match's transaction. A staff short is due only for a
  negative share (bank less bags) on a line with a cashier — every one, however
  small (the R3 leniency is the deduction schedule's): in live mode it is
  RAISED in the same transaction through T040's `usp_Cash_RaiseStaffShort`
  (`-Share`, dated by the cashier's last bag, reason code cashier short, the
  note naming the batch) and the line becomes `raised` with its `StaffShortsNo`;
  a short that cannot be raised fails the whole match. `journal` in journal mode
  (never raised), `none` for an over or zero, `no_cashier` for the bags with
  none recorded. Recon never writes `BRN_StaffShorts` itself. The procedure
  cannot send mail, so the approvers of a short a match raised are told by
  `php artisan cash:staff-short-mail`.
  The split is read back wherever the match is: the run page draws it
  (`usp_Recon_GetAllocation`), one line per cashier with a totals row that
  ties to the batch, reversed lines kept and marked; `usp_Recon_DrillMops`
  gives each cash bag its cashier and its cashier's line of the split, one row
  per bag as before (the other four areas return the columns they always
  did); and the run's extracts carry both — the deposits file (`app.recon.run:mops`)
  per bag, the proposals file (`app.recon.run`) as "Accounted to" and "Staff
  short due". A forced match's message states the variance bank less bags,
  like the split: "a variance of -R10.99, bank less bags: the bank is R10.99
  short of the bags". Every recon screen shows a difference the same way, as
  "Bank less deposits" (Ryan, 27 Sep 2026): the run page, the proposals
  extract, the trace, the manual-match totals and the Suggestions tab.
  `ReconRunLine.DiffAmount` and the suggestions' `DiffAmount` are still stored
  deposits less bank, as the previews return it.
- **Suggestions, FNB only.** `usp_Recon_SuggestMatches` is read-only and
  proposes, by value, pairings for what the batch number could not pair:
  strong, possible or close (ZP's ask, 23 September 2026). Each is accepted
  through `usp_Recon_ManualMatch`; a close one carries a variance and needs a
  reason.
- **Every site.** `/app/recon/auto/{area}/all` opens an `agora.ReconRunGroup`
  and previews one branch per request. Posting it calls `usp_Recon_Commit`
  branch by branch, one transaction each, so one site refusing does not stop
  the rest (`ReconGroupService`).

`usp_Recon_DiscardRuns` discards, closes and reopens runs. A run with anything
processed against it (committed, reversed, or with ledger rows) is never
discarded or closed (Ryan, 9 September 2026).

Routes are in `Modules/Recon/Routes/web.php`. Everything that stamps PumpIT
needs `recon.runs.execute`; reversing needs `recon.runs.reverse`.

---

## Where the previews came from

The five previews are ZP's own corrected `dbo.sp_RPT_AUTORecon*Preview`, ported
onto Agora on 4 September 2026 with two mechanical changes: they live in the
`agora` schema under module-prefixed names, and they reach every legacy table
through a matching `agora.vw_*` view. `SSBranchId` reads as `BranchId`; every
other column keeps its legacy name, so each body stays a readable diff against
what ZP validated. They still read PumpIT, same instance, by three-part name,
and each header records what moved. `usp_Recon_PreviewSmartATM` is the one whose
logic differs, and its header says how: it dates a deposit by the device's own
timestamp.

---

## The tables

| Table | Migration | Holds |
|---|---|---|
| `ReconRun`, `ReconRunLine` | `v1__13_recon_tables.php` | every preview and proposal, acted on or not |
| `ReconBatch`, `ReconMatch`, `ReconStamp` | `v1__13a_recon_execute_tables.php` | the batch; each row touched, with its prior state; what was asked of PumpIT and whether it was applied |
| `ReconRunGroup` | `v1__13g_recon_run_group.php` | one press across every site |
| `ReconCriteria` | `v1__13h_recon_criteria.php`, rekeyed by `v1__13i_recon_criteria_rekey.php` | configuration overrides, each replacing one legacy rule by its `AutoReconId` or adding one |
| `ReconAllocation` | `v1__13k_recon_allocation.php` | the split by cashier a cash-bag match confirmed, and the staff short each line is due |

`v1__13b` to `v1__13f` extend `ReconRunLine` for near references and the
deposit side's own id, and expose the Smart ATM deposit's key through its view.
`v1__13p_recon_procs.php` and the lettered `p` slots after it each
redeploy the whole `Modules/Recon/Database/Procedures` directory.

---

## Decided

- **Agora writes the recon stamp into PumpIT.** Ryan, 4 September 2026.
  CLAUDE.md rule 1.
- **The batch, match and stamp tables, and multi-branch runs.** Built:
  `v1__13a` in the module's first commit (64f74f3, 5 September 2026),
  `v1__13g` on 8 September 2026.
- **Who a stamp is attributed to.** Agora's ledger only: `CommittedBy`,
  `CreatedBy` and `AppliedBy` carry the Agora user. Writing it to PumpIT would
  be an addition to rule 1.
- **Configuration.** Edits live in `agora.ReconCriteria` and shadow the
  customer's rule rather than change it. Ryan, 8 September 2026.
- **Execute is in daily use at ZP** (3.1). ZP, 14 August 2026.
- **ABSA settles on the sum of its credit and debit legs** (3.9). ZP,
  14 August 2026.
- **The Smart ATM trading day runs midnight to midnight** on the date in the
  bank narrative (3.6). ZP, 16 September 2026.

## Still with ZP

- They have not answered the **18 August 2026 recommendation** to stop using
  their own Execute until the comparison defect is corrected, or at least keep
  a record of what it reconciles from then on.
- Agora reconciles **correctly**, which is a different reconciliation from the
  one their history was built with. That was not settled when Execute went live.

From §3 of the findings, with no answer recorded in this repository:

| # | Question | Where it stands |
|---|---|---|
| 3.2 | What do the 15,002 FNB "standalone" lines settle against? | `StandaloneRule` shows the candidate rule's answer and can never reconcile on it. Suggestions pair these lines by value instead, a clerk accepting each |
| 3.4 | Six FNB branches match 0% at their configured positions | The multi-rule read is built and the missing rows can now be added as Agora overrides. Whether to is ZP's call |
| 3.5 | ABSA-format narratives filed under `Type = 'FNB'` at branches 5 and 9 | A classification problem in the import, not in the recon |
| 3.7 | Who stamped the historical reconciliations? | Still unanswerable from the schema. Agora's own stamps can be followed at `/app/recon/trace` |
| 3.8 | Two `BRN_DailyBankingFNB.BatchNo` rows hold the literal `#REF!` | Excel error in production data |

---

## Verifying it

```
scripts/local-sql.sh up            # the local PumpIT stub, recon tables included
php artisan migrate
php artisan test --filter=ExecuteReconciliationTest
```

`ExecuteReconciliationTest` proves journal mode moves nothing in PumpIT, live
mode stamps both sides, and a reversal puts back what was there, prior batch
number included. `CommitStampsEveryAreaTest`, `SmartAtmCommitTest` and
`ManualMatchTest` cover the other areas and the hand-made path;
`ReconAllocationTest` and `ReconAllocationDisplayTest` the cash-bag split,
kept and then read back on the run page, the drill and the extracts.
`AutoReconPreviewTest::test_a_bank_line_with_no_deposit_never_reconciles`
guards finding 2: if `MatchedRows` is ever 2 there, the NULL comparison is back.

These tests write to the stub through the **app** connection by three-part
name, never through `pumpit`, which in a working development `.env` points at
105.247.172.179, the customer's live instance.
