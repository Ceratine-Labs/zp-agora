<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys Recon's stored procedures (slot 13pk).
 *
 * Carries agora.usp_Recon_GridRuns, the run list behind the area's Runs tab.
 * Its own slot rather than an edit to 13pj, which has already run — the rule
 * in CLAUDE.md is that a migration which has run is not edited.
 */
return new class extends ProcedureMigration {};
