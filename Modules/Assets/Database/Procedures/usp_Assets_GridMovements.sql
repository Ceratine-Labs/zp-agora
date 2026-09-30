/* ============================================================================
 * agora.usp_Assets_GridMovements — every asset movement: transfers, trips to
 * repair and back, sales, trade-ins and write-offs.
 *
 * Read by:  Setup -> People and assets -> Asset movement (HO) and
 *           Assets -> Equipment -> Movement (branch)   (T057)
 * Reads:    agora.AssetMovement, agora.Asset, agora.vw_AssetMovementType,
 *           agora.vw_AssetType, agora.Branch.
 * Writes:   nothing.
 *
 * A REVERSED MOVEMENT STAYS ON THIS LIST, marked reversed, with who reversed it
 * and why. It is a record that somebody recorded something and then took it
 * back, and that is exactly the row an auditor goes looking for.
 *
 * SCOPE. A movement is in scope when the asset's own site, the site it left or
 * the site it went to is asked for (@BranchIds; empty = all) and may be seen
 * (@AllowedBranchIds; empty = all). A transfer from Ulundi to Manguzi is on
 * both sites' lists.
 *
 * @DateFrom / @DateTo bound the movement date. With neither, the last 90 days.
 *
 * Result sets: 1 the page · 2 (TotalRows) · 3 grand totals.
 * ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Assets_GridMovements]
    @BranchIds        NVARCHAR(MAX) = NULL,
    @DateFrom         DATE          = NULL,
    @DateTo           DATE          = NULL,
    @Search           NVARCHAR(200) = NULL,
    @SortColumn       NVARCHAR(80)  = NULL,
    @SortAsc          BIT           = 1,
    @Page             INT           = 1,
    @PageSize         INT           = 50,
    @FiltersJson      NVARCHAR(MAX) = NULL,
    @AllowedBranchIds NVARCHAR(MAX) = NULL
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    DECLARE @Today date = CONVERT(date, GETDATE());

    IF @DateFrom IS NULL AND @DateTo IS NULL
        SET @DateFrom = DATEADD(DAY, -90, @Today);

    SET @Page     = CASE WHEN ISNULL(@Page, 1) < 1 THEN 1 ELSE @Page END;
    SET @PageSize = CASE WHEN ISNULL(@PageSize, 50) BETWEEN 1 AND 100000 THEN @PageSize ELSE 50 END;
    SET @Search   = NULLIF(LTRIM(RTRIM(ISNULL(@Search, ''))), '');

    DECLARE @Branch  TABLE (BranchId int PRIMARY KEY);
    DECLARE @Allowed TABLE (BranchId int PRIMARY KEY);

    INSERT INTO @Branch (BranchId)
    SELECT DISTINCT TRY_CONVERT(int, LTRIM(RTRIM(s.value)))
    FROM STRING_SPLIT(ISNULL(@BranchIds, ''), ',') s
    WHERE LTRIM(RTRIM(s.value)) <> ''   -- TRY_CONVERT(int, '') is 0, not NULL
      AND TRY_CONVERT(int, LTRIM(RTRIM(s.value))) IS NOT NULL;

    INSERT INTO @Allowed (BranchId)
    SELECT DISTINCT TRY_CONVERT(int, LTRIM(RTRIM(s.value)))
    FROM STRING_SPLIT(ISNULL(@AllowedBranchIds, ''), ',') s
    WHERE LTRIM(RTRIM(s.value)) <> ''
      AND TRY_CONVERT(int, LTRIM(RTRIM(s.value))) IS NOT NULL;

    DECLARE @AllBranches bit = CASE WHEN EXISTS (SELECT 1 FROM @Branch)  THEN 0 ELSE 1 END;
    DECLARE @AllAllowed  bit = CASE WHEN EXISTS (SELECT 1 FROM @Allowed) THEN 0 ELSE 1 END;

    DECLARE @Filter    TABLE ([Column] nvarchar(80), [Type] nvarchar(10), [Op] nvarchar(20), [Value] nvarchar(400));
    DECLARE @FilterSet TABLE ([Column] nvarchar(80), [Value] nvarchar(400));

    IF @FiltersJson IS NOT NULL AND ISJSON(@FiltersJson) = 1
    BEGIN
        INSERT INTO @Filter ([Column], [Type], [Op], [Value])
        SELECT JSON_VALUE(f.value, '$.column'),
               ISNULL(JSON_VALUE(f.value, '$.type'), 'text'),
               ISNULL(JSON_VALUE(f.value, '$.op'), 'contains'),
               JSON_VALUE(f.value, '$.value')
        FROM OPENJSON(@FiltersJson) f
        WHERE JSON_VALUE(f.value, '$.value') IS NOT NULL;

        INSERT INTO @FilterSet ([Column], [Value])
        SELECT JSON_VALUE(f.value, '$.column'), v.value
        FROM OPENJSON(@FiltersJson) f
        CROSS APPLY OPENJSON(f.value, '$.in') v
        WHERE JSON_VALUE(f.value, '$.type') = 'set';
    END

    SELECT m.BranchId,
           m.Id                                   AS MovementId,
           m.MovementRef,
           m.AssetId,
           a.AssetNo,
           a.Make,
           a.Model,
           t.AssetTypeDescription,
           m.MovementType,
           ISNULL(k.MovementTypeDescription, m.MovementType) AS MovementTypeDescription,
           m.MovementDate,
           fb.Name                                AS FromSite,
           tb.Name                                AS ToSite,
           ob.Name                                AS BranchName,
           m.Proceeds,
           m.Reason,
           CASE WHEN m.ReversedAt IS NOT NULL THEN 'Reversed' ELSE 'In force' END AS [State],
           CASE WHEN m.ReversedAt IS NOT NULL THEN 'neutral' ELSE 'good' END      AS StateTone,
           m.ReversedAt,
           m.ReverseReason,
           m.CreatedAt                            AS CapturedAt,
           cu.UserName                            AS CapturedBy
    INTO #rows
    FROM agora.AssetMovement m
    JOIN agora.Asset a ON a.BranchId = m.BranchId AND a.Id = m.AssetId
    LEFT JOIN agora.vw_AssetType t ON t.AssetGroupCode = a.AssetGroupCode AND t.AssetTypeCode = a.AssetTypeCode
    OUTER APPLY (SELECT TOP 1 x.MovementTypeDescription FROM agora.vw_AssetMovementType x
                  WHERE x.MovementType = m.MovementType) k
    LEFT JOIN agora.Branch fb ON fb.BranchId = m.FromBranchId
    LEFT JOIN agora.Branch tb ON tb.BranchId = m.ToBranchId
    LEFT JOIN agora.Branch ob ON ob.BranchId = m.BranchId
    OUTER APPLY (SELECT TOP 1 u.UserName FROM agora.[User] u WHERE u.Id = m.CreatedBy) cu
    WHERE EXISTS (SELECT 1 FROM (VALUES (m.BranchId), (m.FromBranchId), (m.ToBranchId)) s (b)
                   WHERE (@AllBranches = 1 OR s.b IN (SELECT BranchId FROM @Branch))
                     AND (@AllAllowed  = 1 OR s.b IN (SELECT BranchId FROM @Allowed)))
      AND (@DateFrom IS NULL OR m.MovementDate >= @DateFrom)
      AND (@DateTo   IS NULL OR m.MovementDate <= @DateTo);

    SELECT r.*
    INTO #f
    FROM #rows r
    WHERE (@Search IS NULL
           OR r.MovementRef LIKE '%' + @Search + '%' OR r.AssetNo LIKE '%' + @Search + '%'
           OR r.Make LIKE '%' + @Search + '%' OR r.Model LIKE '%' + @Search + '%'
           OR r.FromSite LIKE '%' + @Search + '%' OR r.ToSite LIKE '%' + @Search + '%'
           OR r.Reason LIKE '%' + @Search + '%')
      AND NOT EXISTS (
            SELECT 1
            FROM @Filter f
            CROSS APPLY (SELECT
                CASE f.[Column]
                     WHEN 'MovementRef' THEN r.MovementRef
                     WHEN 'AssetNo'     THEN r.AssetNo
                     WHEN 'Make'        THEN r.Make
                     WHEN 'Model'       THEN r.Model
                     WHEN 'FromSite'    THEN r.FromSite
                     WHEN 'ToSite'      THEN r.ToSite
                     WHEN 'Reason'      THEN r.Reason
                     WHEN 'CapturedBy'  THEN r.CapturedBy
                END AS Txt,
                CASE f.[Column] WHEN 'Proceeds' THEN r.Proceeds END AS Num,
                CASE f.[Column] WHEN 'MovementDate' THEN r.MovementDate END AS Dt,
                TRY_CONVERT(decimal(18,4), f.[Value]) AS NumV,
                TRY_CONVERT(date, f.[Value])          AS DtV) v
            WHERE 1 = CASE
                WHEN f.[Type] = 'number' THEN CASE
                    WHEN v.NumV IS NULL THEN 0
                    WHEN v.Num IS NULL  THEN 1
                    WHEN f.[Op] = 'eq'  AND v.Num =  v.NumV THEN 0
                    WHEN f.[Op] = 'ne'  AND v.Num <> v.NumV THEN 0
                    WHEN f.[Op] = 'gt'  AND v.Num >  v.NumV THEN 0
                    WHEN f.[Op] = 'gte' AND v.Num >= v.NumV THEN 0
                    WHEN f.[Op] = 'lt'  AND v.Num <  v.NumV THEN 0
                    WHEN f.[Op] = 'lte' AND v.Num <= v.NumV THEN 0
                    ELSE 1 END
                WHEN f.[Type] = 'date' THEN CASE
                    WHEN v.DtV IS NULL THEN 0
                    WHEN v.Dt IS NULL  THEN 1
                    WHEN f.[Op] = 'eq'  AND v.Dt =  v.DtV THEN 0
                    WHEN f.[Op] = 'ne'  AND v.Dt <> v.DtV THEN 0
                    WHEN f.[Op] = 'gt'  AND v.Dt >  v.DtV THEN 0
                    WHEN f.[Op] = 'gte' AND v.Dt >= v.DtV THEN 0
                    WHEN f.[Op] = 'lt'  AND v.Dt <  v.DtV THEN 0
                    WHEN f.[Op] = 'lte' AND v.Dt <= v.DtV THEN 0
                    ELSE 1 END
                ELSE CASE
                    WHEN f.[Op] = 'eq' AND v.Txt = f.[Value] THEN 0
                    WHEN f.[Op] = 'ne' AND ISNULL(v.Txt, '') <> f.[Value] THEN 0
                    WHEN f.[Op] NOT IN ('eq', 'ne') AND v.Txt LIKE '%' + f.[Value] + '%' THEN 0
                    ELSE 1 END
                END
      )
      AND NOT EXISTS (
            SELECT 1
            FROM (SELECT DISTINCT [Column] FROM @FilterSet) s
            CROSS APPLY (SELECT CASE s.[Column]
                                     WHEN 'MovementTypeDescription' THEN r.MovementTypeDescription
                                     WHEN 'State'                   THEN r.[State]
                                END AS Txt) v
            WHERE v.Txt IS NULL
               OR v.Txt NOT IN (SELECT x.[Value] FROM @FilterSet x WHERE x.[Column] = s.[Column])
      );

    DECLARE @Offset int = (@Page - 1) * @PageSize;

    SELECT f.*
    FROM #f f
    ORDER BY
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn
                WHEN 'MovementRef'             THEN f.MovementRef
                WHEN 'AssetNo'                 THEN f.AssetNo
                WHEN 'MovementTypeDescription' THEN f.MovementTypeDescription
                WHEN 'FromSite'                THEN f.FromSite
                WHEN 'ToSite'                  THEN f.ToSite
                WHEN 'State'                   THEN f.[State]
            END
        END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn
                WHEN 'MovementRef'             THEN f.MovementRef
                WHEN 'AssetNo'                 THEN f.AssetNo
                WHEN 'MovementTypeDescription' THEN f.MovementTypeDescription
                WHEN 'FromSite'                THEN f.FromSite
                WHEN 'ToSite'                  THEN f.ToSite
                WHEN 'State'                   THEN f.[State]
            END
        END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'MovementDate' THEN f.MovementDate END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'MovementDate' THEN f.MovementDate END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'Proceeds'     THEN f.Proceeds     END ASC,
        CASE WHEN @SortAsc = 0 AND @SortColumn = 'Proceeds'     THEN f.Proceeds     END DESC,
        /* Newest first when nobody has asked for anything else. */
        f.MovementDate DESC, f.MovementId DESC
    OFFSET @Offset ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT COUNT_BIG(*) AS TotalRows FROM #f;

    SELECT SUM(CASE WHEN f.[State] = 'In force' THEN f.Proceeds END) AS Proceeds
    FROM #f f;
END
