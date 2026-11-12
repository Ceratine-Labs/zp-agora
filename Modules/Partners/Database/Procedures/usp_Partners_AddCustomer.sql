/* Add an account customer to PumpIT's BRN_Customers at one site (T024).
   A NAMED WRITE.

   CLAUDE.md rule 1; Ryan's standing rule of 12 Nov 2026 (PM decision
   01a1cecf-8e08). Customers are per site, so this inserts ONE row.

   THE CODE follows the site's own: the site's three-letter prefix (the one
   most of its codes carry, e.g. TED, WLS) and five digits, the highest at the
   site plus one — which is what PumpIT's own screen issued for the
   customers it added this year (TED00014 … TED00071, 12 Nov 2026). Taken from
   agora.usp_Partners_NextCustomerCode under an exclusive application lock for
   the site and a range lock on its rows, then checked by
   agora.usp_Partners_CheckCode with @Throw = 1.

   THE TILL ACCOUNT is required: DebtorID and DebtorGroupID are NOT NULL and
   both are the till's debtor account number (CNUM), as dbo.sp_InsertDebtorsFromDBF
   and PumpIT's own additions write them. Another customer at the site on the
   same account is refused (ACCOUNT_TAKEN). An account the till does not have
   yet is allowed — PumpIT's own screen allows it (33 of this year's additions
   at Wimpy Ladysmith) — and the result says so.

   WRITES SSBranchId, CustomerCode, CustomerName, DebtorID, DebtorGroupID,
   CustomerContact, Department, JobTitle, TelNoA, TelNoB, CreateDateTime =
   GETDATE() (PumpIT's clock), and MOPTypeId and PosSystemId copied from the
   site's most recently created account customer — the values PumpIT's own
   screen last wrote at that site (MOPTypeId 1, PosSystemId 5 on this year's
   additions). IsMOP = 0. Every other column keeps PumpIT's default; the
   credit limit and the cell are the till's and are not written. One
   agora.PartnersWrite row (Verb 'customer-add').

   NOT REVERSIBLE: taking the row away again would be a DELETE.

   Refuses (AGORA:{Code}):
     WRITES_OFF, DENIED (partners.customers.create), REASON_REQUIRED,
     NAME_REQUIRED, ACCOUNT_REQUIRED, NOT_FOUND (a site outside
     @AllowedBranchIds or not Agora's), NO_PREFIX (the site has no customer
     code to follow), SERIES_FULL, BUSY, and any blocking finding of
     agora.usp_Partners_CheckCode (CODE_TAKEN, ACCOUNT_TAKEN …)

   Returns one row (Ok, Code, Message, WriteId, CustomerCode, OnTill).
   Called by Modules/Partners/Services/PartnerWriteService. Deployed by v1__04pa. */
CREATE OR ALTER PROCEDURE agora.usp_Partners_AddCustomer
    @BranchId INT,
    @CustomerName NVARCHAR(100),
    @AccountNo NVARCHAR(50),
    @ContactPerson NVARCHAR(35) = NULL,
    @Department NVARCHAR(30) = NULL,
    @JobTitle NVARCHAR(50) = NULL,
    @Telephone NVARCHAR(25) = NULL,
    @Telephone2 NVARCHAR(25) = NULL,
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

    SET @CustomerName = NULLIF(LTRIM(RTRIM(@CustomerName)), N'');
    SET @AccountNo = NULLIF(LTRIM(RTRIM(@AccountNo)), N'');
    SET @ContactPerson = NULLIF(LTRIM(RTRIM(@ContactPerson)), N'');
    SET @Department = NULLIF(LTRIM(RTRIM(@Department)), N'');
    SET @JobTitle = NULLIF(LTRIM(RTRIM(@JobTitle)), N'');
    SET @Telephone = NULLIF(LTRIM(RTRIM(@Telephone)), N'');
    SET @Telephone2 = NULLIF(LTRIM(RTRIM(@Telephone2)), N'');
    SET @Reason = NULLIF(LTRIM(RTRIM(@Reason)), N'');

    IF ISNULL(@WriteMode, 'off') <> 'live'
        THROW 51000, 'AGORA:WRITES_OFF:Adding customers is switched off (partners.write_mode). Nothing was written.', 1;
    IF ISNULL(@Authorized, 0) = 0
        THROW 51000, 'AGORA:DENIED:You may not add account customers.', 1;
    IF LEN(ISNULL(@Reason, N'')) < ISNULL(@ReasonMin, 10)
        THROW 51000, 'AGORA:REASON_REQUIRED:Say why the customer is being added — it is kept with the change.', 1;
    IF @CustomerName IS NULL
        THROW 51000, 'AGORA:NAME_REQUIRED:A customer needs a name.', 1;
    IF @AccountNo IS NULL
        THROW 51000, 'AGORA:ACCOUNT_REQUIRED:Give the till account number the customer is charged to.', 1;
    IF NOT EXISTS (SELECT 1 FROM agora.Branch WHERE BranchId = @BranchId)
       OR (NULLIF(LTRIM(RTRIM(@AllowedBranchIds)), '') IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM STRING_SPLIT(@AllowedBranchIds, ',') WHERE TRY_CONVERT(INT, value) = @BranchId))
        THROW 51000, 'AGORA:NOT_FOUND:That is not a site you may see.', 1;

    DECLARE @WriteId UNIQUEIDENTIFIER = NEWID();
    DECLARE @Code NVARCHAR(10), @Prefix NVARCHAR(3), @OnTill BIT;
    DECLARE @Findings TABLE (Code NVARCHAR(40), Severity NVARCHAR(10), Message NVARCHAR(400));
    DECLARE @Lock INT, @Resource NVARCHAR(255) = CONCAT(N'agora.partners.customer-code.', @BranchId);

    BEGIN TRY
    BEGIN TRANSACTION;

    EXEC @Lock = sp_getapplock @Resource = @Resource, @LockMode = 'Exclusive', @LockOwner = 'Transaction', @LockTimeout = 10000;
    IF @Lock < 0
        THROW 51000, 'AGORA:BUSY:Somebody else is adding a customer at this site. Try again in a moment.', 1;

    /* Range-lock the site's customers in PumpIT, then take the number the
       read proposes — under both locks it cannot move. */
    DECLARE @Held INT = (SELECT COUNT(*) FROM [PumpIT].dbo.BRN_Customers WITH (UPDLOCK, HOLDLOCK)
                         WHERE SSBranchId = @BranchId);
    DECLARE @Next TABLE (BranchId INT, Prefix NVARCHAR(3), LastCode NVARCHAR(10), NextCode NVARCHAR(10));
    INSERT INTO @Next EXEC agora.usp_Partners_NextCustomerCode @BranchId = @BranchId;
    SELECT @Prefix = Prefix, @Code = NextCode FROM @Next;
    IF @Prefix IS NULL
        THROW 51000, 'AGORA:NO_PREFIX:This site has no customer code to follow (three letters and five digits). Add its first customer in PumpIT.', 1;
    IF @Code IS NULL
        THROW 51000, 'AGORA:SERIES_FULL:This site has no free customer code left.', 1;

    INSERT INTO @Findings EXEC agora.usp_Partners_CheckCode @Kind = N'Customer', @BranchId = @BranchId, @Code = @Code,
                                                            @Name = @CustomerName, @AccountNo = @AccountNo, @Throw = 1;
    SET @OnTill = CASE WHEN EXISTS (SELECT 1 FROM @Findings WHERE Code = N'ACCOUNT_NOT_ON_TILL') THEN 0 ELSE 1 END;

    DECLARE @MopType INT, @PosSystem INT;
    SELECT TOP (1) @MopType = c.MOPTypeId, @PosSystem = c.PosSystemId
    FROM [PumpIT].dbo.BRN_Customers c
    WHERE c.SSBranchId = @BranchId AND c.IsMOP = 0
    ORDER BY c.CreateDateTime DESC, c.CustomerCode DESC;

    INSERT INTO [PumpIT].dbo.BRN_Customers (SSBranchId, CustomerCode, CustomerName, DebtorID, DebtorGroupID,
                                            CustomerContact, Department, JobTitle, TelNoA, TelNoB,
                                            IsMOP, MOPTypeId, PosSystemId, CreateDateTime)
    VALUES (@BranchId, @Code, @CustomerName, @AccountNo, @AccountNo,
            @ContactPerson, @Department, @JobTitle, @Telephone, @Telephone2,
            0, ISNULL(@MopType, 0), @PosSystem, GETDATE());

    INSERT INTO agora.PartnersWrite (BranchId, WriteId, Verb, PumpItTable, PartnerCode, Field, PriorValue, NewValue,
                                     Reason, WriteMode, State, CreatedAt, CreatedBy)
    VALUES (@BranchId, @WriteId, 'customer-add', 'BRN_Customers', @Code, N'Row', NULL,
            CONCAT(@Code, N' ', @CustomerName, N' · till account ', @AccountNo),
            @Reason, @WriteMode, 'active', SYSUTCDATETIME(), @UserId);

    COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        DECLARE @Err NVARCHAR(2048) = ERROR_MESSAGE();
        THROW 51000, @Err, 1;
    END CATCH

    SELECT CONVERT(BIT, 1) AS Ok, 'ADDED' AS Code,
           CONCAT(@Code, N' ', @CustomerName, N' added to PumpIT',
                  CASE WHEN @OnTill = 0 THEN N'. The till has no debtor account ' + @AccountNo + N' yet.' ELSE N'.' END) AS Message,
           @WriteId AS WriteId, @Code AS CustomerCode, @OnTill AS OnTill;
END
