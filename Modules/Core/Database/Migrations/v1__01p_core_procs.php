<?php

use App\Support\Database\ProcedureMigration;

/**
 * Deploys Core's stored procedures (slot 01p).
 *
 * Everything about how that is done lives in ProcedureMigration, so this file
 * stays a declaration of intent rather than a copy of the mechanism. Add a
 * .sql file to Modules/Core/Database/Procedures and it is deployed on the next
 * migrate; `scripts/check-procs.sh` refuses one that is not a CREATE OR ALTER
 * in the agora schema, and so does the base class at run time.
 */
return new class extends ProcedureMigration {};
