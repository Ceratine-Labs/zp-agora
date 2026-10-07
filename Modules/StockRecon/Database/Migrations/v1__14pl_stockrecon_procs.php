<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys StockRecon's stored procedures (slot 14pl), 7 Oct 2026:
 * usp_StockRecon_ResetToOriginal is new — a run's shifts back to the staff's
 * original open, issue and close in PumpIT, each prior kept (Ryan: "update the
 * amended with the original column values"). usp_StockRecon_PreviewBalancing
 * balances every chain whose total is nil: no finding holds it back, and it
 * runs through every break. usp_StockRecon_Commit lets a balancing commit
 * stand on a reset. The rest redeploy unchanged.
 */
return new class extends ProcedureMigration {};
