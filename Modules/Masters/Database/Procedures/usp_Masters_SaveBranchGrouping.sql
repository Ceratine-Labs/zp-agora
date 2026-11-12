/* Create or change one region, brand or format (T021).

   Owns the rules. Writes agora.Region / agora.Brand / agora.Format and an
   agora.MasterChange row per changed field (Entity = the kind, BranchId = the
   group entity, since these are global), atomically. NEVER writes PumpIT.

   Rules:
     - @Authorized comes from the authenticated permission check —
       masters.groupings.save to create, masters.groupings.edit to change.
     - @Kind is Region, Brand or Format. No dynamic SQL: one branch per table.
     - A name is unique within its kind.
     - One still carried by an active branch cannot be switched off; move the
       branches first.
     - Every change carries a reason of at least 10 characters.
     - A change hands back the RowVer it was opened with (@ExpectedVersion).
   There is no delete: a grouping goes out of use by being switched off, and
   its history stays.

   Returns (Ok, Code, Message, Id). Deployed by v1__02p. */
CREATE OR ALTER PROCEDURE agora.usp_Masters_SaveBranchGrouping
    @Kind NVARCHAR(10),
    @Id BIGINT = NULL,
    @Name NVARCHAR(80),
    @IsActive BIT = 1,
    @ExpectedVersion VARCHAR(18) = NULL,
    @Reason NVARCHAR(400),
    @UserId INT = NULL,
    @Authorized BIT = 0,
    @GroupBranchId INT = 2
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF ISNULL(@Authorized, 0) <> 1
        THROW 51000, 'AGORA:GROUPING_DENIED:You do not have permission to change regions, brands or formats.', 1;
    IF @Kind IS NULL OR @Kind NOT IN (N'Region', N'Brand', N'Format')
        THROW 51000, 'AGORA:GROUPING_KIND:Choose region, brand or format.', 1;

    SET @Name = NULLIF(LTRIM(RTRIM(@Name)), '');
    SET @Reason = NULLIF(LTRIM(RTRIM(@Reason)), '');
    SET @IsActive = ISNULL(@IsActive, 1);
    IF @Name IS NULL
        THROW 51000, 'AGORA:GROUPING_NAME:Give it a name.', 1;
    IF @Reason IS NULL OR LEN(@Reason) < 10
        THROW 51000, 'AGORA:GROUPING_REASON:Say why, in at least 10 characters.', 1;

    DECLARE @Now DATETIME2(0) = SYSUTCDATETIME();
    DECLARE @Version VARCHAR(18), @OldName NVARCHAR(80), @OldActive BIT, @InUse INT, @Taken BIT = 0, @Changed INT = 0;

    BEGIN TRY
        BEGIN TRANSACTION;

        IF @Kind = N'Region'
        BEGIN
            SELECT @Version = CONVERT(VARCHAR(18), CONVERT(BINARY(8), RowVer), 1), @OldName = Name, @OldActive = IsActive
            FROM agora.Region WITH (UPDLOCK, HOLDLOCK) WHERE Id = @Id AND DeletedAt IS NULL;
            SELECT @Taken = 1 FROM agora.Region WHERE Name = @Name AND DeletedAt IS NULL AND (@Id IS NULL OR Id <> @Id);
            SELECT @InUse = COUNT(*) FROM agora.Branch WHERE RegionId = @Id AND IsActive = 1 AND DeletedAt IS NULL;
        END
        ELSE IF @Kind = N'Brand'
        BEGIN
            SELECT @Version = CONVERT(VARCHAR(18), CONVERT(BINARY(8), RowVer), 1), @OldName = Name, @OldActive = IsActive
            FROM agora.Brand WITH (UPDLOCK, HOLDLOCK) WHERE Id = @Id AND DeletedAt IS NULL;
            SELECT @Taken = 1 FROM agora.Brand WHERE Name = @Name AND DeletedAt IS NULL AND (@Id IS NULL OR Id <> @Id);
            SELECT @InUse = COUNT(*) FROM agora.Branch WHERE BrandId = @Id AND IsActive = 1 AND DeletedAt IS NULL;
        END
        ELSE
        BEGIN
            SELECT @Version = CONVERT(VARCHAR(18), CONVERT(BINARY(8), RowVer), 1), @OldName = Name, @OldActive = IsActive
            FROM agora.[Format] WITH (UPDLOCK, HOLDLOCK) WHERE Id = @Id AND DeletedAt IS NULL;
            SELECT @Taken = 1 FROM agora.[Format] WHERE Name = @Name AND DeletedAt IS NULL AND (@Id IS NULL OR Id <> @Id);
            SELECT @InUse = COUNT(*) FROM agora.Branch WHERE FormatId = @Id AND IsActive = 1 AND DeletedAt IS NULL;
        END

        IF @Id IS NOT NULL AND @Version IS NULL
            THROW 51000, 'AGORA:GROUPING_MISSING:That no longer exists. Reload the page.', 1;
        IF @Id IS NOT NULL AND ISNULL(@ExpectedVersion, '') <> @Version
            THROW 51000, 'AGORA:GROUPING_STALE:This changed since you opened it. Reload it and make your change again.', 1;
        IF @Taken = 1
            THROW 51000, 'AGORA:GROUPING_NAME_TAKEN:That name is already in use.', 1;
        IF @Id IS NOT NULL AND @IsActive = 0 AND ISNULL(@OldActive, 0) = 1 AND @InUse > 0
            THROW 51000, 'AGORA:GROUPING_IN_USE:Active branches still carry this. Move them to another before switching it off.', 1;

        IF @Id IS NULL
        BEGIN
            IF @Kind = N'Region'
            BEGIN
                INSERT INTO agora.Region (BranchId, Name, IsActive, SortOrder, CreatedAt, CreatedBy)
                VALUES (@GroupBranchId, @Name, @IsActive, (SELECT ISNULL(MAX(SortOrder), 0) + 10 FROM agora.Region), @Now, @UserId);
                SET @Id = SCOPE_IDENTITY();
            END
            ELSE IF @Kind = N'Brand'
            BEGIN
                INSERT INTO agora.Brand (BranchId, Name, IsActive, SortOrder, CreatedAt, CreatedBy)
                VALUES (@GroupBranchId, @Name, @IsActive, (SELECT ISNULL(MAX(SortOrder), 0) + 10 FROM agora.Brand), @Now, @UserId);
                SET @Id = SCOPE_IDENTITY();
            END
            ELSE
            BEGIN
                INSERT INTO agora.[Format] (BranchId, Name, IsActive, SortOrder, CreatedAt, CreatedBy)
                VALUES (@GroupBranchId, @Name, @IsActive, (SELECT ISNULL(MAX(SortOrder), 0) + 10 FROM agora.[Format]), @Now, @UserId);
                SET @Id = SCOPE_IDENTITY();
            END
            SET @OldActive = NULL;
        END

        INSERT INTO agora.MasterChange (BranchId, Entity, EntityId, Field, PriorValue, NewValue, Reason, Source, CreatedAt, CreatedBy)
        SELECT @GroupBranchId, @Kind, @Id, c.Field, c.PriorValue, c.NewValue, @Reason, N'edit', @Now, @UserId
        FROM (VALUES
            (N'Name', @OldName, @Name),
            (N'IsActive', CASE @OldActive WHEN 1 THEN N'Yes' WHEN 0 THEN N'No' END, CASE WHEN @IsActive = 1 THEN N'Yes' ELSE N'No' END)
        ) c (Field, PriorValue, NewValue)
        WHERE ISNULL(c.PriorValue, N'') <> ISNULL(c.NewValue, N'');
        SET @Changed = @@ROWCOUNT;

        IF @Changed > 0 AND @Version IS NOT NULL
        BEGIN
            IF @Kind = N'Region'
                UPDATE agora.Region SET Name = @Name, IsActive = @IsActive, UpdatedAt = @Now, UpdatedBy = @UserId WHERE Id = @Id;
            ELSE IF @Kind = N'Brand'
                UPDATE agora.Brand SET Name = @Name, IsActive = @IsActive, UpdatedAt = @Now, UpdatedBy = @UserId WHERE Id = @Id;
            ELSE
                UPDATE agora.[Format] SET Name = @Name, IsActive = @IsActive, UpdatedAt = @Now, UpdatedBy = @UserId WHERE Id = @Id;
        END

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT CONVERT(BIT, 1) AS Ok,
           CASE WHEN @Version IS NULL THEN 'CREATED' WHEN @Changed = 0 THEN 'UNCHANGED' ELSE 'SAVED' END AS Code,
           CONVERT(NVARCHAR(400), CASE WHEN @Version IS NULL THEN CONCAT(@Kind, N' ', @Name, N' created.')
                WHEN @Changed = 0 THEN N'Nothing changed, so nothing was saved.'
                ELSE CONCAT(@Kind, N' saved.') END) AS Message,
           @Id AS Id;
END
