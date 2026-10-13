<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys Recon's stored procedures (slot 13pzb), 13 Oct 2026: the bank
 * recon dashboard — agora.usp_Recon_DashboardOutstanding (what is outstanding,
 * added up per site, area and side, aged) and agora.usp_Recon_GridOutstanding
 * (the rows behind it, a page at a time). Both read only. The rest redeploy
 * unchanged.
 */
return new class extends ProcedureMigration {};
