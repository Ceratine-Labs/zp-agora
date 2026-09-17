<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stock recon — the run-line view stops multiplying its own rows (slot 14d).
 *
 * WHAT WENT WRONG IN 14b. The view resolves six labels by joining
 * agora.vw_StockMaster ON (BranchId, StockItemNo). That is not the master's
 * key. STK_StockMaster is keyed by branch AND Location — the same StockItemNo
 * exists once under WINBRANCH and once under AURA at a site running both POS
 * families — and it carries AreaNo besides. A label lookup on two thirds of a
 * key does not label the row, it multiplies it.
 *
 * Ryan, 11 Sep 2026, on the balancing preview: "i think we may have a
 * duplicate entry". The screen he was looking at turned out to be one chain
 * opened from both of its shift rows, which is the panel working as designed —
 * but the join underneath it was real, and it is worse than a display fault.
 * It fans in TWO independent places that compound:
 *
 *   stage 8 of usp_StockRecon_PreviewBalancing   x2, on an INSERT
 *   this view                                    x2, on every read
 *
 * Measured on the local stub with one extra master row: a 15-shift chain
 * became 30 rows in agora.StockReconRunLine and 60 on the proposals grid,
 * while the run header — counted before the join — still said 25. A live
 * commit of that run then reported "30 shift(s) amended" for 15 shifts and
 * left TWO active agora.StockReconAmendment rows on every one of them, which
 * is precisely the state usp_StockRecon_Commit refuses when it arrives from
 * another run: two "prior" pairs and no way to know which a reversal should
 * put back. Its guard runs once, before the transaction, so it cannot see its
 * own twin.
 *
 * THE FIX IS OUTER APPLY, NOT A WIDER JOIN (Ryan's call, same day). A join on
 * (BranchId, StockItemNo, AreaNo) would be cleaner and would let a genuine
 * duplicate surface instead of being swallowed — but it stays correct only for
 * as long as that triple stays unique in a database nobody here controls.
 * TOP 1 cannot fan whatever the master turns out to hold. It is safe because
 * the two rows describe the SAME product ("same product, two POS families"),
 * so the labels are interchangeable; the ORDER BY still prefers the master row
 * for the counting area the line belongs to, and falls back to any.
 *
 * Pinned by tests/Feature/StockRecon/BalancingTest.php
 * ::test_a_second_master_row_does_not_duplicate_the_shift.
 *
 * vw_StockReconShiftEmployee is unchanged and is not redefined here; 14b
 * remains its only definition. Columns are ENUMERATED for the reason 14b gives
 * — a view over a star binds its column list at creation and does not notice a
 * later ALTER TABLE.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = config('agora.schema');

        DB::unprepared("CREATE OR ALTER VIEW [{$schema}].[vw_StockReconRunLine] AS".$this->view($schema).';');
    }

    /** Local sandbox only. Live and staging are forward-only — see CLAUDE.md. */
    public function down(): void
    {
        // No-op by design. Reinstating the fanning definition would be a
        // rollback into a known data fault.
    }

    private function view(string $schema): string
    {
        return "
            SELECT rl.BranchId,
                   rl.Id,
                   rl.RunId,
                   rl.[LineNo],
                   rl.AreaNo,
                   rl.StockItemNo,
                   rl.TransactionDate,
                   rl.ShiftNo,
                   rl.SellPrice,
                   rl.QtyOpen,
                   rl.QtyIssued,
                   rl.QtyClose,
                   rl.QtyPOS,
                   rl.QtyVar,
                   rl.QtyOpenNew,
                   rl.QtyCloseNew,
                   rl.QtyVarNew,
                   rl.AmendClose,
                   rl.IsDormant,
                   rl.ActiveSeq,
                   rl.ActiveLen,
                   rl.ChainNetVar,
                   rl.FlagNetOver,
                   rl.FlagSoldMoreThanOnHand,
                   rl.FlagCloseExceedsOnHand,
                   rl.FlagIssueWentNowhere,
                   rl.FlagBigAmendment,
                   rl.FlagPctAmendment,
                   rl.FlagNegativeClose,
                   rl.FlagShortChain,
                   rl.FlagDormantMoved,
                   rl.FlagChainBroken,
                   rl.ChainBlocked,
                   rl.ExceptionCode,
                   rl.Outcome,
                   rl.WouldAmend,
                   rl.Selected,
                   rl.CommitState,
                   rl.BlockReason,
                   rl.CreatedAt,
                   rl.CreatedBy,
                   rl.UpdatedAt,
                   rl.UpdatedBy,
                   /* Stored first, the estate second, and the caller's own
                      last resort after that — itemLabel() still falls back
                      to the number if the master has no row either. */
                   ISNULL(rl.ItemDescription, m.StockItemDescription) AS ItemDescription,
                   ISNULL(rl.POSCode,         m.POSCode)              AS POSCode,
                   ISNULL(rl.StockLocation,   m.StockLocation)        AS StockLocation,
                   ISNULL(rl.AreaDescription, a.AreaDescription)      AS AreaDescription,
                   m.UOMCode,
                   /* The employee columns move TOGETHER or not at all.
                      EmployeeCount is NOT NULL DEFAULT 0, so it cannot say
                      on its own whether the run recorded anything —
                      EmployeeCodes is what distinguishes a run made before
                      14a (NULL, so resolve) from a shift nobody was signed
                      on to (recorded, count 0, and it must stay 0). */
                   CASE WHEN rl.EmployeeCodes IS NOT NULL THEN rl.EmployeeCodes
                        ELSE se.EmployeeCodes END                     AS EmployeeCodes,
                   CASE WHEN rl.EmployeeCodes IS NOT NULL THEN rl.EmployeeNames
                        ELSE se.EmployeeNames END                     AS EmployeeNames,
                   CASE WHEN rl.EmployeeCodes IS NOT NULL THEN rl.EmployeeCount
                        ELSE ISNULL(se.EmployeeCount, 0) END          AS EmployeeCount
            FROM [{$schema}].StockReconRunLine rl
            /* ONE master row per line, always — see the class docblock. A
               LEFT JOIN here fans the grid; this cannot, whatever the
               customer's master holds. */
            OUTER APPLY (SELECT TOP 1 m.StockItemDescription, m.POSCode, m.StockLocation, m.UOMCode
                           FROM [{$schema}].vw_StockMaster m
                          WHERE m.BranchId = rl.BranchId AND m.StockItemNo = rl.StockItemNo
                          ORDER BY CASE WHEN m.AreaNo = rl.AreaNo THEN 0 ELSE 1 END, m.StockLocation) m
            LEFT JOIN [{$schema}].vw_StockArea a
                   ON a.BranchId = rl.BranchId AND a.AreaNo = rl.AreaNo
            LEFT JOIN [{$schema}].vw_StockReconShiftEmployee se
                   ON se.BranchId        = rl.BranchId
                  AND se.TransactionDate = rl.TransactionDate
                  AND se.ShiftNo         = rl.ShiftNo
                  AND se.AreaNo          = rl.AreaNo";
    }
};
