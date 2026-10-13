<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys StockRecon's stored procedures (slot 14pr), 13 Oct 2026.
 *
 * An over balances: usp_StockRecon_PreviewBalancing (stage 7g) proposes the
 * missing issue on each shift left over after balancing — QtyIssuedNew = the
 * issue + the over — when @CaptureMissingIssues is on
 * (config stockrecon.missing_issue_mode, Ryan's named go-ahead: "customer
 * wants over to balance"). usp_StockRecon_Commit writes it through the
 * existing issue path, its prior kept, and reports it; the "write the chain
 * with its duplicate" rule now applies to a duplicate only.
 *
 * And quantities in the procedures' messages read to three places
 * ('0.000', not '0.###': Ryan, "sometimes 1, sometimes 1.000"). The rest
 * redeploy unchanged.
 */
return new class extends ProcedureMigration {};
