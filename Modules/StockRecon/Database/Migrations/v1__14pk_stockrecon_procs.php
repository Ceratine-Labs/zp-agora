<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys StockRecon's stored procedures (slot 14pk), 6 Oct 2026:
 * usp_StockRecon_PreviewBalancing raises U1 and U2 only where the segment's
 * running total is above zero, so an over that a short before it covers — one
 * miscounted closing, counted right by the next shift — balances instead of
 * holding the whole chain back (MONSTER MARIPO, branch 17, 8–9 Sep; Ryan: "if
 * a chain is 0 … what is it held back for"). usp_StockRecon_DrillChain's
 * Cumulative column runs on the balancing basis and ends on the chain total.
 * The rest redeploy unchanged.
 */
return new class extends ProcedureMigration {};
