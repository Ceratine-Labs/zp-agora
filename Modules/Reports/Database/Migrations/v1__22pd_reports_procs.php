<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys Reports' stored procedures (slot 22pd), 1 Oct 2026, for the audit
 * trail: usp_Reports_GridAuditTrail reads agora.vw_PumpItChange (v1__22a) —
 * every change Agora has made in PumpIT, from the ledgers the reversals run
 * on. Read-only like every procedure in the directory; the rest redeploy
 * unchanged.
 */
return new class extends ProcedureMigration {};
