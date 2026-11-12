/* Setup → Sites and forecourt → Pumps (T022). Read-only.

   Every pump (nozzle) on every forecourt — PumpIT's BRN_Pump, one row per
   (branch, PumpNo): 380 on 12 Nov 2026, at 16 sites. PumpNo and TankNo are
   text (1.1, 1.3 …). PumpOrder is BRN_Pump.[Order], bracketed once in
   agora.vw_MasterPump: the order the pump readings are captured in.

   The fuel type and the tank are joined at the SAME site — (BranchId,
   FuelTypeNo) and (BranchId, TankNo) — because neither number means the same
   thing at two branches. Every pump on the estate resolves to both
   (12 Nov 2026).

   PER BRANCH. Refuses without @BranchIds (MASTERS_BRANCH). RowKey is the
   PumpNo.

   @Authorized is masters.pumps.view. The same filtered set drives the page,
   the total and the CSV extract. Read by Modules/Masters/Grids/PumpGrid.
   Deployed by v1__02pa. Reads agora.vw_MasterPump, vw_MasterFuelType and
   vw_MasterTank. */
CREATE OR ALTER PROCEDURE agora.usp_Masters_GridPumps
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
        THROW 51000, 'AGORA:MASTERS_DENIED:You do not have permission to see the pumps.', 1;
    IF NULLIF(LTRIM(RTRIM(@BranchIds)), '') IS NULL
        THROW 51000, 'AGORA:MASTERS_BRANCH:Choose a branch. Pumps are numbered per site, and so are the fuel types and tanks they name.', 1;

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

    SELECT p.BranchId,
           COALESCE(b.Name, CONCAT(N'Branch ', p.BranchId)) AS BranchName,
           CONVERT(NVARCHAR(200), p.PumpNo) AS RowKey,
           p.PumpNo,
           p.PumpOrder,
           p.FuelTypeNo,
           f.FuelTypeDescription,
           p.TankNo,
           t.TankDescription,
           p.POSType,
           p.POSIdentifier,
           CONVERT(BIT, ISNULL(p.IsActive, 0)) AS IsActive
    INTO #Rows
    FROM agora.vw_MasterPump p
    JOIN @Visible v ON v.BranchId = p.BranchId
    LEFT JOIN agora.Branch b ON b.BranchId = p.BranchId
    LEFT JOIN agora.vw_MasterFuelType f ON f.BranchId = p.BranchId AND f.FuelTypeNo = p.FuelTypeNo
    LEFT JOIN agora.vw_MasterTank t ON t.BranchId = p.BranchId AND t.TankNo = p.TankNo
    WHERE (@Key IS NULL OR CONVERT(NVARCHAR(200), p.PumpNo) = @Key)
      AND (@Search IS NULL OR p.PumpNo = @Search OR f.FuelTypeDescription LIKE '%' + @Search + '%'
           OR t.TankDescription LIKE '%' + @Search + '%' OR p.POSIdentifier LIKE '%' + @Search + '%');

    SELECT r.* INTO #Filtered FROM #Rows r
    WHERE NOT EXISTS (
        SELECT 1 FROM @Filters f
        CROSS APPLY (SELECT
            CASE f.[Column] WHEN 'BranchName' THEN CONVERT(NVARCHAR(400), r.BranchName) WHEN 'PumpNo' THEN CONVERT(NVARCHAR(400), r.PumpNo) WHEN 'FuelTypeDescription' THEN CONVERT(NVARCHAR(400), r.FuelTypeDescription) WHEN 'TankNo' THEN CONVERT(NVARCHAR(400), r.TankNo) WHEN 'TankDescription' THEN CONVERT(NVARCHAR(400), r.TankDescription) WHEN 'POSType' THEN CONVERT(NVARCHAR(400), r.POSType) WHEN 'POSIdentifier' THEN CONVERT(NVARCHAR(400), r.POSIdentifier) WHEN 'IsActive' THEN CASE WHEN r.IsActive = 1 THEN N'Yes' ELSE N'No' END END AS T,
            CASE f.[Column] WHEN 'PumpOrder' THEN CONVERT(DECIMAL(18, 4), r.PumpOrder) WHEN 'FuelTypeNo' THEN CONVERT(DECIMAL(18, 4), r.FuelTypeNo) END AS N,
            CASE WHEN f.[Column] IN ('PumpOrder', 'FuelTypeNo') THEN 1 ELSE 0 END AS IsNum,
            TRY_CONVERT(DECIMAL(18, 4), f.Value) AS V) v
        WHERE f.[Column] IN ('BranchName', 'PumpNo', 'FuelTypeDescription', 'TankNo', 'TankDescription', 'POSType', 'POSIdentifier', 'PumpOrder', 'FuelTypeNo', 'IsActive')
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
        CASE WHEN @SortAsc = 1 THEN CASE @SortColumn WHEN 'BranchName' THEN CONVERT(NVARCHAR(400), BranchName) WHEN 'PumpNo' THEN CONVERT(NVARCHAR(400), PumpNo) WHEN 'FuelTypeDescription' THEN CONVERT(NVARCHAR(400), FuelTypeDescription) WHEN 'TankNo' THEN CONVERT(NVARCHAR(400), TankNo) WHEN 'TankDescription' THEN CONVERT(NVARCHAR(400), TankDescription) WHEN 'POSType' THEN CONVERT(NVARCHAR(400), POSType) WHEN 'POSIdentifier' THEN CONVERT(NVARCHAR(400), POSIdentifier) END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'BranchName' THEN CONVERT(NVARCHAR(400), BranchName) WHEN 'PumpNo' THEN CONVERT(NVARCHAR(400), PumpNo) WHEN 'FuelTypeDescription' THEN CONVERT(NVARCHAR(400), FuelTypeDescription) WHEN 'TankNo' THEN CONVERT(NVARCHAR(400), TankNo) WHEN 'TankDescription' THEN CONVERT(NVARCHAR(400), TankDescription) WHEN 'POSType' THEN CONVERT(NVARCHAR(400), POSType) WHEN 'POSIdentifier' THEN CONVERT(NVARCHAR(400), POSIdentifier) END END DESC,
        CASE WHEN @SortAsc = 1 THEN CASE @SortColumn WHEN 'PumpOrder' THEN CONVERT(DECIMAL(18, 4), PumpOrder) WHEN 'FuelTypeNo' THEN CONVERT(DECIMAL(18, 4), FuelTypeNo) END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'PumpOrder' THEN CONVERT(DECIMAL(18, 4), PumpOrder) WHEN 'FuelTypeNo' THEN CONVERT(DECIMAL(18, 4), FuelTypeNo) END END DESC,
        BranchName, PumpOrder, PumpNo
    OFFSET @Offset ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT COUNT_BIG(*) AS TotalRows FROM #Filtered;
END
