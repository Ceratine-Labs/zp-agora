<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys Recon's stored procedures (slot 13pm).
 *
 * Carries agora.usp_Recon_Trace — one typed value against the whole ledger and
 * the legacy estate behind it. Its own slot rather than an edit to 13pk, which
 * has already run.
 */
return new class extends ProcedureMigration {};
