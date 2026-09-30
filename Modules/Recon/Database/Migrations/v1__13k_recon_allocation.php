<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Recon — the split by cashier a cash-bag match confirmed (slot 13k).
 *
 * A lettered follow-on rather than an edit to v1__13a, which has already run
 * against the customer's instance. Agora's own database only: nothing in
 * PumpIT is created, altered or written by this migration.
 *
 * WHY. A cash bag belongs to the cashier who dropped it (v1__13j), and since
 * task 01a084fd-0674 usp_Recon_ManualMatch refuses a cash-bag match that does
 * not say who the cash is accounted to. It validated that split and threw it
 * away. This table keeps it, written by the same procedure inside the same
 * transaction as the batch, so a batch and the split it was confirmed with can
 * never exist one without the other. Task 01a084fd-07f8.
 *
 * ONE ROW PER CASHIER LINE of a confirmed split, never per bag:
 *
 *   CashierCode   BRN_DropSafe.CashierCode, trimmed. NULL is the line for the
 *                 bags that record no cashier — one per batch at most, which
 *                 the natural key enforces (SQL Server lets a unique index hold
 *                 one NULL). Ryan, 27 Sep 2026 (PM decision 01a0e375): that
 *                 line keeps its share and the match is not refused for it.
 *   CashierName   as the view resolved it; NULL where the code did not resolve.
 *   Bags          how many of the ticked bags are this cashier's.
 *   Banked        SUM(CashBagAmount) — what the split is on (Ryan's q1).
 *   DropSafe      SUM(BRN_DropSafe.Amount), shown beside it; NULL when no bag
 *                 on the line has a drop-safe row.
 *   FirstBagDate, LastBagDate  the span of this cashier's bags' trading dates,
 *                 which is what a short raised from the line is dated by.
 *   DerivedShare  the procedure's own pro-rata share of the variance (q2).
 *   Share         the share the clerk confirmed. BANK LESS BAGS, so NEGATIVE
 *                 IS MONEY MISSING: the bank received less than this cashier's
 *                 bags. Positive is an over.
 *   Accountable   Banked + Share — what the cashier is accountable for.
 *   Basis         'derived' where Share = DerivedShare, 'manual' where the
 *                 clerk changed it.
 *
 * THE SHORT, and the sign rule. Ryan, 9 Sep 2026: a confirmed allocation
 * raises a staff short, through agora.usp_Cash_RaiseStaffShort (task T040)
 * and never from Recon, so the counter, the approval trail and the posting
 * live in one place (PM decision 01a0e22a-9aa9, 27 Sep 2026). A short is due
 * ONLY for a NEGATIVE share on a line with a cashier; an over raises nothing,
 * and neither does a zero. ShortStatus says where each line stands:
 *
 *   pending     a short is due and has not been raised yet. THIS IS THE
 *               HAND-OFF TO T040: usp_Cash_RaiseStaffShort raises exactly these
 *               rows, for AmountShort = -Share (BRN_StaffShorts carries a
 *               positive amount: 1,983 of 1,983 shorts in the twelve months to
 *               27 Sep 2026), and sets ShortStatus = 'raised' and
 *               StaffShortsNo in its own transaction.
 *   raised      T040 raised it; StaffShortsNo is the short. Its approval,
 *               decline and posting live on BRN_StaffShorts, not here.
 *   none        a cashier, and nothing short (Share >= 0).
 *   no_cashier  the bags that record no cashier: nobody to raise a short to.
 *   journal     a short would be due, but the match was journalled — journal
 *               mode writes nothing to PumpIT, so none is ever raised.
 *   cancelled   was pending, and the match was reversed before the short was
 *               raised. Nothing is due any more.
 *
 * NEVER DELETED. A reversal marks the rows (ReversedAt, ReversedBy,
 * ReversalReason, as ReconBatch does) and keeps the numbers readable.
 *
 * The check constraints are the rule stated once, so T040 cannot write a row
 * this procedure would never have written.
 */
return new class extends Migration
{
    public function up(): void
    {
        MigrationHelper::table('ReconAllocation', function (Blueprint $table) {
            $table->unsignedBigInteger('RunId');
            $table->unsignedBigInteger('BatchId');
            // ReconBatch.BatchNo: the customer's batch number the match took
            // (negative in journal mode), carried so a line reads on its own.
            $table->integer('BatchNo');

            $table->string('CashierCode', 50)->nullable();
            $table->string('CashierName', 200)->nullable();
            $table->integer('Bags');
            MigrationHelper::money($table, 'Banked');
            MigrationHelper::money($table, 'DropSafe', true);
            $table->date('FirstBagDate');
            $table->date('LastBagDate');

            MigrationHelper::money($table, 'DerivedShare');
            MigrationHelper::money($table, 'Share');
            MigrationHelper::money($table, 'Accountable');
            $table->string('Basis', 10);

            $table->integer('ConfirmedBy')->nullable();
            $table->dateTime('ConfirmedAt', 0);

            $table->string('ShortStatus', 20);
            // BRN_StaffShorts.StaffShortsNo (INT) once T040 has raised it.
            $table->integer('StaffShortsNo')->nullable();

            $table->dateTime('ReversedAt', 0)->nullable();
            $table->integer('ReversedBy')->nullable();
            $table->string('ReversalReason', 300)->nullable();
        });

        // One line per cashier per batch, and one no-cashier line at most.
        MigrationHelper::naturalKey('ReconAllocation', ['BranchId', 'BatchId', 'CashierCode']);
        MigrationHelper::addRowVersion('ReconAllocation');

        $schema = config('agora.schema');

        foreach ([
            "CREATE INDEX [IX_ReconAllocation_Run] ON [{$schema}].[ReconAllocation] ([BranchId], [RunId], [BatchId]);",
            // T040's pickup, and "what has this cashier been accounted".
            "CREATE INDEX [IX_ReconAllocation_Short] ON [{$schema}].[ReconAllocation] ([BranchId], [ShortStatus]);",
            "CREATE INDEX [IX_ReconAllocation_Cashier] ON [{$schema}].[ReconAllocation] ([BranchId], [CashierCode]);",

            "ALTER TABLE [{$schema}].[ReconAllocation] ADD CONSTRAINT [CK_ReconAllocation_Basis]
                CHECK ([Basis] IN ('derived', 'manual'));",
            "ALTER TABLE [{$schema}].[ReconAllocation] ADD CONSTRAINT [CK_ReconAllocation_Accountable]
                CHECK ([Accountable] = [Banked] + [Share]);",
            // The sign rule and the vocabulary together: a short in any state
            // is only ever a negative share with a cashier to answer for it.
            "ALTER TABLE [{$schema}].[ReconAllocation] ADD CONSTRAINT [CK_ReconAllocation_ShortRule]
                CHECK (([ShortStatus] = 'none' AND [CashierCode] IS NOT NULL AND [Share] >= 0)
                    OR ([ShortStatus] = 'no_cashier' AND [CashierCode] IS NULL)
                    OR ([ShortStatus] IN ('pending', 'raised', 'journal', 'cancelled')
                        AND [CashierCode] IS NOT NULL AND [Share] < 0));",
            "ALTER TABLE [{$schema}].[ReconAllocation] ADD CONSTRAINT [CK_ReconAllocation_Raised]
                CHECK ([ShortStatus] <> 'raised' OR [StaffShortsNo] IS NOT NULL);",
        ] as $statement) {
            DB::statement($statement);
        }

        MigrationHelper::recordVersion(
            '1.17',
            'Recon: agora.ReconAllocation keeps the split by cashier a cash-bag match confirmed, '
            .'one row per cashier line, with the staff short each negative share is due (ShortStatus).'
        );
    }

    /**
     * Live and staging are forward-only; this runs against the local sandbox
     * only.
     */
    public function down(): void
    {
        MigrationHelper::drop('ReconAllocation');
    }
};
