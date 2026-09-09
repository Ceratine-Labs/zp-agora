<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stock recon — one definition of a resolved line, for every reader (slot 14b).
 *
 * WHAT WENT WRONG IN 14a. The labels were stored on the run line at preview
 * time and every reader was left to fall back on its own. Three of them did it
 * differently and one of them not at all, so on 9 September 2026 Ryan opened a
 * run made before 14a and saw:
 *
 *   - proposals   `10` over `area 1` — the Eloquent screen read the stored
 *                 column and nothing else;
 *   - the chain   the HEADER named the product, because DrillChain's header
 *                 block joins the master, while every row under it said `—`
 *                 for ON SHIFT, because the row block does not;
 *   - exceptions  the product name, because it is the one reader that wrote
 *                 ISNULL(stored, join) — but a blank employee, because even
 *                 there the employee was read stored-only.
 *
 * His words: "why does it show 10 instead of the product? but exceptions...
 * shows the product?" One row, three answers, and the difference was which
 * reader you happened to be looking at.
 *
 * THE FIX IS NOT A FOURTH FALLBACK. It is one place that resolves, which
 * everything reads:
 *
 *   vw_StockReconShiftEmployee   who was signed on to an area for a shift,
 *                                aggregated at exactly the grain a recon line
 *                                is — the ONLY definition of that in Agora.
 *   vw_StockReconRunLine         the run line with its six labels resolved,
 *                                stored first and the estate second.
 *
 * STORED STILL WINS, which is the whole point of 14a and is unchanged: a run is
 * a record of what was true when it was made, so a renamed item or a departed
 * employee does not rewrite history. The join is what a run that recorded
 * nothing falls back to, instead of showing an id.
 *
 * THE VIEW DOES NOT FILTER. A view that drops rows is a view that makes a total
 * unexplainable — the same rule the reports views are built on.
 *
 * Columns are ENUMERATED, never SELECT *: a view over a star binds its column
 * list at creation and does not notice a later ALTER TABLE.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = config('agora.schema');

        foreach ($this->views($schema) as $name => $body) {
            DB::unprepared("CREATE OR ALTER VIEW [{$schema}].[{$name}] AS".$body.';');
        }
    }

    /** Local sandbox only. Live and staging are forward-only — see CLAUDE.md. */
    public function down(): void
    {
        $schema = config('agora.schema');

        DB::statement("DROP VIEW IF EXISTS [{$schema}].[vw_StockReconRunLine];");
        DB::statement("DROP VIEW IF EXISTS [{$schema}].[vw_StockReconShiftEmployee];");
    }

    /** @return array<string, string> */
    private function views(string $schema): array
    {
        return [
            /*
             * Who was on a shift, as one row per (branch, date, shift, area).
             *
             * THE EMPLOYEE IS A LIST. 90 of branch 18's 1,138 shifts have more
             * than one person signed on, up to three. A short on a shift two
             * people worked cannot be attributed to either of them, so the
             * count travels beside the names and every screen says so.
             * Collapsing it to one name would manufacture an accountability
             * the data does not support.
             *
             * The display name is computed in a derived table and only then
             * aggregated, and that is a requirement rather than a style. SQL
             * Server refuses "multiple ordered aggregate functions in the same
             * scope with mutually incompatible orderings", and it counts two
             * WITHIN GROUP clauses as incompatible even when the ORDER BY
             * expressions are character-for-character identical, as long as
             * they are expressions rather than columns.
             *
             * Ordering both by the name is also what makes the pair readable:
             * the nth code is the nth name, which is the only reason to carry
             * both on a shared shift.
             */
            'vw_StockReconShiftEmployee' => "
                SELECT d.BranchId,
                       d.TransactionDate,
                       d.ShiftNo,
                       d.AreaNo,
                       COUNT(*)                                                     AS EmployeeCount,
                       /* Capped at the columns they are stored in. Three people
                          never approach 400 characters, and a data fault that
                          put thirty on one shift must not fail a whole read. */
                       LEFT(STRING_AGG(CONVERT(nvarchar(max), d.EmployeeCode), ', ')
                            WITHIN GROUP (ORDER BY d.DisplayName), 200)             AS EmployeeCodes,
                       LEFT(STRING_AGG(CONVERT(nvarchar(max), d.DisplayName), ', ')
                            WITHIN GROUP (ORDER BY d.DisplayName), 400)             AS EmployeeNames
                FROM (
                    SELECT se.BranchId,
                           se.TransactionDate,
                           se.ShiftNo,
                           se.AreaNo,
                           se.EmployeeCode,
                           /* An unresolved code shows as the code. Every one of
                              branch 18's 45 resolves, but a code with no master
                              row is a finding and must not read as a blank. */
                           ISNULL(NULLIF(LTRIM(RTRIM(e.EmployeeName)), ''), se.EmployeeCode) AS DisplayName
                    FROM [{$schema}].vw_StockReconEmployee se
                    LEFT JOIN [{$schema}].vw_Employee e
                           ON e.BranchId = se.BranchId AND e.EmployeeCode = se.EmployeeCode
                ) d
                GROUP BY d.BranchId, d.TransactionDate, d.ShiftNo, d.AreaNo",

            /*
             * The run line, with its labels resolved. Read by the proposals
             * screen, its extract, and anything else in PHP that reads a line.
             *
             * WRITES STILL GO TO THE TABLE. This view joins, so SQL Server
             * cannot update through it — StockReconService::select() names
             * agora.StockReconRunLine directly and says why.
             */
            'vw_StockReconRunLine' => "
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
                LEFT JOIN [{$schema}].vw_StockMaster m
                       ON m.BranchId = rl.BranchId AND m.StockItemNo = rl.StockItemNo
                LEFT JOIN [{$schema}].vw_StockArea a
                       ON a.BranchId = rl.BranchId AND a.AreaNo = rl.AreaNo
                LEFT JOIN [{$schema}].vw_StockReconShiftEmployee se
                       ON se.BranchId        = rl.BranchId
                      AND se.TransactionDate = rl.TransactionDate
                      AND se.ShiftNo         = rl.ShiftNo
                      AND se.AreaNo          = rl.AreaNo",
        ];
    }
};
