/*
 * agora.usp_Core_GridUsers — the people who can sign in, and what they may do.
 *
 * Read by:  Setup -> People and assets -> Users and access (T028)
 * Reads:    agora.User, agora.Role, agora.UserRole, agora.UserBranch
 * Writes:   nothing.
 *
 * The grid procedure template (feature-rules §2), nine parameters. @FiltersJson
 * is declared because the header filters on this screen are the point: with 88
 * people, finding "the branch users who have never signed in" is the question,
 * and a global search box cannot ask it.
 *
 * THE SHAPE OF @FiltersJson, which this procedure had WRONG from the day it
 * shipped until 8 September 2026. GridQuery::filtersJson() sends a JSON ARRAY
 * of the objects GridFilter::normalise() produced:
 *
 *     [{"column":"UserName","type":"text","op":"contains","value":"Ryan"},
 *      {"column":"UserType","type":"set","in":["ho","branch"]}]
 *
 * The column name is INSIDE each element, at $.column. It is NOT the OPENJSON
 * key — over an array that key is the index, 0, 1, 2 — and there is no $.q
 * anywhere in the payload. This procedure read it the other way, so every
 * @f* variable stayed NULL, every filter predicate short-circuited to true,
 * and typing in a header filter on Setup -> Users and access narrowed nothing
 * at all. It never errored and it never returned the wrong rows; it returned
 * ALL of them, silently, which is why it survived review and a passing test
 * suite. Any test that filters on a value which IS present passes either way —
 * the only test that catches it is one that filters on a value that matches
 * nothing.
 *
 * agora.usp_Recon_GridRuns and agora.usp_Recon_GridCriteria parse it correctly
 * and docs/grid.md carries the worked example. scripts/check-procs.sh now
 * refuses a procedure that declares @FiltersJson and never looks at $.column.
 *
 * BranchId is the group entity here rather than a site. A user is a group-level
 * row — the branches they may SEE are a separate grant in agora.UserBranch, and
 * BranchCount below is that count, not this parameter. Conflating the two is
 * how a head-office administrator would vanish from their own screen.
 *
 * RoleNames is a comma-joined list because a person may hold more than one, and
 * the primary is named separately: the landing route comes from the primary and
 * a reader needs to see which one that is without opening the row.
 */
CREATE OR ALTER PROCEDURE agora.usp_Core_GridUsers
    @BranchIds   NVARCHAR(MAX) = NULL,
    @DateFrom    DATE          = NULL,
    @DateTo      DATE          = NULL,
    @Search      NVARCHAR(200) = NULL,
    @SortColumn  NVARCHAR(80)  = NULL,
    @SortAsc     BIT           = 1,
    @Page        INT           = 1,
    @PageSize    INT           = 50,
    @FiltersJson NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;

    DECLARE @Offset INT = (CASE WHEN @Page < 1 THEN 0 ELSE (@Page - 1) END) * @PageSize;

    /* Typed header filters, in the shape the grid actually sends — see the
       header. A filter on a column this screen does not offer is ignored
       rather than refused: the definition decides what is filterable and this
       reads whatever arrived. */
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
           wants. UserType is the one set filter this screen offers. */
        INSERT INTO @FilterSet ([Column], [Value])
        SELECT JSON_VALUE(f.value, '$.column'), v.value
        FROM OPENJSON(@FiltersJson) f
        CROSS APPLY OPENJSON(f.value, '$.in') v
        WHERE JSON_VALUE(f.value, '$.type') = 'set';
    END

    DECLARE @fUserName  NVARCHAR(400) = (SELECT TOP 1 [Value] FROM @Filter WHERE [Column] = 'UserName'),
            @fUserNameOp NVARCHAR(20) = (SELECT TOP 1 [Op]    FROM @Filter WHERE [Column] = 'UserName'),
            @fEmail     NVARCHAR(400) = (SELECT TOP 1 [Value] FROM @Filter WHERE [Column] = 'EmailAddress'),
            @fEmailOp   NVARCHAR(20)  = (SELECT TOP 1 [Op]    FROM @Filter WHERE [Column] = 'EmailAddress'),
            @fRoleNames NVARCHAR(400) = (SELECT TOP 1 [Value] FROM @Filter WHERE [Column] = 'RoleNames'),
            @fStatus    NVARCHAR(400) = (SELECT TOP 1 [Value] FROM @Filter WHERE [Column] = 'Status');

    /* UserType is declared as a SET filter on UserGrid, so it arrives as a
       tick list rather than as a typed value. Reading it as text was the
       second half of the same bug: even with the parse corrected, a set filter
       has no $.value at all. */
    DECLARE @fTypeAny bit = CASE WHEN EXISTS (SELECT 1 FROM @FilterSet WHERE [Column] = 'UserType') THEN 1 ELSE 0 END;

    ;WITH people AS (
        SELECT
            u.Id,
            u.BranchId,
            u.UserCode,
            u.UserName,
            u.EmailAddress,
            u.UserType,
            u.IsActive,
            u.IsLocked,
            u.MustChangePassword,
            u.LastSignInAt,
            primary_role.Name AS PrimaryRole,
            STUFF((
                SELECT ', ' + r2.Name
                FROM agora.UserRole ur2
                JOIN agora.Role r2 ON r2.Id = ur2.RoleId
                WHERE ur2.UserId = u.Id
                ORDER BY r2.SortOrder
                FOR XML PATH(''), TYPE
            ).value('.', 'NVARCHAR(MAX)'), 1, 2, '') AS RoleNames,
            (SELECT COUNT(*) FROM agora.UserBranch ub WHERE ub.UserId = u.Id) AS BranchCount
        FROM agora.[User] u
        OUTER APPLY (
            SELECT TOP 1 r.Name
            FROM agora.UserRole ur
            JOIN agora.Role r ON r.Id = ur.RoleId
            WHERE ur.UserId = u.Id AND ur.IsPrimary = 1
        ) primary_role
        /* Soft-deleted people are gone from the screens, not from the estate:
           agora.User keeps the row so everything they ever wrote stays
           attributable, and every read has to say so. Eloquent applies this
           through the SoftDeletes trait; a procedure has no trait, so it says
           it here — and until it did, a removed user was still listed. */
        WHERE u.DeletedAt IS NULL
    ),
    filtered AS (
        SELECT *
        FROM people p
        WHERE (@Search IS NULL OR @Search = ''
               OR p.UserName    LIKE '%' + @Search + '%'
               OR p.EmailAddress LIKE '%' + @Search + '%'
               OR p.UserCode    LIKE '%' + @Search + '%')
          AND (@fUserName  IS NULL OR (CASE WHEN @fUserNameOp = 'eq' THEN CASE WHEN p.UserName = @fUserName THEN 1 ELSE 0 END
                                            ELSE CASE WHEN p.UserName LIKE '%' + @fUserName + '%' THEN 1 ELSE 0 END END) = 1)
          AND (@fEmail     IS NULL OR (CASE WHEN @fEmailOp = 'eq' THEN CASE WHEN p.EmailAddress = @fEmail THEN 1 ELSE 0 END
                                            ELSE CASE WHEN p.EmailAddress LIKE '%' + @fEmail + '%' THEN 1 ELSE 0 END END) = 1)
          AND (@fTypeAny = 0 OR p.UserType IN (SELECT [Value] FROM @FilterSet WHERE [Column] = 'UserType'))
          AND (@fRoleNames IS NULL OR p.RoleNames    LIKE '%' + @fRoleNames + '%')
          AND (@fStatus    IS NULL OR
               (CASE WHEN p.IsLocked = 1 THEN 'Locked'
                     WHEN p.IsActive = 0 THEN 'Inactive'
                     WHEN p.MustChangePassword = 1 THEN 'Must reset password'
                     WHEN p.LastSignInAt IS NULL THEN 'Never signed in'
                     ELSE 'Active' END) LIKE '%' + @fStatus + '%')
          /* Never signed in sorts and filters as a real state, not as a NULL
             the reader has to interpret. */
          AND (@DateFrom IS NULL OR p.LastSignInAt >= @DateFrom)
          AND (@DateTo   IS NULL OR p.LastSignInAt <  DATEADD(DAY, 1, @DateTo))
    )

    SELECT
        f.Id,
        f.UserCode,
        f.UserName,
        f.EmailAddress,
        f.UserType,
        f.PrimaryRole,
        f.RoleNames,
        f.BranchCount,
        f.LastSignInAt,
        CASE WHEN f.IsLocked = 1 THEN 'Locked'
             WHEN f.IsActive = 0 THEN 'Inactive'
             WHEN f.MustChangePassword = 1 THEN 'Must reset password'
             WHEN f.LastSignInAt IS NULL THEN 'Never signed in'
             ELSE 'Active' END AS Status,
        f.IsActive
    FROM filtered f
    ORDER BY
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn
                WHEN 'UserCode'     THEN f.UserCode
                WHEN 'EmailAddress' THEN f.EmailAddress
                WHEN 'UserType'     THEN f.UserType
                WHEN 'PrimaryRole'  THEN f.PrimaryRole
                ELSE f.UserName
            END
        END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn
                WHEN 'UserCode'     THEN f.UserCode
                WHEN 'EmailAddress' THEN f.EmailAddress
                WHEN 'UserType'     THEN f.UserType
                WHEN 'PrimaryRole'  THEN f.PrimaryRole
                ELSE f.UserName
            END
        END DESC,
        /* The numeric and date sorts, separately, so they compare as
           themselves rather than as text — '10' after '9', not before it. */
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'BranchCount'  THEN f.BranchCount END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'BranchCount'  THEN f.BranchCount END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'LastSignInAt' THEN f.LastSignInAt END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'LastSignInAt' THEN f.LastSignInAt END DESC,
        f.Id
    OFFSET @Offset ROWS FETCH NEXT @PageSize ROWS ONLY;

    /*
     * Result set 2: the size of the whole filtered set, so the grid can say
     * "showing 50 of 88".
     *
     * IT MUST BE THE SAME SET AS THE PAGE. This count used to repeat the
     * predicates by hand and had drifted: RoleNames and Status were never
     * applied to it at all, so filtering on either gave a footer that
     * disagreed with the rows above it — and a pager that offered pages with
     * nothing on them. Counting the CTE is the only version of this that
     * cannot drift.
     */
    ;WITH people AS (
        SELECT
            u.Id, u.UserName, u.EmailAddress, u.UserCode, u.UserType,
            u.IsActive, u.IsLocked, u.MustChangePassword, u.LastSignInAt,
            STUFF((
                SELECT ', ' + r2.Name
                FROM agora.UserRole ur2
                JOIN agora.Role r2 ON r2.Id = ur2.RoleId
                WHERE ur2.UserId = u.Id
                ORDER BY r2.SortOrder
                FOR XML PATH(''), TYPE
            ).value('.', 'NVARCHAR(MAX)'), 1, 2, '') AS RoleNames
        FROM agora.[User] u
        WHERE u.DeletedAt IS NULL
    )
    SELECT COUNT_BIG(*) AS TotalRows
    FROM people p
    WHERE (@Search IS NULL OR @Search = ''
           OR p.UserName    LIKE '%' + @Search + '%'
           OR p.EmailAddress LIKE '%' + @Search + '%'
           OR p.UserCode    LIKE '%' + @Search + '%')
      AND (@fUserName  IS NULL OR (CASE WHEN @fUserNameOp = 'eq' THEN CASE WHEN p.UserName = @fUserName THEN 1 ELSE 0 END
                                        ELSE CASE WHEN p.UserName LIKE '%' + @fUserName + '%' THEN 1 ELSE 0 END END) = 1)
      AND (@fEmail     IS NULL OR (CASE WHEN @fEmailOp = 'eq' THEN CASE WHEN p.EmailAddress = @fEmail THEN 1 ELSE 0 END
                                        ELSE CASE WHEN p.EmailAddress LIKE '%' + @fEmail + '%' THEN 1 ELSE 0 END END) = 1)
      AND (@fTypeAny = 0 OR p.UserType IN (SELECT [Value] FROM @FilterSet WHERE [Column] = 'UserType'))
      AND (@fRoleNames IS NULL OR p.RoleNames LIKE '%' + @fRoleNames + '%')
      AND (@fStatus    IS NULL OR
           (CASE WHEN p.IsLocked = 1 THEN 'Locked'
                 WHEN p.IsActive = 0 THEN 'Inactive'
                 WHEN p.MustChangePassword = 1 THEN 'Must reset password'
                 WHEN p.LastSignInAt IS NULL THEN 'Never signed in'
                 ELSE 'Active' END) LIKE '%' + @fStatus + '%')
      AND (@DateFrom IS NULL OR p.LastSignInAt >= @DateFrom)
      AND (@DateTo   IS NULL OR p.LastSignInAt <  DATEADD(DAY, 1, @DateTo));
END
