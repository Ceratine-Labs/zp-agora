<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys Core's stored procedures (slot 01pa).
 *
 * v1__01p has already run on every instance that exists, and a migration that
 * has run does not run again — so a procedure added after it needs a migration
 * of its own. That is what the lettered `p` slots in CLAUDE.md are for, and
 * this is the first of them: it carries usp_Core_MigrateUsers and
 * usp_Core_LogActivity.
 *
 * It deploys the WHOLE Procedures directory rather than the two new files.
 * Every file is a CREATE OR ALTER, so re-sending the other two costs a
 * millisecond and buys a real property: after any lettered procedure
 * migration, what is in the database is what is in the repository. Deploying a
 * hand-listed subset is how a procedure edited in a pull request ends up never
 * reaching an instance that was already past its original `p` slot.
 */
return new class extends ProcedureMigration {};
