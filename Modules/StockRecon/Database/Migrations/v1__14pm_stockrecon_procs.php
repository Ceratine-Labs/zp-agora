<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys StockRecon's stored procedures (slot 14pm), 7 Oct 2026: every
 * preview calculates on the ORIGINAL values — the issue, the POS and the
 * closing just before the week as well as the counts (Ryan: "we must use
 * original"; the customer saw us calculate on amended). The live values stay
 * beside them for the audit and for what a commit leaves in PumpIT.
 * usp_StockRecon_ResetToOriginal also puts back the closing of the shift just
 * before the week. The rest redeploy unchanged.
 */
return new class extends ProcedureMigration {};
