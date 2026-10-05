<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys Reports' stored procedures (slot 22pf), 5 Oct 2026:
 * usp_Reports_GetAuditChange reads a stock recon shift's issue before and
 * after the commit from agora.StockReconAmendment (PriorQtyIssued,
 * NewQtyIssued), so a clear duplicate issue the commit set to 0 (D1) opens as
 * "Issue 20 → 0" with the shift's variance after the correction at nil — not
 * the run line's issue on both sides. Needs v1__14h (the columns). The rest
 * redeploy unchanged.
 */
return new class extends ProcedureMigration {};
