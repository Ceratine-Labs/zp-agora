<?php

use App\Support\Database\ProcedureMigration;

/**
 * Recon — near-reference detection (slot 13pd).
 *
 * Adds agora.usp_Recon_FlagNearReferences: the pass that notices when a
 * bank-only row and a deposit-only row are the same reconciliation split in
 * two by an extraction that is a character out.
 */
return new class extends ProcedureMigration {};
