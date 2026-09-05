<?php

use App\Support\Database\ProcedureMigration;

/**
 * Reports — the fifteen Today-menu grid procedures (slot 22p).
 *
 * One procedure per report on the Today menu, each taking the same eight
 * parameters and returning the same two result sets, so the grid component can
 * call any of them without knowing which. What differs between them is the
 * question, and every one of those is answered in the .sql file's header —
 * including the four where the obvious column in PumpIT is the wrong one and
 * the report would have been silently empty or silently wrong.
 *
 * They read the customer's estate through agora.vw_*, deployed by
 * v1__22_reports_views.php, and they write nothing anywhere.
 *
 * Deployed by CREATE OR ALTER, so re-running is safe and a change shows up in
 * the diff as an edit to the body rather than a drop and a recreate.
 */
return new class extends ProcedureMigration {};
