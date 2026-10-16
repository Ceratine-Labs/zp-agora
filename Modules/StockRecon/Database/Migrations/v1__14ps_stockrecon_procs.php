<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys StockRecon's stored procedures (slot 14ps), 16 Oct 2026.
 *
 * agora.usp_StockRecon_DrillChain returns QtyIssuedAmended and
 * CumulativeVarNew, so the chain panel's Amended block carries the same
 * columns as its Original block — Open, Issued, Close, POS, Variance, Value,
 * Cumulative — as the legacy Stock Recon Balancing screen does (Ryan: "on the
 * originals, can we show the same columns on the right on the amended?").
 * The rest redeploy unchanged.
 */
return new class extends ProcedureMigration {};
