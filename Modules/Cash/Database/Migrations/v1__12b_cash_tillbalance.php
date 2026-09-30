<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Cash — till balance (slot 12b, T038).
 *
 * A TILL BALANCE, as PumpIT keeps it, moves part of one cashup's variance onto
 * another cashup: a short on one till, shift or day is set against an over on
 * another. It is two BRN_TillBalance rows under one number — the cashup that
 * gives up the amount (TransactionAmount negative) and the cashup that takes it
 * (positive) — so a pair always nets to nil. When an approver passes it in
 * PumpIT, dbo.sp_Insert_TillBalanceTransactions posts each row to its cashup's
 * EXPENSES leg under the 'Till Balance Expense' supplier, through a
 * system-generated purchase request, and the two variances move.
 *
 * THE LEGACY SHAPE, measured on the customer's instance on 27 Sep 2026:
 *
 *   BRN_TillBalance   20,804 rows, 10,413 numbers; a heap with a NONCLUSTERED
 *                     primary key (SSBranchId, TillNo, ShiftNo, TransactionDate,
 *                     LogFile, TillBalanceNo). 10,382 numbers are a pair that
 *                     nets to R0.00 — every number since mid-2024. On every row
 *                     of the last week: LogFile is the LOGFILE of the one Z-read
 *                     on the cashup, EmployeeCode the cashup's person,
 *                     OriginalEmployeeAmount the cashup's variance as the Daily
 *                     Banking grid computes it at capture, and EmployeeAmount =
 *                     OriginalEmployeeAmount + TransactionAmount (20,794 of
 *                     20,804 rows).
 *   SS_UniqueNumber   SectionId 3 hands out the number (next 10,420; 1,401 of
 *                     the 1,403 numbers used in 2026 follow each other).
 *
 * IT IS NOT A PUMP SHORTAGE. A cashup's "pump shortages" leg is
 * BRN_DailyBankingEmployees, fed by approved staff shorts (BRN_StaffShorts);
 * no till balance touches either. A till balance reaches the cashup through its
 * Expenses leg, and the deduction schedule through the cashier's short.
 *
 * WHAT THIS MIGRATION MAKES, all in Agora's own schema:
 *
 *   agora.TillBalanceEvent  every press on a till balance, never updated and
 *                           never deleted: who (Agora's user), when, and — for a
 *                           capture — exactly what was written to PumpIT, the
 *                           counter it took the number from included.
 *   agora.vw_TillBalance    BRN_TillBalance as Cash reads it, one row per side,
 *                           with where it stands (pending, approved, declined)
 *                           and whether Agora captured it.
 *
 * It writes nothing to the customer's databases. agora.usp_Cash_SaveTillBalance
 * does, and its header lists exactly what.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = config('agora.schema');
        $erp = (string) config('agora.source_databases.erp');

        /*
         * Every press, in order. Never updated, never deleted.
         *
         *   captured  the pair was INSERTed into BRN_TillBalance and the number
         *             taken from SS_UniqueNumber section 3
         *
         * Approval, decline and posting are PumpIT's until Ryan decides who
         * writes the purchase request they need; when Agora takes them on,
         * they are events here ('approved', 'declined', 'posted') and the CHECK
         * below widens in a lettered migration.
         *
         * WritesJson is the PumpIT side of the press:
         *   {"counter": {"table": "SS_UniqueNumber", "sectionId": 3, "prior": N, "new": N+1},
         *    "rows": [{"action": "insert", "table": "BRN_TillBalance", "key": {...}, "new": {...}}, ...]}
         */
        MigrationHelper::table('TillBalanceEvent', function (Blueprint $table) {
            $table->integer('TillBalanceNo');
            $table->string('Event', 20);
            MigrationHelper::money($table, 'Amount', nullable: true);

            // The two cashups, so the event can be found from either.
            $table->date('FromDate')->nullable();
            $table->integer('FromTillNo')->nullable();
            $table->integer('FromShiftNo')->nullable();
            $table->date('ToDate')->nullable();
            $table->integer('ToTillNo')->nullable();
            $table->integer('ToShiftNo')->nullable();

            $table->string('WriteMode', 10)->nullable();
            $table->uuid('SaveRef')->nullable();
            $table->text('WritesJson')->nullable();
            $table->string('Note', 400)->nullable();
        });

        foreach ([
            "CREATE INDEX [IX_TillBalanceEvent_No] ON [{$schema}].[TillBalanceEvent] ([BranchId], [TillBalanceNo], [Id]);",
            "ALTER TABLE [{$schema}].[TillBalanceEvent] ADD CONSTRAINT [CK_TillBalanceEvent_Event]
                CHECK ([Event] IN ('captured'));",
            "ALTER TABLE [{$schema}].[TillBalanceEvent] ADD CONSTRAINT [CK_TillBalanceEvent_WriteMode]
                CHECK ([WriteMode] IS NULL OR [WriteMode] = 'live');",
            "ALTER TABLE [{$schema}].[TillBalanceEvent] ADD CONSTRAINT [CK_TillBalanceEvent_WritesJson]
                CHECK ([WritesJson] IS NULL OR ISJSON([WritesJson]) = 1);",
        ] as $statement) {
            DB::statement($statement);
        }

        /*
         * One row per side of a till balance.
         *
         *   Amount          TransactionAmount — negative on the side giving
         *                   the amount up, positive on the side taking it
         *   VarianceBefore  OriginalEmployeeAmount, the cashup's variance when
         *                   the till balance was captured
         *   VarianceAfter   EmployeeAmount, what it becomes once posted
         *   Status          declined | approved | pending — pending means
         *                   waiting for PumpIT's approver
         *   CapturedIn      'agora' when an agora.TillBalanceEvent 'captured'
         *                   names this number at this site, else 'pumpit'
         *
         * TransactionAt stays the legacy DATETIME so a join to the cashup is on
         * the exact value, as every legacy join is.
         */
        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_TillBalance] AS
            SELECT
                t.SSBranchId                               AS BranchId,
                t.TillBalanceNo,
                t.TillNo,
                t.ShiftNo,
                t.TransactionDate                          AS TransactionAt,
                CONVERT(date, t.TransactionDate)           AS TransactionDate,
                LTRIM(RTRIM(t.LogFile))                    AS LogFile,
                LTRIM(RTRIM(t.EmployeeCode))               AS EmployeeCode,
                CONVERT(money, t.TransactionAmount)        AS Amount,
                CONVERT(money, t.OriginalEmployeeAmount)   AS VarianceBefore,
                CONVERT(money, t.EmployeeAmount)           AS VarianceAfter,
                t.CreatedByUserId                          AS CreatedByErpUserId,
                t.CreateDateTime                           AS CapturedAt,
                CONVERT(bit, t.Approved)                   AS Approved,
                t.ApprovedDate                             AS ApprovedAt,
                NULLIF(t.ApprovedByUserId, 0)              AS ApprovedByErpUserId,
                CONVERT(bit, t.IsDeclined)                 AS IsDeclined,
                t.DeclinedReason,
                t.DeclinedDate                             AS DeclinedAt,
                NULLIF(t.DeclinedByUserId, 0)              AS DeclinedByErpUserId,
                CASE WHEN t.IsDeclined = 1 THEN 'declined'
                     WHEN t.Approved = 1 THEN 'approved'
                     ELSE 'pending' END                    AS [Status],
                CASE WHEN a.Id IS NULL THEN 'pumpit' ELSE 'agora' END AS CapturedIn,
                a.Id                                       AS CaptureEventId
            FROM [{$erp}].dbo.BRN_TillBalance t
            OUTER APPLY (
                SELECT TOP 1 e.Id
                FROM [{$schema}].[TillBalanceEvent] e
                WHERE e.BranchId = t.SSBranchId
                  AND e.TillBalanceNo = t.TillBalanceNo
                  AND e.[Event] = 'captured'
                ORDER BY e.Id
            ) a;
        ");

        MigrationHelper::recordVersion(
            '1.18',
            'Cash: till balance — agora.TillBalanceEvent keeps every capture and exactly what it wrote to PumpIT '
            .'(the BRN_TillBalance pair and the SS_UniqueNumber section 3 counter), and agora.vw_TillBalance reads '
            .'the legacy table with where each till balance stands.'
        );
    }

    /**
     * Live and staging are forward-only; this runs against the local sandbox
     * only. Never rely on it to undo something in production.
     */
    public function down(): void
    {
        DB::unprepared('DROP VIEW IF EXISTS ['.config('agora.schema').'].[vw_TillBalance];');
        MigrationHelper::drop('TillBalanceEvent');
    }
};
