/* ============================================================================
   agora.usp_Reports_GridUserActivity

   WHAT IT IS FOR
   User activity (T027): who signed in, out, was refused, reset or changed a
   password, from where and when, newest first.

   WHERE IT COMES FROM
   agora.UserActivity, written only by agora.usp_Core_LogActivity, joined to
   agora.[User] for the person. OccurredAt is UTC (Modules/Core
   ActivityLogger stamps it) and is shown in SAST, moved by the server
   offset. BranchId is the person's own branch — the group entity for a
   head-office user — so the branches selector narrows by whose row it is.
   The default window is the last 30 days.

   WHO READS IT
   Head office, Setup → Governance → User activity
   (Modules/Reports/Grids/UserActivityGrid). @Authorized is the caller's
   reports.user-activity.view, resolved in PHP: without it the procedure
   refuses, so the extract cannot be reached around the screen.
   @AllowedBranchIds is the caller's sites (BranchContext); the branches
   picked are narrowed to it, and none left means no rows. The same
   filtered set drives the page, the total and the extract. Deployed by
   v1__22ph.

   READ-ONLY.

     EXEC agora.usp_Reports_GridUserActivity @Authorized = 1, @Search = 'admin';
   ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Reports_GridUserActivity]
    @BranchIds   NVARCHAR(MAX) = NULL,
    @DateFrom    DATE          = NULL,
    @DateTo      DATE          = NULL,
    @Search      NVARCHAR(200) = NULL,
    @SortColumn  NVARCHAR(80)  = NULL,
    @SortAsc     BIT           = 0,
    @Page        INT           = 1,
    @PageSize    INT           = 50,
    @FiltersJson NVARCHAR(MAX) = NULL,
    @Authorized  BIT           = 0,
    @AllowedBranchIds NVARCHAR(MAX) = NULL   -- the caller's sites; NULL = unrestricted
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;   -- it fills temp tables, and the checker counts those as writes

    IF ISNULL(@Authorized, 0) <> 1
        THROW 51000, 'AGORA:DENIED:You do not have permission to see user activity.', 1;

    SET @DateTo   = ISNULL(@DateTo, CONVERT(date, GETDATE()));
    SET @DateFrom = ISNULL(@DateFrom, DATEADD(day, -30, @DateTo));
    SET @Page     = CASE WHEN ISNULL(@Page, 1) < 1 THEN 1 ELSE @Page END;
    SET @PageSize = CASE WHEN ISNULL(@PageSize, 50) BETWEEN 1 AND 100000 THEN @PageSize ELSE 50 END;
    SET @Search   = NULLIF(LTRIM(RTRIM(ISNULL(@Search, ''))), '');
    SET @SortColumn = NULLIF(@SortColumn, '');

    DECLARE @Sast int = DATEPART(tzoffset, SYSDATETIMEOFFSET());
    /* The SAST window, as the UTC instants the column holds. */
    DECLARE @From datetime2(0) = DATEADD(minute, -@Sast, CONVERT(datetime2(0), @DateFrom)),
            @To   datetime2(0) = DATEADD(minute, -@Sast, DATEADD(day, 1, CONVERT(datetime2(0), @DateTo)));

    /* The sites: those picked (an empty pick is every site), narrowed to the
       caller's own when they are restricted. The empty-string trap:
       STRING_SPLIT('') is one row holding '', and TRY_CONVERT(int, '') is 0,
       not NULL. */
    DECLARE @Picked TABLE (BranchId int PRIMARY KEY);
    DECLARE @Allowed TABLE (BranchId int PRIMARY KEY);
    DECLARE @Branch TABLE (BranchId int PRIMARY KEY);

    INSERT INTO @Picked (BranchId)
    SELECT DISTINCT TRY_CONVERT(int, LTRIM(RTRIM(s.value)))
    FROM STRING_SPLIT(ISNULL(@BranchIds, ''), ',') s
    WHERE LTRIM(RTRIM(s.value)) <> ''
      AND TRY_CONVERT(int, LTRIM(RTRIM(s.value))) IS NOT NULL;

    INSERT INTO @Allowed (BranchId)
    SELECT DISTINCT TRY_CONVERT(int, LTRIM(RTRIM(s.value)))
    FROM STRING_SPLIT(ISNULL(@AllowedBranchIds, ''), ',') s
    WHERE LTRIM(RTRIM(s.value)) <> ''
      AND TRY_CONVERT(int, LTRIM(RTRIM(s.value))) IS NOT NULL;

    INSERT INTO @Branch (BranchId)
    SELECT p.BranchId FROM @Picked p
    WHERE @AllowedBranchIds IS NULL OR p.BranchId IN (SELECT BranchId FROM @Allowed);

    IF @AllowedBranchIds IS NOT NULL AND NOT EXISTS (SELECT 1 FROM @Picked)
        INSERT INTO @Branch (BranchId) SELECT BranchId FROM @Allowed;

    /* Something asked for, and none of it allowed: nothing, not everything. */
    IF (@AllowedBranchIds IS NOT NULL OR EXISTS (SELECT 1 FROM @Picked)) AND NOT EXISTS (SELECT 1 FROM @Branch)
        INSERT INTO @Branch (BranchId) VALUES (-1);

    DECLARE @AllBranches bit = CASE WHEN EXISTS (SELECT 1 FROM @Branch) THEN 0 ELSE 1 END;

    SELECT a.BranchId,
           ISNULL(b.Name, CONCAT(N'Site ', a.BranchId))                     AS BranchName,
           CONVERT(datetime2(0), DATEADD(minute, @Sast, a.OccurredAt))      AS OccurredAt,
           a.UserId,
           CONVERT(nvarchar(200), ISNULL(u.UserName, CONCAT(N'User ', a.UserId))) COLLATE DATABASE_DEFAULT AS UserName,
           CONVERT(nvarchar(200), u.EmailAddress) COLLATE DATABASE_DEFAULT AS EmailAddress,
           CONVERT(nvarchar(60), CASE a.Activity WHEN 'signin'           THEN N'Signed in'
                                                 WHEN 'signout'          THEN N'Signed out'
                                                 WHEN 'signin-refused'   THEN N'Sign-in refused'
                                                 WHEN 'password-reset'   THEN N'Password reset'
                                                 WHEN 'password-changed' THEN N'Password changed'
                                                 ELSE a.Activity END) COLLATE DATABASE_DEFAULT AS Activity,
           CONVERT(nvarchar(40), a.Activity) COLLATE DATABASE_DEFAULT       AS ActivityCode,
           CONVERT(nvarchar(400), a.Detail) COLLATE DATABASE_DEFAULT        AS Detail,
           CONVERT(nvarchar(45), a.IpAddress) COLLATE DATABASE_DEFAULT      AS IpAddress,
           CONVERT(nvarchar(300), a.UserAgent) COLLATE DATABASE_DEFAULT     AS UserAgent,
           a.Id
    INTO #Rows
    FROM agora.UserActivity a
    LEFT JOIN agora.vw_Branch b ON b.BranchId = a.BranchId
    OUTER APPLY (SELECT TOP 1 x.UserName, x.EmailAddress FROM agora.[User] x WHERE x.Id = a.UserId) u
    WHERE a.OccurredAt >= @From AND a.OccurredAt < @To
      AND (@AllBranches = 1 OR a.BranchId IN (SELECT BranchId FROM @Branch))
      AND (@Search IS NULL
           OR u.UserName     LIKE '%' + @Search + '%'
           OR u.EmailAddress LIKE '%' + @Search + '%'
           OR a.Activity     LIKE '%' + @Search + '%'
           OR a.Detail       LIKE '%' + @Search + '%'
           OR a.IpAddress    LIKE '%' + @Search + '%'
           OR b.Name         LIKE '%' + @Search + '%');

    /* The header filters: {column, type, op, value}, or {column, type: set, in}. */
    DECLARE @Filter TABLE ([Column] nvarchar(80), [Type] nvarchar(10), [Op] nvarchar(10), [Value] nvarchar(400));
    DECLARE @FilterSet TABLE ([Column] nvarchar(80), [Value] nvarchar(400));

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

    SELECT r.*
    INTO #Filtered
    FROM #Rows r
    WHERE NOT EXISTS (
        SELECT 1
        FROM @Filter f
        CROSS APPLY (SELECT
            CASE f.[Column]
                WHEN 'BranchName'   THEN r.BranchName
                WHEN 'UserName'     THEN r.UserName
                WHEN 'EmailAddress' THEN r.EmailAddress
                WHEN 'Activity'     THEN r.Activity
                WHEN 'Detail'       THEN r.Detail
                WHEN 'IpAddress'    THEN r.IpAddress
                WHEN 'UserAgent'    THEN r.UserAgent
            END AS T,
            CASE f.[Column] WHEN 'UserId' THEN CONVERT(decimal(19,4), r.UserId) END AS N,
            CASE f.[Column] WHEN 'OccurredAt' THEN CONVERT(date, r.OccurredAt) END AS D,
            TRY_CONVERT(decimal(19,4), f.[Value]) AS FN,
            TRY_CONVERT(date, f.[Value]) AS FD
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
                WHEN f.[Op] = 'eq'  AND v.T =  f.[Value] THEN 0
                WHEN f.[Op] = 'ne'  AND v.T <> f.[Value] THEN 0
                WHEN f.[Op] = 'gt'  AND v.T >  f.[Value] THEN 0
                WHEN f.[Op] = 'gte' AND v.T >= f.[Value] THEN 0
                WHEN f.[Op] = 'lt'  AND v.T <  f.[Value] THEN 0
                WHEN f.[Op] = 'lte' AND v.T <= f.[Value] THEN 0
                WHEN f.[Op] NOT IN ('eq', 'ne', 'gt', 'gte', 'lt', 'lte') AND v.T LIKE '%' + f.[Value] + '%' THEN 0
                ELSE 1 END
        END
    )
    AND NOT EXISTS (
        SELECT 1 FROM (SELECT DISTINCT [Column] FROM @FilterSet) fs
        WHERE NOT EXISTS (
            SELECT 1 FROM @FilterSet x
            WHERE x.[Column] = fs.[Column]
              AND x.[Value] = CASE fs.[Column] WHEN 'Activity' THEN r.Activity END)
    );

    SELECT BranchId, BranchName, OccurredAt, UserId, UserName, EmailAddress, Activity, ActivityCode,
           Detail, IpAddress, UserAgent, Id
    FROM #Filtered
    ORDER BY
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'BranchName'   THEN BranchName
                             WHEN 'UserName'     THEN UserName
                             WHEN 'EmailAddress' THEN EmailAddress
                             WHEN 'Activity'     THEN Activity
                             WHEN 'IpAddress'    THEN IpAddress END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'BranchName'   THEN BranchName
                             WHEN 'UserName'     THEN UserName
                             WHEN 'EmailAddress' THEN EmailAddress
                             WHEN 'Activity'     THEN Activity
                             WHEN 'IpAddress'    THEN IpAddress END END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'OccurredAt' THEN OccurredAt END ASC,
        OccurredAt DESC, Id DESC
    OFFSET (@Page - 1) * @PageSize ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT COUNT_BIG(*) AS TotalRows FROM #Filtered;
END
