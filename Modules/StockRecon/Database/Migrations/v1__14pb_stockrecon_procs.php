<?php

use App\Support\Database\ProcedureMigration;

/**
 * Stock recon — the procedures, redeployed so every reader resolves (slot 14pb).
 *
 * v1__14pa has already run against the customer's instance and will not run
 * again, so a change to any procedure body needs a new lettered file. This one
 * redeploys the whole directory, which is safe because every file is a single
 * CREATE OR ALTER: a procedure whose body has not changed is rewritten to
 * itself.
 *
 * What actually moved, all of it the same bug — 14a stored the labels and left
 * each reader to fall back on its own, so three readers disagreed about one row:
 *
 *   DrillChain       the header joined the master and named the product; the
 *                    ROWS under it read the stored employee and nothing else,
 *                    so a run made before 14a showed an em dash on every shift.
 *                    Both halves now resolve, and so does the "people on it"
 *                    count in the header, which otherwise claimed one person
 *                    for a chain six of them worked.
 *   GridExceptions   fell back to the join for the item and the area but not
 *                    for the employee — the one column the D1 class exists for.
 *   PreviewBalancing unchanged in what it writes; its employee aggregate now
 *                    reads agora.vw_StockReconShiftEmployee instead of spelling
 *                    the same STRING_AGG out inline. Two copies of "who was on
 *                    the shift" is what this whole file is repairing.
 *
 * The views these depend on are created in v1__14b, which runs first.
 */
return new class extends ProcedureMigration {};
