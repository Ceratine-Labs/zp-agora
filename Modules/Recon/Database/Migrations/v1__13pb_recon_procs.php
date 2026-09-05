<?php

use App\Support\Database\ProcedureMigration;

/**
 * Recon — discarding previews (slot 13pb).
 *
 * Adds agora.usp_Recon_DiscardRuns. A preview is a record of a read and can be
 * thrown away; a committed run is the only record of what it stamped and never
 * can. That rule lives in the procedure, not in PHP.
 *
 * Redeploys the whole Procedures directory — every file is a CREATE OR ALTER,
 * so it is free, and it keeps the database holding exactly what the repository
 * says rather than the union of what each historical migration deployed.
 */
return new class extends ProcedureMigration {};
