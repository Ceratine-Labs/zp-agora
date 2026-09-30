<?php

use App\Support\Database\MigrationHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Utilities — meter readings (slot 16, T056).
 *
 * THE READINGS ARE THE CUSTOMER'S TABLE. `PumpIT.dbo.BRN_UtilityTransaction`
 * is ADOPTED, not migrated: it stays where it is, its 86,732 rows stay where
 * they are, and Agora writes it through `agora.usp_Utilities_Save` and nothing
 * else (Ryan's go-ahead of 27 Sep 2026, PM decisions 01a0e22a-97be /
 * 01a0e22a-9aa9). So this file creates no reading table at all. What it
 * creates is what the legacy table has nowhere to put:
 *
 *   agora.UtilityReadingSave   one row per press of Save — who, when, which
 *                              site and days, what it did. BRN_UtilityTransaction
 *                              has no user column, so without this nobody could
 *                              ever say who captured a reading.
 *   agora.UtilityReadingWrite  one row per PumpIT row that press touched, with
 *                              the PRIOR values beside the new ones. The same
 *                              contract the recon stamps keep: a write to the
 *                              customer's table records what was there, so it
 *                              can be put back by hand if it has to be.
 *
 * And one view, `agora.vw_UtilityReading`, which is the whole of the usage
 * arithmetic in one place — see its own comment below.
 */
return new class extends Migration
{
    public function up(): void
    {
        MigrationHelper::table('UtilityReadingSave', function (Blueprint $table) {
            // The days the press covered. One day from the capture screen; a
            // range when a clerk back-fills.
            $table->date('DateFrom');
            $table->date('DateTo');
            $table->integer('LinesSent')->default(0);
            // What happened to them. Unchanged lines are counted and not
            // written — a Save that re-sends a whole day must not rewrite it.
            $table->integer('Captured')->default(0);
            $table->integer('Filled')->default(0);
            $table->integer('Amended')->default(0);
            $table->integer('Unchanged')->default(0);
            $table->integer('Recomputed')->default(0);
            // The literal from Modules/Utilities/Config/config.php at the time.
            $table->string('WriteMode', 10);
            // Required when any line changes a reading somebody had already
            // captured. A correction with no reason is one nobody can explain.
            $table->string('Reason', 300)->nullable();
        });

        MigrationHelper::table('UtilityReadingWrite', function (Blueprint $table) {
            $table->bigInteger('SaveId');
            // The customer's row. INT, as theirs is.
            $table->integer('UtilityTransactionId');
            $table->string('UtilityType', 1);
            $table->string('MeterNo', 50);
            $table->date('ReadingDate');
            /*
             * insert     a new row — the day had none
             * fill       a row the legacy screen wrote as 0 / 0 ahead of the
             *            day (it saves the whole month grid), now read
             * amend      a reading that already had a value, changed
             * recompute  the NEXT reading's stored Opening and Usage, which
             *            moved because the one before it did
             */
            $table->string('Action', 10);
            // DECIMAL(18,4) for a FLOAT column: 290.74000000000001 is what the
            // customer stores for 290.74, and four places hold every reading
            // the estate has ever taken.
            $table->decimal('PriorOpening', 18, 4)->nullable();
            $table->decimal('PriorClosing', 18, 4)->nullable();
            $table->decimal('PriorPrepaid', 18, 4)->nullable();
            $table->decimal('PriorUsage', 18, 4)->nullable();
            $table->decimal('NewOpening', 18, 4)->nullable();
            $table->decimal('NewClosing', 18, 4)->nullable();
            $table->decimal('NewPrepaid', 18, 4)->nullable();
            $table->decimal('NewUsage', 18, 4)->nullable();
        });

        // One write per customer row per press.
        MigrationHelper::naturalKey('UtilityReadingWrite', ['BranchId', 'SaveId', 'UtilityTransactionId']);

        $schema = config('agora.schema');
        DB::statement("
            CREATE INDEX [IX_UtilityReadingWrite_Reading]
                ON [{$schema}].[UtilityReadingWrite] ([BranchId], [UtilityType], [MeterNo], [ReadingDate]);
        ");

        $this->views();

        // 1.12 is Cash's (T036) and 1.13 is left for Assets, both built the
        // same day — the orchestrator renumbers if the two lanes collide.
        MigrationHelper::recordVersion(
            '1.14',
            'Utilities: agora.vw_UtilityReading (one row per meter per day, the usage arithmetic in one place), '
            .'and the record of every write Agora makes to PumpIT.dbo.BRN_UtilityTransaction with its prior values.'
        );
    }

    /**
     * The one view.
     *
     * EVERY USAGE FIGURE IN THIS MODULE COMES FROM HERE — the capture screen,
     * the readings grid, the zero-usage flag and the stored Opening/Usage the
     * save procedure writes back. The legacy estate computes it three
     * different ways (sp_CaptureUtilityTransaction, sp_SelectUtilityTransaction
     * and BRN_vw_UtilityConsumptionDaily each have their own) and they
     * disagree; this is the one Agora answers with.
     *
     *   OpeningReading = the meter's previous READING's closing, whenever it
     *                    was taken. Not "yesterday": the legacy looks for the
     *                    row dated exactly TransactionDate - 1, so a meter
     *                    read weekly gets an opening of 0 and a usage equal to
     *                    its whole register. usp_Reports_GridUtilityMeters made
     *                    the same correction and this keeps it.
     *   Usage          = ClosingReading - OpeningReading - PrepaidUnitsPurchased
     *                    — the customer's own sp_SelectUtilityTransaction,
     *                    sign for sign. Kept as the legacy formula, with no
     *                    per-meter "counts down" flag (coordinator's default,
     *                    27 Sep 2026): one site books its monthly reset as
     *                    prepaid = -opening, which only works this way round.
     *                    NULL for a meter's first reading ever, where there is
     *                    no opening to subtract; the legacy writes the whole
     *                    register there, which is a number nobody should cost.
     *
     * A 0 / 0 ROW IS NOT A READING (Ryan, PM decision 01a0e312-07a0). The
     * legacy screen saves the whole month grid, so every day nobody has read
     * yet sits in PumpIT as ClosingReading 0 and PrepaidUnitsPurchased 0 —
     * over a third of the table, and in September 2026 105 rows up to the day
     * at ten sites. Counted as readings they made the register "go backwards"
     * and turned a month's usage negative (branch 8's September read -24,518).
     * So such a row, IsBlank = 1:
     *   · is never an opening — the next reading is measured from the last
     *     real one, however many blank days sit between;
     *   · has no usage (NULL), whatever its neighbours read;
     *   · still carries the opening it WOULD be measured from, so a screen can
     *     show "last read 9 882 on the 25th" beside the empty box.
     * Saving a reading over one fills it (usp_Utilities_Save, action `fill`).
     *
     * HOW, without a correlated lookup per row: RealSeq counts the real
     * readings up to each row, so a real reading and the blank days after it
     * share one number — a group whose head is that reading. A blank row's
     * opening is its group's head; a real row's opening is the head of the
     * group before it, which is exactly what the row immediately before it
     * (real or blank) carries.
     *
     * ONE ROW PER METER PER DAY. The customer's primary key leads with the
     * identity, so it does not stop two rows for one meter on one day — and
     * live has four such pairs. The newest wins (the legacy client re-saves a
     * month by writing it again), and RowsForDay says how many there were, so
     * a duplicate is visible rather than silently doubling a usage.
     *
     * PARTITIONED BY METER, not by branch and type: a site with two meters of
     * one type is two series. The legacy's opening lookup is by branch and
     * type alone, which is only correct because no site has two yet.
     *
     * Reads the base table rather than agora.vw_UtilityTransaction on purpose:
     * that view belongs to Reports (slot 22), which migrates after this one,
     * and a view cannot be created over an object that does not exist yet.
     *
     * A predicate on BranchId is pushed below every window function (it is in
     * every PARTITION BY), so a one-site read touches one site's rows. A
     * predicate on the date is NOT, and must not be — the opening of the 1st
     * of the month is the last reading of the month before.
     */
    protected function views(): void
    {
        $schema = config('agora.schema');
        $erp = config('agora.source_databases.erp');

        DB::unprepared("
            CREATE OR ALTER VIEW [{$schema}].[vw_UtilityReading] AS
            SELECT o.UtilityTransactionId,
                   o.BranchId,
                   o.UtilityType,
                   o.MeterNo,
                   o.ReadingDate,
                   o.OpeningReading,
                   o.PreviousReadingDate,
                   o.ClosingReading,
                   o.PrepaidUnitsPurchased,
                   CASE WHEN o.IsBlank = 1 OR o.OpeningReading IS NULL THEN NULL
                        ELSE o.ClosingReading - o.OpeningReading - o.PrepaidUnitsPurchased
                   END                                   AS [Usage],
                   o.IsBlank,
                   o.StoredOpeningReading,
                   o.StoredUsage,
                   o.RowsForDay,
                   o.CreateDateTime
            FROM (
                SELECT h.UtilityTransactionId, h.BranchId, h.UtilityType, h.MeterNo, h.ReadingDate,
                       h.ClosingReading, h.PrepaidUnitsPurchased, h.IsBlank,
                       h.StoredOpeningReading, h.StoredUsage, h.RowsForDay, h.CreateDateTime,
                       /* A blank day is measured from its own group's head; a
                          real reading from the head of the group before it,
                          which the row just before it carries. */
                       CASE WHEN h.IsBlank = 1 THEN h.HeadClosing
                            ELSE LAG(h.HeadClosing) OVER (
                                     PARTITION BY h.BranchId, h.UtilityType, h.MeterNo
                                     ORDER BY h.ReadingDate)
                       END                               AS OpeningReading,
                       CASE WHEN h.IsBlank = 1 THEN h.HeadDate
                            ELSE LAG(h.HeadDate) OVER (
                                     PARTITION BY h.BranchId, h.UtilityType, h.MeterNo
                                     ORDER BY h.ReadingDate)
                       END                               AS PreviousReadingDate
                FROM (
                    SELECT g.*,
                           MAX(CASE WHEN g.IsBlank = 0 THEN g.ClosingReading END) OVER (
                               PARTITION BY g.BranchId, g.UtilityType, g.MeterNo, g.RealSeq) AS HeadClosing,
                           MAX(CASE WHEN g.IsBlank = 0 THEN g.ReadingDate END) OVER (
                               PARTITION BY g.BranchId, g.UtilityType, g.MeterNo, g.RealSeq) AS HeadDate
                    FROM (
                        SELECT b.*,
                               SUM(CASE WHEN b.IsBlank = 0 THEN 1 ELSE 0 END) OVER (
                                   PARTITION BY b.BranchId, b.UtilityType, b.MeterNo
                                   ORDER BY b.ReadingDate
                                   ROWS UNBOUNDED PRECEDING)             AS RealSeq
                        FROM (
                            SELECT d.*,
                                   CONVERT(bit, CASE WHEN d.ClosingReading = 0 AND d.PrepaidUnitsPurchased = 0
                                                     THEN 1 ELSE 0 END) AS IsBlank
                            FROM (
                                SELECT t.UtilityTransactionId,
                                       t.SSBranchId                                        AS BranchId,
                                       t.UtilityType,
                                       t.MeterNo,
                                       CONVERT(date, t.TransactionDate)                    AS ReadingDate,
                                       CONVERT(decimal(18,4), ISNULL(t.ClosingReading, 0)) AS ClosingReading,
                                       CONVERT(decimal(18,4), ISNULL(t.PrepaidUnitsPurchased, 0)) AS PrepaidUnitsPurchased,
                                       CONVERT(decimal(18,4), t.OpeningReading)            AS StoredOpeningReading,
                                       CONVERT(decimal(18,4), t.[Usage])                   AS StoredUsage,
                                       t.CreateDateTime,
                                       ROW_NUMBER() OVER (
                                           PARTITION BY t.SSBranchId, t.UtilityType, t.MeterNo, CONVERT(date, t.TransactionDate)
                                           ORDER BY t.UtilityTransactionId DESC)           AS DayRank,
                                       COUNT(*) OVER (
                                           PARTITION BY t.SSBranchId, t.UtilityType, t.MeterNo, CONVERT(date, t.TransactionDate)
                                       )                                                   AS RowsForDay
                                FROM [{$erp}].dbo.BRN_UtilityTransaction t
                            ) d
                            WHERE d.DayRank = 1
                        ) b
                    ) g
                ) h
            ) o;
        ");
    }

    /**
     * Live and staging are forward-only; this runs against the local sandbox
     * only. Never rely on it to undo something in production.
     */
    public function down(): void
    {
        $schema = config('agora.schema');
        DB::unprepared("DROP VIEW IF EXISTS [{$schema}].[vw_UtilityReading];");
        MigrationHelper::drop('UtilityReadingWrite');
        MigrationHelper::drop('UtilityReadingSave');
    }
};
