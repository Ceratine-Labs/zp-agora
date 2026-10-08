<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys StockRecon's stored procedures (slot 14pp), 8 Oct 2026: "too few
 * active shifts" no longer holds a chain back. With one shift that traded there
 * is nothing to move a variance onto, so the hold changed only the label — the
 * panel said "Held back" in red beside a notice saying the missing issue does
 * not hold it back (Ryan: "says held back but not held back ... which is it?").
 * The chain now reads as what it is: an over to capture, or a short that stays.
 * The rest redeploy unchanged.
 */
return new class extends ProcedureMigration {};
