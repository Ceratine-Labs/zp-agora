<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Cash — Z-read allocation (slot 12, T036).
 *
 * A Z-read arrives from the till with a date, an EOD counter, a terminal, a log
 * file, an operator ID and a sales figure — and nothing else. Allocating it
 * means saying which till it was, which shift, and who was on it. Until that is
 * said, the takings belong to nobody and every cashup variance built on top of
 * them is unattributable.
 *
 * WHERE THE Z-READS ACTUALLY ARE. The plan (§4, T036) names MIST_Import's
 * DBF_P3TRANS_ZREAD. Measured on the customer's instance on 27 Sep 2026, there
 * is no such table in MIST_Import — it holds only NAMOS_DBF_P3TRANS_ZREAD
 * (2,004 rows, the NAMOS landing table, flagged IsLoadedToLive). The table with
 * every column the plan lists — LOCATION ARCH | WINBRANCH | AURA and five more,
 * the EOD, the terminal, the log file, the operator, the sales, the till and the
 * shift — is PumpIT.dbo.DBF_P3TRANS_ZREAD, 169,773 rows, which is also what
 * agora.vw_ZRead (Reports) already reads. So that is the source here.
 *
 * WHERE THE HISTORY IS. The legacy "ZREAD Update" screen writes TillNo,
 * ShiftNo and EmployeeCode straight onto that same row through
 * dbo.sp_UpdateDBF_P3TRANS_ZREAD. 167,780 of the 169,773 rows carry all three.
 * That is what the proposer learns from.
 *
 * THE STAMP (Ryan, 27 Sep 2026, PM decision 01a0e312-06da). Update ZREAD also
 * writes those three columns — TillNo, ShiftNo, EmployeeCode, and nothing else
 * — back onto the PumpIT row, so the legacy cashup and stock screens that read
 * them keep working through parallel running. Only
 * agora.usp_Cash_CommitZreadAllocation writes them and only
 * agora.usp_Cash_ReverseZreadCommit puts them back, switched by the literal
 * config('cash.zread.stamp_mode'). Unlike dbo.sp_UpdateDBF_P3TRANS_ZREAD it
 * does NOT stamp DBF_P3TRANS_STKRECON and does NOT delete DBF_COMPUTER_QTY.
 *
 * Two objects:
 *
 *   agora.ZreadAllocation   Agora's OWN allocation ledger. Every row is one
 *                           decision about one Z-read, and a row is never
 *                           deleted: a staged proposal is cleared, an
 *                           allocation is superseded, and both keep who, when
 *                           and what was there before.
 *   agora.vw_CashZread      the legacy table as Cash reads it, with Agora's
 *                           current allocation laid over the legacy one.
 *
 * WHY NOT agora.vw_Zread, which is the name the plan uses. The Agora database
 * is Latin1_General_CI_AS, so object names are case-insensitive and vw_Zread IS
 * vw_ZRead — Reports' view, read by five of its procedures. Redefining it from
 * here would be another module's object changed by a migration it does not own,
 * and it does not carry LOCATION, which the allocation cannot do without.
 *
 * This migration writes nothing to the customer's databases. The view reads
 * PumpIT by three-part name, which is why Agora has to sit on the same instance.
 *
 * Folded 27 Sep 2026 while the table had never left the local container (no
 * row of business data anywhere): 'reversed', the stamp columns and the
 * reversal columns were added to the create rather than as a lettered file.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * One decision about one Z-read.
         *
         * The Z-read is named by the source's own primary key — branch, date,
         * EOD counter, terminal, log file, operator, location — because
         * DBF_P3TRANS_ZREAD has no surrogate key: that composite IS the row.
         * Trimmed, because four of those columns are fixed-width CHAR in the
         * source and carry trailing spaces.
         */
        MigrationHelper::table('ZreadAllocation', function (Blueprint $table) {
            $table->date('ZReadDate');
            $table->string('Location', 10);
            $table->string('EodCounter', 8);
            $table->string('TerminalNo', 3);
            $table->string('LogFile', 10);
            $table->string('PosUserId', 6);

            // What the Z-read is allocated to. All three are required: a Z-read
            // with a till and no shift is the "partly allocated" state the
            // legacy queue already has to explain, and Agora does not create it.
            $table->integer('TillNo');
            $table->integer('ShiftNo');
            $table->string('EmployeeCode', 11);

            // proposed   staged by Auto-allocate or a person, not yet saved
            // allocated  saved by Update ZREAD — the Z-read's allocation
            // superseded an allocation a later one replaced
            // cleared    a staged proposal somebody cleared before saving
            // reversed   an allocation whose Update ZREAD press was reversed
            $table->string('Status', 12)->default('proposed');

            // auto      staged by Auto-allocate, at or above the threshold
            // accepted  a person accepted the system's proposal as it stood
            // manual    a person set it by hand
            $table->string('Source', 10);

            // certain | likely | review | manual — a word, never a percentage
            // (see <x-proposal>). The evidence is the sentence that earned it.
            $table->string('Confidence', 10);
            $table->string('Evidence', 400)->nullable();

            // Update ZREAD. One CommitRef per press, so "what did that press
            // save" is one query.
            $table->dateTime('AllocatedAt', 0)->nullable();
            $table->integer('AllocatedBy')->nullable();
            $table->uuid('CommitRef')->nullable();

            /*
             * THE AUDIT: what the Z-read carried before this allocation, and
             * where that came from. `legacy` is the PumpIT row's own TillNo /
             * ShiftNo / EmployeeCode; `agora` is the allocation this one
             * superseded, whose id is SupersedesId. Null is a Z-read nobody had
             * allocated. Written by the commit, in the same transaction.
             */
            $table->integer('PriorTillNo')->nullable();
            $table->integer('PriorShiftNo')->nullable();
            $table->string('PriorEmployeeCode', 11)->nullable();
            $table->string('PriorSource', 10)->nullable();
            $table->unsignedBigInteger('SupersedesId')->nullable();

            $table->dateTime('SupersededAt', 0)->nullable();
            $table->integer('SupersededBy')->nullable();

            /*
             * THE STAMP, and what a reversal puts back.
             *
             * StampMode says whether this allocation was written onto the PumpIT
             * row ('live') or recorded here only ('journal'), per allocation
             * rather than per config, so a row saved before the switch moved is
             * still reversed correctly after it.
             *
             * StampPrior* are the three PumpIT columns EXACTLY as the row held
             * them the moment before the stamp — a 0 stays 0 and a NULL stays
             * NULL. Prior* above is the allocation Agora showed, which is not
             * always the same thing (an earlier Agora allocation saved in
             * journal mode never reached PumpIT). A reversal restores these.
             */
            $table->string('StampMode', 10)->nullable();
            $table->dateTime('StampedAt', 0)->nullable();
            $table->integer('StampPriorTillNo')->nullable();
            $table->integer('StampPriorShiftNo')->nullable();
            $table->string('StampPriorEmployeeCode', 11)->nullable();

            // agora.usp_Cash_ReverseZreadCommit: who, when and why.
            $table->dateTime('ReversedAt', 0)->nullable();
            $table->integer('ReversedBy')->nullable();
            $table->string('ReversalReason', 300)->nullable();

            // Why a row ended where it did, when that is not obvious from the
            // status — "the source Z-read no longer exists".
            $table->string('Note', 200)->nullable();
        });
        MigrationHelper::rowVersion('ZreadAllocation');

        $schema = config('agora.schema');

        foreach ([
            /*
             * At most ONE allocation and ONE staged proposal per Z-read. Two
             * filtered unique keys rather than one natural key, because a
             * Z-read legitimately has many rows over its life — superseded and
             * cleared ones are the audit — and only these two states must be
             * singular. MigrationHelper::naturalKey() has no filter, so they
             * are written by hand; check-migrations.sh still sees BranchId.
             */
            "CREATE UNIQUE INDEX [UX_ZreadAllocation_Allocated] ON [{$schema}].[ZreadAllocation]
                ([BranchId], [ZReadDate], [Location], [TerminalNo], [EodCounter], [LogFile], [PosUserId])
                WHERE [Status] = 'allocated';",
            "CREATE UNIQUE INDEX [UX_ZreadAllocation_Proposed] ON [{$schema}].[ZreadAllocation]
                ([BranchId], [ZReadDate], [Location], [TerminalNo], [EodCounter], [LogFile], [PosUserId])
                WHERE [Status] = 'proposed';",
            // Staging, clearing and committing all work on one site's period.
            "CREATE INDEX [IX_ZreadAllocation_Scope] ON [{$schema}].[ZreadAllocation]
                ([BranchId], [Status], [ZReadDate]) INCLUDE ([Location]);",
            // "What did that press save?"
            "CREATE INDEX [IX_ZreadAllocation_Commit] ON [{$schema}].[ZreadAllocation]
                ([BranchId], [CommitRef]) WHERE [CommitRef] IS NOT NULL;",
            // The vocabularies, as check constraints rather than enum columns.
            "ALTER TABLE [{$schema}].[ZreadAllocation] ADD CONSTRAINT [CK_ZreadAllocation_Status]
                CHECK ([Status] IN ('proposed', 'allocated', 'superseded', 'cleared', 'reversed'));",
            "ALTER TABLE [{$schema}].[ZreadAllocation] ADD CONSTRAINT [CK_ZreadAllocation_StampMode]
                CHECK ([StampMode] IS NULL OR [StampMode] IN ('live', 'journal'));",
            "ALTER TABLE [{$schema}].[ZreadAllocation] ADD CONSTRAINT [CK_ZreadAllocation_Source]
                CHECK ([Source] IN ('auto', 'accepted', 'manual'));",
            "ALTER TABLE [{$schema}].[ZreadAllocation] ADD CONSTRAINT [CK_ZreadAllocation_Confidence]
                CHECK ([Confidence] IN ('certain', 'likely', 'review', 'manual'));",
            "ALTER TABLE [{$schema}].[ZreadAllocation] ADD CONSTRAINT [CK_ZreadAllocation_PriorSource]
                CHECK ([PriorSource] IS NULL OR [PriorSource] IN ('legacy', 'agora'));",
        ] as $statement) {
            DB::statement($statement);
        }

        $this->view($schema, (string) config('agora.source_databases.erp'));

        MigrationHelper::recordVersion(
            '1.12',
            'Cash: Z-read allocation — Agora\'s own allocation ledger, and the legacy Z-reads read with '
            .'their location and with Agora\'s allocation laid over the legacy one.'
        );
    }

    /**
     * The Z-reads, as Cash reads them.
     *
     * `DATE` is a reserved word and is bracketed once, here. EOD_CNTR, TERMNUM,
     * LOGFILE and USERID are fixed-width CHAR and are trimmed — the ARCH EOD
     * counter is left-justified ('1621') and the WINBRANCH one right-justified
     * ('    1867'), so an untrimmed comparison silently matches nothing.
     *
     * THE EFFECTIVE ALLOCATION is one rule, stated once so the proposer, the
     * grid, the terminal history and the Reports queue cannot disagree about
     * it: Agora's own allocation if there is one, else the legacy row's when
     * ALL THREE of TillNo, ShiftNo and EmployeeCode are set, else none. A
     * legacy row with some but not all is `IsPartlyAllocated` — the state
     * usp_Reports_GridUnallocatedZReads deliberately leaves out of its queue.
     *
     * THE LATEST WINS (Ryan, 27 Sep 2026, PM decision 01a0e371). When Agora's
     * allocation was stamped onto the PumpIT row ('live') and that row's three
     * columns no longer equal what the press wrote, somebody has allocated the
     * Z-read again on the PumpIT screen since, and theirs is the later
     * decision. The view then reads exactly as if Agora had no allocation — the
     * PumpIT values, AllocatedBy 'legacy', partly allocated or open if that is
     * what PumpIT now holds — with ChangedInPumpItAfterAgora = 1 so every
     * screen can say so. A 'journal' allocation never reached PumpIT, so a
     * difference there is expected and Agora's still stands.
     * agora.usp_Cash_ReverseZreadCommit refuses such a press
     * (CASH_COMMIT_MOVED): reversing it would write over the later decision.
     *
     * ZReadAt stays a DATETIME so a date range over it is sargable against the
     * source's clustered key; ZReadDate is the same value as a DATE.
     *
     * The Agora row is found with OUTER APPLY TOP 1 on the filtered unique key,
     * so it cannot fan the Z-read out however the ledger is filled.
     */
    private function view(string $schema, string $erp): void
    {
        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_CashZread] AS
            SELECT
                z.SSBranchId                                         AS BranchId,
                z.[DATE]                                             AS ZReadAt,
                CONVERT(date, z.[DATE])                              AS ZReadDate,
                ISNULL(LTRIM(RTRIM(z.[LOCATION])), '')               AS Location,
                LTRIM(RTRIM(z.EOD_CNTR))                             AS EodCounter,
                LTRIM(RTRIM(z.TERMNUM))                              AS TerminalNo,
                LTRIM(RTRIM(z.LOGFILE))                              AS LogFile,
                LTRIM(RTRIM(z.USERID))                               AS PosUserId,
                z.Sales,
                z.isCaptured                                         AS IsCaptured,
                z.MIST_EOD                                           AS MistEod,
                NULLIF(z.TillNo, 0)                                  AS LegacyTillNo,
                NULLIF(z.ShiftNo, 0)                                 AS LegacyShiftNo,
                NULLIF(LTRIM(RTRIM(z.EmployeeCode)), '')             AS LegacyEmployeeCode,
                a.Id                                                 AS AllocationId,
                CASE WHEN w.UseAgora = 1 THEN a.TillNo
                     WHEN l.Complete = 1 THEN z.TillNo END           AS TillNo,
                CASE WHEN w.UseAgora = 1 THEN a.ShiftNo
                     WHEN l.Complete = 1 THEN z.ShiftNo END          AS ShiftNo,
                CASE WHEN w.UseAgora = 1 THEN a.EmployeeCode
                     WHEN l.Complete = 1 THEN LTRIM(RTRIM(z.EmployeeCode)) END AS EmployeeCode,
                CASE WHEN w.UseAgora = 1 THEN 'agora'
                     WHEN l.Complete = 1 THEN 'legacy' END           AS AllocatedBy,
                CONVERT(bit, CASE WHEN w.UseAgora = 1 OR l.Complete = 1 THEN 1 ELSE 0 END) AS IsAllocated,
                CONVERT(bit, CASE WHEN w.UseAgora = 0 AND l.Complete = 0 AND l.AnySet = 1 THEN 1 ELSE 0 END) AS IsPartlyAllocated,
                CONVERT(bit, w.Changed)                              AS ChangedInPumpItAfterAgora
            FROM [{$erp}].dbo.DBF_P3TRANS_ZREAD z
            CROSS APPLY (
                SELECT
                    CASE WHEN ISNULL(z.TillNo, 0) <> 0 AND ISNULL(z.ShiftNo, 0) <> 0
                               AND ISNULL(LTRIM(RTRIM(z.EmployeeCode)), '') <> '' THEN 1 ELSE 0 END AS Complete,
                    CASE WHEN ISNULL(z.TillNo, 0) <> 0 OR ISNULL(z.ShiftNo, 0) <> 0
                               OR ISNULL(LTRIM(RTRIM(z.EmployeeCode)), '') <> '' THEN 1 ELSE 0 END AS AnySet
            ) l
            OUTER APPLY (
                SELECT TOP 1 x.Id, x.TillNo, x.ShiftNo, x.EmployeeCode, x.StampMode
                FROM [{$schema}].[ZreadAllocation] x
                WHERE x.BranchId   = z.SSBranchId
                  AND x.ZReadDate  = CONVERT(date, z.[DATE])
                  AND x.Location   = ISNULL(LTRIM(RTRIM(z.[LOCATION])), '')
                  AND x.TerminalNo = LTRIM(RTRIM(z.TERMNUM))
                  AND x.EodCounter = LTRIM(RTRIM(z.EOD_CNTR))
                  AND x.LogFile    = LTRIM(RTRIM(z.LOGFILE))
                  AND x.PosUserId  = LTRIM(RTRIM(z.USERID))
                  AND x.[Status]   = 'allocated'
                ORDER BY x.Id DESC
            ) a
            CROSS APPLY (
                SELECT
                    /* Stamped live, and the row no longer holds what the press
                       wrote: somebody allocated it again in PumpIT since. */
                    CASE WHEN a.Id IS NOT NULL AND a.StampMode = 'live'
                              AND (ISNULL(z.TillNo, -1) <> a.TillNo
                                   OR ISNULL(z.ShiftNo, -1) <> a.ShiftNo
                                   OR ISNULL(LTRIM(RTRIM(z.EmployeeCode)), '') <> a.EmployeeCode)
                         THEN 1 ELSE 0 END AS Changed
            ) c
            CROSS APPLY (
                SELECT CASE WHEN a.Id IS NOT NULL AND c.Changed = 0 THEN 1 ELSE 0 END AS UseAgora,
                       c.Changed
            ) w;
        ");
    }

    /**
     * Live and staging are forward-only; this runs against the local sandbox
     * only. Never rely on it to undo something in production.
     */
    public function down(): void
    {
        DB::unprepared('DROP VIEW IF EXISTS ['.config('agora.schema').'].[vw_CashZread];');
        MigrationHelper::drop('ZreadAllocation');
    }
};
