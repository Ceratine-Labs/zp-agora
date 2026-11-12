/* Setup → Sites and forecourt → Statement criteria (T022). Read-only.

   The OLDER bank-recon criteria — PumpIT's SS_ReconCriteria with its group
   (SS_ReconCriteriaGroup): 68 rules at 12 sites on 12 Nov 2026. Each says
   where on a bank statement line the reference sits (Reference, its start
   and end) and where the recon key sits (ReconStart/End), per tender group.

   The CURRENT auto-recon rules are BRN_AutoReconCriteria, which Control →
   Money → Bank reconciliation already reads and overrides (Modules/Recon,
   agora.ReconCriteria). This listing does not touch them.

   PER BRANCH. Refuses without @BranchIds (MASTERS_BRANCH). RowKey is the
   CriteriaId.

   @Authorized is masters.reconcriteria.view. The same filtered set drives
   the page, the total and the CSV extract. Read by
   Modules/Masters/Grids/ReconCriteriaGrid. Deployed by v1__02pa. Reads
   agora.vw_MasterReconCriteria. */
CREATE OR ALTER PROCEDURE agora.usp_Masters_GridReconCriteria
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
        THROW 51000, 'AGORA:MASTERS_DENIED:You do not have permission to see the recon criteria.', 1;
    IF NULLIF(LTRIM(RTRIM(@BranchIds)), '') IS NULL
        THROW 51000, 'AGORA:MASTERS_BRANCH:Choose a branch. Recon criteria are kept per site.', 1;

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

    SELECT c.BranchId,
           COALESCE(b.Name, CONCAT(N'Branch ', c.BranchId)) AS BranchName,
           CONVERT(NVARCHAR(200), c.CriteriaId) AS RowKey,
           c.CriteriaId,
           COALESCE(c.GroupName, c.GroupCode, CONCAT(N'Group ', c.CriteriaGroupId)) AS GroupName,
           c.GroupTable,
           c.Code,
           c.[Description],
           c.Reference,
           c.ReferenceStartPosition,
           c.ReferenceEndPosition,
           c.ReconStartPosition,
           c.ReconEndPosition,
           c.IsAutoReconcile
    INTO #Rows
    FROM agora.vw_MasterReconCriteria c
    JOIN @Visible v ON v.BranchId = c.BranchId
    LEFT JOIN agora.Branch b ON b.BranchId = c.BranchId
    WHERE (@Key IS NULL OR CONVERT(NVARCHAR(200), c.CriteriaId) = @Key)
      AND (@Search IS NULL OR c.Code LIKE '%' + @Search + '%' OR c.[Description] LIKE '%' + @Search + '%'
           OR c.Reference LIKE '%' + @Search + '%' OR c.GroupName LIKE '%' + @Search + '%');

    SELECT r.* INTO #Filtered FROM #Rows r
    WHERE NOT EXISTS (
        SELECT 1 FROM @Filters f
        CROSS APPLY (SELECT
            CASE f.[Column] WHEN 'BranchName' THEN CONVERT(NVARCHAR(400), r.BranchName) WHEN 'GroupName' THEN CONVERT(NVARCHAR(400), r.GroupName) WHEN 'GroupTable' THEN CONVERT(NVARCHAR(400), r.GroupTable) WHEN 'Code' THEN CONVERT(NVARCHAR(400), r.Code) WHEN 'Description' THEN CONVERT(NVARCHAR(400), r.Description) WHEN 'Reference' THEN CONVERT(NVARCHAR(400), r.Reference) WHEN 'IsAutoReconcile' THEN CASE WHEN r.IsAutoReconcile = 1 THEN N'Yes' ELSE N'No' END END AS T,
            CASE f.[Column] WHEN 'CriteriaId' THEN CONVERT(DECIMAL(18, 4), r.CriteriaId) WHEN 'ReferenceStartPosition' THEN CONVERT(DECIMAL(18, 4), r.ReferenceStartPosition) WHEN 'ReferenceEndPosition' THEN CONVERT(DECIMAL(18, 4), r.ReferenceEndPosition) WHEN 'ReconStartPosition' THEN CONVERT(DECIMAL(18, 4), r.ReconStartPosition) WHEN 'ReconEndPosition' THEN CONVERT(DECIMAL(18, 4), r.ReconEndPosition) END AS N,
            CASE WHEN f.[Column] IN ('CriteriaId', 'ReferenceStartPosition', 'ReferenceEndPosition', 'ReconStartPosition', 'ReconEndPosition') THEN 1 ELSE 0 END AS IsNum,
            TRY_CONVERT(DECIMAL(18, 4), f.Value) AS V) v
        WHERE f.[Column] IN ('BranchName', 'GroupName', 'GroupTable', 'Code', 'Description', 'Reference', 'CriteriaId', 'ReferenceStartPosition', 'ReferenceEndPosition', 'ReconStartPosition', 'ReconEndPosition', 'IsAutoReconcile')
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
        CASE WHEN @SortAsc = 1 THEN CASE @SortColumn WHEN 'BranchName' THEN CONVERT(NVARCHAR(400), BranchName) WHEN 'GroupName' THEN CONVERT(NVARCHAR(400), GroupName) WHEN 'Code' THEN CONVERT(NVARCHAR(400), Code) WHEN 'Description' THEN CONVERT(NVARCHAR(400), Description) WHEN 'Reference' THEN CONVERT(NVARCHAR(400), Reference) END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'BranchName' THEN CONVERT(NVARCHAR(400), BranchName) WHEN 'GroupName' THEN CONVERT(NVARCHAR(400), GroupName) WHEN 'Code' THEN CONVERT(NVARCHAR(400), Code) WHEN 'Description' THEN CONVERT(NVARCHAR(400), Description) WHEN 'Reference' THEN CONVERT(NVARCHAR(400), Reference) END END DESC,
        CASE WHEN @SortAsc = 1 THEN CASE @SortColumn WHEN 'CriteriaId' THEN CONVERT(DECIMAL(18, 4), CriteriaId) END END ASC,
        CASE WHEN @SortAsc = 0 THEN CASE @SortColumn WHEN 'CriteriaId' THEN CONVERT(DECIMAL(18, 4), CriteriaId) END END DESC,
        BranchName, GroupName, Code
    OFFSET @Offset ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT COUNT_BIG(*) AS TotalRows FROM #Filtered;
END
