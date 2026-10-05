<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys Recon's stored procedures (slot 13pza — the first three-letter
 * slot: 13pa to 13pz are all used; see scripts/check-migrations.sh).
 *
 *   usp_Recon_ManualMatch  an ABSA or FNB deposit is claimed by its merchant
 *                          number as well as its batch, date and amount, when
 *                          the screen sends it (`ref2`), and stamped on that
 *                          merchant's row. Task 01a0ce85.
 */
return new class extends ProcedureMigration {};
