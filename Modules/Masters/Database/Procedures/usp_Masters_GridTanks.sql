/* Setup → Sites and forecourt → Tanks (T022). Read-only.

   The tanks each forecourt holds — PumpIT's BRN_Tank, one row per
   (branch, TankNo): 50 on 12 Nov 2026. TankNo is text in PumpIT.

   FuelTypes names the grades drawn from the tank, through the pumps on it
   (BRN_Pump.TankNo) and BRN_FuelType at the SAME site — never by FuelTypeNo
   alone. PumpCount is the pumps on it.

   PER BRANCH. Refuses without @BranchIds (MASTERS_BRANCH). RowKey is the
   TankNo. @Key returns the tank, then its pumps.

   @Authorized is masters.tanks.view. The same filtered set drives the page,
   the total and the CSV extract. Read by Modules/Masters/Grids/TankGrid.
   Deployed by v1__02pa. Reads agora.vw_MasterTank, vw_MasterPump and
   vw_MasterFuelType. */
CREATE OR ALTER PROCEDURE agora.usp_Masters_GridTanks
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
        THROW 51000, 'AGORA:MASTERS_DENIED:You do not have permission to see the tanks.', 1;
    IF NULLIF(LTRIM(RTRIM(@BranchIds)), '') IS NULL
        THROW 51000, 'AGORA:MASTERS_BRANCH:Choose a branch. Tanks are numbered per site: tank 1 is a different tank at every forecourt.', 1;

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

    SELECT t.BranchId,
           COALESCE(b.Name, CONCAT(N'Branch ', t.BranchId)) AS BranchName,
           CONVERT(NVARCHAR(200), t.TankNo) AS RowKey,
           t.TankNo,
           t.TankDescription,
           (SELECT STRING_AGG(CONVERT(NVARCHAR(4000), x.FuelTypeDescription), N', ') WITHIN GROUP (ORDER BY x.FuelTypeDescription)
            FROM (SELECT DISTINCT f.FuelTypeDescription FROM agora.vw_MasterPump p
                  JOIN agora.vw_MasterFuelType f ON f.BranchId = p.BranchId AND f.FuelTypeNo = p.FuelTypeNo
                  WHERE p.BranchId = t.BranchId AND p.TankNo = t.TankNo) x) AS FuelTypes,
           (SELECT COUNT(*) FROM agora.vw_MasterPump p WHERE p.BranchId = t.BranchId AND p.TankNo = t.TankNo) AS PumpCount,
           t.CreateDateTime
    INTO #Rows
    FROM agora.vw_MasterTank t
    JOIN @Visible v ON v.BranchId = t.BranchId
    LEFT JOIN agora.Branch b ON b.BranchId = t.BranchId
    WHERE (@Key IS NULL OR CONVERT(NVARCHAR(200), t.TankNo) = @Key)
      AND (@Search IS NULL OR t.TankDescription LIKE '%' + @Search + '%' OR t.TankNo = @Search);

    SELECT r.* INTO #Filtered FROM #Rows r
    WHERE NOT EXISTS (
        SELECT 1 FROM @Filters f
        CROSS APPLY (SELECT
            CASE f.[Column] WHEN 'BranchName' THEN CONVERT(NVARCHAR(400), r.BranchName) WHEN 'TankNo' THEN CONVERT(NVARCHAR(400), r.TankNo) WHEN 'TankDescription' THEN CONVERT(NVARCHAR(400), r.TankDescription) WHEN 'FuelTypes' THEN CONVERT(NVARCHAR(400), r.FuelTypes) END AS T,
            CASE f.[Column] WHEN 'PumpCount' THEN CONVERT(DECIMAL(18, 4), r.PumpCount) END AS N,
            CASE WHEN f.[Column] IN ('PumpCount') THEN 1 ELSE 0 END AS IsNum,
            TRY_CONVERT(DECIMAL(18, 4), f.Value) AS V) v
        WHERE f.[Column] IN ('BranchName', 'TankNo', 'TankDescription', 'FuelTypes', 'PumpCount')
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
        CASE WHEN @SortAsc = 1 THEN CASE @SortColumn WHEN 'BranchName' THEN CONVERT(NVARCHAR(400), BranchName) WHEN 'TankNo' THEN CONVERT(NVARCHAR(400), TankNo) WHEN 'TankDescription' THEN CONVERT(NVARCHAR(400), TankDescription) WHEN 'FuelTypes' THEN CONVERT(NVARCHAR(400), FuelTypes) END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'BranchName' THEN CONVERT(NVARCHAR(400), BranchName) WHEN 'TankNo' THEN CONVERT(NVARCHAR(400), TankNo) WHEN 'TankDescription' THEN CONVERT(NVARCHAR(400), TankDescription) WHEN 'FuelTypes' THEN CONVERT(NVARCHAR(400), FuelTypes) END END DESC,
        CASE WHEN @SortAsc = 1 THEN CASE @SortColumn WHEN 'PumpCount' THEN CONVERT(DECIMAL(18, 4), PumpCount) END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'PumpCount' THEN CONVERT(DECIMAL(18, 4), PumpCount) END END DESC,
        BranchName, TankNo
    OFFSET @Offset ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT COUNT_BIG(*) AS TotalRows FROM #Filtered;

    -- One tank: the pumps drawing from it.
    IF @Key IS NOT NULL
    BEGIN
        SELECT p.PumpNo AS [Pump], f.FuelTypeDescription AS [Fuel type], p.PumpOrder AS [Order],
               p.POSIdentifier AS [POS identifier], CASE WHEN p.IsActive = 1 THEN N'Yes' ELSE N'No' END AS [Active]
        FROM #Filtered t
        JOIN agora.vw_MasterPump p ON p.BranchId = t.BranchId AND p.TankNo = t.TankNo
        LEFT JOIN agora.vw_MasterFuelType f ON f.BranchId = p.BranchId AND f.FuelTypeNo = p.FuelTypeNo
        ORDER BY p.PumpOrder, p.PumpNo;
    END
END
