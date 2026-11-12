/* Save one branch from Setup → The business → Branches (T021).

   Owns every rule about a branch edit. Writes agora.Branch,
   agora.BranchProfitCentre and one agora.MasterChange row per changed field,
   atomically. NEVER writes PumpIT; SS_Branch keeps whatever it said.

   Rules:
     - @Authorized comes from the authenticated permission check
       (masters.branches.edit), never from the form.
     - BranchId is SS_Branch.SSBranchId and is never changed or created here.
     - Status is Trading | Mothballed | Non-trading; POSType BRANCH | NAMOS.
     - An administrative entity is Non-trading: it is a branch for accounting
       and never keeps a trading day.
     - IsTrading, which every trading list and the scope bar read, is set to
       Status = 'Trading'. A mothballed or non-trading branch leaves them.
     - A region, brand or format must exist and be active, unless it is the one
       the branch already carries.
     - Every change carries a reason of at least 10 characters.
     - @ExpectedVersion is the RowVer the screen was opened with; a branch
       changed since is refused rather than overwritten.
     - @ProfitCentreIds is a comma-separated list of SS_ProfitCentre ids; the
       branch's profit centres become exactly that list.

   Returns (Ok, Code, Message, Id = BranchId). Deployed by v1__02p. */
CREATE OR ALTER PROCEDURE agora.usp_Masters_SaveBranch
    @BranchId INT,
    @Name NVARCHAR(100),
    @RegionId BIGINT = NULL,
    @BrandId BIGINT = NULL,
    @FormatId BIGINT = NULL,
    @Status NVARCHAR(20),
    @POSType NVARCHAR(20),
    @IsAdminEntity BIT,
    @IsActive BIT,
    @ProfitCentreIds NVARCHAR(MAX) = NULL,
    @ExpectedVersion VARCHAR(18),
    @Reason NVARCHAR(400),
    @UserId INT = NULL,
    @Authorized BIT = 0
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    IF ISNULL(@Authorized, 0) <> 1
        THROW 51000, 'AGORA:BRANCH_DENIED:You do not have permission to edit branches.', 1;

    SET @Name = NULLIF(LTRIM(RTRIM(@Name)), '');
    SET @Reason = NULLIF(LTRIM(RTRIM(@Reason)), '');
    SET @Status = LTRIM(RTRIM(@Status));
    SET @POSType = UPPER(LTRIM(RTRIM(@POSType)));

    IF @Name IS NULL
        THROW 51000, 'AGORA:BRANCH_NAME:Give the branch a name.', 1;
    IF @Status IS NULL OR @Status NOT IN (N'Trading', N'Mothballed', N'Non-trading')
        THROW 51000, 'AGORA:BRANCH_STATUS:Status is Trading, Mothballed or Non-trading.', 1;
    IF @POSType IS NULL OR @POSType NOT IN (N'BRANCH', N'NAMOS')
        THROW 51000, 'AGORA:BRANCH_POSTYPE:POS type is BRANCH or NAMOS.', 1;
    IF ISNULL(@IsAdminEntity, 0) = 1 AND @Status <> N'Non-trading'
        THROW 51000, 'AGORA:BRANCH_ADMIN_STATUS:An administrative entity is Non-trading. Change the status, or clear the administrative flag.', 1;
    IF @Reason IS NULL OR LEN(@Reason) < 10
        THROW 51000, 'AGORA:BRANCH_REASON:Say why, in at least 10 characters.', 1;

    DECLARE @Wanted TABLE (ProfitCentreId INT PRIMARY KEY);
    INSERT INTO @Wanted (ProfitCentreId)
    SELECT DISTINCT TRY_CONVERT(INT, value) FROM STRING_SPLIT(ISNULL(@ProfitCentreIds, N''), ',')
    WHERE LTRIM(RTRIM(value)) <> '' AND TRY_CONVERT(INT, value) IS NOT NULL;
    IF EXISTS (SELECT 1 FROM @Wanted w WHERE NOT EXISTS (SELECT 1 FROM agora.vw_ProfitCentre p WHERE p.ProfitCentreId = w.ProfitCentreId))
        THROW 51000, 'AGORA:BRANCH_PROFIT_CENTRE:One of the profit centres no longer exists. Reload and choose again.', 1;

    DECLARE @Now DATETIME2(0) = SYSUTCDATETIME();

    BEGIN TRY
        BEGIN TRANSACTION;

        DECLARE @Version VARCHAR(18), @OldName NVARCHAR(100), @OldRegion BIGINT, @OldBrand BIGINT, @OldFormat BIGINT,
                @OldStatus NVARCHAR(20), @OldPOS NVARCHAR(20), @OldAdmin BIT, @OldActive BIT;
        SELECT @Version = CONVERT(VARCHAR(18), CONVERT(BINARY(8), RowVer), 1),
               @OldName = Name, @OldRegion = RegionId, @OldBrand = BrandId, @OldFormat = FormatId,
               @OldStatus = Status, @OldPOS = POSType, @OldAdmin = IsAdminEntity, @OldActive = IsActive
        FROM agora.Branch WITH (UPDLOCK, HOLDLOCK)
        WHERE BranchId = @BranchId AND DeletedAt IS NULL
          AND (NOT EXISTS (SELECT 1 FROM agora.UserBranch WHERE UserId = @UserId)
               OR EXISTS (SELECT 1 FROM agora.UserBranch WHERE UserId = @UserId AND BranchId = @BranchId));

        IF @Version IS NULL
            THROW 51000, 'AGORA:BRANCH_MISSING:That branch does not exist, or is not one of your sites.', 1;
        IF ISNULL(@ExpectedVersion, '') <> @Version
            THROW 51000, 'AGORA:BRANCH_STALE:This branch changed since you opened it. Reload it and make your change again.', 1;

        IF EXISTS (SELECT 1 FROM agora.Branch WHERE Name = @Name AND BranchId <> @BranchId AND DeletedAt IS NULL)
            THROW 51000, 'AGORA:BRANCH_NAME_TAKEN:Another branch already has that name.', 1;

        IF @RegionId IS NOT NULL AND ISNULL(@OldRegion, -1) <> @RegionId
           AND NOT EXISTS (SELECT 1 FROM agora.Region WHERE Id = @RegionId AND IsActive = 1 AND DeletedAt IS NULL)
            THROW 51000, 'AGORA:BRANCH_REGION:That region does not exist or is no longer in use.', 1;
        IF @BrandId IS NOT NULL AND ISNULL(@OldBrand, -1) <> @BrandId
           AND NOT EXISTS (SELECT 1 FROM agora.Brand WHERE Id = @BrandId AND IsActive = 1 AND DeletedAt IS NULL)
            THROW 51000, 'AGORA:BRANCH_BRAND:That brand does not exist or is no longer in use.', 1;
        IF @FormatId IS NOT NULL AND ISNULL(@OldFormat, -1) <> @FormatId
           AND NOT EXISTS (SELECT 1 FROM agora.[Format] WHERE Id = @FormatId AND IsActive = 1 AND DeletedAt IS NULL)
            THROW 51000, 'AGORA:BRANCH_FORMAT:That format does not exist or is no longer in use.', 1;

        DECLARE @OldCentres NVARCHAR(400) = (
            SELECT STRING_AGG(CONVERT(nvarchar(max), pc.Name), N', ') WITHIN GROUP (ORDER BY pc.Name)
            FROM agora.BranchProfitCentre x JOIN agora.vw_ProfitCentre pc ON pc.ProfitCentreId = x.ProfitCentreId
            WHERE x.BranchId = @BranchId);
        DECLARE @NewCentres NVARCHAR(400) = (
            SELECT STRING_AGG(CONVERT(nvarchar(max), pc.Name), N', ') WITHIN GROUP (ORDER BY pc.Name)
            FROM @Wanted w JOIN agora.vw_ProfitCentre pc ON pc.ProfitCentreId = w.ProfitCentreId);

        INSERT INTO agora.MasterChange (BranchId, Entity, EntityId, Field, PriorValue, NewValue, Reason, Source, CreatedAt, CreatedBy)
        SELECT @BranchId, N'Branch', @BranchId, c.Field, c.PriorValue, c.NewValue, @Reason, N'edit', @Now, @UserId
        FROM (VALUES
            (N'Name',          @OldName, @Name),
            (N'Region',        (SELECT Name FROM agora.Region WHERE Id = @OldRegion), (SELECT Name FROM agora.Region WHERE Id = @RegionId)),
            (N'Brand',         (SELECT Name FROM agora.Brand WHERE Id = @OldBrand), (SELECT Name FROM agora.Brand WHERE Id = @BrandId)),
            (N'Format',        (SELECT Name FROM agora.[Format] WHERE Id = @OldFormat), (SELECT Name FROM agora.[Format] WHERE Id = @FormatId)),
            (N'Status',        @OldStatus, @Status),
            (N'POSType',       @OldPOS, @POSType),
            (N'IsAdminEntity', CASE WHEN @OldAdmin = 1 THEN N'Yes' ELSE N'No' END, CASE WHEN @IsAdminEntity = 1 THEN N'Yes' ELSE N'No' END),
            (N'IsActive',      CASE WHEN @OldActive = 1 THEN N'Yes' ELSE N'No' END, CASE WHEN @IsActive = 1 THEN N'Yes' ELSE N'No' END),
            (N'ProfitCentres', @OldCentres, @NewCentres)
        ) c (Field, PriorValue, NewValue)
        WHERE ISNULL(c.PriorValue, N'') <> ISNULL(c.NewValue, N'')
           -- a grouping renamed to the same text is still a different row
           OR (c.Field = N'Region' AND ISNULL(@OldRegion, -1) <> ISNULL(@RegionId, -1))
           OR (c.Field = N'Brand' AND ISNULL(@OldBrand, -1) <> ISNULL(@BrandId, -1))
           OR (c.Field = N'Format' AND ISNULL(@OldFormat, -1) <> ISNULL(@FormatId, -1));

        DECLARE @Changed INT = @@ROWCOUNT;

        IF @Changed > 0
        BEGIN
            UPDATE agora.Branch
            SET Name = @Name, RegionId = @RegionId, BrandId = @BrandId, FormatId = @FormatId,
                Status = @Status, POSType = @POSType, IsAdminEntity = ISNULL(@IsAdminEntity, 0),
                IsActive = ISNULL(@IsActive, 1),
                IsTrading = CASE WHEN @Status = N'Trading' THEN 1 ELSE 0 END,
                UpdatedAt = @Now, UpdatedBy = @UserId
            WHERE BranchId = @BranchId;

            DELETE x FROM agora.BranchProfitCentre x
            WHERE x.BranchId = @BranchId AND NOT EXISTS (SELECT 1 FROM @Wanted w WHERE w.ProfitCentreId = x.ProfitCentreId);

            INSERT INTO agora.BranchProfitCentre (BranchId, ProfitCentreId, Source, CreatedAt, CreatedBy)
            SELECT @BranchId, w.ProfitCentreId, N'Agora', @Now, @UserId
            FROM @Wanted w
            WHERE NOT EXISTS (SELECT 1 FROM agora.BranchProfitCentre x WHERE x.BranchId = @BranchId AND x.ProfitCentreId = w.ProfitCentreId);
        END

        COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;

    SELECT CONVERT(BIT, 1) AS Ok,
           CASE WHEN @Changed = 0 THEN 'UNCHANGED' ELSE 'SAVED' END AS Code,
           CONVERT(NVARCHAR(400), CASE WHEN @Changed = 0 THEN N'Nothing changed, so nothing was saved.'
                ELSE CONCAT(N'Branch saved: ', @Changed, N' change(s) recorded.') END) AS Message,
           CONVERT(BIGINT, @BranchId) AS Id;
END
