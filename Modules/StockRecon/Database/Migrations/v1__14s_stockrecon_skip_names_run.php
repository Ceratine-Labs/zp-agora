<?php

use App\Support\Database\ProcedureMigration;

/**
 * Stock recon — a shift skipped because another run still holds an amendment
 * on it now names that run (slot 14s).
 *
 * Pongola Convenience, 13 Nov 2026 ("do not want to amend", run #70461): 18
 * shifts were skipped with "This shift already carries an amendment from
 * another run. Reverse that first." and nothing said which run. The reason now
 * reads "... from run #N. Reverse this item's chain on that run first.", the
 * single-chain reversal live since 13 Nov. Message text only: what is written,
 * skipped or refused is unchanged.
 */
return new class extends ProcedureMigration
{
    protected function files(): array
    {
        return [$this->path().'/usp_StockRecon_Commit.sql'];
    }
};
