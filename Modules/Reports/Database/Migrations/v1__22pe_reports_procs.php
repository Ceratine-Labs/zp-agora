<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys Reports' stored procedures (slot 22pe), 2 Oct 2026, after the
 * audit trail's first live run took four minutes: usp_Reports_GridAuditTrail
 * now finds its page on the view's cheap columns and builds the text for those
 * rows only, unless a search or a text sort needs every row's text. Depends on
 * v1__22b (the view's index-friendly joins). The rest redeploy unchanged.
 */
return new class extends ProcedureMigration {};
