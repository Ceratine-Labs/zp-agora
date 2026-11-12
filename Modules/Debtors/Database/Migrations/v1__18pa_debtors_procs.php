<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys Debtors' stored procedures (slot 18pa, 12 Nov 2026).
 *
 * v1__18p has already run, and a migration that has run does not run again.
 * This one carries ZP's statement layout: usp_Debtors_GetStatement,
 * usp_Debtors_GridAccounts and usp_Debtors_SaveContact now read
 * agora.vw_DebtorStatementLedger (slot 18a, which sorts before this file).
 *
 * The whole Procedures directory is re-sent, as every lettered `p` slot does:
 * each file is a CREATE OR ALTER, so afterwards what is in the database is
 * what is in the repository.
 *
 * None of them writes the customer's databases. usp_Debtors_SaveContact
 * writes agora.DebtorContact; the rest read.
 */
return new class extends ProcedureMigration {};
