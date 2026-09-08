<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys Recon's stored procedures (slot 13ph).
 *
 * Carries the fix to agora.usp_Recon_DrillBank, which filtered the bank
 * statement on `l.Type = @ReconArea`. That is right for four of the five
 * areas and wrong for CashBags, whose lines carry Type = 'CashDeposit' —
 * which is what usp_Recon_PreviewCashBags has always filtered on.
 *
 * The consequence was not confined to the drill. usp_Recon_Commit re-reads the
 * bank side through this procedure, so for CashBags it found nothing and
 * skipped every batch as "no longer on the statement in this period". Five
 * CashBags runs on the customer's instance, all still `previewed`, not one
 * committed row — against 187 for ABSA and 191 for FNB. The area could not be
 * reconciled at all.
 *
 * Like every other lettered `p` slot it deploys the whole directory, so what
 * is in the database is what is in the repository.
 */
return new class extends ProcedureMigration {};
