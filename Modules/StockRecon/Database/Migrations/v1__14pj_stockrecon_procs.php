<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys StockRecon's stored procedures (slot 14pj), 6 Oct 2026:
 * usp_StockRecon_PreviewBalancing reads U1 and U2 against the opening as
 * counted where it sits on a kept break above the previous closing, so a
 * closing miscounted low and counted again by the next shift balances instead
 * of holding the whole chain back (ZAMBUK, branch 17; Ryan: "chain total is 0
 * … but held back"). Only the break is excused. The rest redeploy unchanged.
 */
return new class extends ProcedureMigration {};
