<?php

use App\Support\Database\ProcedureMigration;

/**
 * Recon — the execute path (slot 13pc).
 *
 * Adds usp_Recon_DrillBank and usp_Recon_DrillMops (the split of the combined
 * drill, so the commit can capture both sides — `INSERT INTO @t EXEC` takes
 * only the first result set), usp_Recon_Commit and usp_Recon_Reverse.
 *
 * usp_Recon_Commit is the only procedure in Agora that writes to the
 * customer's estate. Read its header before changing anything in it.
 */
return new class extends ProcedureMigration {};
