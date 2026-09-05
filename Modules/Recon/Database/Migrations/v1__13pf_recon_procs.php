<?php

use App\Support\Database\ProcedureMigration;

/**
 * Recon — SmartATM scopes on the deposit's own timestamp (slot 13pf).
 *
 * Redeploys usp_Recon_PreviewSmartATM, usp_Recon_DrillMops and
 * usp_Recon_Commit so all three agree on WHICH clock a SmartATM deposit is
 * dated by: the device's `BRN_SmartATM.DepositDateTime`, not the cashup row's
 * midnight filing date. They disagree on 17.5% of the customer's rows.
 *
 * The deviation from the ported original is stated in
 * usp_Recon_PreviewSmartATM's header — it is the only procedure in the module
 * whose logic differs from ZP's, and it says so.
 */
return new class extends ProcedureMigration {};
