<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys Core's stored procedures (slot 01pb).
 *
 * 01p and 01pa have both run everywhere and will not run again, so a change to
 * a procedure needs a slot of its own. This one carries usp_Core_MigrateUsers
 * selecting BranchGrantCount, so the migration report shows the evidence behind
 * its own head-office / branch classification instead of asking the reader to
 * take it on trust — and so the same report makes the old, wrong derivation
 * visible if it ever comes back.
 *
 * Like 01pa it deploys the WHOLE directory, for the reason written there:
 * after any lettered procedure migration, what is in the database is what is in
 * the repository.
 */
return new class extends ProcedureMigration {};
