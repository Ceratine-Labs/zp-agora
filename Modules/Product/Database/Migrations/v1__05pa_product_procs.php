<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys Product's stored procedures (slot 05pa).
 *
 * A lettered follow-on because `v1__05p` has already run on the customer's
 * instance (20 September 2026) and will not run again. It re-sends EVERY .sql
 * file in the directory, not just the new ones — each is a `CREATE OR ALTER`,
 * so re-sending an unchanged procedure is a no-op, and a migration that tried
 * to send a subset would need a list somebody has to keep correct.
 *
 * New here: `usp_Product_GridCriticalLines` and `usp_Product_SaveCriticalLine`.
 */
return new class extends ProcedureMigration {};
