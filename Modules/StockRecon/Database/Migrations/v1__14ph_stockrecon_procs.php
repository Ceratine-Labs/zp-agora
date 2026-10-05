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
 *
 * And, before it left this machine, a possible duplicate (D2) HOLDS ITS CHAIN
 * BACK (Ryan, 5 Oct 2026, PM decision 01a10b21). Needs v1__14i (DupHold,
 * DupReleaseId, agora.StockReconDuplicateRelease), which sorts before this.
 *
 *   usp_StockRecon_PreviewBalancing  a chain with an unreleased D2 is DupHold:
 *                                    blocked, and written nothing — not
 *                                    balanced, not put back to the staff
 *                                    counts (7d), no D1 on its own (7f).
 *   usp_StockRecon_Commit            skips a chain whose D2 has no release
 *                                    standing at the press.
 *   usp_StockRecon_ReleaseDuplicate  NEW. Releases a D2 with a reason.
 *   usp_StockRecon_WithdrawRelease   NEW. Takes a release back.
 *   usp_StockRecon_DrillChain        the hold and the release, per shift.
 *
 * No new write to PumpIT: a release writes Agora's ledger only.
 *
 * And the screen for both (Ryan, 1 Oct 2026, the duplicate-issues panel):
 *
 *   usp_StockRecon_GetDuplicates     NEW. Read-only. Every D1 and D2 on a run
 *                                    with its state, the shifts that prove it
 *                                    (twin, as keyed, corrected) and totals.
 */
return new class extends ProcedureMigration {};
