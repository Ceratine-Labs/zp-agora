<?php

use App\Support\Database\ProcedureMigration;

/**
 * Cash — the Z-read allocation procedures (slot 12p, T036).
 *
 *   ProposeZreadAllocation  the proposer: one row per Z-read with a till, a
 *                           shift, a person, a confidence and the evidence.
 *                           Reads only. Every other procedure here takes its
 *                           proposals from it rather than re-deriving one.
 *   GridZread               the ZREAD Allocation grid, over the proposer.
 *   ZreadTerminalHistory    "who normally runs this terminal", for a row panel.
 *   AutoAllocate            stages the proposals at or above the threshold.
 *   SetZreadAllocation      stages one Z-read — accepted as proposed, or set
 *                           by hand.
 *   ClearZreadProposals     throws staging away (it is marked, not deleted).
 *   CommitZreadAllocation   Update ZREAD: staged becomes allocated, the old
 *                           allocation is superseded, the prior values kept —
 *                           and, in 'live' stamp mode, TillNo / ShiftNo /
 *                           EmployeeCode are written onto the one PumpIT row.
 *   ReverseZreadCommit      undoes one press on both sides, from those priors.
 *   ZreadCommits            the saved presses, for the Reverse list.
 *
 * Writes to the customer's database: Commit and Reverse UPDATE TillNo,
 * ShiftNo, EmployeeCode on [PumpIT].dbo.DBF_P3TRANS_ZREAD; the till balance
 * and drop safe procedures below list their own.
 *
 * Till balance and pump shortages (T038):
 *
 *   SaveTillBalance      captures a till balance: INSERTs the pair of
 *                        [PumpIT].dbo.BRN_TillBalance rows and takes the number
 *                        from SS_UniqueNumber section 3 (UPDATE
 *                        NextUniqueNumber), in one transaction. Approval and
 *                        posting stay in PumpIT.
 *   GetTillBalance       the Till balance screen: a site's cashups worth
 *                        balancing, and the till balances on them.
 *   GridPumpShortages    the Pump shortages grid: the cashups' pump shortages
 *                        leg, by attendant and shift.
 *
 * The drop safe (T039):
 *
 *   SaveDropSafe         drops a bag, or corrects one still in the safe and on
 *                        no cashup: INSERTs (or UPDATEs) one
 *                        [PumpIT].dbo.BRN_DropSafe row, in one transaction.
 *                        Refuses a bag over the threshold without a reason, and
 *                        any bag without a manager.
 *   CollectDropSafe      hands bags to the security company: INSERTs one
 *                        [PumpIT].dbo.BRN_DropSafe_Collection row (its IDENTITY
 *                        is the collection id) and UPDATEs CollectionId on each
 *                        bag it takes, in one transaction.
 *   VoidDropSafe         voids a bag captured in error: DELETEs its one
 *                        [PumpIT].dbo.BRN_DropSafe row — only while it is in the
 *                        safe and on no cashup — after keeping the whole row in
 *                        agora.DropSafeEvent, in one transaction (Ryan's named
 *                        go-ahead, 27 Sep 2026, for this delete only).
 *   GetDropSafe          the Drop safe screen: what is in the safe, the
 *                        collections in a period, and the lists a capture uses.
 *   GridDropSafe         the Drop safe bags grid, every bag across sites.
 *   DropSafeInSafe       "In the safe" per site — the chip, and the badge a
 *                        console reads.
 *
 * Staff shorts (T040), writing only BRN_StaffShorts and SS_UniqueNumber
 * section 2 (and, through the cashup leg, what that leg already wrote):
 *
 *   RaiseStaffShort      raises a short: INSERTs one [PumpIT].dbo.BRN_StaffShorts
 *                        row under the number SS_UniqueNumber section 2 gives
 *                        (UPDATE NextUniqueNumber), in one transaction, with a
 *                        reason code (agora.StaffShortReason) and Agora's record
 *                        (agora.StaffShort). Also called by usp_Recon_ManualMatch
 *                        for a cash-bag match's negative shares.
 *   ApproveStaffShort    UPDATEs Approved, ApprovedDate, ApprovedByUserId on a
 *                        waiting short.
 *   DeclineStaffShort    UPDATEs IsDeclined, DeclinedReason, DeclinedByUserId,
 *                        DeclinedDate on a waiting short. Also called by
 *                        usp_Recon_Reverse.
 *   WithdrawStaffShort   takes an APPROVED, unposted short back (Ryan's named
 *                        go-ahead, PM decision 01a0e5b5): UPDATEs Approved,
 *                        ApprovedDate, ApprovedByUserId to the legacy's
 *                        declined shape and IsDeclined, DeclinedReason,
 *                        DeclinedByUserId, DeclinedDate.
 *   PostStaffShort       writes nothing itself: checks an approved short (never
 *                        one a cash-bag match raised) and calls SaveCashupLeg's
 *                        StaffShorts leg for the cashup.
 *   SaveCashupLeg        (un-held StaffShorts leg) INSERTs the
 *                        BRN_DailyBankingEmployees line and UPDATEs the short's
 *                        IsSelectedForDailyBanking, with the header's totals.
 *   GetCashupPool        offers the approved shorts (dbo.sp_Load_ApprovedStaffShorts).
 *   GetStaffShorts       the Staff shorts screen; GridStaffShorts the list.
 *
 * Deductions and the payroll file (T041, T042 — docs/cash-deductions.md):
 *
 *   DeductionCashShorts, DeductionStockLoss   the two fillers every figure
 *                        comes from (the caller's #CashShort / #StockLoss).
 *   GridDeductionCashShorts, GridStockLossSummary, GridStockLossDetail,
 *   GridNonIntPosSummary, GridNonIntPosDetail  PumpIT's five payroll reports.
 *   BuildDeductions      a site's pay period into agora.DeductionRun/Deduction.
 *   GridDeductionSchedule the Payroll tab; ExportPayroll the file, marking the
 *                        runs exported.
 *   GetUnpostedStaffShorts approved shorts on no cashup, so on no deduction.
 *
 * None of these writes the customer's database.
 *
 * Deployed by CREATE OR ALTER in sorted order, so re-running is safe. The
 * proposer is called by three of the others through INSERT ... EXEC, which
 * binds at run time, so the order does not matter here. A procedure added
 * after this has run needs its own v1__12pa file.
 */
return new class extends ProcedureMigration {};
