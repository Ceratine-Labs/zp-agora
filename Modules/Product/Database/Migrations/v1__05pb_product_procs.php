<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys Product's stored procedures (slot 05pb).
 *
 * A lettered follow-on because `v1__05p` and `v1__05pa` have both already run
 * on the customer's instance and will not run again. It re-sends EVERY .sql
 * file in the directory — each is a `CREATE OR ALTER`, so an unchanged
 * procedure is a no-op.
 *
 * New here: `usp_Product_SetStockItemFlag`, the batch flag action on the stock
 * recon master listing. Changed here: `usp_Product_GridStockItems` returns the
 * other five behaviour flags, so the result of a batch can be put on screen in
 * the column chooser — a flag that can be set in bulk and not seen afterwards
 * is a change nobody can check.
 */
return new class extends ProcedureMigration {};
