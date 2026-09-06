<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys Core's stored procedures (slot 01pc).
 *
 * Carries agora.usp_Core_GridUsers, the procedure behind Setup → Users and
 * access. Like every other lettered `p` slot it deploys the whole directory,
 * so what is in the database is what is in the repository.
 */
return new class extends ProcedureMigration {};
