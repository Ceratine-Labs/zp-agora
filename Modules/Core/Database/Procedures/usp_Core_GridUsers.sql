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

    /* Typed header filters. A filter the screen does not offer is ignored
       rather than refused: the definition decides what is filterable and this
       reads whatever arrived. */
    DECLARE @fUserName    NVARCHAR(200) = NULL,
            @fEmail       NVARCHAR(200) = NULL,
            @fUserType    NVARCHAR(50)  = NULL,
            @fRoleNames   NVARCHAR(200) = NULL,
            @fStatus      NVARCHAR(50)  = NULL;

    IF @FiltersJson IS NOT NULL
    BEGIN
        SELECT
            @fUserName  = MAX(CASE WHEN f.[key] = 'UserName'     THEN JSON_VALUE(f.value, '$.q') END),
            @fEmail     = MAX(CASE WHEN f.[key] = 'EmailAddress' THEN JSON_VALUE(f.value, '$.q') END),
            @fUserType  = MAX(CASE WHEN f.[key] = 'UserType'     THEN JSON_VALUE(f.value, '$.q') END),
            @fRoleNames = MAX(CASE WHEN f.[key] = 'RoleNames'    THEN JSON_VALUE(f.value, '$.q') END),
            @fStatus    = MAX(CASE WHEN f.[key] = 'Status'       THEN JSON_VALUE(f.value, '$.q') END)
        FROM OPENJSON(@FiltersJson) f;
    END

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
    ),
    filtered AS (
        SELECT *
        FROM people p
        WHERE (@Search IS NULL OR @Search = ''
               OR p.UserName    LIKE '%' + @Search + '%'
               OR p.EmailAddress LIKE '%' + @Search + '%'
               OR p.UserCode    LIKE '%' + @Search + '%')
          AND (@fUserName  IS NULL OR p.UserName     LIKE '%' + @fUserName + '%')
          AND (@fEmail     IS NULL OR p.EmailAddress LIKE '%' + @fEmail + '%')
          AND (@fUserType  IS NULL OR p.UserType     = @fUserType)
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

    /* Result set 2: the total, so the grid can say "showing 50 of 88". */
    SELECT COUNT_BIG(*) AS TotalRows
    FROM agora.[User] u
    WHERE (@Search IS NULL OR @Search = ''
           OR u.UserName LIKE '%' + @Search + '%'
           OR u.EmailAddress LIKE '%' + @Search + '%'
           OR u.UserCode LIKE '%' + @Search + '%')
      AND (@fUserName IS NULL OR u.UserName LIKE '%' + @fUserName + '%')
      AND (@fEmail    IS NULL OR u.EmailAddress LIKE '%' + @fEmail + '%')
      AND (@fUserType IS NULL OR u.UserType = @fUserType)
      AND (@DateFrom IS NULL OR u.LastSignInAt >= @DateFrom)
      AND (@DateTo   IS NULL OR u.LastSignInAt <  DATEADD(DAY, 1, @DateTo));
END
