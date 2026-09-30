<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Recon — a cash bag names the cashier who dropped it (slot 13j).
 *
 * A lettered follow-on rather than an edit to v1__13, which has already run
 * against the customer's instance.
 *
 * WHY. The bank line settles a security COLLECTION — BRN_DropSafe_Collection,
 * referenced by its DBagNo — but accountability for the cash is per BAG, and a
 * bag belongs to the cashier who dropped it. `BRN_DropSafe.CashierCode` is that
 * person: over the 90 days to 27 Sep 2026, 1,044 of 1,044 bags have exactly one
 * drop-safe row and every code resolves against BRN_Employee. The shorts table,
 * BRN_DailyBankingEmployees, was measured and rejected as the source (34 of
 * 1,001 bags). Until now the view carried the bag and nothing about whose it
 * was, so every recon procedure that wanted the collection reference joined the
 * drop safe itself, and nothing could name a cashier at all.
 *
 * WHAT IS ADDED, and what each column means when it is NULL:
 *
 *   CollectionId  BRN_DropSafe.CollectionId. NULL: the bag has no drop-safe
 *                 row (3,438 bags, all before 6 Jun 2025, none in the last 90
 *                 days). 0: dropped and never collected — the legacy's own 0,
 *                 kept as it is because "not collected" and "no drop-safe row"
 *                 are different facts.
 *   DBagNo        The collection's reference, which is what the bank narrative
 *                 carries. NULL where there is no collection at this branch.
 *                 RAW, never trimmed: the preview procedures slice it by
 *                 position, and a trim would move every slice.
 *   CashierCode   BRN_DropSafe.CashierCode, trimmed. Trailing blanks exist on
 *                 live (AM261117 at branch 21) and SQL Server ignores them in
 *                 a comparison, but PHP does not — a split grouped in PHP would
 *                 otherwise see one cashier as two.
 *   CashierName   BRN_Employee as "Surname FirstName", the form Reports'
 *                 agora.vw_Employee already prints. NULL means the code did not
 *                 resolve at this branch — never an empty string, so an
 *                 unresolved cashier can be counted.
 *   DropSafeAmount  BRN_DropSafe.Amount off the same drop-safe row that names
 *                 the cashier: what was declared at the safe, beside
 *                 CashBagAmount, which is what the bag was banked as. They are
 *                 NOT the same figure — 416 of the 6,436 bags of the twelve
 *                 months to 27 Sep 2026 differ — and Ryan's call of 9 Sep 2026
 *                 is that the split allocates on CashBagAmount but SHOWS this
 *                 beside it, so an adjusted bag is seen rather than reconciled
 *                 away. NULL: no drop-safe row. (Added 27 Sep 2026, in place,
 *                 while this migration was still undeployed.)
 *
 * WHY OUTER APPLY TOP 1 AND NOT A JOIN. Every lookup here is on its table's
 * full, declared key, and on live each one is enforced:
 *
 *   BRN_DropSafe             PK_BRN_DropSafe      (SSBranchId, BagNo)
 *   BRN_DropSafe_Collection  PK_…_Collection      (CollectionId)
 *   BRN_Employee             aaaaaEmployee_PK     (SSBranchId, EmployeeCode)
 *
 * so on the customer's instance a plain LEFT JOIN could not fan either — and
 * TOP 1 never has a choice to make there, so it hides nothing. It is used
 * anyway because this view's contract is ONE ROW PER BAG, and that must not
 * depend on a constraint in a database nobody here controls, or on the local
 * stub, which declares none of them. The project's rule since the stock-master
 * fan-out of 11 Sep 2026: a label lookup must never be able to change a row
 * count. The ORDER BY in each only
 * matters where the key is not enforced; it is there so the choice is stable.
 *
 * THE BRANCH IS ON EVERY LOOKUP, including the collection, whose key alone
 * would be enough. That matches usp_Recon_PreviewCashBags and DrillMops, which
 * join the collection on (BranchId, CollectionId), so the DBagNo here is the
 * one they resolve. It costs five bags: branch 9's bags of 2–5 Jul 2025 point
 * at collections 2870 and 2883, which are filed under branch 15. They read as
 * uncollected here exactly as they do in the preview.
 *
 * Read-only. Every table is reached by three-part name in the customer's
 * PumpIT; nothing there is written, and the stamp procedures still write
 * BRN_DailyBankingCashBags directly, never through this view.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = config('agora.schema');
        $erp = config('agora.source_databases.erp');

        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_DailyBankingCashBags] AS
            SELECT b.DailyBankingCashBagID,
                   b.SSBranchId AS BranchId,
                   b.TransactionDate,
                   b.CashBagNo,
                   b.CashBagAmount,
                   b.ReconBatchNoPumpIT,
                   d.CollectionId,
                   c.DBagNo,
                   LTRIM(RTRIM(d.CashierCode)) AS CashierCode,
                   e.CashierName,
                   d.Amount AS DropSafeAmount
            FROM [{$erp}].dbo.BRN_DailyBankingCashBags b
            OUTER APPLY (SELECT TOP 1 x.CollectionId, x.CashierCode, x.Amount
                         FROM [{$erp}].dbo.BRN_DropSafe x
                         WHERE x.SSBranchId = b.SSBranchId
                           AND x.BagNo      = b.CashBagNo
                         ORDER BY x.CreateDateTime DESC, x.CollectionId DESC) d
            OUTER APPLY (SELECT TOP 1 x.DBagNo
                         FROM [{$erp}].dbo.BRN_DropSafe_Collection x
                         WHERE x.SSBranchId   = b.SSBranchId
                           AND x.CollectionId = d.CollectionId
                         ORDER BY x.CreateDateTime DESC) c
            /* The name is formed INSIDE the apply, so a code that does not
               resolve gives NULL rather than the blank that ISNULL would build
               out of two missing halves. */
            OUTER APPLY (SELECT TOP 1
                                LTRIM(RTRIM(ISNULL(x.Surname, '') + ' ' + ISNULL(x.FirstName, ''))) AS CashierName
                         FROM [{$erp}].dbo.BRN_Employee x
                         WHERE x.SSBranchId   = b.SSBranchId
                           AND x.EmployeeCode = d.CashierCode
                         ORDER BY x.IsActive DESC, x.Surname, x.FirstName) e;
        ");

        MigrationHelper::recordVersion(
            '1.11',
            'Recon: agora.vw_DailyBankingCashBags names the bag\'s cashier (BRN_DropSafe.CashierCode, '
            .'resolved against BRN_Employee), the amount declared at the drop safe, and its collection '
            .'reference (DBagNo), one row per bag.'
        );
    }

    /**
     * Live and staging are forward-only; this runs against the local sandbox
     * only. It puts back the shape v1__13 created.
     */
    public function down(): void
    {
        $schema = config('agora.schema');
        $erp = config('agora.source_databases.erp');

        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_DailyBankingCashBags] AS
            SELECT b.DailyBankingCashBagID,
                   b.SSBranchId AS BranchId,
                   b.TransactionDate,
                   b.CashBagNo,
                   b.CashBagAmount,
                   b.ReconBatchNoPumpIT
            FROM [{$erp}].dbo.BRN_DailyBankingCashBags b;
        ");
    }
};
