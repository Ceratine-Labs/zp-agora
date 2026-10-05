<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys Recon's stored procedures (slot 13pl — the one letter of the series left unused).
 *
 *   usp_Recon_ManualMatch  a forced FNB match writes its variance to the
 *                          deposits (Amount, AdjustedBy, Adjusted) by primary
 *                          key, keeps each row's prior values on
 *                          agora.ReconMatch, and stamps every FNB row by key.
 *                          Switched by config('recon.fnb_adjust_mode').
 *   usp_Recon_Reverse      puts those rows' Amount, AdjustedBy and Adjusted
 *                          back to their prior values by key, and refuses
 *                          (ADJUST_MOVED) if one has changed since.
 *
 * Needs v1__13l (the columns and the widened vw_DailyBankingFNB).
 */
return new class extends ProcedureMigration {};
