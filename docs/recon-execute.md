# Auto reconciliation — what is built, and what Execute is waiting on

Written 4 September 2026, when the Recon module was scaffolded and the five
AUTO RECON previews were brought across onto Agora. This is the marked-down
version of the decisions that are not ours to make.

Background, all of it verified read-only against the customer's instance
between 2 and 18 August 2026:
[`pumpit-auto-recon-findings.md`](../../Zulu%20Petroleum/docs/pumpit-auto-recon-findings.md)
and the follow-up
[`pumpit-auto-recon-execute-recommendation.md`](../../Zulu%20Petroleum/docs/pumpit-auto-recon-execute-recommendation.md).

---

## What shipped

**The procedures moved, the logic did not.** ZP's five corrected preview
procedures now live on the **Agora** database as
`agora.usp_Recon_Preview{ABSA,FNB,CashMachine,CashBags,SmartATM}`. Each file in
`Modules/Recon/Database/Procedures` carries the original header verbatim plus a
note saying what moved. Two mechanical changes and no others:

1. Created in the `agora` schema, under the module-prefixed name, deployed by
   `v1__13p_recon_procs.php`. Agora owns them; they cannot be edited out from
   under us, and the customer can diff ours against theirs.
2. Every legacy table is reached through a matching `agora.vw_*` view. **The
   procedure still fires at PumpIT** — same instance, three-part name — it just
   no longer lives there. `SSBranchId` reads as `BranchId` because that is what
   the views expose; every other legacy column keeps its own name, so the
   ported body stays a readable diff against the original ZP validated.

**A controller and a screen.** `/app/recon` lists the five areas;
`/app/recon/auto/{area}` runs one. Every optional argument the procedure takes
is on the form rather than buried in a default, because each one exists where
the customer's configuration is ambiguous and we refused to guess.

**A run ledger.** `agora.ReconRun` and `agora.ReconRunLine`. Every preview is
recorded with the procedure it called, the arguments it was given and the rows
it came back with — because the extraction positions come from
`BRN_AutoReconCriteria`, which the customer edits, so the same procedure over
the same dates does not have to return the same rows next week.

---

## The three things Execute is waiting on

### 1. Agora writing to the customer's database at all

Execute stamps `ReconState` and `ReconBatchNo` in
`RCN_BankStatementLinesPumpIT`, and `ReconBatchNoPumpIT` on the matching
`BRN_DailyBanking*` rows. Both are in **PumpIT**, which this repository's first
rule says Agora never writes to.

That rule is right and should not be quietly relaxed for one screen. What is
needed is a decision on the record that says: *for the recon stamp, and only
the recon stamp, Agora writes these two columns on these two table families.*

`config('recon.stamp_mode')` is the switch and it is deliberately a **literal,
not an environment variable** — turning it on should be a commit with a name on
it, not a line somebody adds to a `.env` on a Friday.

### 2. ZP's own decision about Execute

On 18 August 2026 we recommended, in writing, that ZP **stop using Execute**
until the comparison defect is corrected, and offered one alternative: keep a
record of what Execute reconciles from today onward so the eventual clean-up is
bounded. That question has not come back.

Building an Agora Execute that behaves like the exe would ship the defect we
told them to stop using. Building one that behaves correctly is a *different
reconciliation* from the one their history was built with, and they should know
that before it runs.

The run ledger already is the record we offered them, which is why it was worth
building before the write path.

### 3. Who the stamp is attributed to

`agora.User.LegacyUserId` already exists for exactly this — the column the
`SS_Users` migration fills so an Agora user maps back to a PumpIT one. Nothing
in `RCN_BankStatementLinesPumpIT` records who stamped a line, which is why
question 3.7 of the findings ("who stamped the historical reconciliations?")
could only be guessed at from 1,570 orphaned rows.

**Open:** does the stamp write a PumpIT user id anywhere, or does attribution
live only in Agora's ledger? The legacy schema has no column for it, so adding
one would be a change to the customer's estate — which is the first question
again, in a smaller form.

---

## Open questions still with ZP

These are theirs, not ours, and each one bounds what a preview can honestly
claim. Carried over from §3 of the findings document; the state below is as at
4 September 2026.

| # | Question | Where it bites |
|---|---|---|
| 3.2 | What do the 15,002 FNB "standalone" lines settle against? | `@StandaloneRule` exists so ZP can *see* the candidate rule's answer. It can never reconcile on it — a preview that said otherwise would be lying |
| 3.4 | Six FNB branches match 0% at their configured positions | Needs new configuration rows **and** the multi-rule read, which the ported procedures already do |
| 3.5 | ABSA-format narratives filed under `Type = 'FNB'` at branches 5 and 9 | A classification problem in the import, not in the recon |
| 3.6 | Which date is the SmartATM trading day? | The window the preview pairs deposits inside |
| 3.8 | Two `BRN_DailyBankingFNB.BatchNo` rows hold the literal `#REF!` | Excel error in production data |

---

## What is deliberately not built

- **The batch, match and stamp tables.** They arrive with the write path. Five
  unused tables in a production database are five tables somebody has to reason
  about.
- **`<x-data-grid>` (T014).** The result table here is `<x-table>`: it scrolls
  in its own container, names its procedure and says its row count, but it has
  no header filters, no export and no column persistence. A screen that needs
  those waits for the grid rather than growing a second one here.
- **Multi-branch runs.** `ReconRun.GroupRef` exists so "reconcile every branch
  for August" is one action across twenty-six runs; the screen runs one branch.

---

## Verifying it

```
scripts/local-sql.sh up            # creates the PumpIT stub, recon tables included
php artisan migrate                # views + ledger + the five procedures
php artisan test --filter=AutoReconPreviewTest
```

`AutoReconPreviewTest` reproduces the findings against branch 999 on the local
stub. The assertion the file exists for is
`test_a_bank_line_with_no_deposit_never_reconciles`: a bank line with no
deposit behind it must be reported, must not be reconcilable, and must never
reach a state anything could stamp. If `MatchedRows` is ever 2 there, the NULL
comparison has come back.

It writes through the **app** connection by three-part name, never through the
`pumpit` connection — in a working development `.env` that one points at
105.247.172.179, the customer's live instance.
