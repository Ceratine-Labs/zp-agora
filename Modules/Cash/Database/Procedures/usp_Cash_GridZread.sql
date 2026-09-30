/* ============================================================================
   agora.usp_Cash_GridZread

   WHAT IT IS FOR
   The grid on the ZREAD Allocation screen: one site's Z-reads over a period,
   each with where it stands and what the system proposes for it.

   WHO READS IT
   Cash -> ZREAD Allocation (app.cash.zread), and its extract. A branch manager
   or head office, working a site's period until nothing is left waiting.

   The grid procedure template (feature-rules §2), plus what the nine have no
   room for. They are NOT header filters — the person chose them above the grid
   and the grid cannot clear them — so they arrive as named arguments:

     @BranchId     the site. Falls back to @BranchIds when that names exactly
                   one; anything else is refused, because a Z-read is proposed
                   from its own site's history.
     @PeriodFrom   the period. Fall back to @DateFrom / @DateTo, then to the
     @PeriodTo     last ten days.
     @Location     one POS location (ARCH, WINBRANCH, AURA ...), or every one.
     @Show         'open' (default): what still needs doing — open and staged.
                   'all': the allocated ones too.
     the four priors, passed straight to the proposer (config('cash.zread')).

   THE ROWS COME FROM agora.usp_Cash_ProposeZreadAllocation, whole, and only
   the search, the header filters, the sort and the page happen here. The
   proposer is the one place the rules live; this procedure must never
   re-derive a confidence.

   ⚠ The #gz_p table below is the proposer's result set, column for column.
   INSERT ... EXEC fails loudly if the two drift, which is the point; change
   them together (and the same table in usp_Cash_AutoAllocate and
   usp_Cash_SetZreadAllocation).

   Decision is the one coloured column: Allocated, Staged, or — for an open
   Z-read — the proposal's confidence as the word the screen uses (Certain,
   Likely, Review, Needs a person).

   @FiltersJson is a JSON ARRAY of {column, type, op, value | in[]} objects; the
   column is at $.column INSIDE each element, never OPENJSON's [key].

   Result sets: 1 the page, 2 (TotalRows), 3 grand totals (Sales).

   READ-ONLY.

     EXEC agora.usp_Cash_GridZread @BranchId = 9,
          @PeriodFrom = '2026-08-21', @PeriodTo = '2026-08-31', @Show = 'all';
   ============================================================================ */
CREATE OR ALTER PROCEDURE [agora].[usp_Cash_GridZread]
    @BranchIds     NVARCHAR(MAX) = NULL,
    @DateFrom      DATE          = NULL,
    @DateTo        DATE          = NULL,
    @Search        NVARCHAR(200) = NULL,
    @SortColumn    NVARCHAR(80)  = NULL,
    @SortAsc       BIT           = 1,
    @Page          INT           = 1,
    @PageSize      INT           = 50,
    @FiltersJson   NVARCHAR(MAX) = NULL,
    @BranchId      INT           = NULL,
    @PeriodFrom    DATE          = NULL,
    @PeriodTo      DATE          = NULL,
    @Location      NVARCHAR(10)  = NULL,
    @Show          NVARCHAR(10)  = 'open',
    @CertainShare  DECIMAL(5,4)  = 0.75,
    @LikelyShare   DECIMAL(5,4)  = 0.60,
    @ShiftShare    DECIMAL(5,4)  = 0.60,
    @HistoryDays   INT           = 90,
    @DayShiftNo    INT           = 1,
    @NightShiftNo  INT           = 3,
    @SequenceExcluded NVARCHAR(200) = 'ARCH'
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    SET @Page     = CASE WHEN ISNULL(@Page, 1) < 1 THEN 1 ELSE @Page END;
    SET @PageSize = CASE WHEN ISNULL(@PageSize, 50) BETWEEN 1 AND 100000 THEN @PageSize ELSE 50 END;
    SET @Search   = NULLIF(LTRIM(RTRIM(ISNULL(@Search, ''))), '');
    SET @Show     = CASE WHEN @Show = 'all' THEN 'all' ELSE 'open' END;

    /* The empty-string trap: STRING_SPLIT('', ',') is one empty row, and
       TRY_CONVERT(int, '') is 0 rather than NULL. */
    IF @BranchId IS NULL
    BEGIN
        DECLARE @Branch TABLE (BranchId int PRIMARY KEY);

        INSERT INTO @Branch (BranchId)
        SELECT DISTINCT TRY_CONVERT(int, LTRIM(RTRIM(s.value)))
        FROM STRING_SPLIT(ISNULL(@BranchIds, ''), ',') s
        WHERE LTRIM(RTRIM(s.value)) <> '' AND TRY_CONVERT(int, LTRIM(RTRIM(s.value))) IS NOT NULL;

        IF (SELECT COUNT(*) FROM @Branch) = 1
            SET @BranchId = (SELECT BranchId FROM @Branch);
    END

    IF @BranchId IS NULL
        THROW 51000, 'AGORA:CASH_ZREAD_SITE:Choose one site. A Z-read is allocated from what that site has done before, so the grid answers for one site at a time.', 1;

    SET @PeriodTo   = COALESCE(@PeriodTo, @DateTo, CONVERT(date, GETDATE()));
    SET @PeriodFrom = COALESCE(@PeriodFrom, @DateFrom, DATEADD(day, -10, @PeriodTo));

    CREATE TABLE #gz_p (
        BranchId INT, ZReadDate DATE, Location NVARCHAR(10) COLLATE DATABASE_DEFAULT, EodCounter NVARCHAR(8) COLLATE DATABASE_DEFAULT, TerminalNo NVARCHAR(3) COLLATE DATABASE_DEFAULT,
        LogFile NVARCHAR(10) COLLATE DATABASE_DEFAULT, PosUserId NVARCHAR(6) COLLATE DATABASE_DEFAULT, Sales DECIMAL(18,2),
        [State] NVARCHAR(10) COLLATE DATABASE_DEFAULT, AllocatedBy NVARCHAR(10) COLLATE DATABASE_DEFAULT, IsPartlyAllocated BIT, AllocationId BIGINT,
        TillNo INT, ShiftNo INT, EmployeeCode NVARCHAR(22) COLLATE DATABASE_DEFAULT,
        TillDescription NVARCHAR(100) COLLATE DATABASE_DEFAULT, ShiftDescription NVARCHAR(100) COLLATE DATABASE_DEFAULT, EmployeeName NVARCHAR(120) COLLATE DATABASE_DEFAULT,
        LegacyTillNo INT, LegacyShiftNo INT, LegacyEmployeeCode NVARCHAR(22) COLLATE DATABASE_DEFAULT,
        StagedId BIGINT, StagedSource NVARCHAR(10) COLLATE DATABASE_DEFAULT, StagedConfidence NVARCHAR(10) COLLATE DATABASE_DEFAULT,
        ProposedTillNo INT, ProposedShiftNo INT, ProposedEmployeeCode NVARCHAR(22) COLLATE DATABASE_DEFAULT,
        ProposedTillDescription NVARCHAR(100) COLLATE DATABASE_DEFAULT, ProposedShiftDescription NVARCHAR(100) COLLATE DATABASE_DEFAULT, ProposedEmployeeName NVARCHAR(120) COLLATE DATABASE_DEFAULT,
        Confidence NVARCHAR(10) COLLATE DATABASE_DEFAULT, Evidence NVARCHAR(400) COLLATE DATABASE_DEFAULT, ShiftEvidence NVARCHAR(400) COLLATE DATABASE_DEFAULT,
        PriorZreads INT, PriorTotal INT, IsComplete BIT, HistoryMatch NVARCHAR(10) COLLATE DATABASE_DEFAULT,
        ShiftSource NVARCHAR(10) COLLATE DATABASE_DEFAULT,
        ChangedInPumpIt BIT
    );

    INSERT INTO #gz_p
    EXEC agora.usp_Cash_ProposeZreadAllocation
         @BranchId = @BranchId, @DateFrom = @PeriodFrom, @DateTo = @PeriodTo, @Location = @Location,
         @Replay = 0, @CertainShare = @CertainShare, @LikelyShare = @LikelyShare,
         @ShiftShare = @ShiftShare, @HistoryDays = @HistoryDays,
         @DayShiftNo = @DayShiftNo, @NightShiftNo = @NightShiftNo, @SequenceExcluded = @SequenceExcluded;

    DECLARE @Filter    TABLE ([Column] NVARCHAR(80), [Type] NVARCHAR(10), [Op] NVARCHAR(20), [Value] NVARCHAR(400));
    DECLARE @FilterSet TABLE ([Column] NVARCHAR(80), [Value] NVARCHAR(400));

    IF @FiltersJson IS NOT NULL AND ISJSON(@FiltersJson) = 1
    BEGIN
        INSERT INTO @Filter ([Column], [Type], [Op], [Value])
        SELECT JSON_VALUE(f.value, '$.column'),
               ISNULL(JSON_VALUE(f.value, '$.type'), 'text'),
               ISNULL(JSON_VALUE(f.value, '$.op'), 'contains'),
               JSON_VALUE(f.value, '$.value')
        FROM OPENJSON(@FiltersJson) f
        WHERE JSON_VALUE(f.value, '$.value') IS NOT NULL
          AND ISNULL(JSON_VALUE(f.value, '$.type'), 'text') <> 'set';

        INSERT INTO @FilterSet ([Column], [Value])
        SELECT JSON_VALUE(f.value, '$.column'), v.value
        FROM OPENJSON(@FiltersJson) f
        CROSS APPLY OPENJSON(f.value, '$.in') v
        WHERE JSON_VALUE(f.value, '$.type') = 'set';
    END

    SELECT
        p.BranchId, p.ZReadDate, p.Location, p.EodCounter, p.TerminalNo, p.LogFile, p.PosUserId, p.Sales,
        p.[State], p.AllocatedBy,
        CONVERT(NVARCHAR(20), CASE
            WHEN p.[State] = 'staged'    THEN 'Staged'
            WHEN p.[State] = 'allocated' THEN 'Allocated'
            WHEN p.Confidence = 'certain' THEN 'Certain'
            WHEN p.Confidence = 'likely'  THEN 'Likely'
            WHEN p.Confidence = 'review'  THEN 'Review'
            ELSE 'Needs a person' END) AS Decision,
        p.TillNo, p.ShiftNo, p.EmployeeCode, p.TillDescription, p.ShiftDescription, p.EmployeeName,
        p.ProposedTillNo, p.ProposedShiftNo, p.ProposedEmployeeCode,
        p.ProposedTillDescription, p.ProposedShiftDescription, p.ProposedEmployeeName,
        p.Confidence, p.Evidence, p.ShiftEvidence, p.IsComplete,
        p.StagedSource, p.StagedConfidence, p.IsPartlyAllocated, p.PriorZreads, p.PriorTotal, p.ShiftSource,
        p.ChangedInPumpIt
    INTO #gz_rows
    FROM #gz_p p
    WHERE (@Show = 'all' OR p.[State] IN ('open', 'staged'))
      AND (@Search IS NULL
           OR p.EodCounter           LIKE '%' + @Search + '%'
           OR p.TerminalNo           LIKE '%' + @Search + '%'
           OR p.LogFile              LIKE '%' + @Search + '%'
           OR p.PosUserId            LIKE '%' + @Search + '%'
           OR p.Location             LIKE '%' + @Search + '%'
           OR p.EmployeeName         LIKE '%' + @Search + '%'
           OR p.ProposedEmployeeName LIKE '%' + @Search + '%'
           OR p.EmployeeCode         LIKE '%' + @Search + '%');

    /* Header filters. A row survives when no filter rejects it; each value is
       read once per column so the text, number and date comparisons each
       compare as themselves. A NULL cell fails a filter rather than slipping
       past it. */
    DELETE r
    FROM #gz_rows r
    WHERE EXISTS (
        SELECT 1
        FROM @Filter f
        CROSS APPLY (SELECT
            CASE f.[Column]
                WHEN 'Location'         THEN r.Location
                WHEN 'EodCounter'       THEN r.EodCounter
                WHEN 'TerminalNo'       THEN r.TerminalNo
                WHEN 'LogFile'          THEN r.LogFile
                WHEN 'PosUserId'        THEN r.PosUserId
                WHEN 'TillDescription'  THEN r.TillDescription
                WHEN 'ShiftDescription' THEN r.ShiftDescription
                WHEN 'EmployeeName'     THEN r.EmployeeName
                WHEN 'Decision'         THEN r.Decision
                WHEN 'Evidence'         THEN r.Evidence
            END AS T,
            CASE f.[Column] WHEN 'Sales' THEN CONVERT(DECIMAL(19,4), r.Sales) END AS N,
            CASE f.[Column] WHEN 'ZReadDate' THEN r.ZReadDate END AS D,
            TRY_CONVERT(DECIMAL(19,4), f.[Value]) AS FN,
            TRY_CONVERT(DATE, f.[Value]) AS FD
        ) v
        WHERE 1 = CASE
            WHEN f.[Type] = 'number' THEN CASE
                WHEN v.N IS NULL OR v.FN IS NULL THEN 1
                WHEN f.[Op] = 'ne'  AND v.N <> v.FN THEN 0
                WHEN f.[Op] = 'gt'  AND v.N >  v.FN THEN 0
                WHEN f.[Op] = 'gte' AND v.N >= v.FN THEN 0
                WHEN f.[Op] = 'lt'  AND v.N <  v.FN THEN 0
                WHEN f.[Op] = 'lte' AND v.N <= v.FN THEN 0
                WHEN f.[Op] NOT IN ('ne', 'gt', 'gte', 'lt', 'lte') AND v.N = v.FN THEN 0
                ELSE 1 END
            WHEN f.[Type] = 'date' THEN CASE
                WHEN v.D IS NULL OR v.FD IS NULL THEN 1
                WHEN f.[Op] = 'ne'  AND v.D <> v.FD THEN 0
                WHEN f.[Op] = 'gt'  AND v.D >  v.FD THEN 0
                WHEN f.[Op] = 'gte' AND v.D >= v.FD THEN 0
                WHEN f.[Op] = 'lt'  AND v.D <  v.FD THEN 0
                WHEN f.[Op] = 'lte' AND v.D <= v.FD THEN 0
                WHEN f.[Op] NOT IN ('ne', 'gt', 'gte', 'lt', 'lte') AND v.D = v.FD THEN 0
                ELSE 1 END
            ELSE CASE
                WHEN v.T IS NULL THEN 1
                WHEN f.[Op] = 'eq' AND v.T =  f.[Value] THEN 0
                WHEN f.[Op] = 'ne' AND v.T <> f.[Value] THEN 0
                WHEN f.[Op] NOT IN ('eq', 'ne') AND v.T LIKE '%' + f.[Value] + '%' THEN 0
                ELSE 1 END
        END
    )
    OR EXISTS (
        SELECT 1
        FROM (SELECT DISTINCT [Column] FROM @FilterSet) c
        WHERE NOT EXISTS (
            SELECT 1 FROM @FilterSet s
            WHERE s.[Column] = c.[Column]
              AND s.[Value] = CASE c.[Column]
                                  WHEN 'Location'         THEN r.Location
                                  WHEN 'Decision'         THEN r.Decision
                                  WHEN 'ShiftDescription' THEN r.ShiftDescription
                                  WHEN 'TillDescription'  THEN r.TillDescription
                              END)
    );

    DECLARE @Offset INT = (@Page - 1) * @PageSize;

    SELECT *
    FROM #gz_rows
    ORDER BY
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn
                WHEN 'Location'     THEN Location
                WHEN 'EodCounter'   THEN EodCounter
                WHEN 'TerminalNo'   THEN TerminalNo
                WHEN 'LogFile'      THEN LogFile
                WHEN 'PosUserId'    THEN PosUserId
                WHEN 'EmployeeName' THEN EmployeeName
                WHEN 'Decision'     THEN Decision
            END
        END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn
                WHEN 'Location'     THEN Location
                WHEN 'EodCounter'   THEN EodCounter
                WHEN 'TerminalNo'   THEN TerminalNo
                WHEN 'LogFile'      THEN LogFile
                WHEN 'PosUserId'    THEN PosUserId
                WHEN 'EmployeeName' THEN EmployeeName
                WHEN 'Decision'     THEN Decision
            END
        END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'Sales'     THEN Sales END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'Sales'     THEN Sales END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'ZReadDate' THEN ZReadDate END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'ZReadDate' THEN ZReadDate END DESC,
        /* Oldest first, then as the till printed them: a site's day reads in
           the order it happened. */
        ZReadDate, Location, TerminalNo, EodCounter, LogFile
    OFFSET @Offset ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT COUNT_BIG(*) AS TotalRows FROM #gz_rows;

    SELECT SUM(Sales) AS Sales FROM #gz_rows;

    DROP TABLE #gz_rows;
    DROP TABLE #gz_p;
END
