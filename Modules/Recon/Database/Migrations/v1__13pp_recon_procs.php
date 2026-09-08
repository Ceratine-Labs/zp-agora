<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys Recon's stored procedures (slot 13pp).
 *
 * Carries the write half of the configuration editor —
 * agora.usp_Recon_SaveCriteria and agora.usp_Recon_CopyCriteria. Both write to
 * agora.ReconCriteria and nothing else; the customer's BRN_AutoReconCriteria
 * is read-only to Agora for ever.
 */
return new class extends ProcedureMigration {};
