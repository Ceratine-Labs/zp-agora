<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys Recon's stored procedures (slot 13pj).
 *
 * 13pi added @MopsSourceId to usp_Recon_DrillMops but not the branch that
 * reads it — an edit script asserted its way out halfway through and only the
 * parameter landed. The parameter without the branch is inert: the procedure
 * accepts the id and ignores it, which is the shape of change most likely to
 * be mistaken for a working one. This carries the branch.
 *
 * Kept as its own slot rather than folded into 13pi, because 13pi has already
 * run — on the local container and, once this ships, nowhere else. The rule in
 * CLAUDE.md is that a migration which has run is not edited.
 */
return new class extends ProcedureMigration {};
