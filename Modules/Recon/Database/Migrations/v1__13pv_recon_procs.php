<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys Recon's stored procedures (slot 13pv).
 *
 * The working-list pieces Ryan approved on 23 Sep 2026 (Bank Recon Close-out
 * report, q2). On live that morning the clerks' Runs tabs held 1,136 open
 * previews, 504 of them older than two weeks, and three runs had been
 * committed that stamped nothing because every row in them had already been
 * reconciled by another run.
 *
 *  · usp_Recon_DiscardRuns gains three arguments: an action ('discard',
 *    'close' or 'reopen'), an age limit and a creator. Closing and
 *    reopening live beside the discard because all three share the one rule
 *    about "processed" runs, and two definitions of it is how the
 *    reversed-run hole happened. A sweep never takes a closed run.
 *  · usp_Recon_GridRuns gains @OpenOnly (default 0, so older callers are
 *    unchanged) and returns IsClosable / IsClosed for the row actions.
 *  · usp_Recon_RunFreshness is new: which of a preview's pending proposals
 *    another Agora run has committed since it was previewed, in one
 *    set-based read of the ledger rather than a drill per row.
 *
 * Procedure-only. No table changes: 'closed' fits the existing Status column,
 * and who closed a run goes in the UpdatedBy the audit columns already carry.
 */
return new class extends ProcedureMigration {};
