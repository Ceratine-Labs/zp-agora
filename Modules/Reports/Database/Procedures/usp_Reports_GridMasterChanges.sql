/* ============================================================================
   agora.usp_Reports_GridMasterChanges

   WHAT IT IS FOR
   Master data changes (T027): every change made to a master, one row per
   field, newest first — who, when, which record, the value before and after,
   and why. Changing a category's MinGP reads "MINGP 10.8 → 25" with the
   person who pressed it.

   WHERE IT COMES FROM
   Two ledgers, each written by the procedure that made the change, inside
   its transaction — nothing here is copied or inferred:

     Agora      agora.MasterChange — Agora's own masters: branches (and their
                regions, brands, formats), system settings, and the overrides
                Agora holds over PumpIT's rows: stock lines, critical lines and
                bank recon rules. Source 'edit' is a screen; 'migrate' is the
                one-off copy from PumpIT.
     PumpIT     agora.vw_PumpItChange, restricted to the master ledgers —
     write-back MasterWrite (categories, profit centres, standard categories,
                expense codes, tills, shifts, transaction types, fuel types,
                tanks, pumps, utility meters, merchant/terminal numbers),
                PeopleWrite (employees, areas, posts) and PartnersWrite
                (suppliers, customers). The view supplies the action, the row
                and the reversal state exactly as the audit trail shows them;
                the field, before and after come from the ledger row itself.

   Times are SAST (the view's, and MasterChange's UTC moved by the server
   offset). The default window is the last 90 days.

   WHO READS IT
   Head office, Setup → Governance → Master data changes
   (Modules/Reports/Grids/MasterChangeGrid). @Authorized is the caller's
   reports.master-changes.view, resolved in PHP: without it the procedure
   refuses, so the extract cannot be reached around the screen.
   @AllowedBranchIds is the caller's sites (BranchContext); the branches
   picked are narrowed to it, and none left means no rows. The same
   filtered set drives the page, the total and the extract. Deployed by
   v1__22ph, and again by v1__22pi.

   READ-ONLY.

     EXEC agora.usp_Reports_GridMasterChanges @Authorized = 1, @Search = 'MINGP';
   ============================================================================ */

CREATE OR ALTER PROCEDURE [agora].[usp_Reports_GridMasterChanges]
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
        THROW 51000, 'AGORA:DENIED:You do not have permission to see the master data change log.', 1;

    SET @DateTo   = ISNULL(@DateTo, CONVERT(date, GETDATE()));
    SET @DateFrom = ISNULL(@DateFrom, DATEADD(day, -90, @DateTo));
    SET @Page     = CASE WHEN ISNULL(@Page, 1) < 1 THEN 1 ELSE @Page END;
    SET @PageSize = CASE WHEN ISNULL(@PageSize, 50) BETWEEN 1 AND 100000 THEN @PageSize ELSE 50 END;
    SET @Search   = NULLIF(LTRIM(RTRIM(ISNULL(@Search, ''))), '');
    SET @SortColumn = NULLIF(@SortColumn, '');

    DECLARE @From datetime2(0) = @DateFrom, @To datetime2(0) = DATEADD(day, 1, CONVERT(datetime2(0), @DateTo));
    DECLARE @Sast int = DATEPART(tzoffset, SYSDATETIMEOFFSET());

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

    CREATE TABLE #Rows (
        BranchId    int,
        BranchName  nvarchar(100) COLLATE DATABASE_DEFAULT,
        ChangedAt   datetime2(0),
        ChangedById bigint,
        ChangedBy   nvarchar(200) COLLATE DATABASE_DEFAULT,
        Entity      nvarchar(60)  COLLATE DATABASE_DEFAULT,
        Action      nvarchar(80)  COLLATE DATABASE_DEFAULT,
        RowKey      nvarchar(400) COLLATE DATABASE_DEFAULT,
        Field       nvarchar(60)  COLLATE DATABASE_DEFAULT,
        PriorValue  nvarchar(400) COLLATE DATABASE_DEFAULT,
        NewValue    nvarchar(400) COLLATE DATABASE_DEFAULT,
        Change      nvarchar(1000) COLLATE DATABASE_DEFAULT,
        Reason      nvarchar(400) COLLATE DATABASE_DEFAULT,
        Source      nvarchar(30)  COLLATE DATABASE_DEFAULT,
        State       nvarchar(20)  COLLATE DATABASE_DEFAULT,
        PumpItTable nvarchar(60)  COLLATE DATABASE_DEFAULT,
        Ref         nvarchar(60)  COLLATE DATABASE_DEFAULT,
        Ledger      nvarchar(40)  COLLATE DATABASE_DEFAULT,
        LedgerId    bigint
    );

    /* Agora's own masters: one MasterChange row per field. */
    INSERT INTO #Rows (BranchId, BranchName, ChangedAt, ChangedById, ChangedBy, Entity, Action, RowKey, Field, PriorValue, NewValue,
                       Change, Reason, Source, State, PumpItTable, Ref, Ledger, LedgerId)
    SELECT mc.BranchId,
           ISNULL(sb.Name, CONCAT(N'Site ', mc.BranchId)),
           CONVERT(datetime2(0), DATEADD(minute, @Sast, mc.CreatedAt)),
           mc.CreatedBy,
           cu.UserName,
           el.Label,
           CASE WHEN mc.Source = 'migrate' THEN CONCAT(el.Label, N' copied from PumpIT')
                WHEN mc.Entity = 'Setting' AND mc.Field = 'Override' AND mc.PriorValue IS NULL THEN N'Site value added'
                WHEN mc.Entity = 'Setting' AND mc.Field = 'Override' AND mc.NewValue IS NULL THEN N'Site value removed'
                WHEN mc.Field = 'Override' AND mc.PriorValue = N'None' THEN CONCAT(el.Label, N' override added')
                WHEN mc.Field = 'Override' AND mc.NewValue = N'Parked' THEN CONCAT(el.Label, N' override parked')
                WHEN mc.Field = 'Override' AND mc.PriorValue = N'Parked' THEN CONCAT(el.Label, N' override restored')
                ELSE CONCAT(el.Label, N' changed') END,
           COALESCE(CASE mc.Entity WHEN 'Branch' THEN b.Name
                                   WHEN 'Setting' THEN s.SettingKey
                                   WHEN 'Region' THEN rg.Name
                                   WHEN 'Brand' THEN br.Name
                                   WHEN 'Format' THEN fm.Name
                                   WHEN 'StockItem' THEN CONCAT_WS(N' · ', si.StockItemNo, si.StockItemDescription)
                                   WHEN 'CriticalLine' THEN CONCAT_WS(N' · ', CONCAT(sc.PosSystem, N' ', sc.PosCode), sc.Description)
                                   WHEN 'ReconCriteria' THEN CONCAT(rc.BankReconArea, N' rule ', rc.ProcessOrder) END,
                    CONCAT(mc.Entity, N' ', mc.EntityId)),
           mc.Field, mc.PriorValue, mc.NewValue,
           CONCAT(mc.Field, N' ', ISNULL(NULLIF(mc.PriorValue, N''), N'blank'), N' → ', ISNULL(NULLIF(mc.NewValue, N''), N'blank')),
           mc.Reason,
           CASE mc.Source WHEN 'migrate' THEN N'Agora (copied from PumpIT)' ELSE N'Agora' END,
           N'active', NULL,
           CONCAT(mc.Entity, N' ', mc.EntityId),
           N'MasterChange', mc.Id
    FROM agora.MasterChange mc
    LEFT JOIN agora.vw_Branch b  ON mc.Entity = 'Branch' AND b.BranchId = mc.EntityId
    LEFT JOIN agora.Setting s    ON mc.Entity = 'Setting' AND s.BranchId = mc.BranchId AND s.Id = mc.EntityId
    LEFT JOIN agora.Region rg    ON mc.Entity = 'Region' AND rg.Id = mc.EntityId
    LEFT JOIN agora.Brand br     ON mc.Entity = 'Brand' AND br.Id = mc.EntityId
    LEFT JOIN agora.[Format] fm  ON mc.Entity = 'Format' AND fm.Id = mc.EntityId
    LEFT JOIN agora.StockItem si ON mc.Entity = 'StockItem' AND si.BranchId = mc.BranchId AND si.Id = mc.EntityId
    LEFT JOIN agora.StockItemCritical sc ON mc.Entity = 'CriticalLine' AND sc.BranchId = mc.BranchId AND sc.Id = mc.EntityId
    LEFT JOIN agora.ReconCriteria rc ON mc.Entity = 'ReconCriteria' AND rc.BranchId = mc.BranchId AND rc.Id = mc.EntityId
    LEFT JOIN agora.vw_Branch sb ON sb.BranchId = mc.BranchId
    OUTER APPLY (SELECT TOP 1 u.UserName FROM agora.[User] u WHERE u.Id = mc.CreatedBy) cu
    CROSS APPLY (SELECT CONVERT(nvarchar(60), CASE mc.Entity WHEN 'StockItem' THEN N'Stock line'
                                                             WHEN 'CriticalLine' THEN N'Critical line'
                                                             WHEN 'ReconCriteria' THEN N'Recon rule'
                                                             ELSE mc.Entity END) AS Label) el
    WHERE mc.CreatedAt >= DATEADD(minute, -@Sast, @From) AND mc.CreatedAt < DATEADD(minute, -@Sast, @To)
      AND (@AllBranches = 1 OR mc.BranchId IN (SELECT BranchId FROM @Branch));

    /* PumpIT's masters, written back: the master ledgers' rows of the audit
       trail, with each ledger row's own field, before and after. */
    INSERT INTO #Rows (BranchId, BranchName, ChangedAt, ChangedById, ChangedBy, Entity, Action, RowKey, Field, PriorValue, NewValue,
                       Change, Reason, Source, State, PumpItTable, Ref, Ledger, LedgerId)
    SELECT c.BranchId, ISNULL(sb.Name, CONCAT(N'Site ', c.BranchId)), c.ChangedAt, c.ChangedBy, cu.UserName,
           CASE c.Ledger
                WHEN N'MasterWrite' THEN
                    CASE mw.Entity WHEN 'CategoryGl' THEN N'Category band'
                                   WHEN 'ProfitCentre' THEN N'Profit centre'
                                   WHEN 'StandardCategory' THEN N'Standard category'
                                   WHEN 'Expense' THEN N'Expense code'
                                   WHEN 'Till' THEN N'Till'
                                   WHEN 'Shift' THEN N'Shift'
                                   WHEN 'TransactionType' THEN N'Transaction type'
                                   WHEN 'FuelType' THEN N'Fuel type'
                                   WHEN 'Tank' THEN N'Tank'
                                   WHEN 'Pump' THEN N'Pump'
                                   WHEN 'UtilityMeter' THEN N'Utility meter'
                                   WHEN 'Terminal' THEN N'Merchant or terminal number'
                                   ELSE mw.Entity END
                WHEN N'PeopleWrite' THEN
                    CASE pw.Verb WHEN 'employee' THEN N'Employee' WHEN 'post-add' THEN N'Employee post' ELSE N'Employee area' END
                ELSE
                    CASE WHEN pa.Verb LIKE 'supplier%' THEN N'Supplier' ELSE N'Customer' END
           END,
           c.Action, c.RowKey,
           COALESCE(mw.Field, pw.Field, pa.Field),
           COALESCE(mw.PriorValue, pw.PriorValue, pa.PriorValue),
           COALESCE(mw.NewValue, pw.NewValue, pa.NewValue),
           c.Change, c.Reason, N'PumpIT write-back', c.State, c.PumpItTable, c.Ref, c.Ledger, c.LedgerId
    FROM agora.vw_PumpItChange c
    LEFT JOIN agora.MasterWrite mw   ON c.Ledger = N'MasterWrite'   AND mw.BranchId = c.BranchId AND mw.Id = c.LedgerId
    LEFT JOIN agora.PeopleWrite pw   ON c.Ledger = N'PeopleWrite'   AND pw.BranchId = c.BranchId AND pw.Id = c.LedgerId
    LEFT JOIN agora.PartnersWrite pa ON c.Ledger = N'PartnersWrite' AND pa.BranchId = c.BranchId AND pa.Id = c.LedgerId
    LEFT JOIN agora.vw_Branch sb     ON sb.BranchId = c.BranchId
    OUTER APPLY (SELECT TOP 1 u.UserName FROM agora.[User] u WHERE u.Id = c.ChangedBy) cu
    WHERE c.Ledger IN (N'MasterWrite', N'PeopleWrite', N'PartnersWrite')
      AND c.ChangedAt >= @From AND c.ChangedAt < @To
      AND (@AllBranches = 1 OR c.BranchId IN (SELECT BranchId FROM @Branch));

    /* The global search and the header filters: {column, type, op, value}, or {column, type: set, in}. */
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
    WHERE (@Search IS NULL
           OR ISNULL(r.RowKey, N'')      LIKE '%' + @Search + '%'
           OR ISNULL(r.Field, N'')       LIKE '%' + @Search + '%'
           OR ISNULL(r.PriorValue, N'')  LIKE '%' + @Search + '%'
           OR ISNULL(r.NewValue, N'')    LIKE '%' + @Search + '%'
           OR ISNULL(r.Reason, N'')      LIKE '%' + @Search + '%'
           OR ISNULL(r.Entity, N'')      LIKE '%' + @Search + '%'
           OR ISNULL(r.Action, N'')      LIKE '%' + @Search + '%'
           OR ISNULL(r.BranchName, N'')  LIKE '%' + @Search + '%'
           OR ISNULL(r.ChangedBy, N'')   LIKE '%' + @Search + '%'
           OR ISNULL(r.PumpItTable, N'') LIKE '%' + @Search + '%')
      AND NOT EXISTS (
        SELECT 1
        FROM @Filter f
        CROSS APPLY (SELECT
            CASE f.[Column]
                WHEN 'BranchName'  THEN r.BranchName
                WHEN 'ChangedBy'   THEN r.ChangedBy
                WHEN 'Entity'      THEN r.Entity
                WHEN 'Action'      THEN r.Action
                WHEN 'RowKey'      THEN r.RowKey
                WHEN 'Field'       THEN r.Field
                WHEN 'PriorValue'  THEN r.PriorValue
                WHEN 'NewValue'    THEN r.NewValue
                WHEN 'Change'      THEN r.Change
                WHEN 'Reason'      THEN r.Reason
                WHEN 'Source'      THEN r.Source
                WHEN 'State'       THEN r.State
                WHEN 'PumpItTable' THEN r.PumpItTable
                WHEN 'Ref'         THEN r.Ref
                WHEN 'Ledger'      THEN r.Ledger
            END AS T,
            CASE f.[Column] WHEN 'LedgerId' THEN CONVERT(decimal(19,4), r.LedgerId) END AS N,
            CASE f.[Column] WHEN 'ChangedAt' THEN CONVERT(date, r.ChangedAt) END AS D,
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
              AND x.[Value] = CASE fs.[Column] WHEN 'Entity' THEN r.Entity
                                               WHEN 'Source' THEN r.Source
                                               WHEN 'State'  THEN r.State
                                               WHEN 'Ledger' THEN r.Ledger END)
    );

    SELECT BranchId, BranchName, ChangedAt, ChangedBy, Entity, Action, RowKey, Field, PriorValue, NewValue, Change,
           Reason, Source, State, PumpItTable, Ref, Ledger, LedgerId
    FROM #Filtered
    ORDER BY
        CASE WHEN @SortAsc = 1 THEN
            CASE @SortColumn WHEN 'BranchName'  THEN BranchName
                             WHEN 'ChangedBy'   THEN ChangedBy
                             WHEN 'Entity'      THEN Entity
                             WHEN 'Action'      THEN Action
                             WHEN 'RowKey'      THEN RowKey
                             WHEN 'Field'       THEN Field
                             WHEN 'Source'      THEN Source
                             WHEN 'State'       THEN State
                             WHEN 'PumpItTable' THEN PumpItTable END END ASC,
        CASE WHEN @SortAsc = 0 THEN
            CASE @SortColumn WHEN 'BranchName'  THEN BranchName
                             WHEN 'ChangedBy'   THEN ChangedBy
                             WHEN 'Entity'      THEN Entity
                             WHEN 'Action'      THEN Action
                             WHEN 'RowKey'      THEN RowKey
                             WHEN 'Field'       THEN Field
                             WHEN 'Source'      THEN Source
                             WHEN 'State'       THEN State
                             WHEN 'PumpItTable' THEN PumpItTable END END DESC,
        CASE WHEN @SortAsc = 1 AND @SortColumn = 'ChangedAt' THEN ChangedAt END ASC,
        ChangedAt DESC, Ledger, LedgerId DESC
    OFFSET (@Page - 1) * @PageSize ROWS FETCH NEXT @PageSize ROWS ONLY;

    SELECT COUNT_BIG(*) AS TotalRows FROM #Filtered;
END
