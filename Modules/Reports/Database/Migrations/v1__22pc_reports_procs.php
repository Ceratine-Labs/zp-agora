<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys Reports' stored procedures (slot 22pc) — for T037, 27 Sep 2026.
 *
 * usp_Reports_GridDailyBanking, usp_Reports_GridOpenCashups and the
 * open-cashups arm of usp_Reports_GridMyQueue now read
 * agora.vw_CashupTotals (Cash, v1__12a), which computes a cashup's actual
 * total and variance exactly as PumpIT's own Daily Banking grid does
 * (dbo.sp_GetDailyBankingWithMOPS: fifteen legs less ClosingBalance) and says
 * where it stands — Open, Confirmed, Closed or Day balanced.
 *
 *  · Daily banking becomes the manager's view. Its old "Declared" was
 *    CreditCardAmount + EFuelAmount + DirectDepositAmount off the header —
 *    the card total and two columns that are 0 on every row since 2024 — so
 *    its "Declared vs Z" was the card takings less the whole Z-read. The
 *    cashup is declared in the eighteen BRN_DailyBanking<Leg> tables, which it
 *    never read. ActualTotal and Variance replace both.
 *  · Open cashups answers agora.vw_CashupTotals.IsOpen: for a cashup Agora has
 *    never touched, exactly its old rule (the PumpIT day is not balanced); a
 *    cashup closed by a manager in Agora leaves the queue, and one confirmed
 *    on a day PumpIT has not balanced stays in it, waiting on a close.
 *
 *  · My queue's open-cashups count answers the same IsOpen, so the directory
 *    and the queue it points at cannot disagree.
 *
 * It depends on v1__12a_cash_cashup having run, which the slot order
 * guarantees (12a before 22). Every other procedure in the directory is
 * rewritten to itself.
 */
return new class extends ProcedureMigration {};
