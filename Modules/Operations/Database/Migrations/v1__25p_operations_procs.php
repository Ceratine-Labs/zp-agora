<?php

use App\Support\Database\ProcedureMigration;

/**
 * Operations Wall: bounded, read-only snapshots over existing Agora views.
 * No customer tables or existing procedures are changed.
 */
return new class extends ProcedureMigration {};
