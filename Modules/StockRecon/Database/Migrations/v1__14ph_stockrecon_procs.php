<?php

use App\Support\Database\ProcedureMigration;

/**
 * Re-deploys StockRecon's stored procedures (slot 14ph).
 *
 *   usp_StockRecon_PreviewBalancing  duplicate issues: a clear one (D1) is
 *                                    removed from the balancing basis — its
 *                                    issue reads 0 — and proposed for the
 *                                    commit; a possible one (D2) is listed with
 *                                    its twin and the reason it is not clear.
 *                                    Prepared-food areas are never D1.
 *                                    Switched by @RemoveDuplicates
 *                                    (config('stockrecon.issue_mode')).
 *   usp_StockRecon_Commit            writes QtyIssued = 0 on a D1 line, the
 *                                    prior kept on agora.StockReconAmendment;
 *                                    re-checks the issue; writes a chain that
 *                                    removed a duplicate only with it.
 *   usp_StockRecon_Reverse           puts the issue back, and refuses
 *                                    (ISSUE_MOVED) if the row changed since.
 *   usp_StockRecon_GridExceptions    ranks and values D1 and D2.
 *   usp_StockRecon_DrillChain        the duplicate and its twin, per shift.
 *
 * New named write (STK_StockReconLine.QtyIssued, D1 only): Ryan's go-ahead,
 * 1 Oct 2026, PM decision 01a0f60b-0459. Needs v1__14h (the columns,
 * agora.StockAreaSetting and agora.vw_StockAreaFood).
 */
return new class extends ProcedureMigration {};
