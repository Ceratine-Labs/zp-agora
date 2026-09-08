<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys Recon's stored procedures (slot 13pq).
 *
 * agora.usp_Recon_GridCriteria now produces one row per RULE, keyed on
 * AutoReconId, rather than one per (BranchId, BankReconArea, ProcessOrder).
 * That triple does not identify a rule — branch 23 has two FNB rules both at
 * ProcessOrder 1 — so the old shape deduplicated 133 rules to 132 keys and
 * then met each side of its joins twice, reporting 135 rows for an estate of
 * 133. See v1__13i_recon_criteria_rekey for the other half.
 */
return new class extends ProcedureMigration {};
