# Stock recon centre

Shift variance balancing, the exceptions it must refuse to hide, and how to run
it. Read this before changing anything under `Modules/StockRecon`.

`docs/feature-rules.md` says what any feature must do; this says what THIS one
means. The arithmetic is the part to get right, and every default in
`config/stockrecon.php` follows from it.

---

## The one fact everything follows from

A shift's variance is

```
Variance(i) = POS(i) − ( Open(i) + Issued(i) − Close(i) )
Open(i)     = Close(i−1)          ← the chain: one shift's close is the next shift's open
```

The only lever an editor has is the **closing count**. Because every closing is
also the next opening, raising one by *d* lifts that shift's variance by *d* and
drops the next shift's by exactly *d*. Summing over a window, every adjustment
cancels except at the two ends:

```
T = Σ Variance = Σ POS − ( Open(first) + Σ Issued − Close(last) )
```

**Balancing is a redistribution, not a reduction.** The total short after
balancing is fixed the moment the window is chosen. That single fact settles
most of the design:

| | |
|---|---|
| **T < 0** | Real loss of \|T\| over the window. Balancing decides which shifts carry it; it cannot make it smaller. |
| **T = 0** | Every variance is a counting artefact. It balances to zero throughout. |
| **T > 0** | More was sold than the books ever received. **No amendment removes it.** The chain is reported, never balanced. |

## The algorithm

Write `P(k)` for the cumulative variance up to active shift *k*. Rule 1 — no
shift may end over — is exactly "the balanced cumulative curve never rises", so
the answer is the smallest non-rising curve that never sits below the counted
one: its **reverse running maximum**, clamped to the band between 0 and T.

```
P(k) = running total of Variance over ACTIVE shifts
M(k) = MAX( P(j) : j ≥ k )            ← reverse running maximum
Q(k) = CLAMP( M(k), between 0 and T )

Close(k) := Close(k) + ( Q(k) − P(k) )
```

One window function per column and no cursor. It is the closed form of the
backward pass the admin does by hand: work from the latest date backwards,
raise the previous closing to kill an over, let the over walk back until earlier
shorts absorb it.

The **last active shift is pinned** at zero amendment — the latest count is the
one that is trusted — and the **first row keeps its counted opening**, which is
the anchor at the other end.

### The three rules

1. **An over must never end short.** An over is not credible — you cannot end a
   shift holding stock the system never gave you — so it is written down to
   exactly zero and no further.
2. **No shift is made shorter than its own count.** An amendment may only
   reduce a short. Nobody's charge goes up because of a correction made to
   somebody else's shift.
3. **A dormant shift never moves.** Where opening equals closing with no issue
   and no POS quantity, the shift did not trade — it is a sign-off with no
   movement behind it, and charging it charges somebody who was never at the
   till.

Rule 3 is **structural, not a special case**. `ActiveSeq` counts only active
shifts, so a dormant row carries the number of the active shift *before* it and
inherits that shift's amendment from the same join: its opening and closing move
together and its variance stays zero however much the chain around it is re-cut.
Consecutive dormant rows share the number and move as a block. The rule is that
opening and closing stay equal **to each other**, not that they stay at their
original values — the opening *is* the previous closing, so pinning it would
break the chain.

### `FlagChainBroken` — a repair since 30 Sep 2026, no longer a block

The method rests on `Open(i) = Close(i−1)`. On branch 18's August, **880 of
14,483 lines break that in the SOURCE**. Until 30 Sep 2026 a break blocked the
whole chain. The client's rule 3 settles it the other way: the **opening** is
amended to the previous closing and the closing stands, so every test and the
balancing now read the repaired opening, the closing before a repair is pinned,
and the break is reported (S10a / S10b). The dormant guard that fired on broken
chains reads the repaired opening too, so it still holds.

### A break of 3 or fewer keeps the chain going — since 4 Oct 2026

ZRP asked that a break of 1, 2 or 3 units stop splitting the chain. A break no
bigger than the run option `KeepBreaksUpTo` (default 3, 0 restores the split)
is still **repaired** — the opening set to the previous closing — but it does
**not** start a new segment, so the closing before it is free to move and the
chain balances straight through. It is reported: `BreakKept` on the line, the
qty in the outcome (`Break -2 kept: …`), the **Break** column on the proposals
and exceptions tables, and S10a / S10b on the exceptions tab as before. S12 and
S13 read only breaks bigger than the option, on the audit of PumpIT and on the
check of what a commit would write, so a kept break never withdraws its own
chain as F3.

**The client's own engine still flags it.** The pack's S12 is "a previous
closing moved at a break", any size. Agora now moves that closing at a kept
break, so the Tuesday engine reports S12 on those shifts until it is given the
same tolerance.

Measured, Engen Bethlehem 1–27 Sep on the local copy, kept (3) against split
(0): 121 breaks, 63 of them kept; reported chains 192 → 187; U4 chains 49 → 36;
shifts written 10 → 25; S12/S13 audit rows 22 → 5; every clear chain still
balances to exactly T.

---

## The client's rules (ZRP weekly pack, 23 Sep 2026)

On 23 Sep 2026 ZRP's CEO issued the *Weekly Stock Recon Process* pack to the
Group Operations Executive: a guide, the rules, a scenario matrix and a Python
engine she runs in her own Claude every Tuesday against twelve PumpIT reports.
Its master book audits the balancing in PumpIT — including Agora's — so the
module follows its rules exactly. The pack itself, engine included, is in
[`docs/reference/zrp-weekly-stock-recon-pack/`](reference/zrp-weekly-stock-recon-pack/README.md).
Where each rule lands:

| Pack rule | In Agora |
|---|---|
| 1. Variance = POS − (opening + issued − closing) | Unchanged. |
| 3. Opening must equal the previous closing; amend the **opening**, never the closing | A break is **repaired**, not blocked: `QtyOpenNew` = the previous closing (the shift before the window for the first row). The closing before a repaired opening is **pinned** — the chain is balanced in segments split at each repair — because the pack flags any moved previous closing at a break as S12. Reported as **S10a** (opening below: stock vanished) or **S10b** (above). **A break of 3 or fewer does not split** (ZRP, 4 Oct 2026 — see above). |
| 4. Over → nil, never a short; a short never grows; the latest closing stands | Unchanged — it is what the reverse running maximum already did. The last active shift of each segment is pinned. |
| 5. An over within 10% of a whole issue multiple is an issue not written up | **S1**, blocks the chain: captured, never balanced away. Multiples come from `agora.StockIssueMultiple` (head office's workbook, seeded by `IssueMultipleSeeder`), falling back to `vw_StockItem.IssueMultiple` — PumpIT's column is ≥ 2 on 1 of 2,433 items at the pack's sites. |
| 10. Analyse a site only up to its last complete recon day | Stage 0 of the preview. A day is complete when every shift the site normally runs carries ≥ 95% of its normal lines with ≥ half its normal share of closings, and POS is posted on ≥ half the normal share. The first incomplete day and everything after it is **held back** (`CompleteTo`, `HeldBackRows`). Option `HoldBackIncomplete`, default on. |
| E1. Stock falling with no till sales linked is a system fault | **E1**, blocks the chain: T < −max(3, pack) over 2+ days with Σ POS ≤ 10% of \|T\|. Nobody is charged. |
| S7, S8, S11, S12, S13, S4/S4a/S4b — the audit of hand balancing | `AuditCode` on every line: what the balancing **already in PumpIT** did against the rules, measured the pack's way (original counts, opening repaired to the original previous closing, fat-finger issues neutralised). |
| 12. Virtual lines are not stock | Already excluded by `AreaGroup = 'Virtual Items'`; on the pack's sites that is exactly the "Lotto/Electricity/Airtime" area it names. |

**A commit reverses the hand amendments the client's rules forbid** — Ryan's
answer, 30 Sep 2026: "Yes, as built", and "follow the client's request". Three
parts:

* **A row is proposed where its answer differs from what PumpIT holds now**, not
  from the original counts, so on a balanceable chain every hand figure is
  replaced by the balanced one and the chain in PumpIT is left intact. The
  commit re-checks the live pair as well as the counted one.
* **A blocked chain that carries S7, S8, S11, S12 or S13 is put back to the staff
  counts** (`RestoresHand`, outcome "Hand amendment reversed"): every closing as
  counted, every opening repaired to the previous closing — the pack's own staff
  basis. It is **not** balanced; the reason it is blocked still stands. Before
  this, a blocked chain carried no proposal and the breach stayed in PumpIT —
  190 of 255 chains on Bethlehem.
* **Agora never hands the client a new flag** (`FlagAuditConflict`, class **F3**).
  Every chain about to be written is audited as it would stand afterwards, check
  by check; if any shift would trip a check it does not trip today, the chain is
  withdrawn. On real data every such chain had an **issue** changed at balancing
  with a count moved to match. Agora writes counts, never issues (`QtyIssued` is
  not a named write), and with that edit in place no set of counts passes every
  check — so it is left for the site, and reported.

Measured, Engen Bethlehem 1–29 Sep on the local copy, preview → commit →
preview: forbidden hand amendments 141 → 53 (S12 86 → 22, S13 11 → 2, S11
15 → 6, S7 27 → 22, S8 2 → 1); 819 shifts written; **0** new S7/S8 created; 0
breaks left on any chain the commit wrote (breaks in the copy 80 → 43, all 43
on chains it did not touch). All 53 left are on 22 F3 chains, every one with an
issue edited at balancing. Two tolerances: counts are compared at 0.0005 (a
weighed item's 8.355 against 8.350 is a break), variances at 0.005.

**The codes are the pack's where the pack defines the test** (S1, S3, S5, S10a,
S10b, E1; S4–S13 on the audit). Ours moved to letters the pack does not use —
U unrecorded issue, F data fault, N not counted, L loss — because the pack's
A–D are storeroom scenarios with other meanings: its A1 is "confirmed loss,
charge the staff", ours was "a missing issue sheet". `v1__14e` relabelled the
recorded runs.

**Not in Agora yet**, and why: the pack's storeroom side (whole-site stock take
vs recon — A1–D4, RV, NC, E2, E3), potatoes and buns issued on receipt, count
compliance and recency, recipe vs recon, the scorecard and control actions. All
of it needs sources the module does not read — WinBranch stock takes
(`DBF_INVHISTF_STOCKTAKE`), the Aura stk 01 register, Pilot counts, Omni and
Aura GRVs, raw material consumption. Filed in the PM inbox.

**Measured on real data**, 30 Sep 2026: Engen Bethlehem (branch 23), 1–21 Sep,
8,520 shift lines in 3.5 s. Openings below the previous closing 44 / R-7,335 —
the same as a direct count on PumpIT, and the pack's baseline says 46 / R-7,398
(the data has moved since 23 Sep). S4a 136 against the pack's 144 (a line
carries one audit code, so S12/S7 take some). 65 of 255 chains balanceable; of
the 190 reported, 90 have too few active shifts, 57 closed holding more than
they received (U2), 16 are whole-pack overs (S1), 16 sold more than they held
(U1), and 5 are blocked only because a segment ends over.

---

## What the module is made of

| | |
|---|---|
| `agora.usp_StockRecon_PreviewBalancing` | The whole answer, computed and recorded. Reads PumpIT through `agora.vw_Stock*`; writes only Agora's ledger. |
| `agora.usp_StockRecon_Commit` | Acts on the ticked rows of a recorded run, re-checks each one, writes the amendment record. Touches PumpIT in `live` mode only. |
| `agora.usp_StockRecon_Reverse` | Puts back the prior pair the commit stored. The legacy procedure can undo nothing. |
| `agora.usp_StockRecon_DiscardRuns` | Throws away previews. Refuses a committed run. |
| `agora.usp_StockRecon_GridRuns` | The runs grid. |
| `agora.usp_StockRecon_GridExceptions` | The exception report. |
| `agora.usp_StockRecon_DrillChain` | The whole chain behind one clicked shift. |
| `agora.StockReconRun` | One press of Preview: window, caps, procedure, and the before/after shape. |
| `agora.StockReconRunLine` | One shift of one item: counted, balanced, every flag, the tick. |
| `agora.StockReconAmendment` | What a commit did, with the PRIOR counts. This is what makes a reversal exact. |
| `agora.StockIssueMultiple` | Head office's pack size per (branch, area, item). Rule 5 reads it. |

**The preview WRITES, unlike every other preview in Agora.** A branch-month is
fifteen thousand shift lines, which as a chunked multi-row INSERT from PHP is
three hundred round trips. The run header is created first and its id passed in,
and one `INSERT ... SELECT` lands the lot — which also means the header counts
and the lines come out of the same pass and cannot disagree.

---

## The stamp mode

`config('stockrecon.stamp_mode')` is a **literal, deliberately not an
environment variable** — exactly as `recon.stamp_mode` is. An env flag makes
"Agora starts amending counts in the production ERP" a one-line change somebody
can make on a Friday.

* **`journal`** — a run is previewed, ticked, confirmed and committed, every
  amendment lands in `agora.StockReconAmendment` with the shift's prior counts
  beside the new ones, and **nothing in PumpIT moves**. The extract from a
  committed run is then the worklist an admin applies by hand.
* **`live`** (**shipped**) — the same commit additionally writes `QtyOpen` and
  `QtyClose` back to `[PumpIT].dbo.STK_StockReconLine`.

Nothing else differs between the two. The arithmetic, the ticks, the
confirmation, the re-check and the reversal are identical.

**`live` was turned on by Ryan on 8 September 2026**, answering the blocking
question on the build report — the same shape of explicit go-ahead bank recon
got on 4 September. What it turns on:

* `agora.usp_StockRecon_Commit` updates two count columns per amended shift in
  the customer's live database.
* It acts ONLY on rows a recorded run marked `WouldAmend = 1` on an unblocked
  chain and an operator ticked, re-checks every one against the source first,
  and skips anything whose counts have moved.
* Every write is recorded with the row's PRIOR pair, so
  `agora.usp_StockRecon_Reverse` puts back precisely what was changed. The
  PumpIT executable can undo nothing.
* **The whole commit is one transaction.** It now spans two databases — the
  amendment record in Agora and the counts in PumpIT — and without that, a
  failure on the second leaves the ledger claiming a prior pair for counts that
  never moved. A reversal would then write those "prior" values over counts
  nobody had touched.

**`QtyIssued` and the four `_Original` columns are never written**, in either
mode. Amending an issue changes T itself — that is *capturing a missing issue*,
a different act, and it has to stay visible as one. The `_Original` pair is what
makes a re-preview propose the same amendment rather than one on top of the
last, which is precisely how the legacy procedure compounds silently.

### The round trip, proved

Branch 999 in the local container, 17–19 July, three days of the worked-example
pattern. `tests/Feature/StockRecon/BalancingTest::test_a_live_commit_writes_both_counts_and_a_reversal_puts_them_back`
asserts all of it; this is the same thing with the counts printed:

```
BEFORE — as counted            AFTER THE LIVE COMMIT          AFTER THE REVERSAL
date        sh  Open  Close    date        sh  Open  Close    date        sh  Open  Close
2026-07-17  1     40     60    2026-07-17  1     40     70    2026-07-17  1     40     60
2026-07-17  3     60     60    2026-07-17  3     70     60    2026-07-17  3     60     60
2026-07-18  1     60     50    2026-07-18  1     60     54    2026-07-18  1     60     50
2026-07-18  3     50     50    2026-07-18  3     54     50    2026-07-18  3     50     50
2026-07-19  1     50     30    2026-07-19  1     50     39    2026-07-19  1     50     30
2026-07-19  3     30     30    2026-07-19  3     39     30    2026-07-19  3     30     30
```

Shift 1's closing is lifted to absorb shift 3's over; shift 3's opening follows
and its closing does not, so its variance goes to zero. The `_Original` columns
are identical in all three states, and the reversal restores the source exactly.

---

## What it replaces, and why

`dbo.sp_UpdateAUTOStockReconBalancing` has the right instinct: find a variance
and the one after it, and zero the pair. Three things stop it working:

* It acts only where `QtyVar + Diff = 0` **exactly**. In the worked example
  chain not one of the five pairs is exact, so none would be touched.
* Its `LEAD` has no `PARTITION BY`, so the "next" row can belong to a different
  stock item and one item's variance is netted against another's.
* Its `LEAD` is ordered by `StockItemDescription, StockItemNo, …` while the
  `ROW_NUMBER` beside it is ordered by `StockItemDescription, POSCode, …`.
  Wherever two items share a description the two orderings disagree and the
  pairing silently shifts.

All three fall away when the work is done on a running total per item rather
than on adjacent pairs.

It also writes through `sp_UpdateStockReconLine_QtyOpen_xQtyClose`, which sets
the closing on this shift and the opening on the **next** one, working out which
shift that is from `STK_Area`'s day/afternoon/night flags — an inference that is
wrong the moment a shift is missing from the data. Agora names both columns of
both rows explicitly.

And it keeps **no record at all**: nothing anywhere says which counts it moved,
by how much, on whose authority, or what they were before.

---

## The exception classes

Ranked most serious first, which is also the order the report comes in. A line
that satisfies several tests is reported under the first it trips. Every test
reads the **repaired** opening. `config('stockrecon.exceptions')` carries the
same list with the wording the screen uses.

| | | | |
|---|---|---|---|
| **S10a** | Opening below the previous closing | `Open < Close(i−1)` | repaired |
| **E1** | No till sales linked | `T < −max(3, pack)`, 2+ days, `Σ POS ≤ 10% of \|T\|` | blocks |
| **S1** | Over of a whole pack | over within 10% of k × multiple | blocks |
| **U1** | Unrecorded issue, proven | `POS > Open + Issued` | blocks |
| **U2** | Closing exceeds what was on hand | `Close > Open + Issued` | blocks |
| **U3** | Selling with no issues captured at all | `Σ Issued = 0 and Σ POS > 0` and a segment over | blocks |
| **U4** | Net over | a segment's `T > 0` | blocks |
| **S10b** | Opening above the previous closing | `Open > Close(i−1)` | repaired |
| **F1** | Data fault | a count below zero, or a correction larger than the caps allow | blocks |
| **F2** | Issue went nowhere | `Open = Close and Issued > 0 and POS = 0` | blocks |
| **F3** | Audit conflict — issue changed at balancing | writing the chain would trip S7/S8/S11/S12/S13 on a shift that trips none of them today | blocks |
| **S3** | Short of a whole pack | short after balancing within 10% of k × multiple | — |
| **S5** | Issue not in whole packs | `Issued mod multiple ≠ 0` | — |
| **N1** | No physical count taken | `Close = Open and Issued = 0 and POS > 0`, on more than half the active shifts | — |
| **N2** | Mostly dormant | dormant on more than half of six or more shifts | — |
| **L1** | Persistent short — accountable | short on more than half of six or more ACTIVE shifts | — |

And, separately, the **audit** of what the hand balancing already in PumpIT did
(`AuditCode`, `config('stockrecon.audit')`): S13 mismatch still open, S12
mismatch fixed by moving the previous closing, S7 over turned into a short, S8
short increased, S11 latest closing amended (only when the window reaches the
data's end), S4 issue mis-keyed, S4a issue added at balancing, S4b issue reduced.

**Dormant shifts are out of every denominator.** N1, N2 and L1 count against
ACTIVE shifts, never raw line count. A chain where nine of twelve shifts never
traded is not "short a quarter of the time" — it is short on one of three real
shifts, and the rate that goes to a branch has to say so.

**Lotto, airtime and electricity are excluded throughout**, by
`STK_Area.AreaGroup = 'Virtual Items'` — the customer's own column, not a
description pattern Agora invented. They are virtual products with no issues and
no physical count, so they read as a permanent enormous net over and cannot be
reconciled as stock, on the same reasoning that keeps fuel out of the pack.

---

## Running it

1. **Exception report first, on a full month.** Clear the S10a, E1, S1 and U items. The
   unrecorded issues are worth more than the balancing is.
2. **Preview one area**, caps tight — try `MaxAmendmentPct = 0.50`. Read the
   Outcome column and compare a few chains against what the admin would do.
3. **Loosen the caps** once the blocked list is understood, not before. A cap
   that blocks a chain is telling you something about that chain.
4. **Commit one area, then widen.** The preview is idempotent, so it can be
   re-run safely.

### Cautions

* **Widen the window before trusting a flag.** A net over on a short window can
  be an artefact of where the window starts. Run at least a full stock cycle
  before treating `T > 0` as a finding.
* **A blocked chain is blocked whole**, so a chain is never half balanced around
  a fault — and a blocked chain carries **no proposal at all**, not even its
  opening repairs: its "after" figures are its counted figures, because anything
  else describes a world the press cannot produce.
* **Balancing and capturing an issue are different acts.** Amending a closing
  redistributes variance; amending an issue or a POS quantity changes T. The
  second is often correct, but recording it as balancing makes the
  unrecorded-issue figure disappear.

### Two things the algorithm cannot see

* **Which shift a short belongs to is a modelling choice, not a fact.** The
  method places the residual where the counts put it and moves counts as little
  as possible, which is the most defensible rule available — but the invariant
  means that if the total is R500, somebody carries R500. Before a balanced
  figure is used to charge anybody, the N1 list should be clear for that item: a
  shift that never counted cannot support a charge on the shift next to it.
* ~~**The cashier is not in the recon line.**~~ **It is** — corrected
  9 September 2026. `dbo.STK_StockReconEmployees` is keyed on
  (branch, date, shift, area), which is exactly the grain of a recon line, and
  on branch 18 across August and early September it covers **all 1,138 shifts**
  with every one of its 45 distinct codes resolving against `BRN_Employee`. The
  preview resolves it once and stores it on the line, so the proposals table,
  the chain drill, the exception report and the extract all name the person.

  **L1 can now name somebody, and that raises the bar rather than lowering it.**
  90 of those 1,138 shifts had more than one person signed on, up to three. A
  short on a shared shift cannot be attributed to either of them, so the count
  travels beside the names and every screen says so. Collapsing the list to one
  name would manufacture an accountability the counts do not support.

  What it does NOT settle is the dormant test: a shift with nobody signed on
  would be dormant as a fact rather than as an inference from three quantities
  being equal, but on this data there is no such shift — coverage is total —
  so there is nothing to test the idea against yet.
