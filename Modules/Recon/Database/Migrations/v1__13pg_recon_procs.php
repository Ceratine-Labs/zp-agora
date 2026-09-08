<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys Recon's stored procedures (slot 13pg).
 *
 * Carries the fix to agora.usp_Recon_Commit's re-check. It re-drilled the bank
 * side with @IncludeReconciled = 1 — correct, so a genuine loss can be named —
 * and then blocked a batch on ANY reconciled row that came back, including
 * lines that merely share the key and the window and were never part of it.
 *
 * Run 38 on branch 18 skipped 127 of 130 batches on 7 September 2026 for that
 * reason, and the reason was false: re-drilling the same batches with
 *
 * @IncludeReconciled = 0 reproduced the preview's line count and its total to
 * the cent, with nothing claimed. 112 of the 127 balance and should have gone
 * through; the other 15 are genuinely claimed elsewhere.
 *
 * Like every other lettered `p` slot it deploys the whole directory, so what
 * is in the database is what is in the repository.
 */
return new class extends ProcedureMigration {};
