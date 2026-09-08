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

### `FlagChainBroken` — ours, and not in the method note

The method rests on `Open(i) = Close(i−1)`. On branch 18's August, **880 of
14,483 lines break that in the SOURCE**: the opening simply is not the previous
closing. Redistributing a quantity along a path that does not exist is
meaningless, and it is also what made the note's own dormant guard fire 144
times on the first real run.

Measured 8 September 2026, and the number that settles it: **of the 64 chains
where the dormant guard fired, zero had an intact chain.** Blocking a broken
chain takes the guard to zero and the invariant then holds to the fourth
decimal.

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
that satisfies several tests is reported under the first it trips, so an
unrecorded issue is never filed as a counting habit.

| | | |
|---|---|---|
| **A1** | Unrecorded issue, proven | `POS > Open + Issued` |
| **A2** | Closing exceeds what was on hand | `Close > Open + Issued` |
| **A3** | Selling with no issues captured at all | `Σ Issued = 0 and Σ POS > 0 and T > 0` |
| **A4** | Net over for the window | `T > 0` |
| **B1** | Data fault | a count below zero, or a correction larger than the caps allow |
| **B2** | Issue went nowhere | `Open = Close and Issued > 0 and POS = 0` |
| **C1** | No physical count taken | `Close = Open and Issued = 0 and POS > 0`, on more than half the active shifts |
| **C2** | Mostly dormant | dormant on more than half of six or more shifts |
| **D1** | Persistent short — accountable | short on more than half of six or more ACTIVE shifts |

**Dormant shifts are out of every denominator.** C1, C2 and D1 count against
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

1. **Exception report first, on a full month.** Clear the A-class items. The
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
  a fault — and a blocked chain carries **no proposal at all**: its "after"
  figures are its counted figures, because anything else describes a world the
  press cannot produce.
* **Balancing and capturing an issue are different acts.** Amending a closing
  redistributes variance; amending an issue or a POS quantity changes T. The
  second is often correct, but recording it as balancing makes the
  unrecorded-issue figure disappear.

### Two things the algorithm cannot see

* **Which shift a short belongs to is a modelling choice, not a fact.** The
  method places the residual where the counts put it and moves counts as little
  as possible, which is the most defensible rule available — but the invariant
  means that if the total is R500, somebody carries R500. Before a balanced
  figure is used to charge anybody, the C1 list should be clear for that item: a
  shift that never counted cannot support a charge on the shift next to it.
* **The cashier is not in the recon line.** Shift number is a poor proxy for a
  person. Joining the cashier on duty to each shift is what would turn D1 from
  an item-level observation into an accountability record — and it would settle
  the dormant test properly, as a fact rather than an inference from three
  quantities all being equal.
