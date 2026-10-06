<?php

/*
|--------------------------------------------------------------------------
| Stock recon centre
|--------------------------------------------------------------------------
|
| Shift variance balancing, and the exceptions balancing must refuse to hide.
|
| THE METHOD, in one paragraph, because every default below follows from it.
| A shift's variance is POS - (Open + Issued - Close), and one shift's closing
| count IS the next shift's opening. So raising a closing by d lifts this
| shift's variance by d and drops the next one's by exactly d: over a window,
| every amendment cancels except at the two ends. Balancing therefore
| REDISTRIBUTES variance and can never reduce it. The total across the window,
| T, is fixed the moment the dates are chosen — which is what makes T the most
| useful number on the screen:
|
|   T < 0   real loss. Balancing decides which shifts carry it.
|   T = 0   every variance is a counting artefact; it balances to zero throughout.
|   T > 0   more was sold than the books ever received. NO amendment removes it,
|           so a net-over chain is REPORTED and never balanced.
|
| The algorithm is the reverse running maximum of the cumulative variance,
| clamped between 0 and T — the closed form of the backward pass the admin does
| by hand. One window function per column, no cursor.
|
| Source: docs/reference/ZRP_Shift_Variance_Balancing method note (Ryan, 8 Sep
| 2026) and dbo.sp_UpdateAUTOStockReconBalancing, the procedure this replaces.
|
| And, since 30 Sep 2026, the client's own rules: ZRP's Weekly Stock Recon pack
| (23 Sep 2026, docs/reference/zrp-weekly-stock-recon-pack). An opening that is
| not the previous closing is REPAIRED (the opening amended, the closing
| standing) rather than blocking the chain; a whole-pack over and a chain with
| no till sales against it are reported, not balanced; unfinished recon days
| are held back. docs/stock-recon.md → "The client's rules" has the mapping.
|
*/

return [

    'name' => 'StockRecon',

    /*
    | There is no free date range any more (ZRP, 4 Oct 2026). The form offers a
    | month and the Monday–Sunday weeks inside it, and opens on the last full
    | week — see Services/ReconPeriods. BOTH ends of the window are anchors, so
    | a week is also the conservative choice.
    */

    /*
    | Balancing writes to the CUSTOMER'S estate. This literal decides whether
    | it may.
    |
    | Deliberately a literal and NOT an environment variable, exactly as
    | config('recon.stamp_mode') is: an env flag makes "Agora starts amending
    | counts in the production ERP" a one-line change somebody can make on a
    | Friday. Turning it on is meant to be a commit with a name on it.
    |
    | 'journal' — a run is previewed, recorded, ticked and committed, every
    |             amendment is written to agora.StockReconAmendment, and
    |             NOTHING in PumpIT moves. The ledger is then the worklist an
    |             admin applies, or the evidence for turning 'live' on.
    | 'live'    — the same commit also updates QtyOpen / QtyClose on
    |             [PumpIT].dbo.STK_StockReconLine, recording each row's PRIOR
    |             values first so usp_StockRecon_Reverse can put them back.
    |
    | SET TO 'live' ON 8 SEPTEMBER 2026, on Ryan's answer to the blocking
    | question on the build report — "Turn live writes on". It shipped as
    | 'journal' earlier the same day and was flipped within the hour, which is
    | why this paragraph exists rather than a git blame.
    |
    | What that turns on, so the next person reading this knows exactly what is
    | now possible from a browser:
    |
    |   · agora.usp_StockRecon_Commit UPDATEs QtyOpen and QtyClose on
    |     [PumpIT].dbo.STK_StockReconLine, in the customer's live database.
    |   · It acts ONLY on rows a recorded run marked WouldAmend = 1 on an
    |     unblocked chain and an operator ticked, re-checks every one against
    |     the source first, and skips anything whose counts have moved.
    |   · Every write is recorded in agora.StockReconAmendment with the row's
    |     PRIOR pair, so agora.usp_StockRecon_Reverse puts back precisely what
    |     was changed. dbo.sp_UpdateAUTOStockReconBalancing can undo nothing.
    |   · The four _Original columns are never written, and QtyIssued only
    |     to take out a clear duplicate issue — the separate switch below,
    |     issue_mode. The _Original pair is what makes a re-preview
    |     idempotent, and amending an issue would change T itself — that is
    |     capturing a missing issue, a different act that has to stay visible
    |     as one.
    |
    | TWO THINGS ARE NOT SETTLED BY THIS SWITCH, and both were on the report
    | he answered:
    |
    |   · On a month of Elephant Coast, 221 of 225 chains are BLOCKED — mostly
    |     because they end over, which no amendment can fix. Balancing the four
    |     that are not is correct; it is also not where the money is. The
    |     exception report is the thing to run first.
    |   · MaxAmendmentPct below is still the default I invented with no data
    |     behind it. He chose to leave it at 1.00 for now (same report), which
    |     is the loose end: the other flags do the blocking.
    */
    'stamp_mode' => 'live',

    /*
    | A clear duplicate issue (D1) is removed, and the commit writes it.
    |
    | The one place Agora writes an ISSUE: QtyIssued = 0 on
    | [PumpIT].dbo.STK_StockReconLine, on a shift short by exactly an issue
    | that was keyed again on a neighbouring shift whose stock did arrive —
    | docs/stock-recon.md → "Duplicate issues". A literal like stamp_mode, for
    | the same reason.
    |
    | 'live' — the preview removes a D1 from the balancing basis (the shift
    |          balances, nobody is charged) and the commit records the issue's
    |          prior in agora.StockReconAmendment and, in live stamp mode,
    |          writes the 0. usp_StockRecon_Reverse puts it back.
    | 'off'  — a D1 is still found and reported, but left in the basis and
    |          never written. A run previewed with it on is refused at commit
    |          (ISSUE_MODE_OFF), because its counts assumed the issue gone.
    |
    | SET TO 'live' ON 1 OCTOBER 2026, on Ryan's answer to the PM question "May
    | Agora write QtyIssued?" — "Yes: clear duplicates only, priors kept,
    | reversible" (decision 01a0f60b-0459). Possible ones (D2) are corrected by
    | the site, never by Agora.
    */
    'issue_mode' => 'live',

    /*
    | Areas whose AreaGroup is in this list are excluded from every figure.
    |
    | Lotto, airtime and electricity are VIRTUAL products: no issues are ever
    | captured against them and no physical count is ever taken, so they show
    | as a permanent, enormous net over that cannot be reconciled as stock —
    | the same reasoning that keeps fuel out of the pack. On the Elephant Coast
    | export those three chains alone carried more net over than everything
    | else combined.
    |
    | By AreaGroup rather than by item description, because the customer
    | already maintains that column (STK_Area.AreaGroup) and 20 of the 250
    | areas across the estate are marked 'Virtual Items'. Excluding on a
    | description LIKE would be Agora inventing a classification the customer
    | already keeps.
    |
    | 'NOT USED' is deliberately NOT excluded: the estate has 31 areas marked
    | that way and some of them are trading. That is a finding for the branch,
    | not a filter.
    */
    'excluded_area_groups' => ['Virtual Items'],

    /*
    | The arguments beyond branch, area and dates.
    |
    | Every one of them BLOCKS rather than bends: a chain that needs more than
    | a cap allows is reported untouched, never amended half way. The defaults
    | are deliberately the loose end of each, so the first thing a person sees
    | is what the method would do, and tightening a cap is a decision they make
    | on purpose after reading the blocked list.
    */
    'options' => [

        'MaxAmendmentPct' => [
            'label' => 'Largest correction, as a share of stock on hand',
            'help' => 'A correction bigger than this share of what the shift held (opening plus issues) '
                .'is not a miscount, so its whole chain is reported instead of amended. 0.50 means never '
                .'move a count by more than half the stock it was holding. 1.00 allows any correction up '
                .'to the whole of it.',
            'default' => 1.0,
            'type' => 'decimal',
            'min' => 0.01,
            'max' => 1.0,
            // A number input's step defaults to 1, which makes 1.0 the only
            // legal value between 0.01 and 1.0 and 0.5 a refusal the browser
            // reports as "an invalid form control is not focusable".
            'step' => 0.01,
        ],

        'MaxAmendment' => [
            'label' => 'Largest correction, in units',
            'help' => 'The same cap in absolute terms, for items where a share of a small holding is still '
                .'an implausible number of units.',
            'default' => 9999,
            'type' => 'int',
            'min' => 1,
            'max' => 999999,
        ],

        'MinShiftsInChain' => [
            'label' => 'Shortest chain worth balancing',
            'help' => 'Active shifts, not raw rows. A chain with one live shift in the window has no '
                .'evidence in it — there is nowhere for a variance to move to.',
            'default' => 2,
            'type' => 'int',
            'min' => 2,
            'max' => 30,
        ],

        /*
        | ZRP, 4 Oct 2026: a break of 1, 2 or 3 units should not split a chain.
        | The opening is still repaired to the previous closing and the break
        | reported (S10a/S10b, with its qty), but the closing before it may move,
        | so the chain balances straight through. 0 splits at every break, as
        | the pack's rule 3 reads on its own.
        */
        'KeepBreaksUpTo' => [
            'label' => 'Keep the chain going through a break of up to',
            'help' => 'Units. An opening that misses the previous closing by this much or less is repaired '
                .'and reported, but does not split the chain. 0 splits at every break.',
            'default' => 3,
            'type' => 'int',
            'min' => 0,
            'max' => 99,
        ],

        /*
        | Rule 10 of the client's pack: a site is analysed only up to its last
        | complete recon day — every shift in on 95% of its lines, closings in,
        | POS posted. Later days are held back and counted on the run, because
        | balancing them would pin "the latest count" on one nobody has taken.
        */
        'HoldBackIncomplete' => [
            'label' => 'Hold back days the recon has not finished',
            'help' => 'On, a day with a shift missing, closings not captured or POS not posted — and every '
                .'day after it — is left out of the run and counted on it, as the client\'s weekly pack does. '
                .'Turn it off only to look at an unfinished week on purpose.',
            'default' => 1,
            'type' => 'bool',
        ],

        'UseOriginalCounts' => [
            'label' => 'Recompute from the original counts',
            'help' => 'On, the preview reads QtyOpen_Original and QtyClose_Original, which is what makes '
                .'a run idempotent — re-running never compounds an earlier amendment. Turn it off only to '
                .'balance on top of edits somebody has already made by hand.',
            'default' => 1,
            'type' => 'bool',
        ],
    ],

    /*
    | The exception classes, in the order the report ranks them.
    |
    | Declared here rather than in the blade so the screen, the legend and the
    | grid's tick-list filter cannot drift into three different vocabularies.
    | The `test` is the same predicate the procedure applies, written out, so a
    | reader can check the classification against the arithmetic without
    | opening SSMS. Every test reads the REPAIRED opening (rule 3).
    |
    | THE CODES ARE THE CLIENT'S WHERE THE CLIENT DEFINES THE TEST. The weekly
    | pack (ZRP, 23 Sep 2026) names its shift tests S1–S13 and its data faults
    | E1–E3; where Agora applies the same test it uses the same code, so a line
    | reads the same in Agora and in the master book on Monday. Where the test is
    | ours it takes a letter the pack does not use — U unrecorded issue, F data
    | fault, N not counted, L loss — because the pack's A–D mean storeroom
    | scenarios, and our old A1 ("a missing issue sheet") would have read as the
    | pack's A1 ("confirmed loss, charge the staff"). v1__14e relabelled the
    | recorded runs; the tests behind U, F, N and L did not change.
    |
    | Blocks: a chain with this class is reported, never balanced.
    */
    'exceptions' => [
        'S10a' => ['tone' => 'crit', 'label' => 'Opening below the previous closing',
            'test' => 'Open < Close of the shift before',
            'blocks' => false,
            'blurb' => 'Stock vanished between two shifts and nobody carries it — the pack calls it the known '
                .'manipulation route. Rule 3: the OPENING is amended to the previous closing, which stands, '
                .'and the incoming shift carries the difference. Balancing does that repair; the line stays '
                .'on this report because somebody has to explain it. A break of 3 or fewer (the run\'s '
                .'"keep the chain going" option) is repaired the same way but does not split the chain.'],
        'E1' => ['tone' => 'crit', 'label' => 'No till sales linked',
            'test' => 'T < −max(3, pack) over 2+ days and Σ POS ≤ 10% of |T|',
            'blocks' => true,
            'blurb' => 'The stock is falling and the till shows next to nothing against it: the selling PLU '
                .'or recipe is not reducing this item, so every count looks short. A system fault, not a '
                .'loss — nobody is charged, and it is not balanced. Link the PLU and re-run.'],
        'S1' => ['tone' => 'crit', 'label' => 'Over of a whole pack — issue not written up',
            'test' => 'over within 10% of k × issue multiple, k ≥ 1, not weighed',
            'blocks' => true,
            'blurb' => 'A whole pack reached the counter without an issue sheet (rule 5). Capture the issue '
                .'on the shift it happened; balancing it away would hide the paperwork failure.'],
        'U1' => ['tone' => 'crit', 'label' => 'Unrecorded issue — proven',
            'test' => 'POS > Open + Issued (the counted opening on a kept break above the previous closing)',
            'blocks' => true,
            'blurb' => 'The till sold more in one shift than the opening plus every recorded issue could '
                .'supply. The stock was there; the paperwork was not.'],
        'U2' => ['tone' => 'crit', 'label' => 'Closing exceeds what was on hand',
            'test' => 'Close > Open + Issued (the counted opening on a kept break above the previous closing)',
            'blocks' => true,
            'blurb' => 'The shift closed holding more than it opened with plus everything issued to it. '
                .'The surplus arrived without a document.'],
        /*
        | Duplicate issues (1 Oct 2026). Above the chain-wide classes: they are
        | this shift's own explanation of its short, and a chain that ends over
        | would otherwise hide every one.
        |
        | D2's 'blocks' is THE switch for whether a possible duplicate holds its
        | chain back from the commit — StockReconService passes it to the
        | preview and to the commit as @BlockPossibleDuplicates.
        |
        | ON SINCE 5 OCTOBER 2026 (Ryan, PM decision 01a10b21, task
        | 01a0f610-5ac0). Since 30 Sep a commit reverses the count moves the
        | client's pack forbids, and 59 of 134 duplicate candidates had been
        | hidden by exactly such moves — committing those chains would put the
        | short back on staff pay. A chain with a D2 is held back, and NOTHING on
        | it is written, until the site corrects the issue in PumpIT (the next
        | preview no longer finds it) or somebody releases it with a reason
        | (agora.StockReconDuplicateRelease, permission
        | stockrecon.duplicates.release). A released D2 is still listed.
        */
        'D2' => ['tone' => 'serious', 'label' => 'Possible duplicate issue',
            'test' => 'short within 10% of the issue, an identical issue on a neighbouring shift, not clear',
            'blocks' => true,
            'balanced' => 'No — held back until corrected or released',
            'blurb' => 'The same issue was keyed on a neighbouring shift and this one is short by about that much — '
                .'but the count cannot prove which issue was real: both shifts are short, the short is near the '
                .'issue rather than equal to it, it is the item\'s usual delivery a day apart, or it is prepared '
                .'food, where a batch thrown away looks the same. Its whole chain is HELD BACK: nothing on it is '
                .'written to PumpIT, not the balancing and not a hand amendment put back, because that could put '
                .'a short somebody hid by moving counts back on staff pay. Two ways out: the site corrects the issue '
                .'in PumpIT against the issue book, the storeroom count or the delivery note, and the next preview no '
                .'longer finds it; or somebody who has checked releases it here with a reason, and the next preview '
                .'lets the chain commit. Agora never changes the issue itself.'],
        'D1' => ['tone' => 'good', 'label' => 'Duplicate issue removed',
            'test' => 'short = the issue exactly, an identical issue on a neighbouring shift that arrived, same day '
                .'(or a day apart and not the usual size), one candidate per pair, shelf goods',
            'blocks' => false,
            'blurb' => 'The issue was keyed twice: the count moved only by what the till sold, and the same issue had '
                .'already arrived on the shift beside it. It is taken out before balancing, so the shift balances '
                .'and nobody is charged, and the commit sets the issue to 0 in PumpIT with the old value kept for a '
                .'reversal. The client\'s weekly pack will show the line as S4b, "issue reduced at balancing".'],
        'U3' => ['tone' => 'serious', 'label' => 'Selling with no issues captured at all',
            'test' => 'Σ Issued = 0 and Σ POS > 0 and a segment ends over',
            'blocks' => true,
            'blurb' => 'Sales all through the window and not one issue recorded. Usually a recipe or a '
                .'transfer route that was never set up, rather than a one-off omission.'],
        'U4' => ['tone' => 'serious', 'label' => 'Net over for the window',
            'test' => 'a segment\'s total T > 0',
            'blocks' => true,
            'blurb' => 'The window (or the stretch between two repaired openings) received less than it '
                .'sold. No set of closing counts changes that, so chasing it with amendments only spreads '
                .'a data fault across innocent shifts.'],
        'S10b' => ['tone' => 'serious', 'label' => 'Opening above the previous closing',
            'test' => 'Open > Close of the shift before',
            'blocks' => false,
            'blurb' => 'Stock appeared between shifts, which can hide an over or offset a short. Repaired '
                .'like S10a: the opening is amended to the previous closing. A small one keeps the chain going.'],
        'F1' => ['tone' => 'warn', 'label' => 'Data fault',
            'test' => 'a count below zero, or a correction larger than the caps allow',
            'blocks' => true,
            'blurb' => 'Either a negative count, or an amendment so large it cannot be a miscount. Both '
                .'are withheld rather than printed.'],
        'F2' => ['tone' => 'warn', 'label' => 'Issue went nowhere',
            'test' => 'Open = Close and Issued > 0 and POS = 0',
            'blocks' => true,
            'blurb' => 'Stock was issued, nothing was sold, and the count did not move. Either the issue '
                .'was never delivered or the count was never taken.'],
        'F3' => ['tone' => 'warn', 'label' => 'Audit conflict — issue changed at balancing',
            'test' => 'writing the chain would trip S7, S8, S11, S12 or S13 on a shift that trips none today',
            'blocks' => true,
            'blurb' => 'An issue (or POS) quantity was changed at balancing and a count moved to match. Agora '
                .'writes counts, and an issue only to take out one keyed twice; with that edit in place no set '
                .'of counts passes every one '
                .'of the client\'s checks — so the chain is left for a person rather than given a new flag.'],
        'S3' => ['tone' => 'warn', 'label' => 'Short of a whole pack',
            'test' => 'short after balancing within 10% of k × issue multiple',
            'blocks' => false,
            'blurb' => 'A pack issued on paper but not handed over, or a whole pack missing. Check the issue '
                .'book before treating it as a loss.'],
        'S5' => ['tone' => 'neutral', 'label' => 'Issue not in whole packs',
            'test' => 'Issued is not a multiple of the issue multiple',
            'blocks' => false,
            'blurb' => 'The issue was captured in a quantity the pack does not come in — a keying or unit '
                .'problem on the issue sheet.'],
        'N1' => ['tone' => 'warn', 'label' => 'No physical count taken',
            'test' => 'Close = Open and Issued = 0 and POS > 0, on more than half the active shifts',
            'blocks' => false,
            'blurb' => 'The closing was carried forward instead of counted — the biggest source of the '
                .'over/short sawtooth, and a supervision issue: balancing corrects the number, only the '
                .'branch corrects the habit.'],
        'N2' => ['tone' => 'neutral', 'label' => 'Mostly dormant',
            'test' => 'dormant on more than half of six or more shifts',
            'blocks' => false,
            'blurb' => 'More than half the chain never traded. Two live shifts in twelve is almost no '
                .'evidence.'],
        'L1' => ['tone' => 'serious', 'label' => 'Persistent short — accountable',
            'test' => 'short on more than half of six or more ACTIVE shifts',
            'blocks' => false,
            'blurb' => 'After balancing, still short on most of its real shifts. The pack\'s rule stands: a '
                .'consistent short is not a "wrong count" until a recount proves it. This is the class '
                .'that carries a charge.'],
    ],

    /*
    | The hand-balancing audit — what somebody already did to a shift in PumpIT,
    | measured against the client's rules. The pack's own codes and wording.
    | A line carries at most one, the first that applies in this order. Agora's
    | own commits never produce any of them; a commit on a clear chain puts the
    | amendment behind S7, S8, S11 and S12 back.
    */
    'audit' => [
        'S13' => ['tone' => 'crit', 'label' => 'Opening/closing mismatch still open',
            'blurb' => 'The recon was balanced with the mismatch still in it. Fix the opening; re-run.'],
        'S12' => ['tone' => 'crit', 'label' => 'Mismatch fixed the wrong way',
            'blurb' => 'Balancing changed the previous CLOSING to meet the opening — it rewrites what the '
                .'staff counted. Restore the closing; amend the opening instead.'],
        'S7' => ['tone' => 'crit', 'label' => 'Over turned into a short by balancing',
            'blurb' => 'An over may be brought to nil against a neighbouring short, never made a short.'],
        'S8' => ['tone' => 'crit', 'label' => 'Short increased by balancing',
            'blurb' => 'A short may shrink by absorbing a neighbouring over, never grow.'],
        'S11' => ['tone' => 'serious', 'label' => 'Latest closing count amended',
            'blurb' => 'Balancing works backwards from the latest count, which stands.'],
        'S4' => ['tone' => 'warn', 'label' => 'Issue mis-keyed',
            'blurb' => 'A fat-finger issue (e.g. 123456) that was later corrected. Confirm the correction.'],
        'S4a' => ['tone' => 'warn', 'label' => 'Issue added at balancing',
            'blurb' => 'The over was an issue not written up on the shift. It balances now, but the issue '
                .'sheet was not completed at the time — a paperwork failure against the issuer.'],
        'S4b' => ['tone' => 'neutral', 'label' => 'Issue reduced at balancing',
            'blurb' => 'An issue was over-captured or captured twice. Confirm with the issue book.'],
    ],
];
