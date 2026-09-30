<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Cash — staff shorts (slot 12d, T040).
 *
 * A STAFF SHORT is money a named person is answerable for: a pump attendant
 * who handed in less than the pumps sold, a cashier whose bag reached the bank
 * lighter than it left, a stock line nobody paid for. It is raised, an
 * approver passes or declines it, and an approved short is POSTED — copied onto
 * one cashup's pump-shortages leg (BRN_DailyBankingEmployees), which is where
 * the cashup counts it and where the deduction schedule reads it
 * (dbo.sp_RPT_CashShortDeductionPerEmployee: PumpAttShort = -AmountShort).
 *
 * THE LEGACY SHAPE, measured on the customer's instance on 27 Sep 2026:
 *
 *   BRN_StaffShorts   4,851 shorts since 15 Feb 2024; PK NONCLUSTERED
 *                     (SSBranchId, StaffShortsNo), no trigger. The number is
 *                     SS_UniqueNumber section 2 (dbo.sp_GenerateStaffShortsNo,
 *                     next 4852) and unique across every site. Waiting: 20;
 *                     approved 4,347 (4,167 posted, 180 not); declined 484.
 *                     Reason and Note are ntext and FREE TEXT: 2,619 different
 *                     reasons for 4,851 shorts, "pump short" alone typed 11+
 *                     ways. TransactionDate is the moment of capture, not the
 *                     trading day — 3,893 of 4,160 posted shorts sit on a
 *                     cashup dated BEFORE the short, most often by one day.
 *   Posting           no PumpIT procedure posts: the Daily Banking screen lists
 *                     approved, unposted shorts (dbo.sp_Load_ApprovedStaffShorts),
 *                     the cashier ticks them onto the cashup's pump-shortages
 *                     leg — one BRN_DailyBankingEmployees row each, carrying
 *                     the short's StaffShortsNo, EmployeeCode, AmountShort and
 *                     Note (4,160 of 4,160) — and the short is flagged
 *                     IsSelectedForDailyBanking (dbo.sp_IsSelectedForDailyBanking_
 *                     DailyBanking_Update 'Staff Shorts'). Approval never posts
 *                     by itself (every posting is 1 minute to days later).
 *
 * WHAT THIS MIGRATION MAKES, all in Agora's own schema:
 *
 *   agora.StaffShortReason       the five codes a short is raised under (seeded
 *                                by StaffShortReasonSeeder): pump short,
 *                                cashier short, stock loss, driver drinks /
 *                                promotion (Ryan, PM decision 01a0e5b5), other.
 *                                Master data, soft-deleted, group-wide (branch 2).
 *   agora.StaffShortReasonAlias  how a LEGACY short's free text reads as a code:
 *                                a LIKE pattern per spelling, first match by
 *                                Priority — over the reason, then the note
 *                                (MatchOn 'text'), then the person it is against
 *                                (MatchOn 'employee': the "Driver Drinks"
 *                                pseudo-employee D001). So "pump short",
 *                                "Pumpshort", "punp short" and "Palesa 23.07.2024
 *                                Keletso Pump Short" are one code, and the text as
 *                                typed is kept as the note. Data, not code: the
 *                                customer adds a pattern, and nothing in PumpIT
 *                                is rewritten.
 *   agora.StaffShort             Agora's record of each short it RAISED — the
 *                                code, the free note, the cashup it is for
 *                                (optional), where it came from (the screen, the
 *                                phone, or a cash-bag match's split), and the
 *                                mails sent about it. The short itself — its
 *                                number, amount and approval — is PumpIT's row.
 *   agora.StaffShortEvent        every press on a short, never updated and never
 *                                deleted: raised, approved, declined, withdrawn,
 *                                posted,
 *                                with exactly what was written to PumpIT and the
 *                                values it replaced.
 *   agora.vw_CashStaffShort      every BRN_StaffShorts row, with its code (Agora's
 *                                own, else the alias, else 'other'), the note
 *                                kept, where it stands, and the cashup it was
 *                                posted to.
 *
 * It writes nothing to the customer's databases. agora.usp_Cash_RaiseStaffShort,
 * _ApproveStaffShort, _DeclineStaffShort, _WithdrawStaffShort and
 * usp_Cash_SaveCashupLeg (the
 * StaffShorts leg, reached from the Daily Banking screen or through
 * _PostStaffShort) do, and their headers list exactly what.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = config('agora.schema');
        $erp = (string) config('agora.source_databases.erp');

        /*
         * The codes. `Code` is what a procedure and a filter use; `Label` is
         * what a person reads, and what is written into BRN_StaffShorts.Reason
         * for a short raised in Agora — so PumpIT's own screens read one of five
         * labels instead of a new spelling. NeedsNote: 'other' says nothing on
         * its own, so a short raised under it must carry a note.
         */
        MigrationHelper::table('StaffShortReason', function (Blueprint $table) {
            $table->string('Code', 20);
            $table->string('Label', 60);
            $table->string('Description', 400)->nullable();
            $table->boolean('NeedsNote')->default(false);
            $table->boolean('IsActive')->default(true);
            $table->integer('SortOrder')->default(0);
        });
        MigrationHelper::naturalKey('StaffShortReason', ['BranchId', 'Code']);
        MigrationHelper::rowVersion('StaffShortReason');
        MigrationHelper::softDeletes('StaffShortReason');

        /*
         * A legacy spelling, as a LIKE pattern, and the code it stands for.
         * Lowest Priority wins. The database's CI collation makes it case-blind.
         *
         *   MatchOn 'text'      matched against the short's Reason, trimmed; and,
         *                       where no pattern matches the reason, its Note
         *   MatchOn 'employee'  matched against the person it is against — their
         *                       name ("Surname FirstName") or code — where neither
         *                       the reason nor the note matched: the shorts booked
         *                       against the "Driver Drinks" pseudo-employee
         */
        MigrationHelper::table('StaffShortReasonAlias', function (Blueprint $table) {
            $table->string('MatchOn', 10)->default('text');
            $table->string('Pattern', 200);
            $table->string('ReasonCode', 20);
            $table->integer('Priority')->default(100);
            $table->string('Description', 200)->nullable();
            $table->boolean('IsActive')->default(true);
        });
        MigrationHelper::naturalKey('StaffShortReasonAlias', ['BranchId', 'MatchOn', 'Pattern']);
        MigrationHelper::rowVersion('StaffShortReasonAlias');
        MigrationHelper::softDeletes('StaffShortReasonAlias');

        /*
         * One row per short Agora raised, keyed as PumpIT keys the short.
         *
         *   TransactionDate  the trading day the short belongs to (what Agora
         *                    also writes to BRN_StaffShorts.TransactionDate —
         *                    the legacy client writes the moment of capture)
         *   TillNo, ShiftNo  the cashup it is for, when the raiser said —
         *                    what Post offers first. Both or neither.
         *   Source           'screen' | 'phone' | 'recon'
         *   SourceId         for 'recon', the agora.ReconAllocation line it was
         *                    raised from — once only (UX_StaffShort_Source)
         *   SourceRef        words: "Recon batch 145399 (cash bags)"
         *   RaisedMail*, DecidedMailAt   when the approvers were told it was
         *                    raised, and the raiser that it was decided
         */
        MigrationHelper::table('StaffShort', function (Blueprint $table) {
            $table->integer('StaffShortsNo');
            $table->string('EmployeeCode', 11);
            MigrationHelper::money($table, 'AmountShort');
            $table->date('TransactionDate');
            $table->string('ReasonCode', 20);
            $table->string('Note', 400)->nullable();
            $table->integer('TillNo')->nullable();
            $table->integer('ShiftNo')->nullable();
            $table->string('Source', 10);
            $table->unsignedBigInteger('SourceId')->nullable();
            $table->string('SourceRef', 100)->nullable();
            $table->dateTime('RaisedMailAt', 0)->nullable();
            $table->integer('RaisedMailTo')->nullable();
            $table->dateTime('DecidedMailAt', 0)->nullable();
        });
        MigrationHelper::naturalKey('StaffShort', ['BranchId', 'StaffShortsNo']);

        /*
         * Every press, in order. Never updated, never deleted.
         *
         *   raised    a BRN_StaffShorts row was INSERTed and SS_UniqueNumber
         *             section 2 moved on by one
         *   approved  Approved, ApprovedDate, ApprovedByUserId were UPDATEd
         *   declined  IsDeclined, DeclinedReason, DeclinedByUserId, DeclinedDate
         *             were UPDATEd — Note carries the whole reason, the column
         *             only 50 characters of it
         *   withdrawn an APPROVED short not yet on a cashup was taken back
         *             (Ryan's named go-ahead, PM decision 01a0e5b5): Approved,
         *             ApprovedDate, ApprovedByUserId back to the legacy's
         *             declined shape (0, NULL, 0) and IsDeclined, DeclinedReason,
         *             DeclinedByUserId, DeclinedDate set — the approval it
         *             replaced is in WritesJson.prior, the whole reason in Note
         *   posted    the short was copied onto a cashup's pump-shortages leg
         *             (a BRN_DailyBankingEmployees row) and flagged
         *             IsSelectedForDailyBanking; TransactionDate / TillNo /
         *             ShiftNo name that cashup
         *
         * WritesJson is the PumpIT side, with the values replaced:
         *   {"counter": {...}, "rows": [{"action", "table", "key", "prior", "new"}]}
         */
        MigrationHelper::table('StaffShortEvent', function (Blueprint $table) {
            $table->integer('StaffShortsNo');
            $table->string('Event', 12);
            $table->string('EmployeeCode', 11)->nullable();
            MigrationHelper::money($table, 'Amount', nullable: true);
            $table->string('ReasonCode', 20)->nullable();
            $table->date('TransactionDate')->nullable();
            $table->integer('TillNo')->nullable();
            $table->integer('ShiftNo')->nullable();
            $table->string('WriteMode', 10)->nullable();
            $table->uuid('SaveRef')->nullable();
            $table->text('WritesJson')->nullable();
            $table->string('Note', 400)->nullable();
        });

        foreach ([
            "CREATE UNIQUE INDEX [UX_StaffShort_Source] ON [{$schema}].[StaffShort] ([BranchId], [Source], [SourceId])
                WHERE [SourceId] IS NOT NULL;",
            "CREATE INDEX [IX_StaffShort_Employee] ON [{$schema}].[StaffShort] ([BranchId], [EmployeeCode], [TransactionDate]);",
            "CREATE INDEX [IX_StaffShortEvent_Short] ON [{$schema}].[StaffShortEvent] ([BranchId], [StaffShortsNo], [Id]);",
            "CREATE INDEX [IX_StaffShortReasonAlias_Order] ON [{$schema}].[StaffShortReasonAlias] ([MatchOn], [Priority], [Id]) INCLUDE ([Pattern], [ReasonCode]);",

            "ALTER TABLE [{$schema}].[StaffShortReason] ADD CONSTRAINT [CK_StaffShortReason_Code]
                CHECK (LEN([Code]) > 0 AND [Code] NOT LIKE '%[^a-z_]%');",
            "ALTER TABLE [{$schema}].[StaffShortReasonAlias] ADD CONSTRAINT [CK_StaffShortReasonAlias_Pattern]
                CHECK (LEN(LTRIM(RTRIM([Pattern]))) > 0);",
            "ALTER TABLE [{$schema}].[StaffShortReasonAlias] ADD CONSTRAINT [CK_StaffShortReasonAlias_MatchOn]
                CHECK ([MatchOn] IN ('text', 'employee'));",
            "ALTER TABLE [{$schema}].[StaffShort] ADD CONSTRAINT [CK_StaffShort_Source]
                CHECK ([Source] IN ('screen', 'phone', 'recon'));",
            "ALTER TABLE [{$schema}].[StaffShort] ADD CONSTRAINT [CK_StaffShort_Amount]
                CHECK ([AmountShort] > 0);",
            "ALTER TABLE [{$schema}].[StaffShort] ADD CONSTRAINT [CK_StaffShort_Cashup]
                CHECK (([TillNo] IS NULL AND [ShiftNo] IS NULL) OR ([TillNo] IS NOT NULL AND [ShiftNo] IS NOT NULL));",
            "ALTER TABLE [{$schema}].[StaffShort] ADD CONSTRAINT [CK_StaffShort_Recon]
                CHECK ([Source] <> 'recon' OR [SourceId] IS NOT NULL);",
            "ALTER TABLE [{$schema}].[StaffShortEvent] ADD CONSTRAINT [CK_StaffShortEvent_Event]
                CHECK ([Event] IN ('raised', 'approved', 'declined', 'withdrawn', 'posted'));",
            "ALTER TABLE [{$schema}].[StaffShortEvent] ADD CONSTRAINT [CK_StaffShortEvent_WriteMode]
                CHECK ([WriteMode] IS NULL OR [WriteMode] = 'live');",
            "ALTER TABLE [{$schema}].[StaffShortEvent] ADD CONSTRAINT [CK_StaffShortEvent_WritesJson]
                CHECK ([WritesJson] IS NULL OR ISJSON([WritesJson]) = 1);",
        ] as $statement) {
            DB::statement($statement);
        }

        $this->view($schema, $erp);

        MigrationHelper::recordVersion(
            '1.20',
            'Cash: staff shorts — agora.StaffShortReason (five codes) and agora.StaffShortReasonAlias (the legacy '
            .'spellings as codes, by reason, note or person), agora.StaffShort / agora.StaffShortEvent for what Agora raised and every press with '
            .'what it wrote to PumpIT, and agora.vw_CashStaffShort over BRN_StaffShorts with the code, the note kept '
            .'and the cashup each posted short is on.'
        );
    }

    /**
     * Every short PumpIT holds, one row each, read the way Cash reads it.
     *
     *   ReasonCode   Agora's code for a short Agora raised; for a legacy one,
     *                the first alias its Reason matches (by Priority), else the
     *                first its Note matches — "short" / "Pump short" is a pump
     *                short, and so is "Over filled customer vehicle" / "Pump
     *                short Luphiwo …" (82 more pump shorts on the customer's
     *                rows, 27 Sep 2026) — else the first employee alias the person
     *                matches (the "Driver Drinks" pseudo-employee D001) — else
     *                'other'. ReasonFrom says which: agora | alias | note |
     *                employee | none.
     *   Note         Agora's note for a short Agora raised. For a legacy one,
     *                the reason AS TYPED — that is the note "kept" when eleven
     *                spellings become one code — and the legacy Note after it
     *                when it says something else.
     *   Status       Waiting | Approved | Declined | Posted. Posted is
     *                IsSelectedForDailyBanking, or a pump-shortages line
     *                carrying the number (they agree on every row, 27 Sep 2026).
     *   Posted*      the cashup the short is on, off BRN_DailyBankingEmployees
     *                by (site, person, number) — TOP 1, so a label lookup can
     *                never change the row count.
     *
     * Every other lookup is OUTER APPLY TOP 1 for the same reason: one row per
     * BRN_StaffShorts row, always. EmployeeName is "Surname FirstName", as
     * agora.vw_Employee and the recon split write it — read off BRN_Employee
     * here because vw_Employee (Reports, slot 22) is created after this.
     */
    private function view(string $schema, string $erp): void
    {
        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_CashStaffShort] AS
            SELECT
                s.SSBranchId                                              AS BranchId,
                s.StaffShortsNo,
                LTRIM(RTRIM(s.EmployeeCode))                              AS EmployeeCode,
                e.EmployeeName,
                CONVERT(bit, CASE WHEN e.EmployeeCode IS NULL THEN 0 ELSE 1 END) AS OnEmployeeList,
                CONVERT(money, s.AmountShort)                             AS AmountShort,
                s.TransactionDate                                         AS TransactionAt,
                CONVERT(date, s.TransactionDate)                          AS TransactionDate,
                k.ReasonCode,
                ISNULL(r.Label, k.ReasonCode)                             AS ReasonLabel,
                k.ReasonFrom,
                CONVERT(nvarchar(400), CASE
                    WHEN a.Id IS NOT NULL THEN a.Note
                    WHEN t.LegacyNote IS NULL OR t.LegacyNote = t.LegacyReason THEN t.LegacyReason
                    WHEN t.LegacyReason IS NULL THEN t.LegacyNote
                    ELSE t.LegacyReason + N' — ' + t.LegacyNote END)      AS Note,
                t.LegacyReason,
                t.LegacyNote,
                CONVERT(varchar(10), CASE
                    WHEN s.IsDeclined = 1 THEN 'Declined'
                    WHEN ISNULL(s.IsSelectedForDailyBanking, 0) = 1 OR p.StaffShortsNo IS NOT NULL THEN 'Posted'
                    WHEN s.Approved = 1 THEN 'Approved'
                    ELSE 'Waiting' END)                                   AS Status,
                s.CreatedByUserId,
                CONVERT(bit, s.Approved)                                  AS Approved,
                s.ApprovedDate,
                s.ApprovedByUserId,
                CONVERT(bit, s.IsDeclined)                                AS IsDeclined,
                LTRIM(RTRIM(s.DeclinedReason))                            AS DeclinedReason,
                s.DeclinedByUserId,
                s.DeclinedDate,
                CONVERT(bit, ISNULL(s.IsSelectedForDailyBanking, 0))      AS IsSelectedForDailyBanking,
                p.TillNo                                                  AS PostedTillNo,
                p.ShiftNo                                                 AS PostedShiftNo,
                CONVERT(date, p.TransactionDate)                          AS PostedDate,
                CONVERT(varchar(10), CASE WHEN a.Id IS NULL THEN 'PumpIT' ELSE 'Agora' END) AS RaisedIn,
                a.Id                                                      AS AgoraShortId,
                a.TransactionDate                                         AS ForDate,
                a.TillNo                                                  AS ForTillNo,
                a.ShiftNo                                                 AS ForShiftNo,
                a.Source,
                a.SourceId,
                a.SourceRef,
                a.CreatedBy                                               AS RaisedByUserId,
                a.RaisedMailAt,
                a.DecidedMailAt,
                s.CreateDateTime                                          AS CapturedAt
            FROM [{$erp}].dbo.BRN_StaffShorts s
            LEFT JOIN [{$schema}].[StaffShort] a
                   ON a.BranchId = s.SSBranchId AND a.StaffShortsNo = s.StaffShortsNo
            CROSS APPLY (SELECT NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(400), s.Reason))), N'') AS LegacyReason,
                                NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(400), s.Note))), N'')   AS LegacyNote) t
            OUTER APPLY (SELECT TOP 1 x.EmployeeCode,
                                LTRIM(RTRIM(ISNULL(x.Surname, N'') + N' ' + ISNULL(x.FirstName, N''))) AS EmployeeName
                         FROM [{$erp}].dbo.BRN_Employee x
                         WHERE x.SSBranchId = s.SSBranchId AND x.EmployeeCode = s.EmployeeCode) e
            OUTER APPLY (SELECT TOP 1 x.ReasonCode
                         FROM [{$schema}].[StaffShortReasonAlias] x
                         WHERE a.Id IS NULL AND x.MatchOn = 'text' AND x.IsActive = 1 AND x.DeletedAt IS NULL
                           AND t.LegacyReason LIKE x.Pattern
                         ORDER BY x.Priority, x.Id) m
            OUTER APPLY (SELECT TOP 1 x.ReasonCode
                         FROM [{$schema}].[StaffShortReasonAlias] x
                         WHERE a.Id IS NULL AND m.ReasonCode IS NULL AND x.MatchOn = 'text' AND x.IsActive = 1 AND x.DeletedAt IS NULL
                           AND t.LegacyNote LIKE x.Pattern
                         ORDER BY x.Priority, x.Id) n
            OUTER APPLY (SELECT TOP 1 x.ReasonCode
                         FROM [{$schema}].[StaffShortReasonAlias] x
                         WHERE a.Id IS NULL AND m.ReasonCode IS NULL AND n.ReasonCode IS NULL
                           AND x.MatchOn = 'employee' AND x.IsActive = 1 AND x.DeletedAt IS NULL
                           AND (e.EmployeeName LIKE x.Pattern OR LTRIM(RTRIM(s.EmployeeCode)) LIKE x.Pattern)
                         ORDER BY x.Priority, x.Id) w
            CROSS APPLY (SELECT CONVERT(varchar(20), COALESCE(a.ReasonCode, m.ReasonCode, n.ReasonCode, w.ReasonCode, 'other')) AS ReasonCode,
                                CONVERT(varchar(10), CASE WHEN a.Id IS NOT NULL THEN 'agora'
                                                          WHEN m.ReasonCode IS NOT NULL THEN 'alias'
                                                          WHEN n.ReasonCode IS NOT NULL THEN 'note'
                                                          WHEN w.ReasonCode IS NOT NULL THEN 'employee'
                                                          ELSE 'none' END) AS ReasonFrom) k
            OUTER APPLY (SELECT TOP 1 x.Label
                         FROM [{$schema}].[StaffShortReason] x
                         WHERE x.Code = k.ReasonCode
                         ORDER BY x.Id) r
            OUTER APPLY (SELECT TOP 1 x.TillNo, x.ShiftNo, x.TransactionDate, x.StaffShortsNo
                         FROM [{$erp}].dbo.BRN_DailyBankingEmployees x
                         WHERE x.SSBranchId = s.SSBranchId AND x.EmployeeCode = s.EmployeeCode
                           AND x.StaffShortsNo = s.StaffShortsNo
                         ORDER BY x.TransactionDate DESC) p;
        ");
    }

    /**
     * Live and staging are forward-only; this runs against the local sandbox
     * only. Never rely on it to undo something in production.
     */
    public function down(): void
    {
        DB::unprepared('DROP VIEW IF EXISTS ['.config('agora.schema').'].[vw_CashStaffShort];');
        MigrationHelper::drop('StaffShortEvent');
        MigrationHelper::drop('StaffShort');
        MigrationHelper::drop('StaffShortReasonAlias');
        MigrationHelper::drop('StaffShortReason');
    }
};
