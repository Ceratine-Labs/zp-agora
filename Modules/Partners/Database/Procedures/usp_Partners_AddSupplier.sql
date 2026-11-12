/* Add a new supplier to PumpIT's BRN_Suppliers, at every site (T024).
   A NAMED WRITE.

   CLAUDE.md rule 1; Ryan's standing rule of 12 Nov 2026 (PM decision
   01a1cecf-8e08). The supplier list is ONE list repeated at every site — the
   same code is the same supplier everywhere (2,994 codes at 31 sites,
   12 Nov 2026) — so a new supplier is a new code inserted at every site that
   carries the list, as one transaction, or not at all.

   THE CODE. Taken from agora.usp_Partners_NextSupplierCode inside this
   transaction, after an exclusive application lock on the series and a
   range lock on its rows in BRN_Suppliers, so two presses cannot take the
   same number; then agora.usp_Partners_CheckCode with @Throw = 1 refuses
   anything the duplicate guard would block.

   WRITES one row per site: SSBranchId, SupplierCode, SupplierName,
   ContactPerson, Department, IsStock, IsCash, isActive = 1 and
   CreateDateTime = GETDATE() (PumpIT's own clock — its rows are local time).
   SupplierID, the site's POS account number, is written as '' at every site:
   PumpIT's rule (dbo.sp_InsertSuppliersFromDBF) takes it from the site's POS
   supplier master, and a supplier created here is on no till yet — which is
   how PumpIT's own later additions and 89,880 of the 91,850 live rows carry it
   (12 Nov 2026). Every other column keeps PumpIT's default. One
   agora.PartnersWrite row per site inserted (Verb 'supplier-add').

   NOT REVERSIBLE: taking the rows away again would be a DELETE, which needs
   its own go-ahead. A supplier added by mistake is switched off.

   Refuses (AGORA:{Code}):
     WRITES_OFF, DENIED (partners.suppliers.create), REASON_REQUIRED,
     NAME_REQUIRED
     ESTATE_ONLY     the caller is granted only some sites; a new supplier is
                     added at every site, so it is head office's
     BAD_SERIES      not APS or APE; SERIES_FULL  at 9999
     NO_SITES        no site Agora knows carries the list
     and any blocking finding of agora.usp_Partners_CheckCode (CODE_TAKEN …)

   Returns one row (Ok, Code, Message, WriteId, SupplierCode, Sites).
   Called by Modules/Partners/Services/PartnerWriteService. Deployed by v1__04pa. */
CREATE OR ALTER PROCEDURE agora.usp_Partners_AddSupplier
    @Series NVARCHAR(3),
    @SupplierName NVARCHAR(55),
    @ContactPerson NVARCHAR(50) = NULL,
    @Department NVARCHAR(30) = NULL,
    @IsStock BIT,
    @IsCash BIT,
    @Reason NVARCHAR(400),
    @UserId INT,
    @Authorized BIT = 0,
    @WriteMode VARCHAR(10) = 'off',
    @AllowedBranchIds NVARCHAR(MAX) = NULL,
    @ReasonMin INT = 10
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;

    SET @Series = UPPER(LTRIM(RTRIM(@Series)));
    SET @SupplierName = NULLIF(LTRIM(RTRIM(@SupplierName)), N'');
    SET @ContactPerson = NULLIF(LTRIM(RTRIM(@ContactPerson)), N'');
    SET @Department = NULLIF(LTRIM(RTRIM(@Department)), N'');
    SET @Reason = NULLIF(LTRIM(RTRIM(@Reason)), N'');

    IF ISNULL(@WriteMode, 'off') <> 'live'
        THROW 51000, 'AGORA:WRITES_OFF:Adding suppliers is switched off (partners.write_mode). Nothing was written.', 1;
    IF ISNULL(@Authorized, 0) = 0
        THROW 51000, 'AGORA:DENIED:You may not add suppliers.', 1;
    IF LEN(ISNULL(@Reason, N'')) < ISNULL(@ReasonMin, 10)
        THROW 51000, 'AGORA:REASON_REQUIRED:Say why the supplier is being added — it is kept with the change.', 1;
    IF @SupplierName IS NULL
        THROW 51000, 'AGORA:NAME_REQUIRED:A supplier needs a name.', 1;
    IF NULLIF(LTRIM(RTRIM(@AllowedBranchIds)), '') IS NOT NULL
        THROW 51000, 'AGORA:ESTATE_ONLY:A new supplier is added at every site, so it needs somebody who sees every site.', 1;
    IF ISNULL(@Series, N'') NOT IN (N'APS', N'APE')
        THROW 51000, 'AGORA:BAD_SERIES:A supplier code is APS (stock) or APE (expense).', 1;

    DECLARE @WriteId UNIQUEIDENTIFIER = NEWID();
    DECLARE @Code NVARCHAR(8), @Sites INT;
    DECLARE @Next TABLE (Series NVARCHAR(3), LastCode NVARCHAR(8), NextCode NVARCHAR(8), CodesInSeries INT, Sites INT);
    DECLARE @Findings TABLE (Code NVARCHAR(40), Severity NVARCHAR(10), Message NVARCHAR(400));
    DECLARE @Lock INT, @Resource NVARCHAR(255) = CONCAT(N'agora.partners.supplier-code.', @Series);

    BEGIN TRY
    BEGIN TRANSACTION;

    EXEC @Lock = sp_getapplock @Resource = @Resource, @LockMode = 'Exclusive', @LockOwner = 'Transaction', @LockTimeout = 10000;
    IF @Lock < 0
        THROW 51000, 'AGORA:BUSY:Somebody else is adding a supplier in this series. Try again in a moment.', 1;

    /* Range-lock the series in PumpIT, so PumpIT's own screen cannot slip a
       code in between the number being taken and the rows being written. */
    DECLARE @Held INT = (SELECT COUNT(*) FROM [PumpIT].dbo.BRN_Suppliers WITH (UPDLOCK, HOLDLOCK)
                         WHERE SupplierCode LIKE @Series + N'-%');

    INSERT INTO @Next EXEC agora.usp_Partners_NextSupplierCode @Series = @Series;
    SELECT @Code = NextCode FROM @Next;
    IF @Code IS NULL
        THROW 51000, 'AGORA:SERIES_FULL:This series has no free code left.', 1;

    DECLARE @Site INT = (SELECT MIN(s.BranchId) FROM agora.vw_PartnerSupplier s JOIN agora.Branch b ON b.BranchId = s.BranchId);
    IF @Site IS NULL
        THROW 51000, 'AGORA:NO_SITES:No site Agora knows carries the supplier list.', 1;

    INSERT INTO @Findings EXEC agora.usp_Partners_CheckCode @Kind = N'Supplier', @BranchId = @Site, @Code = @Code,
                                                            @Name = @SupplierName, @Throw = 1;

    DECLARE @SiteList TABLE (BranchId INT PRIMARY KEY);
    INSERT INTO @SiteList
    SELECT DISTINCT s.SSBranchId
    FROM [PumpIT].dbo.BRN_Suppliers s WITH (UPDLOCK, HOLDLOCK)
    JOIN agora.Branch b ON b.BranchId = s.SSBranchId;
    SET @Sites = @@ROWCOUNT;

    IF EXISTS (SELECT 1 FROM [PumpIT].dbo.BRN_Suppliers WITH (UPDLOCK, HOLDLOCK) WHERE SupplierCode = @Code)
        THROW 51000, 'AGORA:CODE_TAKEN:That code was taken while the form was open. Nothing was written; press again for the next one.', 1;

    INSERT INTO [PumpIT].dbo.BRN_Suppliers (SSBranchId, SupplierCode, SupplierName, ContactPerson, Department, SupplierID,
                                            IsStock, IsCash, isActive, CreateDateTime)
    SELECT l.BranchId, @Code, @SupplierName, @ContactPerson, @Department, N'', @IsStock, @IsCash, 1, GETDATE()
    FROM @SiteList l;

    INSERT INTO agora.PartnersWrite (BranchId, WriteId, Verb, PumpItTable, PartnerCode, Field, PriorValue, NewValue,
                                     Reason, WriteMode, State, CreatedAt, CreatedBy)
    SELECT l.BranchId, @WriteId, 'supplier-add', 'BRN_Suppliers', @Code, N'Row', NULL,
           CONCAT(@Code, N' ', @SupplierName, N' · stock ', CONVERT(INT, @IsStock), N' · cash ', CONVERT(INT, @IsCash)),
           @Reason, @WriteMode, 'active', SYSUTCDATETIME(), @UserId
    FROM @SiteList l;

    COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        DECLARE @Err NVARCHAR(2048) = ERROR_MESSAGE();
        THROW 51000, @Err, 1;
    END CATCH

    SELECT CONVERT(BIT, 1) AS Ok, 'ADDED' AS Code,
           CONCAT(@Code, N' ', @SupplierName, N' added to PumpIT at ', @Sites, N' site(s).') AS Message,
           @WriteId AS WriteId, @Code AS SupplierCode, @Sites AS Sites;
END
