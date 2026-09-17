<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys Reports' stored procedures (slot 22pa).
 *
 * usp_Reports_GridStockCount and usp_Reports_GridWaste looked the stock master
 * up ON (BranchId, StockItemNo), which is not its key — STK_StockMaster is
 * keyed by branch AND Location (WINBRANCH | AURA). An item counted under both
 * POS families was therefore reported twice on the stock-count grid, and on
 * the waste grid the duplicate doubled the VALUE as well as the row count.
 *
 * Both now use OUTER APPLY with TOP 1, preferring the master row for the
 * counting area the line belongs to. The full account is in StockRecon's
 * v1__14d, where the same fault was found first.
 */
return new class extends ProcedureMigration {};
