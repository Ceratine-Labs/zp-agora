<?php

use App\Support\Database\ProcedureMigration;

/**
 * Recon — the five AUTO RECON preview procedures (slot 13p).
 *
 * Ported from ZP's own `dbo.sp_RPT_AUTORecon*Preview` on PumpIT. The logic is
 * theirs, validated against their data; what changed is where it lives and
 * how it reaches the tables. Each file's header records which procedure it
 * came from and what moved.
 *
 * They are deployed by CREATE OR ALTER, so this migration is safe to re-run
 * and a change shows up in the diff as an edit to the body.
 */
return new class extends ProcedureMigration {};
