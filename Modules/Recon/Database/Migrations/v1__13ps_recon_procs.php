<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys Recon's stored procedures (slot 13ps).
 *
 * agora.usp_Recon_DrillMops gains @IncludeReconciled and returns
 * ReconBatchNoPumpIT, so the SCREEN can show a deposit something else has
 * reconciled since the preview instead of showing nothing at all.
 *
 * Ryan, 9 Sep 2026, on run #260 (branch 13, ABSA, batch 904): expanding a
 * Matched proposal showed "Bank lines 0 · R0.00" and "Nothing was declared
 * against this reference", while the row above it said 2 bank lines at
 * R2,241.20 against 17 deposits. Every one of those rows had been stamped
 * since the preview — the deposits under ReconBatchNoPumpIT, the bank lines as
 * batches 125384 and 125385 — and both drills filter to what is still
 * outstanding, so the panel described a fully settled batch as one that had
 * never been declared. The bank side has carried @IncludeReconciled since
 * usp_Recon_Commit needed it for exactly this reason; the deposit side had no
 * such parameter at all.
 *
 * usp_Recon_Commit is re-deployed alongside it because `INSERT INTO @DrillM
 * EXEC` is positional: the extra output column had to be added to @DrillM and
 *
 * @Mops or the commit would fail on a column-count mismatch. Its BEHAVIOUR is
 * unchanged — it now passes @IncludeReconciled = 0 explicitly, and nothing
 * reads the new column.
 */
return new class extends ProcedureMigration {};
