<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys StockRecon's stored procedures (slot 14pq), 8 Oct 2026: Reset to
 * original puts the POS back as well — QtyComputer = QtyComputer_Original, the
 * prior kept in agora.StockReconAmendment (v1__14k) — so every amended column
 * goes back (Ryan: "clear any amendments, they will not be inputting anything
 * on v2"). usp_StockRecon_Reverse puts the prior POS back and refuses
 * (POS_MOVED) over a POS changed since. The rest redeploy unchanged.
 */
return new class extends ProcedureMigration {};
