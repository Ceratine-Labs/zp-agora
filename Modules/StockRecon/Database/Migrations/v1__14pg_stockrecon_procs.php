<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys StockRecon's stored procedures (slot 14pg).
 *
 *   usp_StockRecon_PreviewBalancing  a break of @KeepBreaksUpTo units or fewer
 *                                    (3 by default) no longer splits the chain:
 *                                    the opening is repaired, the chain is
 *                                    balanced through it, and the qty is
 *                                    reported on the row (BreakKept) and on the
 *                                    exceptions tab. S12/S13 ignore a break
 *                                    that small.
 *   usp_StockRecon_GridExceptions    the break qty, and whether it was kept.
 *   usp_StockRecon_DrillChain        the same, per shift.
 *
 * Needs v1__14g (the columns).
 */
return new class extends ProcedureMigration {};
