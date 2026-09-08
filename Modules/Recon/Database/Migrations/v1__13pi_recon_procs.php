<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys Recon's stored procedures (slot 13pi).
 *
 * Carries the fix for a CashBags proposal with no bank line. In `contains`
 * mode usp_Recon_DrillMops finds deposits by looking for the bag reference
 * inside a bank NARRATIVE, so a "Deposit only - no bank line" row — which by
 * definition has no narrative to look in — drilled to nothing. All eleven of
 * them on run 53 expanded to two empty columns, and both side exports skipped
 * them.
 *
 * usp_Recon_PreviewCashBags now emits the bag's own DailyBankingCashBagID on
 * an orphan row, agora.ReconRunLine stores it as MopsSourceId (v1__13f), and
 * the drill matches on it when it is given. A reference could not have done
 * the job: lines 4435 and 4436 on that run share the DBagNo 304822859458.
 *
 * Like every other lettered `p` slot it deploys the whole directory, so what
 * is in the database is what is in the repository.
 */
return new class extends ProcedureMigration {};
