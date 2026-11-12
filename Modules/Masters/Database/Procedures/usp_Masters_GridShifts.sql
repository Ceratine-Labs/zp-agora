/* Setup → Sites and forecourt → Shifts (T022). Read-only.

   The shifts each site trades — PumpIT's BRN_Shifts, one row per
   (branch, ShiftNo): 65 on 12 Nov 2026. Only three shift numbers exist
   estate-wide, so a join on ShiftNo alone fans out 21.67 times; a shift is
   always (BranchId, ShiftNo). Shift 2 is "Afternoon Shift" at six sites and
   "Do Not Use" at twelve.

   LastCashup is the latest BRN_DailyBanking TransactionDate on the shift
   (index SSBranchId, ShiftNo).

   PER BRANCH. Refuses without @BranchIds (MASTERS_BRANCH). RowKey is the
   ShiftNo.

   @Authorized is masters.shifts.view. The same filtered set drives the page,
   the total and the CSV extract. Read by Modules/Masters/Grids/ShiftGrid.
   Deployed by v1__02pa. Reads agora.vw_Shift and agora.vw_DailyBanking. */
CREATE OR ALTER PROCEDURE agora.usp_Masters_GridShifts
    @BranchIds NVARCHAR(MAX) = NULL,
    @DateFrom DATE = NULL,
    @DateTo DATE = NULL,
    @Search NVARCHAR(200) = NULL,
    @SortColumn NVARCHAR(80) = NULL,
    @SortAsc BIT = 1,
    @Page INT = 1,
    @PageSize INT = 50,
    @FiltersJson NVARCHAR(MAX) = NULL,
    @Key NVARCHAR(200) = NULL,
    @UserId INT = NULL,
    @Authorized BIT = 0
AS
BEGIN
    SET NOCOUNT ON;

    IF ISNULL(@Authorized, 0) <> 1
        THROW 51000, 'AGORA:MASTERS_DENIED:You do not have permission to see the shifts.', 1;
    IF NULLIF(LTRIM(RTRIM(@BranchIds)), '') IS NULL
        THROW 51000, 'AGORA:MASTERS_BRANCH:Choose a branch. Shifts are numbered per site: there are 3 shift numbers estate-wide, and a bare ShiftNo join fans out 21.67 times.', 1;

    SET @Page = CASE WHEN ISNULL(@Page, 0) < 1 THEN 1 ELSE @Page END;
    SET @PageSize = CASE WHEN ISNULL(@PageSize, 0) BETWEEN 1 AND 100000 THEN @PageSize ELSE 50 END;
    SET @Search = NULLIF(LTRIM(RTRIM(@Search)), '');
    SET @Key = NULLIF(LTRIM(RTRIM(@Key)), '');
    DECLARE @Offset BIGINT = CONVERT(BIGINT, @Page - 1) * @PageSize;

    -- The sites in scope: the branches chosen, within the caller's grants
    -- (agora.UserBranch; no grant rows means every branch).
    DECLARE @Visible TABLE (BranchId INT PRIMARY KEY);
    INSERT INTO @Visible (BranchId)
    SELECT b.BranchId FROM agora.Branch b
    WHERE b.DeletedAt IS NULL
      AND (NULLIF(LTRIM(RTRIM(@BranchIds)), '') IS NULL OR b.BranchId IN (
               SELECT TRY_CONVERT(INT, value) FROM STRING_SPLIT(@BranchIds, ',') WHERE LTRIM(RTRIM(value)) <> ''))
      AND (NOT EXISTS (SELECT 1 FROM agora.UserBranch WHERE UserId = @UserId)
           OR EXISTS (SELECT 1 FROM agora.UserBranch u WHERE u.UserId = @UserId AND u.BranchId = b.BranchId));

    DECLARE @Filters TABLE ([Column] NVARCHAR(80), Op NVARCHAR(20), Value NVARCHAR(400), SetJson NVARCHAR(MAX));
    INSERT INTO @Filters
    SELECT JSON_VALUE(f.value, '$.column'), ISNULL(JSON_VALUE(f.value, '$.op'), 'contains'),
           JSON_VALUE(f.value, '$.value'), JSON_QUERY(f.value, '$.in')
    FROM OPENJSON(@FiltersJson) f;

    SELECT s.BranchId,
           COALESCE(b.Name, CONCAT(N'Branch ', s.BranchId)) AS BranchName,
           CONVERT(NVARCHAR(200), s.ShiftNo) AS RowKey,
           s.ShiftNo,
           s.ShiftDescription,
           CONVERT(BIT, ISNULL(s.IsActive, 0)) AS IsActive,
           (SELECT MAX(d.TransactionDate) FROM agora.vw_DailyBanking d
            WHERE d.BranchId = s.BranchId AND d.ShiftNo = s.ShiftNo) AS LastCashup
    INTO #Rows
    FROM agora.vw_Shift s
    JOIN @Visible v ON v.BranchId = s.BranchId
    LEFT JOIN agora.Branch b ON b.BranchId = s.BranchId
    WHERE (@Key IS NULL OR CONVERT(NVARCHAR(200), s.ShiftNo) = @Key)
      AND (@Search IS NULL OR s.ShiftDescription LIKE '%' + @Search + '%' OR CONVERT(NVARCHAR(12), s.ShiftNo) = @Search);

    SELECT r.* INTO #Filtered FROM #Rows r
    WHERE NOT EXISTS (
        SELECT 1 FROM @Filters f
        CROSS APPLY (SELECT
            CASE f.[Column] WHEN 'BranchName' THEN CONVERT(NVARCHAR(400), r.BranchName) WHEN 'ShiftDescription' THEN CONVERT(NVARCHAR(400), r.ShiftDescription) WHEN 'IsActive' THEN CASE WHEN r.IsActive = 1 THEN N'Yes' ELSE N'No' END END AS T,
            CASE f.[Column] WHEN 'ShiftNo' THEN CONVERT(DECIMAL(18, 4), r.ShiftNo) END AS N,
            CASE WHEN f.[Column] IN ('ShiftNo') THEN 1 ELSE 0 END AS IsNum,
            TRY_CONVERT(DECIMAL(18, 4), f.Value) AS V) v
        WHERE f.[Column] IN ('BranchName', 'ShiftDescription', 'ShiftNo', 'IsActive')
          AND CASE
                WHEN f.SetJson IS NOT NULL THEN CASE WHEN EXISTS (SELECT 1 FROM OPENJSON(f.SetJson) s WHERE s.value = v.T) THEN 1 ELSE 0 END
                WHEN v.IsNum = 1 THEN CASE
                    WHEN v.V IS NULL THEN 1
                    WHEN f.Op IN ('eq', '=') THEN IIF(v.N = v.V, 1, 0)
                    WHEN f.Op = 'ne' THEN IIF(v.N <> v.V, 1, 0)
                    WHEN f.Op = 'gt' THEN IIF(v.N > v.V, 1, 0)
                    WHEN f.Op = 'gte' THEN IIF(v.N >= v.V, 1, 0)
                    WHEN f.Op = 'lt' THEN IIF(v.N < v.V, 1, 0)
                    WHEN f.Op = 'lte' THEN IIF(v.N <= v.V, 1, 0) ELSE 1 END
                ELSE CASE
                    WHEN f.Op = 'contains' THEN IIF(v.T LIKE '%' + f.Value + '%', 1, 0)
                    WHEN f.Op = 'eq' THEN IIF(v.T = f.Value, 1, 0)
                    WHEN f.Op = 'ne' THEN IIF(ISNULL(v.T, N'') <> f.Value, 1, 0)
                    WHEN f.Op = 'gt' THEN IIF(v.T > f.Value, 1, 0)
                    WHEN f.Op = 'gte' THEN IIF(v.T >= f.Value, 1, 0)
                    WHEN f.Op = 'lt' THEN IIF(v.T < f.Value, 1, 0)
                    WHEN f.Op = 'lte' THEN IIF(v.T <= f.Value, 1, 0) ELSE 1 END
              END = 0
    );

    SELECT * FROM #Filtered
    ORDER BY
        CASE WHEN @SortAsc = 1 THEN CASE @SortColumn WHEN 'BranchName' THEN CONVERT(NVARCHAR(400), BranchName) WHEN 'ShiftDescription' THEN CONVERT(NVARCHAR(400), ShiftDescription) END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'BranchName' THEN CONVERT(NVARCHAR(400), BranchName) WHEN 'ShiftDescription' THEN CONVERT(NVARCHAR(400), ShiftDescription) END END DESC,
        CASE WHEN @SortAsc = 1 THEN CASE @SortColumn WHEN 'ShiftNo' THEN CONVERT(DECIMAL(18, 4), ShiftNo) END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'ShiftNo' THEN CONVERT(DECIMAL(18, 4), ShiftNo) END END DESC,
        BranchName, ShiftNo
    OFFSET @Offset ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT COUNT_BIG(*) AS TotalRows FROM #Filtered;
END
