<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys Recon's stored procedures (slot 13pw).
 *
 * Suggestions by value for FNB (ZP's ask, 23 Sep 2026): the lines the batch
 * number cannot pair — mostly the 'FN' lines, which carry no batch number at
 * all — proposed against deposits by amount and grouping, for a clerk to
 * accept.
 *
 *  · usp_Recon_SuggestMatches is new and READ-ONLY. It calls
 *    usp_Recon_PreviewFNB to set aside what the batch number settles, then
 *    runs the iterative passes described in its header over what is left.
 *  · usp_Recon_ManualMatch gains @Basis (default NULL, so every existing
 *    caller is unchanged). An accepted suggestion records its reading on the
 *    run — Note, ParamsJson and the 'Matched by suggestion' outcome. What is
 *    checked and what is stamped in PumpIT do not change.
 *
 * Procedure-only. No table changes.
 */
return new class extends ProcedureMigration {};
