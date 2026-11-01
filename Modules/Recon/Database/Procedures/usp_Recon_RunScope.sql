/* Exact shared run-list scope. Read-only; no pagination or export ceiling.
   GridRuns and ArchiveInvalidRuns consume these IDs, so branch grants, Mine,
   period overlap, search and header filters cannot diverge. NULL selection
   means all matching runs; [] means none. Never accepts an identity from UI. */
CREATE OR ALTER PROCEDURE [agora].[usp_Recon_RunScope]
    @BranchIds nvarchar(max) = NULL,
    @DateFrom date = NULL,
    @DateTo date = NULL,
    @Search nvarchar(200) = NULL,
    @FiltersJson nvarchar(max) = NULL,
    @ReconArea nvarchar(20) = NULL,
    @MineOnly bit = 1,
    @UserId int = NULL,
    @AllowedBranchIds nvarchar(max) = NULL,
    @OpenOnly bit = 0,
    @RunIdsJson nvarchar(max) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET @Search = NULLIF(LTRIM(RTRIM(ISNULL(@Search, ''))), '');
    DECLARE @Selected TABLE (RunId bigint PRIMARY KEY);
    IF @RunIdsJson IS NOT NULL
    BEGIN
        IF ISJSON(@RunIdsJson) <> 1 OR LEFT(LTRIM(@RunIdsJson), 1) <> '['
            THROW 51000, 'AGORA:BAD_RUN_IDS:Choose a list of run numbers.', 1;
        IF EXISTS (SELECT 1 FROM OPENJSON(@RunIdsJson)
                   WHERE [type] NOT IN (1,2) OR TRY_CONVERT(bigint, [value]) IS NULL OR TRY_CONVERT(bigint, [value]) <= 0)
            THROW 51000, 'AGORA:BAD_RUN_IDS:Every selected run must be a positive whole run number.', 1;
        INSERT INTO @Selected SELECT DISTINCT CONVERT(bigint, [value]) FROM OPENJSON(@RunIdsJson);
    END
    /* The empty-string trap, twice. STRING_SPLIT('', ',') returns ONE row
       holding an empty string and TRY_CONVERT(int, '') is 0, not NULL — so
       without the predicates below a caller who selected no branches gets a
       table containing branch 0 and this screen silently returns nothing. */
    DECLARE @Branch  TABLE (BranchId int PRIMARY KEY);
    DECLARE @Allowed TABLE (BranchId int PRIMARY KEY);

    INSERT INTO @Branch (BranchId)
    SELECT DISTINCT TRY_CONVERT(int, LTRIM(RTRIM(s.value)))
    FROM STRING_SPLIT(ISNULL(@BranchIds, ''), ',') s
    WHERE LTRIM(RTRIM(s.value)) <> ''
      AND TRY_CONVERT(int, LTRIM(RTRIM(s.value))) IS NOT NULL;

    INSERT INTO @Allowed (BranchId)
    SELECT DISTINCT TRY_CONVERT(int, LTRIM(RTRIM(s.value)))
    FROM STRING_SPLIT(ISNULL(@AllowedBranchIds, ''), ',') s
    WHERE LTRIM(RTRIM(s.value)) <> ''
      AND TRY_CONVERT(int, LTRIM(RTRIM(s.value))) IS NOT NULL;

    DECLARE @AllBranches bit = CASE WHEN EXISTS (SELECT 1 FROM @Branch)  THEN 0 ELSE 1 END;
    /* An EMPTY grant list means every branch, not none — the same reading
       BranchContext::maySee() and BranchScope apply. Reading it the other way
       does not fail loudly: the screen simply comes back empty for the
       administrator who can see the most. */
    DECLARE @AllAllowed  bit = CASE WHEN EXISTS (SELECT 1 FROM @Allowed) THEN 0 ELSE 1 END;

    /* The header filters, in the shape the grid actually sends. A filter on a
       column this screen does not offer is ignored rather than refused: the
       definition decides what is filterable and this reads what arrived. */
    DECLARE @Filter TABLE ([Column] nvarchar(80), [Op] nvarchar(20), [Value] nvarchar(400));
    DECLARE @FilterSet TABLE ([Column] nvarchar(80), [Value] nvarchar(400));

    IF @FiltersJson IS NOT NULL
    BEGIN
        INSERT INTO @Filter ([Column], [Op], [Value])
        SELECT JSON_VALUE(f.value, '$.column'),
               ISNULL(JSON_VALUE(f.value, '$.op'), 'contains'),
               JSON_VALUE(f.value, '$.value')
        FROM OPENJSON(@FiltersJson) f
        WHERE JSON_VALUE(f.value, '$.value') IS NOT NULL;

        /* A tick list is an array inside the element, so it needs its own
           pass. Flattened to one row per ticked value, which is what an IN
           wants. */
        INSERT INTO @FilterSet ([Column], [Value])
        SELECT JSON_VALUE(f.value, '$.column'), v.value
        FROM OPENJSON(@FiltersJson) f
        CROSS APPLY OPENJSON(f.value, '$.in') v
        WHERE JSON_VALUE(f.value, '$.type') = 'set';
    END

    DECLARE @fNote    NVARCHAR(400) = (SELECT TOP 1 [Value] FROM @Filter WHERE [Column] = 'Note'),
            @fNoteOp  NVARCHAR(20)  = (SELECT TOP 1 [Op]    FROM @Filter WHERE [Column] = 'Note'),
            @fBranch  NVARCHAR(400) = (SELECT TOP 1 [Value] FROM @Filter WHERE [Column] = 'BranchName'),
            @fBranchOp NVARCHAR(20) = (SELECT TOP 1 [Op]    FROM @Filter WHERE [Column] = 'BranchName'),
            @fRunBy   NVARCHAR(400) = (SELECT TOP 1 [Value] FROM @Filter WHERE [Column] = 'RunBy'),
            @fRunByOp NVARCHAR(20)  = (SELECT TOP 1 [Op]    FROM @Filter WHERE [Column] = 'RunBy');

    DECLARE @fId bigint = (SELECT TOP 1 TRY_CONVERT(bigint, [Value]) FROM @Filter WHERE [Column] = 'Id'),
            @fIdOp nvarchar(20) = (SELECT TOP 1 [Op] FROM @Filter WHERE [Column] = 'Id');

    DECLARE @fStatusAny bit = CASE WHEN EXISTS (SELECT 1 FROM @FilterSet WHERE [Column] = 'Status') THEN 1 ELSE 0 END;

    /* Scope predicates operate on exactly the displayed names. */
    ;WITH base AS (
        SELECT r.Id, r.Status, b.Name AS BranchName, u.UserName AS RunBy,
               ISNULL(NULLIF(LTRIM(RTRIM(r.Note)), ''),
                   CONVERT(nvarchar(10), r.FromDate, 120) + ' to ' + CONVERT(nvarchar(10), r.ToDate, 120)) AS Note
        FROM agora.ReconRun r
        LEFT JOIN agora.Branch b ON b.BranchId = r.BranchId AND b.DeletedAt IS NULL
        LEFT JOIN agora.[User] u ON u.Id = r.CreatedBy
        WHERE (@ReconArea IS NULL OR r.ReconArea = @ReconArea)
          AND (@AllBranches = 1 OR r.BranchId IN (SELECT BranchId FROM @Branch))
          AND (@AllAllowed  = 1 OR r.BranchId IN (SELECT BranchId FROM @Allowed))
          /* @MineOnly with no @UserId is NO runs rather than every run: a
             filter that fails open is the one that leaks. */
          AND (@MineOnly = 0 OR (@UserId IS NOT NULL AND r.CreatedBy = @UserId))
          AND (ISNULL(@OpenOnly, 0) = 0 OR r.Status IN ('previewing', 'previewed'))
          AND (@DateFrom IS NULL OR r.ToDate   >= @DateFrom)
          AND (@DateTo   IS NULL OR r.FromDate <= @DateTo)
          AND (@RunIdsJson IS NULL OR r.Id IN (SELECT RunId FROM @Selected))
    )
    SELECT Id AS RunId
    FROM base
    WHERE (@Search IS NULL
           OR base.Note       LIKE '%' + @Search + '%'
           OR base.BranchName LIKE '%' + @Search + '%'
           OR base.RunBy      LIKE '%' + @Search + '%'
           OR CONVERT(nvarchar(20), base.Id) = @Search)
      AND (@fNote   IS NULL OR (CASE WHEN @fNoteOp   = 'eq' THEN CASE WHEN base.Note       =        @fNote        THEN 1 ELSE 0 END
                                     ELSE                        CASE WHEN base.Note       LIKE '%'+@fNote  +'%' THEN 1 ELSE 0 END END) = 1)
      AND (@fBranch IS NULL OR (CASE WHEN @fBranchOp = 'eq' THEN CASE WHEN base.BranchName =        @fBranch      THEN 1 ELSE 0 END
                                     ELSE                        CASE WHEN base.BranchName LIKE '%'+@fBranch+'%' THEN 1 ELSE 0 END END) = 1)
      AND (@fRunBy  IS NULL OR (CASE WHEN @fRunByOp  = 'eq' THEN CASE WHEN base.RunBy      =        @fRunBy       THEN 1 ELSE 0 END
                                     ELSE                        CASE WHEN base.RunBy      LIKE '%'+@fRunBy +'%' THEN 1 ELSE 0 END END) = 1)
      AND (@fId IS NULL OR (@fIdOp = 'eq' AND base.Id = @fId)
           OR (@fIdOp = 'ne' AND base.Id <> @fId) OR (@fIdOp = 'gt' AND base.Id > @fId)
           OR (@fIdOp = 'gte' AND base.Id >= @fId) OR (@fIdOp = 'lt' AND base.Id < @fId)
           OR (@fIdOp = 'lte' AND base.Id <= @fId))
      AND (@fStatusAny = 0 OR base.Status IN (SELECT [Value] FROM @FilterSet WHERE [Column] = 'Status'));

END
