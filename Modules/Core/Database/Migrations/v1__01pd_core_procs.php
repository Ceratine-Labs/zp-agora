<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys Core's stored procedures (slot 01pd).
 *
 * Carries the fix to agora.usp_Core_GridUsers: it read agora.[User] with no
 * `DeletedAt IS NULL`, so a soft-deleted person stayed on Setup → Users and
 * access forever. Eloquent applies that filter through the SoftDeletes trait
 * and a procedure has no trait to apply it — which is exactly why it has to be
 * written down in the SQL.
 *
 * Like every other lettered `p` slot it deploys the whole directory, so what
 * is in the database is what is in the repository.
 */
return new class extends ProcedureMigration {};
