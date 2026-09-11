<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys StockRecon's stored procedures (slot 14pd).
 *
 * agora.usp_StockRecon_DrillChain now returns VarValue and VarValueNew — the
 * rand behind the quantity on each side of the chain — plus the SellPrice they
 * are derived from.
 *
 * Ryan, 11 Sep 2026, looking at run 7 beside the legacy Stock Recon Balancing
 * screen: "any chance in the detail we can show the original value and the
 * amended value?" The legacy screen carries Qty Var and Value Var under each
 * of its two blocks, Original Values and Amended Values, and that pairing is
 * how the people who work this screen read it — a 0.1 kg variance on a R240.78
 * cheese is not the same finding as 0.1 kg of sauce.
 *
 * Derived from the stored SellPrice rather than joined to the master, for the
 * same reason v1__14a stored the item labels: a run is a record of what was
 * true when it was made, and a price changes underneath it.
 */
return new class extends ProcedureMigration {};
