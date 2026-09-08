<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys Recon's stored procedures (slot 13pn).
 *
 * Carries agora.usp_Recon_GetSides — both sides of an area laid out for a
 * person to pair by hand — and agora.usp_Recon_ManualMatch, which puts that
 * pairing through the same ledger an automatic one goes through.
 */
return new class extends ProcedureMigration {};
