/* Migrate dbo.SS_Branch into agora.Branch (T021).

   Reads PumpIT only through agora.vw_LegacyBranch, vw_LegacyBranchGrouping and
   vw_LegacyBranchProfitCentre. Writes only Agora's own tables, in one
   transaction, with an agora.MasterChange row (Source 'migrate') for every
   value it sets. NEVER writes PumpIT.

   What it does:
     1. agora.Region / Brand / Format: every SS_Region / SS_Brand / SS_Class row
        not yet present (matched on LegacyId, then on Name for a row made here
        before the migration) is inserted, in PumpIT id order.
     2. A branch in SS_Branch but not in agora.Branch is inserted. BranchId IS
        SSBranchId — never re-numbered.
     3. Each branch whose MigratedAt is NULL gets Region, Brand, Format, Status,
        POSType, IsAdminEntity, IsTrading, LegacyName and its profit centres,
        and MigratedAt is stamped. A branch already migrated is NOT touched, so
        a re-run never overwrites an edit made on the Branches screen.

   The derivation, and where each value comes from:
     Region / Brand    SS_Branch.RegionId / BrandId, mapped to the agora row
                       carrying that LegacyId.
     Format            SS_Branch.ClassId (PumpIT's "class"). ClassId itself is
                       kept as it was.
     POSType           SS_Branch.POSType; NULL is BRANCH. Anything other than
                       BRANCH or NAMOS is refused rather than guessed.
     IsAdminEntity     The six administrative entities, BY ID: 1 AJLG
                       Properties, 2 Zululand Petroleum, 4 Arcum Venandi,
                       14 Jakarie Vulstasie, 20 Thokozile Trust, 27 Zickyza
                       Properties. PumpIT has no column that says so (BrandId 8
                       "Administrative" is also carried by trading sites, and
                       Bullion, 29, has no POS import either), so the list is
                       the plan's and the mockup's, verified against the live
                       SS_Branch on 12 Nov 2026.
     Status            Non-trading for an administrative entity; otherwise
                       Trading while SS_Branch.IsActive = 1, Mothballed when 0.
                       PumpIT has no mothballed flag — a site that has stopped
                       trading but is still IsActive comes out Trading and is
                       corrected on the Branches screen.
     IsTrading         Status = 'Trading'.
     Profit centres    The distinct ACTIVE SDK_CategoryGLCode profit centres per
                       branch (Source 'PumpIT').

   Result set 1: the status row (Ok, Code, Message, Id = branches migrated).
   Result set 2: every SS_Branch row beside what Agora now holds, for review.

   Called by Core's BranchSeeder and BranchMasterSeeder. Deployed by v1__01pi. */
CREATE OR ALTER PROCEDURE agora.usp_Migrate_Branch
    @UserId INT = NULL,
    @GroupBranchId INT = 2
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF NOT EXISTS (SELECT 1 FROM agora.vw_LegacyBranch)
        THROW 51000, 'AGORA:BRANCH_SOURCE_EMPTY:SS_Branch returned no rows. Nothing was migrated.', 1;

    IF EXISTS (SELECT 1 FROM agora.vw_LegacyBranch WHERE ISNULL(POSType, N'BRANCH') NOT IN (N'BRANCH', N'NAMOS'))
        THROW 51000, 'AGORA:BRANCH_POSTYPE:SS_Branch carries a POS type other than BRANCH or NAMOS. Nothing was migrated.', 1;

    DECLARE @Now DATETIME2(0) = SYSUTCDATETIME();
    DECLARE @Admin TABLE (BranchId INT PRIMARY KEY);
    INSERT INTO @Admin (BranchId) VALUES (1), (2), (4), (14), (20), (27);

    DECLARE @Migrated INT = 0;

    BEGIN TRY
        BEGIN TRANSACTION;

        /* 1. The three groupings. A row made here before the migration with the
              same name is adopted (LegacyId set) rather than duplicated. */
        UPDATE r SET LegacyId = g.LegacyId, UpdatedAt = @Now, UpdatedBy = @UserId
        FROM agora.Region r
        JOIN agora.vw_LegacyBranchGrouping g ON g.Kind = N'Region' AND g.Name = r.Name
        WHERE r.BranchId = @GroupBranchId AND r.LegacyId IS NULL
          AND NOT EXISTS (SELECT 1 FROM agora.Region x WHERE x.LegacyId = g.LegacyId);
        INSERT INTO agora.Region (BranchId, Name, LegacyId, IsActive, SortOrder, CreatedAt, CreatedBy)
        SELECT @GroupBranchId, g.Name, g.LegacyId, ISNULL(g.IsActive, 1), g.LegacyId * 10, @Now, @UserId
        FROM agora.vw_LegacyBranchGrouping g
        WHERE g.Kind = N'Region' AND NOT EXISTS (SELECT 1 FROM agora.Region x WHERE x.LegacyId = g.LegacyId)
        ORDER BY g.LegacyId;

        UPDATE r SET LegacyId = g.LegacyId, UpdatedAt = @Now, UpdatedBy = @UserId
        FROM agora.Brand r
        JOIN agora.vw_LegacyBranchGrouping g ON g.Kind = N'Brand' AND g.Name = r.Name
        WHERE r.BranchId = @GroupBranchId AND r.LegacyId IS NULL
          AND NOT EXISTS (SELECT 1 FROM agora.Brand x WHERE x.LegacyId = g.LegacyId);
        INSERT INTO agora.Brand (BranchId, Name, LegacyId, IsActive, SortOrder, CreatedAt, CreatedBy)
        SELECT @GroupBranchId, g.Name, g.LegacyId, ISNULL(g.IsActive, 1), g.LegacyId * 10, @Now, @UserId
        FROM agora.vw_LegacyBranchGrouping g
        WHERE g.Kind = N'Brand' AND NOT EXISTS (SELECT 1 FROM agora.Brand x WHERE x.LegacyId = g.LegacyId)
        ORDER BY g.LegacyId;

        UPDATE r SET LegacyId = g.LegacyId, UpdatedAt = @Now, UpdatedBy = @UserId
        FROM agora.[Format] r
        JOIN agora.vw_LegacyBranchGrouping g ON g.Kind = N'Format' AND g.Name = r.Name
        WHERE r.BranchId = @GroupBranchId AND r.LegacyId IS NULL
          AND NOT EXISTS (SELECT 1 FROM agora.[Format] x WHERE x.LegacyId = g.LegacyId);
        INSERT INTO agora.[Format] (BranchId, Name, LegacyId, IsActive, SortOrder, CreatedAt, CreatedBy)
        SELECT @GroupBranchId, g.Name, g.LegacyId, ISNULL(g.IsActive, 1), g.LegacyId * 10, @Now, @UserId
        FROM agora.vw_LegacyBranchGrouping g
        WHERE g.Kind = N'Format' AND NOT EXISTS (SELECT 1 FROM agora.[Format] x WHERE x.LegacyId = g.LegacyId)
        ORDER BY g.LegacyId;

        /* 2. A branch PumpIT has and Agora does not. Its values arrive in step 3. */
        INSERT INTO agora.Branch (BranchId, Name, IsTrading, IsActive, SortOrder, CreatedAt, CreatedBy)
        SELECT s.BranchId, s.Name, 0, s.IsActive, s.BranchId, @Now, @UserId
        FROM agora.vw_LegacyBranch s
        WHERE NOT EXISTS (SELECT 1 FROM agora.Branch b WHERE b.BranchId = s.BranchId);

        /* 3. The derivation, for every branch not yet migrated. */
        SELECT s.BranchId,
               s.Name,
               s.Name AS LegacyName,
               r.Id AS RegionId,
               br.Id AS BrandId,
               s.ClassId,
               f.Id AS FormatId,
               CONVERT(BIT, CASE WHEN a.BranchId IS NULL THEN 0 ELSE 1 END) AS IsAdminEntity,
               CONVERT(NVARCHAR(20), CASE WHEN a.BranchId IS NOT NULL THEN N'Non-trading'
                                          WHEN s.IsActive = 1 THEN N'Trading'
                                          ELSE N'Mothballed' END) AS Status,
               CONVERT(NVARCHAR(20), ISNULL(s.POSType, N'BRANCH')) AS POSType,
               CONVERT(BIT, s.IsActive) AS IsActive
        INTO #M
        FROM agora.vw_LegacyBranch s
        JOIN agora.Branch b ON b.BranchId = s.BranchId AND b.MigratedAt IS NULL
        LEFT JOIN agora.Region r    ON r.LegacyId = s.RegionId
        LEFT JOIN agora.Brand br    ON br.LegacyId = s.BrandId
        LEFT JOIN agora.[Format] f  ON f.LegacyId = s.ClassId
        LEFT JOIN @Admin a          ON a.BranchId = s.BranchId;

        SET @Migrated = @@ROWCOUNT;

        INSERT INTO agora.MasterChange (BranchId, Entity, EntityId, Field, PriorValue, NewValue, Reason, Source, CreatedAt, CreatedBy)
        SELECT m.BranchId, N'Branch', m.BranchId, c.Field, c.PriorValue, c.NewValue,
               N'Migrated from SS_Branch', N'migrate', @Now, @UserId
        FROM #M m
        JOIN agora.Branch b ON b.BranchId = m.BranchId
        -- Before the migration a branch held PumpIT's bare ids, not Agora
        -- groupings, so a grouping's prior value is recorded as empty.
        CROSS APPLY (VALUES
            (N'Name',          b.Name,                                   m.Name),
            (N'Region',        NULL,                                     (SELECT Name FROM agora.Region WHERE Id = m.RegionId)),
            (N'Brand',         NULL,                                     (SELECT Name FROM agora.Brand WHERE Id = m.BrandId)),
            (N'Format',        NULL,                                     (SELECT Name FROM agora.[Format] WHERE Id = m.FormatId)),
            (N'Status',        b.Status,                                 m.Status),
            (N'POSType',       b.POSType,                                m.POSType),
            (N'IsAdminEntity', CASE WHEN b.IsAdminEntity = 1 THEN N'Yes' ELSE N'No' END,
                               CASE WHEN m.IsAdminEntity = 1 THEN N'Yes' ELSE N'No' END),
            (N'IsActive',      CASE WHEN b.IsActive = 1 THEN N'Yes' ELSE N'No' END,
                               CASE WHEN m.IsActive = 1 THEN N'Yes' ELSE N'No' END)
        ) c (Field, PriorValue, NewValue)
        WHERE ISNULL(c.PriorValue, N'') <> ISNULL(c.NewValue, N'');

        UPDATE b
        SET Name = m.Name, LegacyName = m.LegacyName,
            RegionId = m.RegionId, BrandId = m.BrandId, ClassId = m.ClassId, FormatId = m.FormatId,
            IsAdminEntity = m.IsAdminEntity, Status = m.Status, POSType = m.POSType,
            IsActive = m.IsActive,
            IsTrading = CASE WHEN m.Status = N'Trading' THEN 1 ELSE 0 END,
            MigratedAt = @Now, UpdatedAt = @Now, UpdatedBy = @UserId
        FROM agora.Branch b
        JOIN #M m ON m.BranchId = b.BranchId;

        INSERT INTO agora.BranchProfitCentre (BranchId, ProfitCentreId, Source, CreatedAt, CreatedBy)
        SELECT p.BranchId, p.ProfitCentreId, N'PumpIT', @Now, @UserId
        FROM agora.vw_LegacyBranchProfitCentre p
        JOIN #M m ON m.BranchId = p.BranchId
        WHERE NOT EXISTS (SELECT 1 FROM agora.BranchProfitCentre x
                          WHERE x.BranchId = p.BranchId AND x.ProfitCentreId = p.ProfitCentreId);

        INSERT INTO agora.MasterChange (BranchId, Entity, EntityId, Field, PriorValue, NewValue, Reason, Source, CreatedAt, CreatedBy)
        SELECT m.BranchId, N'Branch', m.BranchId, N'ProfitCentres', NULL,
               (SELECT STRING_AGG(CONVERT(nvarchar(max), pc.Name), N', ') WITHIN GROUP (ORDER BY pc.Name)
                FROM agora.BranchProfitCentre x JOIN agora.vw_ProfitCentre pc ON pc.ProfitCentreId = x.ProfitCentreId
                WHERE x.BranchId = m.BranchId),
               N'Migrated from SDK_CategoryGLCode', N'migrate', @Now, @UserId
        FROM #M m
        WHERE EXISTS (SELECT 1 FROM agora.BranchProfitCentre x WHERE x.BranchId = m.BranchId);

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT CONVERT(BIT, 1) AS Ok,
           CASE WHEN @Migrated = 0 THEN 'UNCHANGED' ELSE 'MIGRATED' END AS Code,
           CONVERT(NVARCHAR(400), CONCAT(@Migrated, N' branch(es) migrated from SS_Branch; ',
                   (SELECT COUNT(*) FROM agora.vw_LegacyBranch), N' in PumpIT.')) AS Message,
           CONVERT(BIGINT, @Migrated) AS Id;

    SELECT s.BranchId, s.Name AS LegacyName, b.Name,
           s.RegionId AS LegacyRegionId, r.Name AS Region,
           s.BrandId AS LegacyBrandId, br.Name AS Brand,
           s.ClassId AS LegacyClassId, f.Name AS [Format],
           b.Status, b.POSType, b.IsAdminEntity, b.IsTrading, b.IsActive,
           (SELECT STRING_AGG(CONVERT(nvarchar(max), pc.Name), N', ') WITHIN GROUP (ORDER BY pc.Name)
            FROM agora.BranchProfitCentre x JOIN agora.vw_ProfitCentre pc ON pc.ProfitCentreId = x.ProfitCentreId
            WHERE x.BranchId = b.BranchId) AS ProfitCentres,
           b.MigratedAt
    FROM agora.vw_LegacyBranch s
    LEFT JOIN agora.Branch b    ON b.BranchId = s.BranchId
    LEFT JOIN agora.Region r    ON r.Id = b.RegionId
    LEFT JOIN agora.Brand br    ON br.Id = b.BrandId
    LEFT JOIN agora.[Format] f  ON f.Id = b.FormatId
    ORDER BY s.BranchId;
END
