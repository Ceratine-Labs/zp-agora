/* Setup → People and assets → Utility meters (T022). Read-only.

   Every electricity, water and gas meter on the estate — PumpIT's
   BRN_UtilityMeter: 106 on 12 Nov 2026, unique on (branch, utility type,
   meter number) and on UtilityMeterId. Readings are captured on Today →
   Utility reading (Modules/Utilities); this is the master they are captured
   against.

   LastReadingDate and LastClosingReading come from BRN_UtilityTransaction
   for the same (branch, utility type, meter). That table's key leads on its
   own id, so it is read once, grouped, for the sites in scope — not once per
   meter. ReadingCount is all its readings.

   PER BRANCH. Refuses without @BranchIds (MASTERS_BRANCH). RowKey is the
   UtilityMeterId. @Key returns the meter, then its last ten readings.

   @Authorized is masters.meters.view. The same filtered set drives the page,
   the total and the CSV extract. Read by Modules/Masters/Grids/UtilityMeterGrid.
   Deployed by v1__02pa. Reads agora.vw_UtilityMeter, vw_UtilityType and
   vw_UtilityTransaction. */
CREATE OR ALTER PROCEDURE agora.usp_Masters_GridUtilityMeters
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
        THROW 51000, 'AGORA:MASTERS_DENIED:You do not have permission to see the utility meters.', 1;
    IF NULLIF(LTRIM(RTRIM(@BranchIds)), '') IS NULL
        THROW 51000, 'AGORA:MASTERS_BRANCH:Choose a branch. Meters belong to a site: a meter number is only unique with its branch and utility type.', 1;

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

    SELECT x.BranchId, x.UtilityType, x.MeterNo, COUNT(*) AS ReadingCount, MAX(x.TransactionDate) AS LastReadingDate
    INTO #Readings
    FROM agora.vw_UtilityTransaction x
    JOIN @Visible v ON v.BranchId = x.BranchId
    GROUP BY x.BranchId, x.UtilityType, x.MeterNo;

    SELECT m.BranchId,
           COALESCE(b.Name, CONCAT(N'Branch ', m.BranchId)) AS BranchName,
           CONVERT(NVARCHAR(200), m.UtilityMeterId) AS RowKey,
           m.UtilityMeterId,
           m.UtilityType,
           COALESCE(u.UtilityTypeDescription, m.UtilityType) AS UtilityTypeName,
           m.MeterNo,
           m.MeterDescription,
           ISNULL(r.ReadingCount, 0) AS ReadingCount,
           r.LastReadingDate,
           (SELECT TOP 1 x.ClosingReading FROM agora.vw_UtilityTransaction x
            WHERE x.BranchId = m.BranchId AND x.UtilityType = m.UtilityType AND x.MeterNo = m.MeterNo
              AND x.TransactionDate = r.LastReadingDate
            ORDER BY x.UtilityTransactionId DESC) AS LastClosingReading
    INTO #Rows
    FROM agora.vw_UtilityMeter m
    JOIN @Visible v ON v.BranchId = m.BranchId
    LEFT JOIN agora.Branch b ON b.BranchId = m.BranchId
    LEFT JOIN agora.vw_UtilityType u ON u.UtilityType = m.UtilityType
    LEFT JOIN #Readings r ON r.BranchId = m.BranchId AND r.UtilityType = m.UtilityType AND r.MeterNo = m.MeterNo
    WHERE (@Key IS NULL OR CONVERT(NVARCHAR(200), m.UtilityMeterId) = @Key)
      AND (@Search IS NULL OR m.MeterNo LIKE '%' + @Search + '%' OR m.MeterDescription LIKE '%' + @Search + '%'
           OR u.UtilityTypeDescription LIKE '%' + @Search + '%');

    SELECT r.* INTO #Filtered FROM #Rows r
    WHERE NOT EXISTS (
        SELECT 1 FROM @Filters f
        CROSS APPLY (SELECT
            CASE f.[Column] WHEN 'BranchName' THEN CONVERT(NVARCHAR(400), r.BranchName) WHEN 'UtilityTypeName' THEN CONVERT(NVARCHAR(400), r.UtilityTypeName) WHEN 'MeterNo' THEN CONVERT(NVARCHAR(400), r.MeterNo) WHEN 'MeterDescription' THEN CONVERT(NVARCHAR(400), r.MeterDescription) END AS T,
            CASE f.[Column] WHEN 'UtilityMeterId' THEN CONVERT(DECIMAL(18, 4), r.UtilityMeterId) WHEN 'ReadingCount' THEN CONVERT(DECIMAL(18, 4), r.ReadingCount) WHEN 'LastClosingReading' THEN CONVERT(DECIMAL(18, 4), r.LastClosingReading) END AS N,
            CASE WHEN f.[Column] IN ('UtilityMeterId', 'ReadingCount', 'LastClosingReading') THEN 1 ELSE 0 END AS IsNum,
            TRY_CONVERT(DECIMAL(18, 4), f.Value) AS V) v
        WHERE f.[Column] IN ('BranchName', 'UtilityTypeName', 'MeterNo', 'MeterDescription', 'UtilityMeterId', 'ReadingCount', 'LastClosingReading')
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
        CASE WHEN @SortAsc = 1 THEN CASE @SortColumn WHEN 'BranchName' THEN CONVERT(NVARCHAR(400), BranchName) WHEN 'UtilityTypeName' THEN CONVERT(NVARCHAR(400), UtilityTypeName) WHEN 'MeterNo' THEN CONVERT(NVARCHAR(400), MeterNo) WHEN 'MeterDescription' THEN CONVERT(NVARCHAR(400), MeterDescription) END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'BranchName' THEN CONVERT(NVARCHAR(400), BranchName) WHEN 'UtilityTypeName' THEN CONVERT(NVARCHAR(400), UtilityTypeName) WHEN 'MeterNo' THEN CONVERT(NVARCHAR(400), MeterNo) WHEN 'MeterDescription' THEN CONVERT(NVARCHAR(400), MeterDescription) END END DESC,
        CASE WHEN @SortAsc = 1 THEN CASE @SortColumn WHEN 'UtilityMeterId' THEN CONVERT(DECIMAL(18, 4), UtilityMeterId) WHEN 'ReadingCount' THEN CONVERT(DECIMAL(18, 4), ReadingCount) WHEN 'LastClosingReading' THEN CONVERT(DECIMAL(18, 4), LastClosingReading) END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'UtilityMeterId' THEN CONVERT(DECIMAL(18, 4), UtilityMeterId) WHEN 'ReadingCount' THEN CONVERT(DECIMAL(18, 4), ReadingCount) WHEN 'LastClosingReading' THEN CONVERT(DECIMAL(18, 4), LastClosingReading) END END DESC,
        BranchName, UtilityType, MeterNo
    OFFSET @Offset ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT COUNT_BIG(*) AS TotalRows FROM #Filtered;

    -- One meter: its last ten readings.
    IF @Key IS NOT NULL
    BEGIN
        SELECT TOP 10 CONVERT(DATE, x.TransactionDate) AS [Date], x.StoredOpeningReading AS [Opening (stored)],
               x.ClosingReading AS [Closing], x.PrepaidUnitsPurchased AS [Prepaid units], x.StoredUsage AS [Usage (stored)]
        FROM #Filtered m
        JOIN agora.vw_UtilityTransaction x ON x.BranchId = m.BranchId AND x.UtilityType = m.UtilityType AND x.MeterNo = m.MeterNo
        ORDER BY x.TransactionDate DESC, x.UtilityTransactionId DESC;
    END
END
