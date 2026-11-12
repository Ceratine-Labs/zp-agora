<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys Fuel's stored procedures (slot 11pa).
 *
 * A lettered follow-on because `v1__11_fuel_views` and `v1__11p_fuel_procs`
 * have already run on the customer's instance (batches 83 and 84, 8 Nov 2026)
 * and will not run again. It re-sends EVERY .sql file in the directory — each
 * is a `CREATE OR ALTER`, so an unchanged procedure is a no-op.
 *
 * New here: `usp_Fuel_GetPriceMoves`, the fuel matrix's price-change log and
 * per-grade summary over BRN_FuelPrice (T025). Changed here:
 * `usp_Fuel_GridPrices` notes a zero price in its source notes.
 */
return new class extends ProcedureMigration {};
