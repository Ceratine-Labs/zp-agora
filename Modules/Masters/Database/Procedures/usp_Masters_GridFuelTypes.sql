/* Setup → Sites and forecourt → Fuel types (T022). Read-only.

   The grades each forecourt sells — PumpIT's BRN_FuelType, one row per
   (branch, FuelTypeNo): 42 on 12 Nov 2026. FuelTypeDescription is NOT
   unique over FuelTypeNo across branches (2 and 5 name different fuels at
   different sites), so a fuel type is always (BranchId, FuelTypeNo) and
   every join here carries the branch.

   CostPrice / SellingPrice are not shown: 0 on every row
   (docs/reference/pumpit-dead-columns.md). The price in force is the Fuel
   matrix (vw_FuelPrice).

   PumpCount and TankCount are the pumps of this grade and the tanks they
   draw from, at the same site.

   PER BRANCH. Refuses without @BranchIds (MASTERS_BRANCH). RowKey is the
   FuelTypeNo. @Key returns the grade, then its pumps.

   @Authorized is masters.fueltypes.view. The same filtered set drives the
   page, the total and the CSV extract. Read by
   Modules/Masters/Grids/FuelTypeGrid. Deployed by v1__02pa. Reads
   agora.vw_MasterFuelType and agora.vw_MasterPump. */
CREATE OR ALTER PROCEDURE agora.usp_Masters_GridFuelTypes
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
        THROW 51000, 'AGORA:MASTERS_DENIED:You do not have permission to see the fuel types.', 1;
    IF NULLIF(LTRIM(RTRIM(@BranchIds)), '') IS NULL
        THROW 51000, 'AGORA:MASTERS_BRANCH:Choose a branch. Fuel types are numbered per site: FuelTypeDescription is not unique over FuelTypeNo across branches, so every fuel-type lookup is per branch.', 1;

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

    SELECT f.BranchId,
           COALESCE(b.Name, CONCAT(N'Branch ', f.BranchId)) AS BranchName,
           CONVERT(NVARCHAR(200), f.FuelTypeNo) AS RowKey,
           f.FuelTypeNo,
           f.FuelTypeDescription,
           f.FuelCode,
           f.POSType,
           f.POSIdentifier,
           f.IsFleetCard,
           f.HandlingFee,
           (SELECT COUNT(*) FROM agora.vw_MasterPump p WHERE p.BranchId = f.BranchId AND p.FuelTypeNo = f.FuelTypeNo) AS PumpCount,
           (SELECT COUNT(DISTINCT p.TankNo) FROM agora.vw_MasterPump p WHERE p.BranchId = f.BranchId AND p.FuelTypeNo = f.FuelTypeNo) AS TankCount
    INTO #Rows
    FROM agora.vw_MasterFuelType f
    JOIN @Visible v ON v.BranchId = f.BranchId
    LEFT JOIN agora.Branch b ON b.BranchId = f.BranchId
    WHERE (@Key IS NULL OR CONVERT(NVARCHAR(200), f.FuelTypeNo) = @Key)
      AND (@Search IS NULL OR f.FuelTypeDescription LIKE '%' + @Search + '%' OR f.FuelCode LIKE '%' + @Search + '%'
           OR f.POSIdentifier LIKE '%' + @Search + '%' OR CONVERT(NVARCHAR(12), f.FuelTypeNo) = @Search);

    SELECT r.* INTO #Filtered FROM #Rows r
    WHERE NOT EXISTS (
        SELECT 1 FROM @Filters f
        CROSS APPLY (SELECT
            CASE f.[Column] WHEN 'BranchName' THEN CONVERT(NVARCHAR(400), r.BranchName) WHEN 'FuelTypeDescription' THEN CONVERT(NVARCHAR(400), r.FuelTypeDescription) WHEN 'FuelCode' THEN CONVERT(NVARCHAR(400), r.FuelCode) WHEN 'POSType' THEN CONVERT(NVARCHAR(400), r.POSType) WHEN 'POSIdentifier' THEN CONVERT(NVARCHAR(400), r.POSIdentifier) WHEN 'IsFleetCard' THEN CASE WHEN r.IsFleetCard = 1 THEN N'Yes' ELSE N'No' END END AS T,
            CASE f.[Column] WHEN 'FuelTypeNo' THEN CONVERT(DECIMAL(18, 4), r.FuelTypeNo) WHEN 'HandlingFee' THEN CONVERT(DECIMAL(18, 4), r.HandlingFee) WHEN 'PumpCount' THEN CONVERT(DECIMAL(18, 4), r.PumpCount) WHEN 'TankCount' THEN CONVERT(DECIMAL(18, 4), r.TankCount) END AS N,
            CASE WHEN f.[Column] IN ('FuelTypeNo', 'HandlingFee', 'PumpCount', 'TankCount') THEN 1 ELSE 0 END AS IsNum,
            TRY_CONVERT(DECIMAL(18, 4), f.Value) AS V) v
        WHERE f.[Column] IN ('BranchName', 'FuelTypeDescription', 'FuelCode', 'POSType', 'POSIdentifier', 'FuelTypeNo', 'HandlingFee', 'PumpCount', 'TankCount', 'IsFleetCard')
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
        CASE WHEN @SortAsc = 1 THEN CASE @SortColumn WHEN 'BranchName' THEN CONVERT(NVARCHAR(400), BranchName) WHEN 'FuelTypeDescription' THEN CONVERT(NVARCHAR(400), FuelTypeDescription) WHEN 'FuelCode' THEN CONVERT(NVARCHAR(400), FuelCode) WHEN 'POSType' THEN CONVERT(NVARCHAR(400), POSType) WHEN 'POSIdentifier' THEN CONVERT(NVARCHAR(400), POSIdentifier) END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'BranchName' THEN CONVERT(NVARCHAR(400), BranchName) WHEN 'FuelTypeDescription' THEN CONVERT(NVARCHAR(400), FuelTypeDescription) WHEN 'FuelCode' THEN CONVERT(NVARCHAR(400), FuelCode) WHEN 'POSType' THEN CONVERT(NVARCHAR(400), POSType) WHEN 'POSIdentifier' THEN CONVERT(NVARCHAR(400), POSIdentifier) END END DESC,
        CASE WHEN @SortAsc = 1 THEN CASE @SortColumn WHEN 'FuelTypeNo' THEN CONVERT(DECIMAL(18, 4), FuelTypeNo) WHEN 'HandlingFee' THEN CONVERT(DECIMAL(18, 4), HandlingFee) WHEN 'PumpCount' THEN CONVERT(DECIMAL(18, 4), PumpCount) WHEN 'TankCount' THEN CONVERT(DECIMAL(18, 4), TankCount) END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'FuelTypeNo' THEN CONVERT(DECIMAL(18, 4), FuelTypeNo) WHEN 'HandlingFee' THEN CONVERT(DECIMAL(18, 4), HandlingFee) WHEN 'PumpCount' THEN CONVERT(DECIMAL(18, 4), PumpCount) WHEN 'TankCount' THEN CONVERT(DECIMAL(18, 4), TankCount) END END DESC,
        BranchName, FuelTypeNo
    OFFSET @Offset ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT COUNT_BIG(*) AS TotalRows FROM #Filtered;

    -- One grade: its pumps, at the same site.
    IF @Key IS NOT NULL
    BEGIN
        SELECT p.PumpNo AS [Pump], p.TankNo AS [Tank], t.TankDescription AS [Tank description],
               p.PumpOrder AS [Order], p.POSIdentifier AS [POS identifier],
               CASE WHEN p.IsActive = 1 THEN N'Yes' ELSE N'No' END AS [Active]
        FROM #Filtered f
        JOIN agora.vw_MasterPump p ON p.BranchId = f.BranchId AND p.FuelTypeNo = f.FuelTypeNo
        LEFT JOIN agora.vw_MasterTank t ON t.BranchId = p.BranchId AND t.TankNo = p.TankNo
        ORDER BY p.PumpOrder, p.PumpNo;
    END
END
