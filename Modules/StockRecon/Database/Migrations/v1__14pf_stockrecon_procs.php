<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys StockRecon's stored procedures (slot 14pf).
 *
 * The balancing follows the client's weekly stock recon pack (ZRP, 23 Sep
 * 2026); docs/stock-recon.md → "The client's rules" says where each rule lands.
 *
 *   usp_StockRecon_PreviewBalancing  repairs every opening to the previous
 *                                    closing instead of blocking the chain, and
 *                                    pins the closing before a repair; blocks a
 *                                    whole-pack over (rule 5) and a chain with
 *                                    no till sales against it (E1); holds back
 *                                    days the recon has not finished (rule 10);
 *                                    records the pack's audit of the hand
 *                                    balancing already in PumpIT; proposes a
 *                                    row wherever its answer differs from what
 *                                    PumpIT holds now; puts a blocked chain
 *                                    carrying S7/S8/S11/S12/S13 back to the
 *                                    staff counts; withdraws any chain whose
 *                                    write would give the client a new flag
 *                                    (F3).
 *   usp_StockRecon_Commit            re-checks the live pair, and writes a
 *                                    blocked chain only to reverse a forbidden
 *                                    hand amendment.
 *   usp_StockRecon_GridExceptions    the new codes, the audit column, and the
 *                                    date and value filters it never applied.
 *   usp_StockRecon_DrillChain        the repair and the audit on each shift.
 *
 * Needs v1__14e (the columns) and v1__14f (agora.StockIssueMultiple).
 */
return new class extends ProcedureMigration {};
