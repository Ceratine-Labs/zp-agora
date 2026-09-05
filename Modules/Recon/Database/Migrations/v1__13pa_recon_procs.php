<?php

use App\Support\Database\ProcedureMigration;

/**
 * Recon — the proposal drill (slot 13pa).
 *
 * A new procedure needs its own migration: v1__13p already ran, and a
 * migration that has run does not run again when a file appears beside it.
 * The lettered follow-on is the same rule the tables obey.
 *
 * It redeploys the WHOLE Procedures directory rather than the one new file,
 * and that is intentional. Every file is a CREATE OR ALTER, so redeploying is
 * free and idempotent — and it means running the migrations leaves the
 * database holding exactly what the repository says it should, rather than the
 * union of whatever each historical migration happened to deploy.
 *
 * Adds agora.usp_Recon_DrillProposal: the constituent bank lines and deposit
 * rows behind one preview row, which the five ported previews cannot answer
 * because they return aggregates and their bodies are not ours to change.
 */
return new class extends ProcedureMigration {};
