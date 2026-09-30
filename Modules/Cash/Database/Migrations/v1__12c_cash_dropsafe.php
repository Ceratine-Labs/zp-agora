<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Cash — the drop safe (slot 12c, T039).
 *
 * A BAG is cash a cashier could not put through the electronic safe — coins,
 * a safe out of order, a note it rejected — sealed in a numbered bag, dropped
 * into the manual drop safe with a manager watching, and later handed to the
 * security company. PumpIT keeps each bag as one BRN_DropSafe row and each
 * hand-over as one BRN_DropSafe_Collection row; a bag is COLLECTED when its
 * CollectionId names the collection (0 until then). That is the whole of
 * "collected" in the data: there is no flag beside it.
 *
 * THE LEGACY SHAPE, measured on the customer's instance on 27 Sep 2026:
 *
 *   BRN_DropSafe             33,979 bags; PK (SSBranchId, BagNo). BagNo is the
 *                            number printed on the bag, typed in — nothing in
 *                            PumpIT generates it. ManagerCode is NOT NULL and
 *                            set on every bag; a bag over R2,000 carries a
 *                            reason in ReasonIfAmountMoreThanR2000 (every one
 *                            but one since October 2023). Blank reasons and
 *                            fault references are stored as '' — never NULL.
 *   BRN_DropSafe_Collection  4,450 collections; CollectionId is an IDENTITY.
 *                            DBagNo is the security company's bag, which the
 *                            bank statement quotes — recon matches on it.
 *   No SS_UniqueNumber section numbers either: BagNo is typed and CollectionId
 *   is the table's own IDENTITY.
 *
 * WHAT IS READ, AND THROUGH WHICH VIEWS. The Reports module already publishes
 * the bag, the collection and the manual-bag reason (agora.vw_DropSafeBag,
 * agora.vw_DropSafeCollectionHeader, agora.vw_DropSafeReason, v1__22) and Recon
 * a narrower pair (agora.vw_DropSafe, agora.vw_DropSafeCollection, v1__13). Cash
 * reads the Reports pair — every column it needs is there — and adds no third.
 * The one table nobody publishes is the list of reasons offered for a bag over
 * R2,000, and that is the one view made here.
 *
 * WHAT THIS MIGRATION MAKES, all in Agora's own schema:
 *
 *   agora.DropSafeEvent          every press on the drop safe, never updated and
 *                                never deleted: who (Agora's user), when, and
 *                                exactly what was written to PumpIT, with the
 *                                values an edit replaced and the whole row a
 *                                void deleted. It is THE record of a drop-safe
 *                                press: the PumpIT client's MIST_Audit.dbo.ActionLog
 *                                line is not written (Ryan, 27 Sep 2026).
 *   agora.vw_DropSafeLimitReason BRN_DropSafe_ReasonAmountMoreThanR2000 — the
 *                                reasons the PumpIT drop screen offers for a
 *                                bag over R2,000. Group-wide, so the group
 *                                entity's branch id.
 *
 * It writes nothing to the customer's databases. agora.usp_Cash_SaveDropSafe
 * and agora.usp_Cash_CollectDropSafe do, and their headers list exactly what.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = config('agora.schema');
        $erp = (string) config('agora.source_databases.erp');
        $group = (int) config('agora.group_branch_id', 2);

        /*
         * Every press, in order. Never updated, never deleted.
         *
         *   dropped    a bag was INSERTed into BRN_DropSafe
         *   edited     a bag not yet collected and not on a cashup was
         *              UPDATEd — its number included, which is how a mistyped
         *              bag number is put right (PumpIT deleted and recaptured;
         *              Agora deletes nothing). PriorBagNo is the number it had.
         *   collected  a BRN_DropSafe_Collection row was INSERTed and its bags'
         *              CollectionId UPDATEd from 0 to the new id
         *   voided     a bag captured in error was DELETEd from BRN_DropSafe —
         *              only while in the safe and on no cashup (Ryan's named
         *              go-ahead, 27 Sep 2026, for this delete only). The WHOLE
         *              row, every column, is in WritesJson as `prior`, written
         *              before the delete in the same transaction; Note holds
         *              the reason, which is required.
         *
         * BagNo is the bag's number after the press (NULL on a collection,
         * whose bags are listed in WritesJson). WritesJson is the PumpIT side:
         *   {"rows": [{"action": "insert|update|delete", "table": "BRN_DropSafe",
         *              "key": {...}, "prior": {...}, "new": {...}}, ...],
         *    "collection": {"action": "insert", "table": "BRN_DropSafe_Collection",
         *                   "key": {"CollectionId": N}, "new": {...}}}
         */
        MigrationHelper::table('DropSafeEvent', function (Blueprint $table) {
            $table->string('Event', 20);
            $table->string('BagNo', 20)->nullable();
            $table->string('PriorBagNo', 20)->nullable();
            $table->bigInteger('CollectionId')->nullable();
            $table->string('DBagNo', 20)->nullable();
            $table->integer('Bags')->nullable();
            MigrationHelper::money($table, 'Amount', nullable: true);

            $table->string('WriteMode', 10)->nullable();
            $table->uuid('SaveRef')->nullable();
            $table->text('WritesJson')->nullable();
            $table->string('Note', 400)->nullable();
        });

        foreach ([
            "CREATE INDEX [IX_DropSafeEvent_Bag] ON [{$schema}].[DropSafeEvent] ([BranchId], [BagNo], [Id]);",
            "CREATE INDEX [IX_DropSafeEvent_Collection] ON [{$schema}].[DropSafeEvent] ([BranchId], [CollectionId]);",
            "ALTER TABLE [{$schema}].[DropSafeEvent] ADD CONSTRAINT [CK_DropSafeEvent_Event]
                CHECK ([Event] IN ('dropped', 'edited', 'collected', 'voided'));",
            "ALTER TABLE [{$schema}].[DropSafeEvent] ADD CONSTRAINT [CK_DropSafeEvent_WriteMode]
                CHECK ([WriteMode] IS NULL OR [WriteMode] = 'live');",
            "ALTER TABLE [{$schema}].[DropSafeEvent] ADD CONSTRAINT [CK_DropSafeEvent_WritesJson]
                CHECK ([WritesJson] IS NULL OR ISJSON([WritesJson]) = 1);",
        ] as $statement) {
            DB::statement($statement);
        }

        /*
         * The reasons offered for a bag over R2,000 — four on the customer's
         * instance. A suggestion, not a code: BRN_DropSafe keeps the reason as
         * text (ReasonIfAmountMoreThanR2000), and 132 different texts are in
         * it, so the capture accepts what is typed as well as what is picked.
         */
        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_DropSafeLimitReason] AS
            SELECT CONVERT(int, {$group})                   AS BranchId,
                   r.ReasonAmountMoreThanR2000Id            AS LimitReasonId,
                   LTRIM(RTRIM(r.ReasonAmountMoreThanR2000)) AS LimitReason,
                   CONVERT(bit, r.IsActive)                 AS IsActive
            FROM [{$erp}].dbo.BRN_DropSafe_ReasonAmountMoreThanR2000 r;
        ");

        MigrationHelper::recordVersion(
            '1.19',
            'Cash: drop safe — agora.DropSafeEvent keeps every drop, edit and collection and exactly what it wrote to '
            .'PumpIT (BRN_DropSafe, BRN_DropSafe_Collection), and agora.vw_DropSafeLimitReason reads the reasons '
            .'offered for a bag over R2,000.'
        );
    }

    /**
     * Live and staging are forward-only; this runs against the local sandbox
     * only. Never rely on it to undo something in production.
     */
    public function down(): void
    {
        DB::unprepared('DROP VIEW IF EXISTS ['.config('agora.schema').'].[vw_DropSafeLimitReason];');
        MigrationHelper::drop('DropSafeEvent');
    }
};
