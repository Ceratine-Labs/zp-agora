<?php

use App\Support\Database\ProcedureMigration;

/**
 * Deploys Debtors' stored procedures (slot 18p).
 *
 * Everything about how that is done lives in ProcedureMigration: every .sql in
 * Modules/Debtors/Database/Procedures is sent as its own batch, each a
 * CREATE OR ALTER in the agora schema.
 *
 * None of them writes the customer's databases. usp_Debtors_SaveContact
 * writes agora.DebtorContact; the rest read.
 */
return new class extends ProcedureMigration {};
