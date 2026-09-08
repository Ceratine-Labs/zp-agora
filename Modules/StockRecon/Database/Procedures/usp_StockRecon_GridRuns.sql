/*
 * agora.usp_StockRecon_GridRuns — the balancing runs made at a site.
 *
 * Read by:  Stock recon centre -> Runs, and the centre's hub, which renders the
 *           same grid with no area.
 * Reads:    agora.StockReconRun, agora.Branch, agora.[User], agora.vw_StockArea
 * Writes:   nothing.
 *
 * The grid procedure template (feature-rules §2), plus four arguments the nine
 * have no room for. They are NOT header filters — the person did not type them
 * and cannot clear them — so they arrive as extra named parameters:
 *
 *   @AreaNo            which counting area's tab this is. NULL is the hub.
 *   @MineOnly          the Mine / All switch. Default 1.
 *   @UserId            who "mine" is. From the session, never the query string.
 *   @AllowedBranchIds  every site this person may see AT ALL, which is a
 *                      different question from the sites they have SELECTED.
 *                      @BranchIds is empty for a head-office user who has
 *                      selected none, and without this the grid would answer
 *                      that with the whole estate.
 *
 * @FiltersJson IS A JSON ARRAY of {column, type, op, value | in[]} objects, so
 * the column name is at $.column INSIDE each element — it is NOT the OPENJSON
 * key, which over an array is the index 0, 1, 2. Keying it the other way
 * compiles, runs, returns rows and filters nothing, which is how a shipped
 * screen carried header filters that had never narrowed anything.
 *
 * THE DATE RANGE IS THE RUN'S PERIOD, NOT WHEN IT WAS RUN, and it overlaps
 * rather than contains: a run of 25 July to 5 August is part of what happened
 * in August, and hiding it from an August search would be the screen quietly
 * dropping evidence.
 */
CREATE OR ALTER PROCEDURE [agora].[usp_StockRecon_GridRuns]
    @BranchIds        NVARCHAR(MAX) = NULL,
    @DateFrom         DATE          = NULL,
    @DateTo           DATE          = NULL,
    @Search           NVARCHAR(200) = NULL,
    @SortColumn       NVARCHAR(80)  = NULL,
    @SortAsc          BIT           = 1,
    @Page             INT           = 1,
    @PageSize         INT           = 50,
    @FiltersJson      NVARCHAR(MAX) = NULL,
    @AreaNo           INT           = NULL,
    @MineOnly         BIT           = 1,
    @UserId           INT           = NULL,
    @AllowedBranchIds NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    SET @Page     = CASE WHEN ISNULL(@Page, 1) < 1 THEN 1 ELSE @Page END;
    SET @PageSize = CASE WHEN ISNULL(@PageSize, 50) BETWEEN 1 AND 100000 THEN @PageSize ELSE 50 END;
    SET @Search   = NULLIF(LTRIM(RTRIM(ISNULL(@Search, ''))), '');

    DECLARE @Offset INT = (@Page - 1) * @PageSize;

    /* The empty-string trap, twice. STRING_SPLIT('', ',') returns ONE row
       holding an empty string and TRY_CONVERT(int, '') is 0, not NULL — so
       without the predicates below a caller who selected no branches gets a
       table containing branch 0 and the screen silently returns nothing. */
    DECLARE @Branch  TABLE (BranchId int PRIMARY KEY);
    DECLARE @Allowed TABLE (BranchId int PRIMARY KEY);

    INSERT INTO @Branch (BranchId)
    SELECT DISTINCT TRY_CONVERT(int, LTRIM(RTRIM(s.value)))
    FROM STRING_SPLIT(ISNULL(@BranchIds, ''), ',') s
    WHERE LTRIM(RTRIM(s.value)) <> '' AND TRY_CONVERT(int, LTRIM(RTRIM(s.value))) IS NOT NULL;

    INSERT INTO @Allowed (BranchId)
    SELECT DISTINCT TRY_CONVERT(int, LTRIM(RTRIM(s.value)))
    FROM STRING_SPLIT(ISNULL(@AllowedBranchIds, ''), ',') s
    WHERE LTRIM(RTRIM(s.value)) <> '' AND TRY_CONVERT(int, LTRIM(RTRIM(s.value))) IS NOT NULL;

    DECLARE @AllBranches bit = CASE WHEN EXISTS (SELECT 1 FROM @Branch)  THEN 0 ELSE 1 END;
    /* An EMPTY grant list means every branch, not none — the same reading
       BranchContext::maySee() and BranchScope apply. Reading it the other way
       does not fail loudly: the screen comes back empty for the administrator
       who can see the most. */
    DECLARE @AllAllowed  bit = CASE WHEN EXISTS (SELECT 1 FROM @Allowed) THEN 0 ELSE 1 END;

    DECLARE @Filter    TABLE ([Column] nvarchar(80), [Op] nvarchar(20), [Value] nvarchar(400));
    DECLARE @FilterSet TABLE ([Column] nvarchar(80), [Value] nvarchar(400));

    IF @FiltersJson IS NOT NULL
    BEGIN
        INSERT INTO @Filter ([Column], [Op], [Value])
        SELECT JSON_VALUE(f.value, '$.column'),
               ISNULL(JSON_VALUE(f.value, '$.op'), 'contains'),
               JSON_VALUE(f.value, '$.value')
        FROM OPENJSON(@FiltersJson) f
        WHERE JSON_VALUE(f.value, '$.value') IS NOT NULL;

        INSERT INTO @FilterSet ([Column], [Value])
        SELECT JSON_VALUE(f.value, '$.column'), v.value
        FROM OPENJSON(@FiltersJson) f
        CROSS APPLY OPENJSON(f.value, '$.in') v
        WHERE JSON_VALUE(f.value, '$.type') = 'set';
    END

    DECLARE @fNote     NVARCHAR(400) = (SELECT TOP 1 [Value] FROM @Filter WHERE [Column] = 'Note'),
            @fNoteOp   NVARCHAR(20)  = (SELECT TOP 1 [Op]    FROM @Filter WHERE [Column] = 'Note'),
            @fBranch   NVARCHAR(400) = (SELECT TOP 1 [Value] FROM @Filter WHERE [Column] = 'BranchName'),
            @fBranchOp NVARCHAR(20)  = (SELECT TOP 1 [Op]    FROM @Filter WHERE [Column] = 'BranchName'),
            @fArea     NVARCHAR(400) = (SELECT TOP 1 [Value] FROM @Filter WHERE [Column] = 'AreaName'),
            @fAreaOp   NVARCHAR(20)  = (SELECT TOP 1 [Op]    FROM @Filter WHERE [Column] = 'AreaName'),
            @fRunBy    NVARCHAR(400) = (SELECT TOP 1 [Value] FROM @Filter WHERE [Column] = 'RunBy'),
            @fRunByOp  NVARCHAR(20)  = (SELECT TOP 1 [Op]    FROM @Filter WHERE [Column] = 'RunBy');

    DECLARE @fStatusAny bit = CASE WHEN EXISTS (SELECT 1 FROM @FilterSet WHERE [Column] = 'Status') THEN 1 ELSE 0 END;

    ;WITH base AS (
        SELECT
            r.Id,
            r.BranchId,
            b.Name AS BranchName,
            r.AreaNo,
            /* Every area is a real answer, not a missing one, so it is named
               rather than left as a dash the reader has to interpret. */
            ISNULL(a.AreaDescription, CASE WHEN r.AreaNo IS NULL THEN 'Every area' ELSE 'Area ' + CONVERT(nvarchar(10), r.AreaNo) END) AS AreaName,
            /* The run's own name, and a readable stand-in when nobody gave it
               one. A column of forty dashes is a column nobody can navigate,
               and the filter above then matches what is on screen. */
            ISNULL(NULLIF(LTRIM(RTRIM(r.Note)), ''),
                   CONVERT(nvarchar(10), r.FromDate, 120) + ' to ' + CONVERT(nvarchar(10), r.ToDate, 120)) AS Note,
            r.FromDate, r.ToDate, r.[Status], r.StampMode, r.ProcedureName,
            r.TotalRows, r.DormantRows, r.ChainCount, r.BalanceableChains, r.BlockedChains,
            r.AmendedRows, r.UnitsAmended,
            r.OverRowsBefore, r.OverRowsAfter, r.ShortRowsBefore, r.ShortRowsAfter,
            r.NetOverUnits, r.NetOverValue, r.ShortValueAfter,
            r.CommittedRows, r.CommittedUnits, r.PreviewMs, r.CreatedAt,
            u.UserName AS RunBy,
            /* Whether this run is still open work. Returned rather than derived
               on the page, so the row action and the resume card cannot
               disagree about it. */
            CONVERT(bit, CASE WHEN r.[Status] = 'previewed' THEN 1 ELSE 0 END) AS IsOpen
        FROM agora.StockReconRun r
        LEFT JOIN agora.Branch b ON b.BranchId = r.BranchId AND b.DeletedAt IS NULL
        LEFT JOIN agora.[User] u ON u.Id = r.CreatedBy
        LEFT JOIN agora.vw_StockArea a ON a.BranchId = r.BranchId AND a.AreaNo = r.AreaNo
        WHERE (@AreaNo IS NULL OR r.AreaNo = @AreaNo)
          AND (@AllBranches = 1 OR r.BranchId IN (SELECT BranchId FROM @Branch))
          AND (@AllAllowed  = 1 OR r.BranchId IN (SELECT BranchId FROM @Allowed))
          /* @MineOnly with no @UserId is NO runs rather than every run: a
             filter that fails open is the one that leaks. */
          AND (@MineOnly = 0 OR (@UserId IS NOT NULL AND r.CreatedBy = @UserId))
          AND (@DateFrom IS NULL OR r.ToDate   >= @DateFrom)
          AND (@DateTo   IS NULL OR r.FromDate <= @DateTo)
    )
    /* Materialised once, then paged and counted from the same rows. Repeating
       the query in the page and again in the count is how a predicate ends up
       in one of them and not the other, and a grid whose footer disagrees with
       its own rows is worse than one that is slow. */
    SELECT * INTO #Rows FROM base
    WHERE (@Search IS NULL
           OR base.Note       LIKE '%' + @Search + '%'
           OR base.BranchName LIKE '%' + @Search + '%'
           OR base.AreaName   LIKE '%' + @Search + '%'
           OR base.RunBy      LIKE '%' + @Search + '%'
           OR CONVERT(nvarchar(20), base.Id) = @Search)
      AND (@fNote   IS NULL OR (CASE WHEN @fNoteOp   = 'eq' THEN CASE WHEN base.Note       =        @fNote        THEN 1 ELSE 0 END
                                     ELSE                        CASE WHEN base.Note       LIKE '%'+@fNote  +'%' THEN 1 ELSE 0 END END) = 1)
      AND (@fBranch IS NULL OR (CASE WHEN @fBranchOp = 'eq' THEN CASE WHEN base.BranchName =        @fBranch      THEN 1 ELSE 0 END
                                     ELSE                        CASE WHEN base.BranchName LIKE '%'+@fBranch+'%' THEN 1 ELSE 0 END END) = 1)
      AND (@fArea   IS NULL OR (CASE WHEN @fAreaOp   = 'eq' THEN CASE WHEN base.AreaName   =        @fArea        THEN 1 ELSE 0 END
                                     ELSE                        CASE WHEN base.AreaName   LIKE '%'+@fArea  +'%' THEN 1 ELSE 0 END END) = 1)
      AND (@fRunBy  IS NULL OR (CASE WHEN @fRunByOp  = 'eq' THEN CASE WHEN base.RunBy      =        @fRunBy       THEN 1 ELSE 0 END
                                     ELSE                        CASE WHEN base.RunBy      LIKE '%'+@fRunBy +'%' THEN 1 ELSE 0 END END) = 1)
      AND (@fStatusAny = 0 OR base.[Status] IN (SELECT [Value] FROM @FilterSet WHERE [Column] = 'Status'));

    SELECT
        Id, Note, BranchId, BranchName, AreaNo, AreaName, FromDate, ToDate, [Status], RunBy, CreatedAt,
        TotalRows, DormantRows, ChainCount, BalanceableChains, BlockedChains, AmendedRows, UnitsAmended,
        OverRowsBefore, OverRowsAfter, ShortRowsBefore, ShortRowsAfter,
        NetOverUnits, NetOverValue, ShortValueAfter,
        CommittedRows, CommittedUnits, PreviewMs, StampMode, ProcedureName, IsOpen
    FROM #Rows
    ORDER BY
        /* Text and date sorts kept apart so each compares as itself — '10'
           after '9', not before it. */
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn
                WHEN 'Note'       THEN Note
                WHEN 'BranchName' THEN BranchName
                WHEN 'AreaName'   THEN AreaName
                WHEN 'Status'     THEN [Status]
                WHEN 'RunBy'      THEN RunBy
            END
        END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn
                WHEN 'Note'       THEN Note
                WHEN 'BranchName' THEN BranchName
                WHEN 'AreaName'   THEN AreaName
                WHEN 'Status'     THEN [Status]
                WHEN 'RunBy'      THEN RunBy
            END
        END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'FromDate'        THEN FromDate END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'FromDate'        THEN FromDate END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'ToDate'          THEN ToDate END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'ToDate'          THEN ToDate END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'TotalRows'       THEN TotalRows END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'TotalRows'       THEN TotalRows END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'AmendedRows'     THEN AmendedRows END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'AmendedRows'     THEN AmendedRows END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'BlockedChains'   THEN BlockedChains END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'BlockedChains'   THEN BlockedChains END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'NetOverValue'    THEN NetOverValue END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'NetOverValue'    THEN NetOverValue END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'ShortValueAfter' THEN ShortValueAfter END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'ShortValueAfter' THEN ShortValueAfter END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'CreatedAt'       THEN CreatedAt END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'CreatedAt'       THEN CreatedAt END DESC,
        /* Newest first is the default and also the tie-break: the run a person
           wants is almost always the one they just made. */
        Id DESC
    OFFSET @Offset ROWS FETCH NEXT @PageSize ROWS ONLY;

    /* Result set 2: the size of the whole filtered set, by contract. */
    SELECT COUNT_BIG(*) AS TotalRows FROM #Rows;

    DROP TABLE #Rows;
END
