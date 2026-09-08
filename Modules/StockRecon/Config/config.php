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
*/

return [

    'name' => 'StockRecon',

    /*
    | How far back a preview looks by default.
    |
    | A fortnight rather than a month, and the reason is arithmetic rather than
    | taste: BOTH ends of the window are anchors — the opening count of the
    | first shift and the closing count of the last are believed — so the
    | window choice FIXES the residual short before anything else happens. A
    | short window is a conservative one. The form allows anything.
    */
    'default_days' => 14,

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
    |   · QtyIssued and the four _Original columns are never written. The
    |     _Original pair is what makes a re-preview idempotent, and amending an
    |     issue would change T itself — that is capturing a missing issue, a
    |     different act that has to stay visible as one.
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
    | opening SSMS.
    |
    | A = the stock arrived without paperwork. Never balanced, always reported:
    |     these are worth more than the balancing is.
    | B = the data is wrong. Withheld rather than printed as a performance figure.
    | C = the count was not taken. A supervision finding, not an arithmetic one.
    | D = the balanced answer, and the only class that can carry a charge.
    */
    'exceptions' => [
        'A1' => ['tone' => 'crit', 'label' => 'Unrecorded issue — proven',
            'test' => 'POS > Open + Issued',
            'blurb' => 'The till sold more in one shift than the opening count plus every recorded issue '
                .'could supply. The stock was there; the paperwork was not. This needs no interpretation.'],
        'A2' => ['tone' => 'crit', 'label' => 'Closing exceeds what was on hand',
            'test' => 'Close > Open + Issued',
            'blurb' => 'The shift closed holding more than it opened with plus everything issued to it. '
                .'The surplus arrived without a document.'],
        'A3' => ['tone' => 'serious', 'label' => 'Selling with no issues captured at all',
            'test' => 'Σ Issued = 0 and Σ POS > 0 and T > 0',
            'blurb' => 'Sales all through the window and not one issue recorded. Usually a recipe or a '
                .'transfer route that was never set up, rather than a one-off omission.'],
        'A4' => ['tone' => 'serious', 'label' => 'Net over for the window',
            'test' => 'T > 0',
            'blurb' => 'The general case. Nothing individually impossible, but the window as a whole '
                .'received less than it sold. No set of closing counts can change T, so chasing it with '
                .'amendments only spreads a data fault across innocent shifts.'],
        'B1' => ['tone' => 'warn', 'label' => 'Data fault',
            'test' => 'a count below zero, or a correction larger than the caps allow',
            'blurb' => 'Either a negative count, or an amendment so large it cannot be a miscount. Both '
                .'are withheld rather than printed.'],
        'B2' => ['tone' => 'warn', 'label' => 'Issue went nowhere',
            'test' => 'Open = Close and Issued > 0 and POS = 0',
            'blurb' => 'Stock was issued, nothing was sold, and the count did not move. Either the issue '
                .'was never delivered or the count was never taken. It LOOKS dormant and is not, so it is '
                .'never folded away.'],
        'C1' => ['tone' => 'warn', 'label' => 'No physical count taken',
            'test' => 'Close = Open and Issued = 0 and POS > 0, on more than half the active shifts',
            'blurb' => 'The closing was carried forward instead of counted. This is the single biggest '
                .'source of the over/short sawtooth, and it is a supervision issue: balancing corrects the '
                .'number, only the branch corrects the habit.'],
        'C2' => ['tone' => 'neutral', 'label' => 'Mostly dormant',
            'test' => 'dormant on more than half of six or more shifts',
            'blurb' => 'More than half the chain never traded. The item barely moves on this shift '
                .'pattern, or it is counted on a shift that does not sell it. Two live shifts in twelve is '
                .'almost no evidence.'],
        'D1' => ['tone' => 'serious', 'label' => 'Persistent short — accountable',
            'test' => 'short on more than half of six or more ACTIVE shifts',
            'blurb' => 'After balancing, still short on most of its real shifts. Consistent shorts are not '
                .'a counting problem and should not be treated as one. This is the output that carries a '
                .'charge.'],
    ],
];
