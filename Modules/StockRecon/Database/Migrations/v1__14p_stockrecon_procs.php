<?php

use App\Support\Database\ProcedureMigration;

/**
 * Stock recon — the balancing procedures (slot 14p).
 *
 * Seven procedures, and the split between them is the point:
 *
 *   PreviewBalancing   computes the whole answer and records it. Reads PumpIT
 *                      through agora.vw_Stock*, writes only Agora's ledger.
 *   Commit             acts on the rows an operator ticked on a recorded run,
 *                      re-checks every one, and writes the amendment record.
 *                      It touches PumpIT ONLY in 'live' stamp mode.
 *   Reverse            puts back exactly what a commit wrote, from the prior
 *                      values that commit stored.
 *   DiscardRuns        throws away previews, never a committed run.
 *   GridRuns           the runs grid.
 *   GridExceptions     the exception report — read-only, and the half of this
 *                      module that is worth more than the balancing.
 *   DrillChain         the whole chain behind one clicked shift.
 *
 * Deployed by CREATE OR ALTER, so this migration is safe to re-run and a
 * change to a body shows up in the diff as an edit rather than a drop.
 * A procedure ADDED after this has run needs its own v1__14pa file — this one
 * is in agora.Migration and will not run again.
 */
return new class extends ProcedureMigration {};
