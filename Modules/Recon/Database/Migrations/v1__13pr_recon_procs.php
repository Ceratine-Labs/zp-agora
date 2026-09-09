<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys Recon's stored procedures (slot 13pr).
 *
 * agora.usp_Recon_DiscardRuns now protects any run that has had something
 * PROCESSED against it, not merely one whose status reads 'committed'.
 *
 * Ryan, 9 Sep 2026: "if anything is processed against a run we can't close it,
 * else the ladies can remove it, but if anything processed then no." A
 * REVERSED run walked through the old guard — it stamped rows and then
 * unstamped them, so something was unarguably processed against it, and it is
 * the only record that both halves happened. Because this procedure deletes
 * ReconRun and ReconRunLine and nothing else, sweeping one also left its
 * ReconBatch, ReconMatch and ReconStamp rows behind pointing at a RunId that
 * no longer existed. Local container before the fix: runs 205 and 206, both
 * reversed, both swept, seven orphaned evidence rows each.
 */
return new class extends ProcedureMigration {};
