<?php

use App\Support\Database\ProcedureMigration;

/**
 * Stock recon — the procedures, redeployed for the labels (slot 14pa).
 *
 * v1__14p has already run against the customer's instance and will not run
 * again, so a change to any procedure body needs a new lettered file. This one
 * redeploys the whole directory, which is safe because every file is a single
 * CREATE OR ALTER: a procedure whose body has not changed is rewritten to
 * itself.
 *
 * What actually moved:
 *
 *   PreviewBalancing   resolves the item description, POS code, location, area
 *                      name and the shift's employees ONCE, at preview time,
 *                      onto the run line — see v1__14a for why they are stored
 *                      rather than joined.
 *   DrillChain         returns the employee on every shift of the chain, which
 *                      is what Ryan asked for: the detail breakdown says who
 *                      was on.
 *   GridExceptions     reads the stored label and falls back to the join, so a
 *                      run made before v1__14a still shows an item name.
 */
return new class extends ProcedureMigration {};
