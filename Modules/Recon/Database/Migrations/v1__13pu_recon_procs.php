<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys Recon's stored procedures (slot 13pu).
 *
 * usp_Recon_DrillMops no longer repeats the Smart ATM deposit timestamp inside
 * its Detail string. That timestamp is what SourceDate carries and what the
 * panel's Date column has shown since v1__13pt; printing it a second time on
 * the same row is noise. What the CASE still says is the thing a date column
 * cannot: that a deposit has no device row behind it, so the date beside it is
 * the filing date rather than a real deposit time.
 *
 * Ryan, 16 Sep 2026, checking the change that prompted it: "are you showing
 * line date and time or the deposit date and times? it must be deposit." It is
 * the deposit — BRN_SmartATM.DepositDateTime, the device's own — on both the
 * manual-match screen and this panel. The bank pane is the only place a
 * statement LineDate is shown, which is correct for the bank side.
 */
return new class extends ProcedureMigration {};
