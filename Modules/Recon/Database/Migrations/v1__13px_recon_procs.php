<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys Recon's stored procedures (slot 13px).
 *
 * The CLOSE tier on FNB Suggestions (ZP and Ryan, 23 Sep 2026): sites 8, 23,
 * 25 and 26 settle a few rand off their takings, so the two exact tiers offer
 * them almost nothing.
 *
 *  · usp_Recon_SuggestMatches gains passes 4 and 5, over what strong and
 *    possible left only: a bank day, device day or line against takings
 *    within 1% of the bank side, capped at R500 (@CloseMax, @ClosePct), days
 *    before units. Result set 1 gains DiffAmount; the summary gains the close
 *    counts. Still READ-ONLY. @CloseMax = 0 is the procedure as it was — the
 *    blind replay returns strong and possible row for row either way.
 *  · usp_Recon_ManualMatch: a forced match that came from a suggestion says
 *    so — Outcome 'Matched by suggestion - forced', Note 'Suggested match
 *    (forced) — …'. What is checked and what is stamped in PumpIT do not
 *    change; a variance still needs @Reason.
 *
 * Procedure-only. No table changes.
 */
return new class extends ProcedureMigration {};
