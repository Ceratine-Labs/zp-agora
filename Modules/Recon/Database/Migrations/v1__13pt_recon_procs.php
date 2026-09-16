<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys Recon's stored procedures (slot 13pt). Three changes, one area.
 *
 * 1. usp_Recon_DrillBank — SMART ATM COULD NEVER COMMIT ANYTHING.
 *
 *    A Smart ATM proposal is grouped on (terminal, the MM/DD in the bank
 *    narrative), and the window derived from that MM/DD describes the DEPOSIT
 *    side. The commit re-found the BANK lines by LineDate inside that window —
 *    but the bank posts an ATM's takings the FOLLOWING calendar day, so the
 *    line the preview grouped lies outside its own window by construction. It
 *    never matched, on any site, ever.
 *
 *    What made it hard to see is that it did not fail. It reported every row as
 *    'A bank line has been reconciled by something else since the preview' or
 *    'The two sides no longer balance' — statements about the customer's data,
 *    and all of them false. Ryan, 16 Sep 2026: "smart atm is not ammending in
 *    batch", across 5 posted sites and 17 proposals worth R565,500.00, every
 *    one of which reported success and stamped nothing.
 *
 *    Proven on run 999 branch 9: the preview's line ATMH0130|0828, 26,800.00,
 *    LineDate 2026-08-29, ReconState 1 — still outstanding — while the drill's
 *    window returned ATMH0130|0827 from 2026-08-28, already reconciled.
 *
 *    SmartATM now matches on ExtractedRef + ExtractedRef2, which is exactly how
 *    the preview grouped it. FNB's standalone population keeps the LineDate
 *    window; it is the only area that legitimately needs one.
 *
 * 2. usp_Recon_Commit — refuses a Smart ATM run previewed before that fix, by
 *    name, instead of inheriting the false skip messages. Those runs never
 *    carried the narrative MM/DD (see ReconService::line()), so there is
 *    nothing to re-find the bank lines by. AGORA:RUN_PREDATES_KEYREF2.
 *
 * 3. usp_Recon_PreviewSmartATM and usp_Recon_GetSides — the trading day is now
 *    MIDNIGHT TO MIDNIGHT (ZP, 16 Sep 2026, closing a question open since
 *    14 Aug), and the manual-match screen reads the DEVICE timestamp so it
 *    shows a real time and agrees with what the matcher pairs on. The cashup
 *    column it read before is a date wearing a datetime's clothes — all 286 of
 *    branch 9's rows for Aug-Sep 2026 sit at exactly 00:00:00.
 *
 * Measured on branch 9, 16 Aug - 16 Sep 2026, before shipping: see the task
 * outcome for the before/after pairing counts under the two windows.
 */
return new class extends ProcedureMigration {};
