<?php

use App\Support\Database\ProcedureMigration;

/**
 * Deploys Utilities' stored procedures (slot 16p).
 *
 * Everything about how that is done lives in ProcedureMigration: every .sql in
 * Modules/Utilities/Database/Procedures is sent as its own batch, each a
 * CREATE OR ALTER in the agora schema.
 *
 * ONE OF THEM WRITES THE CUSTOMER'S DATABASE: usp_Utilities_Save, into
 * PumpIT.dbo.BRN_UtilityTransaction, and only there. Deploying this migration
 * to the customer's instance is a deploy, and needs Ryan's go-ahead like any
 * other.
 */
return new class extends ProcedureMigration {};
