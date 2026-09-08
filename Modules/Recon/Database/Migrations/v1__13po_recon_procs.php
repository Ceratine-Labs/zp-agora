<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys Recon's stored procedures (slot 13po).
 *
 * Carries agora.usp_Recon_GridCriteria — the extraction configuration with the
 * customer's own values beside the effective ones, which is the reporting half
 * of the override design accepted on 8 September 2026.
 */
return new class extends ProcedureMigration {};
