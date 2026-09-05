<?php

namespace Tests\Feature\Grid;

use App\Grid\GridColumn;
use App\Grid\GridDefinition;
use App\Grid\Sources\GridSource;
use App\Grid\Sources\ProcedureSource;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * Ten thousand rows out of a stored procedure, on demand.
 *
 * The acceptance for T014 names a number — "a 10 000-row proc result pages,
 * sorts numerically, and extracts" — and nothing in the estate returns that
 * many against the local container. So the test builds a procedure that does,
 * proves the grid against it, and drops it again.
 *
 * It is NOT a migration and it does NOT live under Modules/{M}/Database/
 * Procedures. It is a test fixture: nothing deploys it, nothing outside this
 * test knows it exists, and `scripts/check-procs.sh` does not look here —
 * a procedure only a test uses is not a rule the system follows, and putting it
 * in a module would say it was.
 *
 * The shape is the grid procedure template from feature-rules §2, verbatim:
 * eight parameters, one page of rows, then `(TotalRows BIGINT)`. If the
 * template ever changes, this breaks — which is the right way round.
 *
 * WHY IT CARRIES THE SAME NUMBER TWICE. `Amount` is a decimal and `AmountText`
 * is the same value written as an nvarchar. Sorted ascending, the first gives
 * 9 then 10 and the second gives '10' then '9'. A numeric column ordered as
 * text is the classic grid defect and the acceptance names it deliberately, so
 * the fixture is built to be able to produce BOTH answers rather than only the
 * one we would like to see.
 */
final class BigSetFixture
{
    public const PROCEDURE = 'usp_TEST_GridBigSet';

    public const ROWS = 10000;

    /** Create the procedure. Idempotent — it is a CREATE OR ALTER. */
    public static function deploy(): void
    {
        self::connection()->unprepared(self::sql());
    }

    /** And take it away again. A fixture that survives a run is a fixture nobody trusts. */
    public static function drop(): void
    {
        $name = self::qualified();

        self::connection()->unprepared("IF OBJECT_ID('{$name}', 'P') IS NOT NULL DROP PROCEDURE {$name};");
    }

    public static function qualified(): string
    {
        return config('agora.schema').'.'.self::PROCEDURE;
    }

    private static function connection(): Connection
    {
        return DB::connection(config('agora.connections.app'));
    }

    private static function sql(): string
    {
        $schema = config('agora.schema');
        $rows = self::ROWS;

        return <<<SQL
CREATE OR ALTER PROCEDURE [{$schema}].[usp_TEST_GridBigSet]
    @BranchIds   NVARCHAR(MAX) = NULL,
    @DateFrom    DATE          = NULL,
    @DateTo      DATE          = NULL,
    @Search      NVARCHAR(200) = NULL,
    @SortColumn  NVARCHAR(80)  = NULL,
    @SortAsc     BIT           = 1,
    @Page        INT           = 1,
    @PageSize    INT           = 50
AS
BEGIN
    SET NOCOUNT ON;

    SET @Page     = CASE WHEN ISNULL(@Page, 1) < 1 THEN 1 ELSE @Page END;
    SET @PageSize = CASE WHEN ISNULL(@PageSize, 50) BETWEEN 1 AND 100000 THEN @PageSize ELSE 50 END;
    SET @Search   = NULLIF(LTRIM(RTRIM(ISNULL(@Search, ''))), '');

    DECLARE @Branch TABLE (BranchId int PRIMARY KEY);

    /* The empty-string trap, kept because the real procedures have it:
       STRING_SPLIT('', ',') returns one row holding an empty string and
       TRY_CONVERT(int, '') is 0, not NULL — so without the first predicate a
       caller who passes no branches gets a table containing branch 0 and every
       row is filtered away. */
    INSERT INTO @Branch (BranchId)
    SELECT DISTINCT TRY_CONVERT(int, LTRIM(RTRIM(s.value)))
    FROM STRING_SPLIT(ISNULL(@BranchIds, ''), ',') s
    WHERE LTRIM(RTRIM(s.value)) <> ''
      AND TRY_CONVERT(int, LTRIM(RTRIM(s.value))) IS NOT NULL;

    DECLARE @AllBranches bit = CASE WHEN EXISTS (SELECT 1 FROM @Branch) THEN 0 ELSE 1 END;

    /* A tally, four joins of ten: 10 000 rows without a loop and without a
       table to read. */
    WITH d(n) AS (SELECT 0 UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4
                  UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9),
         n(i) AS (SELECT ROW_NUMBER() OVER (ORDER BY (SELECT NULL))
                  FROM d a, d b, d c, d e)
    SELECT
        CONVERT(int, n.i)                                            AS Id,
        CONVERT(int, 2 + (n.i % 25))                                 AS BranchId,
        CONVERT(nvarchar(60), N'TEST-Site ' + RIGHT('00' + CONVERT(varchar(3), 2 + (n.i % 25)), 2)) AS SiteName,
        CONVERT(decimal(18,2), n.i)                                  AS Amount,
        CONVERT(nvarchar(30), CONVERT(varchar(20), n.i))             AS AmountText,
        CONVERT(date, DATEADD(day, -(n.i % 365), '2026-09-05'))      AS TradingDay,
        CONVERT(nvarchar(40), CASE WHEN n.i % 7 = 0 THEN N'Closed'
                                   WHEN n.i % 7 = 1 THEN N'Still open'
                                   ELSE N'Balanced' END)             AS Status
    INTO #Rows
    FROM n
    WHERE n.i <= {$rows};

    DELETE FROM #Rows
    WHERE (@AllBranches = 0 AND BranchId NOT IN (SELECT BranchId FROM @Branch))
       OR (@Search IS NOT NULL AND SiteName NOT LIKE '%' + @Search + '%' AND Status NOT LIKE '%' + @Search + '%')
       OR (@DateFrom IS NOT NULL AND TradingDay < @DateFrom)
       OR (@DateTo IS NOT NULL AND TradingDay > @DateTo);

    DECLARE @Total bigint = (SELECT COUNT_BIG(*) FROM #Rows);

    /* The ORDER BY shape every grid procedure uses: one CASE per direction per
       type, so the sort is chosen by a parameter and the types never mix. The
       numeric branch CONVERTs to decimal — this is the line that decides
       whether 10 sorts after 9 or before it, and AmountText is here to prove
       the difference is real rather than asserted. */
    SELECT r.Id, r.BranchId, r.SiteName, r.Amount, r.AmountText, r.TradingDay, r.Status
    FROM #Rows r
    ORDER BY
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'SiteName'   THEN r.SiteName
                             WHEN 'Status'     THEN r.Status
                             WHEN 'AmountText' THEN r.AmountText END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'SiteName'   THEN r.SiteName
                             WHEN 'Status'     THEN r.Status
                             WHEN 'AmountText' THEN r.AmountText END END DESC,
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'Amount' THEN CONVERT(decimal(38,6), r.Amount)
                             WHEN 'Id'     THEN CONVERT(decimal(38,6), r.Id) END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'Amount' THEN CONVERT(decimal(38,6), r.Amount)
                             WHEN 'Id'     THEN CONVERT(decimal(38,6), r.Id) END END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'TradingDay' THEN CONVERT(datetime2, r.TradingDay) END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'TradingDay' THEN CONVERT(datetime2, r.TradingDay) END DESC,
        r.Id
    OFFSET (@Page - 1) * @PageSize ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT @Total AS TotalRows;
END
SQL;
    }

    /** The grid over it. Every column the fixture returns, typed. */
    public static function definition(): GridDefinition
    {
        return new class extends GridDefinition
        {
            public function key(): string
            {
                return 'app.test.bigset';
            }

            public function title(): string
            {
                return 'TEST-Big set';
            }

            public function columns(): array
            {
                return [
                    new GridColumn(key: 'Id', label: 'Id', format: 'number', sort: 'Id', mono: true),
                    new GridColumn(key: 'SiteName', label: 'Site', sort: 'SiteName'),
                    new GridColumn(key: 'TradingDay', label: 'Trading day', format: 'date', sort: 'TradingDay'),
                    new GridColumn(key: 'Status', label: 'Status', format: 'chip', sort: 'Status'),
                    // The same value, twice: one the grid sorts as a number and
                    // one it sorts as text.
                    new GridColumn(key: 'Amount', label: 'Amount', format: 'money', sort: 'Amount', total: true),
                    new GridColumn(key: 'AmountText', label: 'Amount as text', sort: 'AmountText'),
                    new GridColumn(key: 'BranchId', label: 'Branch', format: 'number', sort: null, visible: false),
                ];
            }

            public function source(): GridSource
            {
                return new ProcedureSource(BigSetFixture::PROCEDURE);
            }

            public function defaultSort(): string
            {
                return 'Id';
            }
        };
    }
}
