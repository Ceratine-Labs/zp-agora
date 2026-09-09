<?php

use App\Support\Database\ProcedureMigration;

/**
 * Stock recon — one outcome given the label the other fourteen already had (14pc).
 *
 * v1__14pb has run against the customer's instance and will not run again, so a
 * change to a procedure body needs a new lettered file. This redeploys the whole
 * directory, which is safe because every file is a single CREATE OR ALTER.
 *
 * PreviewBalancing is the only body that moved. The outcome vocabulary is
 * "Label: explanation" — the customer reads that column in SSMS, so a bare
 * label there would send them to the procedure to find out what it meant. The
 * screen wants the opposite, and now takes the label and hovers the rest
 * (StockReconRunLine::outcomeLabel()).
 *
 * "Opening follows the amended closing before it" was the one outcome written
 * without a label, so it had no short form and rendered its whole 45-character
 * sentence in a status pill — which is how the Outcome column came to claim
 * 327px of a 1546px table. It is now "Opening only: follows the amended closing
 * before it". The wording is otherwise unchanged and nothing matches on it:
 * outcomeKey() reads the stored flags, not the string.
 */
return new class extends ProcedureMigration {};
