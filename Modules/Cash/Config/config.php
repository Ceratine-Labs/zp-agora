<?php

/*
|--------------------------------------------------------------------------
| Cash
|--------------------------------------------------------------------------
|
| Z-read allocation (T036), daily banking / cashups (T037), till balance and
| pump shortages (T038), the drop safe (T039), staff shorts (T040);
| deductions are the rest of E08 and will add their own block here.
|
*/

return [

    'name' => 'Cash',

    'zread' => [

        /*
        | Update ZREAD writes to the CUSTOMER'S estate. This literal decides
        | whether it may.
        |
        | Deliberately a literal and NOT an environment variable, exactly as
        | config('recon.stamp_mode') and config('stockrecon.stamp_mode') are:
        | an env flag makes "Agora starts writing the production ERP" a one-line
        | change somebody can make on a Friday. Changing it is a commit with a
        | name on it.
        |
        | 'journal' — Update ZREAD saves the allocation in agora.ZreadAllocation
        |             and NOTHING in PumpIT moves. The screen says so.
        | 'live'    — the same press also UPDATEs TillNo, ShiftNo and
        |             EmployeeCode on [PumpIT].dbo.DBF_P3TRANS_ZREAD, for each
        |             saved Z-read, in the same transaction, after recording the
        |             row's exact prior values (StampPrior*) so
        |             agora.usp_Cash_ReverseZreadCommit can put them back.
        |
        | SET TO 'live' ON 27 SEPTEMBER 2026 on Ryan's answer to the T036 report
        | (PM decision 01a0e312-06da: "write back three columns"). What that
        | turns on, so the next person reading this knows what a browser can now
        | do:
        |
        |   · agora.usp_Cash_CommitZreadAllocation UPDATEs those three columns,
        |     and only those, on the one row the Z-read's full key names
        |     (SSBranchId, DATE, EOD_CNTR, TERMNUM, LOGFILE, USERID, LOCATION),
        |     under UPDLOCK. A Z-read that does not resolve to exactly one row is
        |     skipped, never written.
        |   · It does NOT do the legacy screen's side effects: no stamp on
        |     DBF_P3TRANS_STKRECON, no delete from DBF_COMPUTER_QTY.
        |   · agora.usp_Cash_ReverseZreadCommit puts the prior three back, by
        |     press (CommitRef), and refuses if a later press has superseded any
        |     of it or the PumpIT row has moved since.
        */
        'stamp_mode' => 'live',

        /*
        | THE PRIORS, and why they are here rather than in agora.Setting.
        |
        | Plan §3.4 says a proposer "reads the auto-accept threshold from
        | agora.Setting". agora.Setting is T026 and is not built, so the four
        | numbers and the threshold live here, each under the key it will
        | carry there (`setting`), and ZreadService is the only thing that
        | reads them. Moving them is one method, not a hunt.
        |
        | The values are the plan's, unchanged (T036):
        |
        |   certain  the same till + shift + person on >= 75% of the prior
        |            Z-reads from this terminal under this operator ID
        |   likely   the operator ID has been the same person on >= 60%
        |   shift    the operator ID has run the same shift on >= 60% —
        |            below that the shift is left to a person
        |   review   (no number) the usual person on this terminal and shift
        |
        | The procedures carry the same numbers as parameter defaults so a
        | person running them in SSMS gets the same answer; this is what the
        | application passes.
        */
        'certain_share' => 0.75,   // setting: cash.zread.certain_share
        'likely_share' => 0.60,    // setting: cash.zread.likely_share
        'shift_share' => 0.60,     // setting: cash.zread.shift_share

        /*
        | What Auto-allocate stages without a person: certain | likely | review.
        | 'certain' is the plan's reading of "accepts >= Settings threshold"
        | with the most cautious setting. On Esikhawini Convenience's 21–31 Aug
        | 2026, replayed blind, 'certain' stages 16 of 80 Z-reads and 14 of those
        | match the branch's own allocation exactly (the other two differ in the
        | shift or the till, never the person); 'likely' would add 64 more, 37
        | of which have no shift to stage and so would not be staged at all.
        */
        'auto_accept' => 'certain', // setting: cash.zread.auto_accept_confidence

        /*
        | How far back the proposer looks. Ninety days is long enough for a
        | roster to repeat many times and short enough that an operator ID
        | re-issued to somebody new stops speaking for the old owner within a
        | quarter. Not in the plan; a default, stated.
        */
        'history_days' => 90,      // setting: cash.zread.history_days

        /*
        | THE FIFTH PRIOR (Ryan, 27 Sep 2026, same decision): where a terminal
        | printed exactly two Z-reads in a day, the first by log file is the Day
        | shift and the second the Night — for every location except these.
        |
        | Measured on the customer's instance, 23 May – 31 Aug 2026, terminal-
        | days with two Z-reads (first -> Day, second -> Night):
        |   WINBRANCH 3,084 / 3,081 of 3,155 · AURA 750 / 749 of 756 ·
        |   NAMOS 141 / 141 of 142 · ARCHLIQ 48 / 47 of 57 (84%) ·
        |   PILOT 47 / 47 of 95 (a coin toss) · ARCH 647 / 572 of 1,177.
        | ARCH is excluded because its LOGFILE is a per-operator counter, not a
        | sequence. PILOT is not excluded because Ryan's answer named ARCH only;
        | adding it here is the one-word change if he agrees (see the report).
        |
        | It is a FALLBACK: it supplies the shift only where the operator's own
        | history (shift_share) does not, and it never touches a 'certain'
        | proposal, whose shift comes from the whole-allocation pattern.
        |
        | Day and Night are ShiftNo 1 and 3 at every site that has them
        | (BRN_Shifts, 27 Sep 2026: "Day Shift" 1 at 24 sites, "Night Shift" 3
        | at 23). A site without both is simply not offered the rule.
        */
        'sequence_excluded_locations' => ['ARCH'], // setting: cash.zread.sequence_excluded_locations
        'day_shift_no' => 1,
        'night_shift_no' => 3,

        /* The period the screen opens on, ending yesterday. */
        'default_days' => 10,

        /* The longest period one screen (and one press) covers. */
        'max_days' => 93,

        /*
        | The POS locations a Z-read can come from, measured on the customer's
        | PumpIT.dbo.DBF_P3TRANS_ZREAD on 27 Sep 2026 (rows): WINBRANCH 86,194,
        | ARCH 50,265, AURA 22,722, NAMOS 5,287, ARCHLIQ 3,024, PILOT 1,367,
        | NIGHTSB 497, GAAP 417. The plan names the first three; the rest are
        | real and are offered rather than hidden.
        */
        'locations' => ['WINBRANCH', 'ARCH', 'AURA', 'NAMOS', 'ARCHLIQ', 'PILOT', 'NIGHTSB', 'GAAP'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Daily banking — cashups (T037)
    |--------------------------------------------------------------------------
    */
    'cashup' => [

        /*
        | Whether a save on the Daily Banking screen may write the customer's
        | PumpIT (BRN_DailyBanking and BRN_DailyBankingFNB — the list, column
        | by column, is in agora.usp_Cash_SaveCashupLeg's header and CLAUDE.md
        | rule 1).
        |
        | A LITERAL, never an environment variable, like every other PumpIT
        | write switch here (cash.zread.stamp_mode, recon.stamp_mode): Agora
        | starting to write the production ERP is a commit with a name on it,
        | not a one-line change somebody makes on a Friday.
        |
        |   'live'  the card load can be saved: FNB lines and the header's
        |           totals are written, each with what it replaced
        |   'off'   the screen is read-only, the procedure is never called, and
        |           the screen says so
        |
        | 'live' on Ryan's go-ahead of 27 Sep 2026 (PM decisions 01a0e22a-97be /
        | 01a0e22a-9aa9). Confirm and Close write Agora's database only and are
        | not switched by this.
        */
        'write_mode' => 'live',

        /*
        | Beyond this variance, either way, a confirmation needs a reason — the
        | mockup's own line between an ordinary variance and a critical one
        | (R150), and the chip on the screen turns at the same place.
        */
        'variance_tolerance' => 150.00,   // setting: cash.cashup.variance_tolerance

        /*
        | The legs, in the order the Daily Banking screen shows them as tabs,
        | each under the name agora.vw_CashupLeg gives its channel.
        |
        | `hidden` channels are the dead ones — no row in 2026 on the customer's
        | instance (Cheques, ManualCard1, ManualCard2 hold none at all; Zapper
        | one, from 2023). They are left off the screen by this setting, not
        | deleted from anything: a row arriving in one is still counted by the
        | views, and the screen lists it under "Other legs".
        */
        'channels' => [
            'CashBags' => 'Cash bags',
            'FNB' => 'Cards · FNB',
            'ABSA' => 'Cards · ABSA',
            'CashDevices' => 'Cash devices',
            'SmartATM' => 'Smart ATM',
            'Debtors' => 'Accounts (debtors)',
            'DirectDeposits' => 'Direct deposits',
            'StaffShorts' => 'Pump shortages',
            'Expenses' => 'Expenses',
            'Suppliers' => 'Suppliers',
            'FleetCard' => 'Fleet card',
            'Yumbi' => 'Yumbi',
            'YumbiVoucher' => 'Yumbi vouchers',
            'Infinity' => 'Infinity',
            'Zapper' => 'Zapper',
            'Cheques' => 'Cheques',
            'ManualCard1' => 'Manual card 1',
            'ManualCard2' => 'Manual card 2',
        ],

        'hidden_channels' => ['Cheques', 'ManualCard1', 'ManualCard2', 'Zapper'], // setting: cash.cashup.hidden_channels

        /*
        | The legs Agora may WRITE (Ryan, 27 Sep 2026, Q1 of the T037 review:
        | each leg AND its pool row's banked mark, in one transaction).
        |
        | FNB comes from the card terminal's batch file. Every other leg here
        | is chosen from a PumpIT pool — the drop safe, the cash device and
        | Smart ATM feeds, the ABSA mark-off file, Yumbi, Yumbi vouchers,
        | Infinity, Zapper, the POS account sales and supplier paid-outs, the
        | approved cash purchase requests — and saving one also marks the pool
        | row banked, exactly as the legacy does. agora.usp_Cash_SaveCashupLeg
        | is the authority on this list; the screen reads it to decide which
        | tabs offer "Add from PumpIT".
        */
        'savable_channels' => ['FNB', 'CashBags', 'CashDevices', 'SmartATM', 'ABSA', 'Yumbi', 'YumbiVoucher',
            'Infinity', 'Zapper', 'Debtors', 'Suppliers', 'Expenses', 'StaffShorts'],

        /*
        | The legs Agora does NOT write, and why — said on the leg's tab. The
        | procedure refuses them (CASH_LEG_HELD) whatever this says.
        */
        'held_channels' => [
            'FleetCard' => 'A fleet-card line carries a price, cost, margin, rebate and payout the PumpIT client works out; no PumpIT procedure states how, so Agora does not write it. Capture it in PumpIT.',
            'DirectDeposits' => 'A direct deposit is typed against a bank line in PumpIT, sometimes for a different amount, and the rule is not in any procedure. Capture it in PumpIT.',
        ],

        /*
        | How far either side of the cashup's day a pool is offered, in days:
        | [before, after]. Measured on the customer's instance (27 Sep 2026):
        |
        |   bags, devices, Smart ATM, ABSA, Yumbi, Infinity  the shift's day or
        |       the next (a night shift's deposits are stamped after midnight)
        |   supplier paid-outs  within three days either way (516 of 516)
        |   account sales       the Z-read's own day, mostly
        |   purchase requests   95% within a month either way (3,624 of 3,806)
        |   Yumbi vouchers      often weeks after the redemption (125 of 149
        |                       within 31 days, some up to a year)
        |   staff shorts        the legacy offers EVERY approved, unposted short
        |                       whatever its date (dbo.sp_Load_ApprovedStaffShorts
        |                       has no date at all), and a short raised in PumpIT
        |                       is dated when it was captured — 3,893 of 4,160
        |                       posted shorts sit on a cashup dated before the
        |                       short, most by a day, some by more than a year
        |                       (27 Sep 2026). So the window is the widest the
        |                       procedure takes, and the short raised for this
        |                       cashup is listed first.
        */
        'pool_windows' => [                    // setting: cash.cashup.pool_windows
            'CashBags' => [3, 3],
            'CashDevices' => [3, 3],
            'SmartATM' => [3, 3],
            'ABSA' => [3, 3],
            'Yumbi' => [3, 3],
            'Infinity' => [3, 3],
            'Zapper' => [7, 7],
            'Debtors' => [7, 7],
            'Suppliers' => [7, 7],
            'Expenses' => [31, 31],
            'YumbiVoucher' => [62, 7],
            'StaffShorts' => [400, 400],
        ],

        /* What each pool is called on the screen. */
        'pool_names' => [
            'CashBags' => 'the drop safe',
            'CashDevices' => 'the cash devices',
            'SmartATM' => 'the Smart ATM deposits',
            'ABSA' => 'the ABSA mark-off file',
            'Yumbi' => 'the Yumbi orders',
            'YumbiVoucher' => 'the Yumbi vouchers',
            'Infinity' => 'the Infinity redemptions',
            'Zapper' => 'the Zapper payments',
            'Debtors' => 'the POS account sales',
            'Suppliers' => 'the POS supplier paid-outs',
            'Expenses' => 'the approved cash purchase requests',
            'StaffShorts' => 'the approved staff shorts',
        ],

        /* The period the Cashups grid opens on, ending yesterday. */
        'default_days' => 7,
    ],

    /*
    |--------------------------------------------------------------------------
    | Till balance (T038)
    |--------------------------------------------------------------------------
    |
    | A till balance moves part of one cashup's variance onto another cashup at
    | the same site — a short on one till, shift or day set against an over on
    | another — as PumpIT's own till-balance screen does.
    */
    'tillbalance' => [

        /*
        | Whether capturing a till balance may write the customer's PumpIT:
        | the pair of BRN_TillBalance rows and the SS_UniqueNumber section 3
        | counter it takes the number from (agora.usp_Cash_SaveTillBalance's
        | header lists them, column by column).
        |
        | A LITERAL, never an environment variable, like every other PumpIT
        | write switch here: Agora starting to write the production ERP is a
        | commit with a name on it.
        |
        |   'live'  a till balance can be captured, and waits for approval in
        |           PumpIT — whose approval posts it to both cashups
        |   'off'   the screen is read-only, the procedure is never called, and
        |           the screen says so
        |
        | 'live' on Ryan's go-ahead of 27 Sep 2026 (PM decisions 01a0e22a-97be /
        | 01a0e22a-9aa9: till balance writes the legacy BRN_TillBalance tables).
        | Approving and posting are NOT here: they write purchase requests and
        | expense lines that go-ahead did not name, and stay in PumpIT until
        | Ryan decides otherwise.
        */
        'write_mode' => 'live',

        /*
        | The smallest variance, either way, for which a cashup is offered to
        | balance. Cashups a till balance waiting for approval already touches
        | are listed whatever their variance.
        */
        'variance_floor' => 1.00,   // setting: cash.tillbalance.variance_floor

        /* The period the screen opens on, ending yesterday. */
        'default_days' => 7,        // setting: cash.tillbalance.default_days

        /*
        | The longest period one screen covers. The two cashups of a till
        | balance were at most 52 days apart in 2026 (934 of 1,406 on the same
        | day, 103 more than three days apart).
        */
        'max_days' => 62,
    ],

    /*
    |--------------------------------------------------------------------------
    | Drop safe (T039)
    |--------------------------------------------------------------------------
    |
    | A bag of cash that could not go through the electronic safe, dropped into
    | the manual safe with a manager watching, and later handed to the security
    | company.
    */
    'dropsafe' => [

        /*
        | Whether dropping, correcting, voiding and collecting bags may write
        | the customer's PumpIT: BRN_DropSafe (a bag — INSERT; UPDATE, or
        | DELETE for a void, only while it is in the safe and on no cashup) and
        | BRN_DropSafe_Collection (INSERT, and CollectionId UPDATEd on the bags
        | it takes). The headers of agora.usp_Cash_SaveDropSafe,
        | agora.usp_Cash_VoidDropSafe and agora.usp_Cash_CollectDropSafe list
        | them column by column.
        |
        | A LITERAL, never an environment variable, like every other PumpIT
        | write switch here: Agora starting to write the production ERP is a
        | commit with a name on it.
        |
        |   'live'  bags can be dropped, corrected, voided and collected
        |   'off'   the screens are read-only, the procedures are never called,
        |           and the screens say so
        |
        | 'live' on Ryan's go-ahead of 27 Sep 2026 (PM decisions 01a0e22a-97be /
        | 01a0e22a-9aa9: drop safe captured in Agora writes the legacy
        | BRN_DropSafe tables); the void's DELETE on his named go-ahead of the
        | same day, for that delete only. Nothing is written to MIST_Audit:
        | agora.DropSafeEvent is the record.
        */
        'write_mode' => 'live',

        /*
        | Above this, a bag needs a reason (and, like every bag, a manager).
        |
        | PumpIT keeps no setting for it: R2,000 is written into the legacy
        | client and into the column's name (ReasonIfAmountMoreThanR2000), and
        | the customer's data shows it enforced — every bag over R2,000.00 since
        | October 2023 carries a reason bar one; a bag of exactly R2,000.00
        | needs none. Here it lives under the key agora.Setting will carry (T026,
        | not built), and DropSafeService is the only thing that reads it. The
        | procedures carry the same number as a parameter default.
        */
        'reason_threshold' => 2000.00,   // setting: cash.dropsafe.reason_threshold

        /* The period the collections list and the bags grid open on, ending today. */
        'default_days' => 14,            // setting: cash.dropsafe.default_days

        /* The longest period one screen covers. */
        'max_days' => 93,
    ],

    /*
    |--------------------------------------------------------------------------
    | Staff shorts (T040)
    |--------------------------------------------------------------------------
    |
    | Money a named person is answerable for — raised, approved or declined,
    | and posted to a cashup's pump shortages.
    */
    'staffshort' => [

        /*
        | Whether raising, approving and declining may write the customer's
        | PumpIT: BRN_StaffShorts and the SS_UniqueNumber section 2 counter
        | (agora.usp_Cash_RaiseStaffShort, _ApproveStaffShort and
        | _DeclineStaffShort list them column by column). Posting is the
        | cashup's StaffShorts leg and ALSO needs cash.cashup.write_mode.
        |
        | A LITERAL, never an environment variable, like every other PumpIT
        | write switch here: Agora starting to write the production ERP is a
        | commit with a name on it.
        |
        |   'live'  shorts can be raised, decided and posted
        |   'off'   the screens are read-only, the procedures are never called,
        |           and the screens say so
        |
        | 'live' on Ryan's go-ahead of 27 Sep 2026 (PM decisions 01a0e22a-97be /
        | 01a0e22a-9aa9: staff shorts write the legacy BRN_StaffShorts, only
        | through the Cash staff-shorts procedures). A short raised by a
        | cash-bag match is switched by Recon's own recon.stamp_mode instead —
        | a journalled match raises nothing.
        */
        'write_mode' => 'live',

        /* The period the screen and the list open on, ending today. */
        'default_days' => 14,     // setting: cash.staffshort.default_days

        /* The longest period one screen covers. */
        'max_days' => 93,
    ],

    /*
    |--------------------------------------------------------------------------
    | Deductions and the payroll file (T041)
    |--------------------------------------------------------------------------
    |
    | The head-office deduction tabs port PumpIT's payroll reports row for row
    | (dbo.sp_RPT_CashShortDeductionPerEmployee, the stock-loss PAYROLL /
    | PAYROLLDETAIL / LeniencySummary set and the non-integrated POS pair) —
    | measured identical on Engen Bethlehem, August 2026, all five tabs.
    |
    | cash_leniency  A cashier short at or inside this many rand is written
    |                off; worse, it is deducted IN FULL — there is no partial
    |                rule. R3.00 is the legacy's own figure, hard-coded there.
    |                Stock loss has its own allowance per area group
    |                (STK_AreaGroup.LossGraceDaily, the customer's master) and
    |                is not set here.
    */
    'deductions' => [
        'cash_leniency' => 3.00,   // setting: cash.deductions.cash_leniency
    ],
];
