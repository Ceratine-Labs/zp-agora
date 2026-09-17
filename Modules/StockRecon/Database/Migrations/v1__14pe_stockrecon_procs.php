<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys StockRecon's stored procedures (slot 14pe).
 *
 * Both procedures that looked the stock master up by item number alone now use
 * OUTER APPLY with TOP 1, so a label lookup can no longer multiply a row:
 *
 *   usp_StockRecon_PreviewBalancing  stage 8 — it fanned on an INSERT, so the
 *                                    duplicate was WRITTEN to the ledger with
 *                                    its own LineNo, tick box and commit path.
 *   usp_StockRecon_DrillChain        the header block — it fanned COUNT(*),
 *                                    which is the chain panel's "Shifts".
 *
 * STK_StockMaster is keyed by branch AND Location (WINBRANCH | AURA), never by
 * item alone. The full account, the measurements and the reasoning behind
 * OUTER APPLY rather than a wider join are in v1__14d.
 */
return new class extends ProcedureMigration {};
