<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys Recon's stored procedures (slot 13pz) — the cash-bag cashier.
 *
 * A cash bag belongs to the cashier who dropped it, and v1__13j put that
 * person on agora.vw_DailyBankingCashBags (CashierCode, CashierName and the
 * DropSafeAmount they declared, one row per bag). This slot carries the
 * procedure changes that use it. It has not left our hands yet, so each later
 * change in the same piece of work adds a line here rather than taking a new
 * letter:
 *
 *  · usp_Recon_GetSides — result set 2 for CashBags gains CashierCode,
 *    CashierName and DropSafeAmount (what was declared at the safe, shown
 *    beside CashBagAmount), after every existing column, read straight off
 *    the view (no second join to the drop safe or the employee list, so the
 *    row count is the view's). ABSA, FNB, CashMachine and SmartATM return
 *    exactly the columns they returned before. Read-only, as it always was.
 *
 *  · usp_Recon_ManualMatch — a cash-bag match carries the split by cashier
 *    the clerk confirmed (@AllocationJson, last and defaulted to NULL, so the
 *    other four areas' callers are unchanged; on CashBags it is required, and
 *    the manual-match screen is the only caller there). The procedure groups
 *    the ticked bags by cashier itself, derives each one's pro-rata share of
 *    the bank-vs-bags variance, and refuses a split that is not those bags'
 *    or does not add up — ALLOCATION_INCOMPLETE, before the transaction, like
 *    every other guard — or that arrives on another area
 *    (ALLOCATION_NOT_APPLICABLE). Nothing new is written in PumpIT, where
 *    the procedure stamps exactly the three places it stamped before. Task
 *    01a084fd-0674, on Ryan's decisions of 9 Sep 2026.
 *
 *  · usp_Recon_ManualMatch, again — the confirmed split is now KEPT: one
 *    agora.ReconAllocation row per cashier line (v1__13k), inside the
 *    match's own transaction, with the staff short each line is due
 *    (ShortStatus: 'pending' for a negative share with a cashier in live
 *    mode, 'journal' for the same in journal mode, 'none' for an over or
 *    zero, 'no_cashier' for the bags with none recorded). Agora's database
 *    only; the short itself is raised by T040's usp_Cash_RaiseStaffShort,
 *    never here. Task 01a084fd-07f8.
 *
 *  · usp_Recon_Reverse — marks a reversed batch's allocation lines reversed
 *    (never deleted; a 'pending' short becomes 'cancelled') in the same
 *    transaction, and refuses SHORT_RAISED for a batch whose short has been
 *    raised — which nothing can do until T040 exists. PumpIT writes
 *    unchanged.
 *
 *  · usp_Recon_DiscardRuns — a run with an allocation line hanging off it
 *    counts as processed, beside ReconBatch, ReconMatch and ReconStamp, so a
 *    discard can never orphan one.
 *
 *  · usp_Recon_Trace — an eighth result set, the split by cashier, reached
 *    by the batch, its run, any bag in it, or the cashier's code or name.
 *    Sets 1–7 are unchanged and keep their positions. Read-only.
 *
 *  · usp_Recon_GetAllocation — NEW, read-only: one run's split by cashier
 *    (reversed lines included, marked) and, per batch, the lines' sums beside
 *    the batch's own figures and whether they tie. The run page and the
 *    run's proposals extract read it. Task 01a084fd-096f.
 *
 *  · usp_Recon_DrillMops — for CashBags only, eight columns after the seven
 *    every area returns: the bag's cashier (CashierCode, CashierName,
 *    DropSafeAmount, off the view) and the line of the split its cash was
 *    accounted on in the unreversed batch that holds it (AllocationBatchNo,
 *    AllocationShare, AllocationAccountable, AllocationShortStatus,
 *    AllocationStaffShortsNo). The bags are matched once, into a table
 *    variable, and only those are looked up, by id. A new @WithCashier bit
 *    (default 1) returns the original seven columns with no lookups when 0.
 *    One row per bag, as before; ABSA, FNB, CashMachine and SmartATM return
 *    exactly the columns they did.
 *
 *  · usp_Recon_Commit — passes @WithCashier = 0, so it captures the drill
 *    into the seven columns it always has and pays for no cashier lookup
 *    inside its per-proposal loop (live, 27 Sep 2026, branch 3, 31 days:
 *    the cashier shape grouped over the window cost +21% server time and
 *    about twice the logical reads per call). Its header no longer counts or
 *    lists the procedures that write to the customer's estate: CLAUDE.md
 *    rule 1 holds that list. Nothing it checks, sums or stamps changed.
 *
 *  · usp_Recon_ManualMatch, a third time — the forced-match message states
 *    the variance as the split does, bank less bags ("a variance of -R10.99,
 *    bank less bags: the bank is R10.99 short of the bags"), where it said
 *    "a variance of 10.99" (bags less bank) directly above a split adding up
 *    to -10.99. The status row's Variance column follows it. And "Split
 *    between N cashiers" no longer counts the bags with no cashier recorded
 *    as a cashier ("Split between 3 cashiers and the bags with no cashier
 *    recorded"). The procedure's writes are unchanged; ReconRunLine.DiffAmount
 *    keeps the preview's Diff_MOPS_BANK sign.
 *
 *  · usp_Recon_ManualMatch, a fourth time — T040 CALL SITE wired: in live
 *    mode every 'pending' line of the split is RAISED inside the match's
 *    transaction through agora.usp_Cash_RaiseStaffShort (@Quiet = 1, the
 *    number back through OUTPUT): EmployeeCode = CashierCode, AmountShort =
 *    -Share, TransactionDate = LastBagDate, reason 'cashier_short', the note
 *    naming the batch, Source 'recon' / SourceId = the line's Id, WriteMode =
 *
 *    @StampMode. The line becomes 'raised' with StaffShortsNo; the status
 *    message names the shorts. A short that cannot be raised fails the match.
 *    New PumpIT writes, all Cash's: BRN_StaffShorts (INSERT) and
 *    SS_UniqueNumber section 2 — see that procedure's header. Ryan, 27 Sep
 *    2026, PM decision 01a0e388: every negative share, however small.
 *
 *  · usp_Recon_Reverse, again — the SHORT_RAISED placeholder replaced: a short
 *    the match raised that is still waiting is DECLINED in the reversal's
 *    transaction through agora.usp_Cash_DeclineStaffShort (reason "Recon
 *    batch N reversed: …"), and its line becomes 'cancelled' keeping the
 *    number; one approved or posted to a cashup refuses the reversal
 *    (SHORT_RAISED, naming it) until it is dealt with in Cash. New PumpIT
 *    write, Cash's: BRN_StaffShorts IsDeclined, DeclinedReason,
 *    DeclinedByUserId, DeclinedDate.
 *
 * CROSS-MODULE ORDER. Both calls name Cash procedures, which v1__12p deploys:
 * migrations run in filename order across every module, and v1__12p (Cash,
 * slot 12) sorts before every v1__13* file, so on a deploy the Cash procedures
 * exist before this redeploys the Recon ones. (A call to a missing procedure
 * would still create — T-SQL resolves it when it runs — and then fail the
 * match, never skip the short.)
 *
 * Procedure-only; the table these write is v1__13k's, which sorts before
 * this. Like every lettered procs migration it redeploys the whole Procedures
 * directory, and it sorts after v1__13py, so the procedures it deploys are
 * the ones already on UTC.
 */
return new class extends ProcedureMigration {};
