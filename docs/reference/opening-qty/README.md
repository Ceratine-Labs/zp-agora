# Opening-quantity correction — the pack for Zululand

Prepared 21 Sep 2026 after the customer said they want to proceed; revised the
same day once their answers came back. Every figure below was measured
read-only against live PumpIT on 21 Sep 2026 at 07:00 UTC (09:00 SAST).

> ## ⚠ THIS HAS RUN. ONE SITE. READ THIS BEFORE ANYTHING ELSE.
>
> The line that used to sit here said *"Nothing has been run against the
> customer's instance."* That has been false since 21 Sep 2026 19:36 SAST.
> Verified against the customer's instance on 22 Sep 2026:
>
> | | |
> |---|---|
> | `02` deployed to PumpIT | **21 Sep 2026 19:36:26** — 3 tables + 2 procedures created |
> | Correction applied | **21 Sep 2026 19:36:38**, RunId `738E5311-8465-4993-8FC2-73665DFAF380`, by `Revteck` |
> | Scope of that run | **Branch 9 only — Esikhawini Convenience.** Not the 17-site run. |
> | Openings changed | **2,153** — 2,075 as-captured, 141 working |
> | Dates touched | 2024-08-29 … 2026-09-20 |
> | Gross value moved | **R 1,252,099.58** |
> | Still reversible | **2,152 of 2,153** — one row has been edited since and the reversal will skip it |
>
> The other sixteen sites have **not** been run. `STK_OpeningFixScope` holds
> all 17 rows, so `sp_RebuildStockReconLine_QtyOpen_RunAll` would do the rest;
> nobody has called it.
>
> **It ran at 19:36, inside trading hours** — not the 00:15–01:15 window this
> pack argues for further down. The `RowsSkipped_ChangedUnderUs` count from it
> was never recorded, and for a day that was the open worry. **Settled 22 Sep:
> it skipped nothing.** A what-if across branch 9's whole history now finds
> seven broken openings, and all seven were created at 12:01–12:04 on 22 Sep —
> this morning's capture of Saturday's shifts. A skipped row would have
> pre-dated the run. `09-branch-9-verify.sql` step 5 is the proof.
>
> `07-branch-9-report.sql` reads the audit log and reports exactly what that
> run did; `09-branch-9-verify.sql` is the shorter read-only pass that was
> actually taken on 22 Sep, with every result recorded inline.

| File | What it is | Who it is for |
|---|---|---|
| `01-copy-STK_StockReconLine.sql` | The table copy. Full row-for-row copy into `PumpIT.dbo.STK_StockReconLine_Bak_20260921`, with completeness checks and a commented-out restore. **Revision 2** — fully qualified names throughout, so it no longer matters which database the client is connected to. | **Ryan runs this first.** |
| `02-proposed-proc.sql` | The correction: `sp_RebuildStockReconLine_QtyOpen_FromPriorClose` (one branch), `sp_RebuildStockReconLine_QtyOpen_RunAll` (the driver), plus the audit-log, exclusion and scope tables. **DEPLOYED to the customer 21 Sep 2026 19:36:26.** Re-running it is a `CREATE OR ALTER` on the procedures and a no-op on the tables. | Deployed. |
| `03-affected-products.xlsx` | The listing to forward. Sheet 1 by site, sheet 2 by site and product (2,003 rows), sheet 3 by product across the group (983 products). | The customer. |
| `03-affected-products.csv` | Same detail as sheet 2, plain text. | Tooling. |
| `04-top-up-the-copy.sql` | Adds to the backup anything captured since the copy was taken. Re-runnable, insert-only. **Step 0 builds the clustered index the first copy did not get.** Run it again immediately before the correction. | Ryan. |
| `05-run-the-correction.sql` | The night's runbook: preflight, dry run, the gate, apply, verify, reverse. Only step 6 writes. | Ryan. |
| `06-run-one-site.sql` | The same night, one site: branch 9, Esikhawini Convenience. Every step is one statement with no variables, so DBeaver's Ctrl+Enter works. Only STEP 4 writes, and it ships commented out. | Ryan. |
| `07-branch-9-report.sql` | **Reads only.** What the correction actually did at branch 9, out of the audit log the UPDATE wrote through `OUTPUT`: the run, the headline, direction of movement, by area, by month, by product, the twenty biggest lines, the working chain on its own, the three guarantees checked rather than asserted, and whether it is still reversible. STEP 1b answers `NEVER RUN` rather than returning nothing. | Ryan runs it; the report is built from the grids. |
| `08-overlap-with-agora-amendments.sql` | **Reads only.** Where this pack meets Agora's stock-recon balancing, which has been in `live` stamp mode since 8 Sep and writes `QtyOpen` / `QtyClose` into PumpIT itself. Seven steps: what is on the instance, how much balancing has been committed, how much of it sits inside a fix window, whether branch 9's run already hit an amended row, whether any live amendment is already stale, and — steps 6 and 7 — the `LinkBroken` count at branch 9 (corrected) against branch 18 (not), which is the before-and-after on whether repairing openings unblocks balancing. | Ryan. |
| `09-branch-9-verify.sql` | **Reads only.** The branch-9 verification actually taken on 22 Sep 2026, with each step's result recorded in the comment above it so a re-run can be diffed against the day it was first taken. Eight steps: what is deployed, what the run did, whether it is still reversible, the what-if rewritten as a `SELECT` (so no procedure is EXECed on production), the outstanding rows named with their `CreateDateTime`, the branch 9 / branch 18 `LinkBroken` comparison, how much of the run was below Agora's own tolerance, and what Agora's balancing has committed. | Ryan. |

Report page: <https://claude.ai/artifact/LeNuYW9aiPZhr5BYYtV1Jk>

Procedure breakdown, and the collision with Agora's own balancing (22 Sep 2026):
<https://claude.ai/artifact/TM7BZXtN1HdakAZH8j3jTA>

## What the customer decided, 21 Sep 2026

| | Question | Answer |
|---|---|---|
| q1 | "Thu, 07/09/2026" — which date? | **7 September 2026** |
| q2 | "Since Start" = 30 June 2023. All three years? | **Yes, the whole history** |
| q3 | Exclude the four water-refill lines? | **Yes, handled separately** |
| q4 | Which opening quantity gets written? | **Both** — see the refinement below |
| q5 | One site at a time, or all seventeen? | **All seventeen in one run** |
| q6 | Never-entered openings first, then the rest? | **No — both kinds in one pass** |
| q7 | Cover the twelve areas with no shift flags? | **Yes** |
| q8 | Timing and sign-off | Copy first, taken by Ryan |

### The refinement on q4 — and why it was not implemented literally

"Both" taken literally means writing the as-captured closing into the working
opening as well. Measured over the full scope:

| | Openings | Units | Gross value |
|---|---:|---:|---:|
| A — as-captured chain repaired from its own closing | 35,647 | 504,254 | R 20,975,731 |
| B — working chain repaired from its own closing | 1,024 | 7,009 | R 274,856 |
| **C — collateral, if A's closing is forced into `QtyOpen` too** | **31,805** | **361,628** | **R 12,386,238** |

The working columns are not a stale copy of the as-captured ones. They are a
separately maintained, already-reconciled set: **270,336 rows in scope have both
chains intact while the two carry different numbers, 3,060,972 units apart.**
Forcing one chain's closing into the other's opening would break 31,805 working
openings that are correct today, worth R12.39 m, to repair a chain they have
nothing to do with.

So "both" is implemented as **repair both chains, each from its own closing**.
Both end up self-consistent, which is what the request was actually after. The
literal reading survives as `@ForceOriginalCloseIntoWorkingOpen`, default **0**.
It should not be switched on until the customer has seen the R12.39 m.

## The numbers

| | Openings changed | Units | Gross value moved |
|---|---:|---:|---:|
| Everything in the customer's date ranges | 35,969 | 146,442,316 | R 166,912,473 |
| — of which four water-refill lines | 342 | 145,938,100 | R 145,938,100 |
| **Real stock, water excluded (as-captured chain)** | **35,647** | **504,254** | **R 20,975,731** |
| — openings never entered (safe) | 5,067 | 157,486 | R 2,628,495 |
| — openings that disagree with a real count | 30,560 | 346,730 | R 18,345,877 |
| Working chain, repaired from its own closing | 1,024 | 7,009 | R 274,856 |

Net effect on the business, water excluded: **−R 227,017**. The total barely
moves; which shift carries it moves a great deal.

2,348,724 count lines are in scope across the 17 sites. The six sites marked
"Since Start" carry 2,315,744 of them and 35,297 of the openings.

## How the procedure was verified

Deployed and exercised against the **local Docker PumpIT stub**, never the
customer's instance. Fixture on branch 999, cleaned up in the same script
(`leftover_lines 0, leftover_log 0`). Every assertion passed:

- What-if reports 2 lines and writes nothing; the table is byte-identical after.
- Apply sets `QtyOpen_Original` 0 → 37 on the broken as-captured link **and
  leaves `QtyOpen` at 40** — the two chains do not cross-contaminate.
- A separately broken working link goes `QtyOpen` 0 → 5 while
  `QtyOpen_Original` stays at 5.
- An excluded item is untouched.
- The audit log carries both before/after pairs and both prior closings.
- A second what-if immediately after finds **0 lines** — the correction is
  idempotent.
- The reversal statement restores both columns; 2 rows reverted.
- `@UseAreaShiftFlags = 1` finds **nothing** in areas whose three flags are all
  zero — the legacy blind spot, demonstrated rather than asserted.
- `@ForceOriginalCloseIntoWorkingOpen = 1` shows the collateral on the fixture:
  the working opening moves 40 → 37 and `UnitsWorking` goes 5 → 8.
- The driver iterates the scope table, shortest windows first, under one RunId.

Cross-check worth noting: run against the local stub, branch 7 reported **11**
as-captured openings — the same 11 the analysis query found on the live
instance for Theku Plaza. The procedure's chain logic reproduces the
measurement exactly.

## The copy script, revision 2 — why

Revision 1 opened with `USE PumpIT` and then used two-part names. DBeaver runs
only the statement under the cursor, so the `USE` never ran, the session stayed
on the connection's default database, and step 2 came back with

    SQL Error [208] [S0002]: Invalid object name 'dbo.STK_StockReconLine'.

Nothing was wrong with the table or the login. Revision 2 names every object in
full (`PumpIT.dbo.X`) and pushes the only two statements that cannot take a
three-part name — `CREATE INDEX` and `sp_addextendedproperty` — through
`PumpIT.sys.sp_executesql`, which runs them inside PumpIT whatever database the
client is on. The guard and the copy now sit in one batch so a client cannot
separate them.

Verified on the local container by running the whole file from `master` and
again from `Agora` — never from PumpIT:

- 261,130 rows copied both times; `row_count_check PASS`, `quantity_total_check PASS`
- step 4 (row-by-row on the key) returned zero rows in both directions
- the clustered index was created on the copy from the wrong context
- step 2 extracted and run **on its own** from `Agora` still copied 261,130 rows
- the re-run guard threw the intended message when the copy already existed
- local backup table dropped afterwards; `leftover_backup NULL`

Step 3 now reports PASS/FAIL against a 0.01 tolerance rather than printing raw
float totals. The columns are `float`, and a 7-million-row parallel sum differs
in the last digit or two between runs — on the dry run, `-140722345.77350011`
against `-140722345.77350008`. That is arithmetic, not a lost row, and step 4
is what actually proves the row count.

## The copy script, revision 3 — the completeness check was wrong for a live table

Revisions 1 and 2 of steps 3 and 4 said *"expect zero rows from both halves"*
and compared row counts for exact equality. That is only true of a table nobody
is writing to. On the real run:

| | |
|---|---|
| Copy taken | 2026-09-21 09:54:02 |
| Rows in the backup | 7,271,822 |
| Rows that existed at 09:54:02 | 7,271,708 |
| **Rows the copy missed** | **0** |
| Rows captured after the copy, by 09:57 | 132 |
| Rows in the backup no longer in the source | 0 |

All 132 were created between 09:54:38 and 09:57:10, all at one branch, all for
transaction date 2026-09-20 — somebody entering the previous day's shifts while
the check ran. The copy was perfect and the check called it a failure.

(The backup holding *more* than existed at 09:54:02 is also normal: the scan
runs for about thirteen seconds and picks up rows inserted ahead of it.)

Step 3 now returns **one verdict** on the only question that matters — did the
copy miss a row that already existed — with `captured_after_the_copy` and
`deleted_since_the_copy` as information rather than failure. Step 4 lists rows
only when the verdict is FAIL. Step 4b lists what has been captured since, per
branch and date.

`CreateDateTime` is nullable, so a NULL is read as "existed before the copy" —
the conservative choice, which keeps such a row inside the check rather than
excusing it.

Verified on the local container, both directions:

- copy taken, five rows inserted afterwards → `PASS`,
  `rows_missed_by_the_copy 0`, `captured_after_the_copy 5`, step 4 empty
- three pre-existing rows then removed from the backup → `FAIL`,
  `rows_missed_by_the_copy 3`, and step 4 named all three. Those three happened
  to carry `CreateDateTime NULL`, so the null-handling path was exercised too
- local backup table and fixture dropped afterwards

**What covers rows captured after the copy.** They are not in the backup and do
not need to be — but if one is in scope and its opening is broken, the
correction will amend it and the table copy cannot put it back. The audit log in
`02-proposed-proc.sql` can: it records the prior value of every row it changes,
in the same transaction, whether or not that row is in this backup. It is the
better reversal path in general, because it touches only what the correction
touched.

## Keeping the copy current — `04-top-up-the-copy.sql`

The table is live, so the backup starts going stale the moment it is taken. The
top-up script inserts every source row that is not already in the backup,
stamped with the time of that run, and then re-reports the verdict. Run it once
now and again immediately before the correction: the gap that matters is
between the last top-up and the first write.

**It never updates a row that is already in the backup.** If a pre-existing row
has been edited since the copy, the backup holds the value from *before* that
edit — that is the whole point. Refreshing it would overwrite the original with
the current value and leave a backup table that can no longer undo anything.

One honest limitation: a row created after the copy and then edited before the
top-up is captured at its edited value. Nobody was watching when it was first
written. The audit log in `02-proposed-proc.sql` still records the true prior
value of anything the correction itself touches, which is the reversal path
that matters.

Verified on the local container:

- copy taken, 5 rows inserted after it, one **pre-existing row edited** to a
  sentinel value, then the top-up run from the `Agora` database
- 5 rows added, verdict `PASS`, both tables at 261,135
- the edited row: source holds the sentinel, **backup still holds the original
  `10.06`** — `backup_rows_holding_999999 = 0`
- second run from `master` added **0** rows and created no third batch — idempotent
- with the backup table absent, every batch returns the same readable message
  (`51002`) instead of a raw `208`
- fixture, backup table and the edit all reverted; container back to 261,130 rows

Step 5 of that script lists one row per top-up batch — when, by whom, how many.
`SYSDATETIME()` is evaluated once per statement, confirmed on the live backup
(7,271,822 rows, exactly one distinct `BackupTakenAt`), so the batches stay
countable.

## The window, and what the backups actually are

**Run it between 00:15 and 01:15, any night but the 1st of the month.** Measured,
not guessed.

Count capture over the last 14 days, rows per day by hour:

| Hour | Rows/day | | Hour | Rows/day |
|---|---:|---|---|---:|
| 00:00 | **none at all** | | 12:00 | 667 |
| 01:00 | 4 | | 14:00 | 690 |
| 03:00 | 6 | | 15:00 | **844** |
| 04:00 | 97 | | 17:00 | 496 |
| 09:00 | 726 | | 19:00 | 57 |
| | | | 23:00 | **none at all** |

SQL Agent and the backups on the instance:

| Time | What |
|---|---|
| 21:30 | three MIST backup jobs |
| 22:00 | **PumpIT FULL backup** to `E:\Shabeer PumpIT Backups\` — 221.8 GB, ~17 min |
| 23:50 | PumpIT — Month-End Stock Snapshot |
| ~01:25 | a **second full backup**, to `C:\Windows\TEMP` — ~6 min, not from any enabled Agent job |
| 02:00 | Daily Alteryx Rev Refresh |
| 06:00 | FuelSheet Nightly Refresh |
| 01:00, 1st of month | PumpIT Monthly Partition Maintenance — why the 1st is excluded |

**PumpIT is fully backed up twice a day.** `msdb.dbo.backupset` confirms it; the
most recent FULL finished 2026-09-21 01:30, 221.80 GB. The architecture notes
said nobody had confirmed whether a full backup ran. It does. SIMPLE recovery
still means no point-in-time restore, and restoring 221 GB would undo a day of
trading as well as the correction — so it is a last resort, not a rollback
plan. But it is real, and running after 00:15 puts it ahead of the change.

## Why the top-up kept inserting, and the defect it exposed

Ryan ran the top-up repeatedly and it inserted every time. It was genuine
capture, and the batch record proves it:

| Batch | Rows | Branches | Rows created between |
|---|---:|---:|---|
| 09:54:02 (the copy) | 7,271,822 | 20 | 2023-07-26 … 2026-09-21 09:54:15 |
| 10:32:16 | 597 | 3 | 09:54:38 … 10:32:22 |
| 10:32:57 | 80 | 1 | 10:32:58 … 10:33:11 |

Every topped-up row carries a `CreateDateTime` after the previous run. Zero
duplicate keys. 677 count lines arrived in the forty minutes between 09:54 and
10:34, and the outstanding gap was 9 rows seconds after a top-up. It will never
reach zero while the tills are open.

A cancelled run rolls back whole: his output read *"Rows added: 20 / The
statement has been terminated / 597 / 80"*, and the backup holds exactly two
top-up batches — 597 and 80, no trace of the 20.

**The defect: the clustered index was never created.**
`has_unique_clustered_index = 0` on the live backup — step 5 of `01` did not
run. Two consequences, both real:

- every `NOT EXISTS` scanned a 989 MB heap, so each top-up took minutes. That
  is why it looked hung.
- nothing prevented duplicate rows. There were none, but the net was absent.

Fixed by adding **step 0** to `04`: builds the index if missing, skips if
present, and refuses with a readable message if duplicates exist. All three
paths tested on the container.

## Why `02` needs the right database and the others do not

`CREATE PROCEDURE` cannot take a three-part name — it always creates the
procedure in the *current* database. Run `02` from the Agora database and it
would silently create Agora's own copy of these procedures and tables inside
the database Agora migrates. `02` therefore opens with a hard guard
(`IF DB_NAME() <> 'PumpIT' … SET NOEXEC ON`) and ends with a batch that reports
where the five objects actually landed. `01`, `04` and `05` name everything in
full and genuinely do not care.

One trap inside that guard, found by running it: `RAISERROR` substitution
arguments must be constants or variables. `RAISERROR('…%s…', 16, 1, DB_NAME())`
is a *syntax* error, not a runtime one, so it takes the whole batch down
without the guard ever firing. It assigns to a variable first.

## The run script, proved end to end on the container

`05-run-the-correction.sql` was exercised against the local Docker PumpIT stub
through its whole lifecycle. Never the customer's instance.

| Step | Expected | Got |
|---|---|---|
| Dry run across 17 sites | reports work, writes nothing | 3,087 lines; `log_rows 0` straight after |
| Apply | identical numbers to the dry run | identical, site for site |
| Re-run the dry run | **0** — a repaired link is not broken | **0 at all seventeen sites** |
| Reverse from the audit log | the same count back | **3,087 reverted** |
| Compare data to the pre-correction copy | no differences | **0 rows differ** |
| Dry run once more | the same work detectable again | 11, 414, 70, 136, 87, 1,295, 1,074 — the original set |

The last two lines together are the ones that matter: the reversal restored the
data itself, not merely the log, and the correction is genuinely idempotent
rather than just leaving no trace.

Container left as found — backup table dropped, log cleared, 261,130 rows.

One harness note for whoever runs this next: piping sqlcmd through `head`
closes the pipe and kills it mid-run. The first attempt looked like the driver
stopping after eleven sites; it was `head -50`. Redirect to a file.

## The preflight threw instead of reporting

`05` step 1 exists to say which pieces are missing. The first version asked
directly — `SELECT COUNT(*) FROM PumpIT.dbo.STK_OpeningFixScope` — so when that
table did not exist the whole batch failed to **compile**:

    SQL Error [208] [S0002]: Invalid object name 'PumpIT.dbo.STK_OpeningFixScope'

A check that throws in exactly the case it exists to detect is no check at all.
Every count now goes through `sp_executesql`, invoked only once `OBJECT_ID` has
confirmed the object, so the batch always runs and always answers. Steps 2, 3,
4 and 5 are guarded the same way and print which file to run instead of failing.

Verified on the container in all three states, run from the wrong database each
time:

| State | Result |
|---|---|
| Nothing deployed, no copy | every column `MISSING - run 02` / `MISSING - run 01`, four plain-English lines, **no 208** |
| Copy taken, `02` not deployed | `table_copy OK - 261,130 rows`, the rest still `MISSING` |
| Everything deployed | `OK ǀ OK ǀ OK ǀ OK - 17 sites ǀ OK - 4 excluded items ǀ OK - 261,130 rows ǀ OK - never run`, dry run reports 17 sites and 3,087 lines, zero errors |

The file header now separates the once-off steps from the night's:
`01` and `02` beforehand, `04` and `05` on the night. **`02` is the first thing
in the pack that writes to PumpIT** — three new tables and two new procedures,
nothing existing altered or dropped, no count line touched — and it is the one
file that must be run with PumpIT selected in the client, because
`CREATE PROCEDURE` cannot be redirected by a three-part name.

## Why it must not run in trading hours — and the defect that proved it

Report for the customer: <https://claude.ai/artifact/GLjHWE9yK6NvZZJZGAY2nM>

Three mechanisms, all measured rather than argued.

**1. A count captured mid-run was overwritten — and the audit log recorded the
wrong thing.** Reproduced on the container using the exact statements from
`02`. A line whose opening was blank; the correction snapshots it; a clerk then
captures 41; the correction writes 37.

| | Old code | With the guard |
|---|---|---|
| Row after the run | **37 — the clerk's 41 destroyed** | **41 — untouched** |
| Log's "before" value | **0** — never the value it replaced | nothing logged |
| Rows updated / skipped | 1 / 0 | 0 / 1 |

The log entry is the worse half. Undoing that run would have restored the blank,
destroying the clerk's count a second time and leaving no trace it existed.

**Fixed in `02` revision 3.** The apply is now a single `UPDATE` that writes the
log through `OUTPUT` and carries an optimistic guard —
`WHERE ABS(l.QtyOpen_Original - f.QtyOpen_Original) <= @Tolerance AND ABS(l.QtyOpen - f.QtyOpen) <= @Tolerance`.
A row that changed under us is skipped, never overwritten, and never logged.
`OUTPUT` makes the log exactly the set that changed, with true before values,
because it is the same statement. Skips are reported as
`RowsSkipped_ChangedUnderUs`, with a `RAISERROR ... WITH NOWAIT` when non-zero.

**2. The write escalates to a table lock.** Measured on the container: an update
touching 8,000 rows produced `RID X 5,749`, `PAGE IX 81` and — the point —
`OBJECT X 1`. An exclusive lock on the whole table. All twenty sites capture
into that one table, so while a large branch is written **no site anywhere can
save a count**. Elephant Coast (13,539) and Nseleni (8,630) are both above the
~5,000 escalation threshold on their own.

**3. A daytime run leaves the job unfinished.** The guard skips contended rows,
so the run corrects less than it reported it would — and the skipped rows are
the busy ones. Intended stops equalling actual, invisibly.

**Exposure, measured:** of the openings due for correction, **999 sit on trading
dates from the last seven days** and **440 were captured in the last twenty-four
hours**. Estate-wide capture ran at about **22 lines a minute** through the
morning of 21 Sep — 1,497 lines in 69 minutes.

## Branch 9, verified on the instance — 22 September 2026

Ryan narrowed the scope that day: *"just focus on getting branch id 9 right for
now please."* Every figure below was read from live PumpIT, read-only, through
`09-branch-9-verify.sql`. No procedure was executed against the instance — the
what-if is the correction's own logic rewritten as a `SELECT`.

| | |
|---|---:|
| The run, from its own audit log | 2,153 rows · 2,075 as-captured · 141 working · 553 never entered |
| Areas / items / dates | 12 · 307 · 2024-08-29 … 2026-09-20 |
| Gross value moved | R 1,252,099.58 |
| **Net value moved** | **+R 46,398.15** — never previously written down |
| Still reversible | 2,152 of 2,153 · 0 rows gone · 1 edited since |
| **What-if today, whole history** | 302,359 lines scanned → **7 would change**, R 328.66 |
| Working chain still broken | **0** |
| Rows skipped by the 19:36 run | **none** — see below |

**The correction is idempotent against live data.** A second pass over branch 9
finds seven rows out of 302,359, and the working chain is clean.

**Nothing was skipped.** All seven outstanding rows carry `CreateDateTime`
between `2026-09-22 12:01:09` and `12:04:41`, and none appears in the run's
audit log. A row the run had skipped would pre-date it. That closes the worry
this pack carried since the trading-hours run.

**It is what unblocks balancing, and here is the measurement.**
`agora.usp_StockRecon_PreviewBalancing` blocks a whole chain wherever
`Open(i) ≠ Close(i−1)` on the `_Original` pair. Same month, one site corrected
and one not:

| Branch | August lines | Links broken | Chains | **Chains blocked** |
|---|---:|---:|---:|---:|
| 9 — corrected 21 Sep | 11,008 | 2 | 190 | **1** |
| 18 — never corrected | 14,484 | 864 | 234 | **137** |

59% of branch 18's chains carry no proposal at all. Branch 9 has one.

### The finding that is not about history

The seven outstanding rows are not residue. They are this morning's capture of
Saturday's shifts, and item 634 broke on **both** shifts of the same day. Before
the fix, branch 9 carried 2,075 broken as-captured openings spread over roughly
750 days — about 2.8 a day. Seven arrived in one batch.

**Whatever writes an opening at capture time is not reading the previous
closing.** Until that is dealt with, the correction is a mop rather than a fix,
and branch 9 needs a periodic re-run. Re-running step 5 of
`09-branch-9-verify.sql` on consecutive days measures the rate; that work has
not been started.

### Two numbers worth keeping

- **`@Tolerance` is 0.0001; Agora's `@eps` is 0.005** — fifty times looser.
  **207 of the 2,153 rows** the run changed moved by less than Agora's
  threshold, worth **R 59.44 in total**. Nothing is harmed and every one is
  reversible, but a tenth of that run was churn on weighed items the rest of
  the system already considers equal.
- **Branch 9 carries no `agora.StockReconAmendment` rows**, so the two-writers
  problem below does not touch this work. Estate-wide there are 61 live active
  amendments — 44 at branch 18, 17 at branch 25 — and **6 of branch 18's are
  already stale**, their source counts having moved since the commit. Not this
  pack's doing; it has never run at branch 18. Ryan's call, 22 Sep: the
  reversal stays absolute.

## Two writers on the same two columns

`agora.usp_StockRecon_Commit` and `_Reverse` have written `QtyOpen` and
`QtyClose` on `[PumpIT].dbo.STK_StockReconLine` since live stamp mode was
turned on 8 September 2026. `@RepairWorking = 1` writes the same two columns.
Neither knows about the other, and the shapes are not symmetrical:

- the correction's apply carries an optimistic guard and **skips** a row that
  moved under it; so does its reversal;
- `usp_StockRecon_Reverse` guards neither — it writes `PriorQtyOpen` /
  `PriorQtyClose` whatever the row currently holds.

So a shift both have touched can end up holding a value neither log can put
back. `08-overlap-with-agora-amendments.sql` measures it. It is zero at
branch 9 and stays a live concern for any site where both are used.

## Still open

- **When may it run, and who signs off on the day.** q8 came back with the copy
  requirement only.
- **Free space on the SQL Server's data volume.** Step 0 of the copy script
  reports it; `sys.dm_os_volume_stats` could not be read from here.

## Things measured that are worth keeping

- `dbo.STK_StockReconLine` is a **heap** — no clustered index. 7,271,083 rows,
  989 MB, data from 30 Jun 2023 to 20 Sep 2026.
- The PumpIT data file has **1,549 MB reusable** against a 989 MB copy. Thin.
- `trg_Sync_STK_StockReconLine` (copies inserts/updates to
  `Alteryx.dbo.Rev_STK_StockReconLine`) is **disabled**. If it is re-enabled it
  inserts a duplicate on every update rather than replacing — it deletes on
  delete, but only inserts on update.
- The table moves while you read it: two identical passes 40 minutes apart
  returned 35,990 then 35,969 broken openings.
- `dbo.STK_StockMaster` is unique on `(SSBranchId, StockItemNo)` — that join
  does not fan out, despite a second, non-unique index on `(SSBranchId, AreaNo)`.
- 24 areas across 5 branches have `DayShift = AfternoonShift = NightShift = 0`.

## "They just want auto balancing" — what that already is, and why it is not this

Asked 22 Sep 2026. The customer's phrasing — *"update closing quantities to
opening quantities so that it balances"* — describes two different jobs that
have been run together in the conversation. PumpIT already contains both, and
they touch almost none of the same rows. Measured read-only against live PumpIT
on 22 Sep 2026.

### Job 1 — the opening chain. This pack.

`02-proposed-proc.sql` sets **this shift's opening to the previous shift's
closing**, per (area, item), in date and shift order. It reads `QtyClose` /
`QtyClose_Original` and writes `QtyOpen` / `QtyOpen_Original`. **It never writes
a closing, an issued or a computer quantity.** That is the whole of it.

It does not make anything balance. Variance is computed live, not stored —
`sp_SelectStockReconView_PerShiftVariance` is literally

    QtyVar = QtyComputer - (QtyOpen + QtyIssued - QtyClose)

so moving an opening by *n* moves that shift's variance by *−n* the moment the
report is next run. The short does not disappear; it lands on the shift whose
opening was amended. Net across the scope is **−R227,017 on R20.98 m moved**.

### Job 2 — auto balancing. Already in PumpIT, and it is not ours.

`dbo.sp_UpdateAUTOStockReconBalancing (@BranchId, @FromDate, @ToDate, @AreaNo)`
exists today and is what "auto balancing" means in this system:

1. compute `QtyVar` for every line in the window;
2. keep only the lines outside the item's `QtyVarAllowance` (its Yellow and Red);
3. of those, keep only lines where **this shift's variance and the next
   shift's variance cancel exactly** — `QtyVar + LEAD(QtyVar) = 0`;
4. for each, back-solve the closing to `QtyOpen + QtyIssued - QtyComputer` and
   call `sp_UpdateStockReconLine_QtyOpen_xQtyClose`, which writes that closing
   onto the current shift **and the same figure onto the next shift's opening**.

Both variances go to zero. It is a miscount-rolled-into-the-next-shift fixer:
the case where a counter wrote 8 instead of 18 and the following shift's count
puts the 10 back. It is deliberately narrow, and it does overwrite the physical
count with the theoretical one — which is the right call for a cancelling pair
and the wrong call for anything else.

### They do not overlap. Measured.

Last 365 days, all 20 branches, 2,236,335 count lines:

| | Lines | |
|---|---:|---|
| Carry a variance at all | 214,233 | R 36,188,320 gross |
| **Auto-balance candidates** (cancelling pairs) | **6,546** | 3.1% of them |
| **Broken as-captured openings** (this pack) | **57,943** | |
| **Rows that are both** | **1,028** | 1.8% of the openings |

Branch 9 alone, last 180 days: 3,060 variance lines, 74 auto-balance
candidates, 29 broken openings, **overlap 0**.

### What to tell them

- **Auto balancing is not a shorts eraser.** By its own design it can only
  touch the 3% of variance lines where two adjacent shifts cancel. Running it
  across everything would not remove the other 97%, because there is no second
  shift to take the other half.
- **It cannot do this job.** 98% of the broken openings are invisible to it —
  they are not cancelling pairs, so step 3 discards them.
- **This pack cannot do their job either.** It repairs the chain; it does not
  zero a variance, and shift-level and employee-level loss reports will read
  differently after it than before.
- If what they actually want is *every* variance forced to zero, that is
  writing `QtyClose := QtyOpen + QtyIssued - QtyComputer` on all 214,233 lines
  — deleting R36.19 m of measured shrinkage from the record, including the
  employee stock-loss reports that PumpIT bills off
  (`sp_RPT_EmployeeStockLossReportPAYROLL*`). That is a decision for the
  customer in writing, not a script.
