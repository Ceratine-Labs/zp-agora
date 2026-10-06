<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys StockRecon's stored procedures (slot 14pi), 6 Oct 2026:
 * usp_StockRecon_DrillChain says how a chain's total is made up — the counted
 * total the Cumulative column ends on, what setting each opening to the
 * previous closing moves, and any clear duplicate issue taken out — and
 * whether those add up to the stored total (a run made before the method
 * settled on 30 Sep 2026 was totalled another way). Ryan: "how is this chain
 * total 1 when its cumulative ends at 6?". The rest redeploy unchanged.
 */
return new class extends ProcedureMigration {};
