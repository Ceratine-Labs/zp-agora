/* Change one account customer in PumpIT's BRN_Customers (T024). A NAMED WRITE.

   CLAUDE.md rule 1; Ryan's standing rule of 12 Nov 2026 (PM decision
   01a1cecf-8e08). The cashup's Debtors leg resolves an account sale through
   this row, so an edit in Agora writes it back.

   WRITES exactly [PumpIT].dbo.BRN_Customers.CustomerName, CustomerContact,
   Department, JobTitle, TelNoA and TelNoB on ONE row, by its primary key
   (SSBranchId, CustomerCode), in one transaction — and one
   agora.PartnersWrite row per column it changed, prior exactly as stored and
   new, so agora.usp_Partners_ReverseWrite can put it back.

   NOT WRITTEN, deliberately: the till account (DebtorID), the credit limit and
   the cell number — the limit and cell the screens show are the TILL's
   (DBF_CLDB_CUSTOMER through Debtors' vw_DebtorAccount), and neither the till
   nor the Debtors tables are written from here; the PumpIT copies of them are
   empty on every live row and writing them would change nothing anybody
   sees. The statement address is Debtors' (agora.DebtorContact). BRN_Customers
   has no active flag.

   A blank text value is written as NULL.

   Refuses (AGORA:{Code}):
     WRITES_OFF, DENIED (partners.customers.edit), REASON_REQUIRED,
     NAME_REQUIRED, NOT_FOUND (also a site outside @AllowedBranchIds)
     PAYMENT_CONTROL  an IsMOP row — PumpIT's own bookkeeping, not a customer
     STALE            the row changed since the form was drawn
   Nothing changed is not a refusal: Code NOTHING_CHANGED, nothing written.

   Returns one row (Ok, Code, Message, WriteId).
   Called by Modules/Partners/Services/PartnerWriteService. Deployed by v1__04pa. */
CREATE OR ALTER PROCEDURE agora.usp_Partners_SaveCustomer
    @BranchId INT,
    @CustomerCode NVARCHAR(10),
    @CustomerName NVARCHAR(100),
    @ContactPerson NVARCHAR(35) = NULL,
    @Department NVARCHAR(30) = NULL,
    @JobTitle NVARCHAR(50) = NULL,
    @Telephone NVARCHAR(25) = NULL,
    @Telephone2 NVARCHAR(25) = NULL,
    @ExpectedCustomerName NVARCHAR(100) = NULL,
    @ExpectedContactPerson NVARCHAR(35) = NULL,
    @ExpectedDepartment NVARCHAR(30) = NULL,
    @ExpectedJobTitle NVARCHAR(50) = NULL,
    @ExpectedTelephone NVARCHAR(25) = NULL,
    @ExpectedTelephone2 NVARCHAR(25) = NULL,
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

    SET @CustomerCode = LTRIM(RTRIM(@CustomerCode));
    SET @Reason = NULLIF(LTRIM(RTRIM(@Reason)), N'');

    DECLARE @New TABLE (Field NVARCHAR(40) PRIMARY KEY, NewValue NVARCHAR(200), Expected NVARCHAR(200));
    INSERT INTO @New VALUES
        (N'CustomerName',    NULLIF(LTRIM(RTRIM(@CustomerName)), N''),  NULLIF(LTRIM(RTRIM(@ExpectedCustomerName)), N'')),
        (N'CustomerContact', NULLIF(LTRIM(RTRIM(@ContactPerson)), N''), NULLIF(LTRIM(RTRIM(@ExpectedContactPerson)), N'')),
        (N'Department',      NULLIF(LTRIM(RTRIM(@Department)), N''),    NULLIF(LTRIM(RTRIM(@ExpectedDepartment)), N'')),
        (N'JobTitle',        NULLIF(LTRIM(RTRIM(@JobTitle)), N''),      NULLIF(LTRIM(RTRIM(@ExpectedJobTitle)), N'')),
        (N'TelNoA',          NULLIF(LTRIM(RTRIM(@Telephone)), N''),     NULLIF(LTRIM(RTRIM(@ExpectedTelephone)), N'')),
        (N'TelNoB',          NULLIF(LTRIM(RTRIM(@Telephone2)), N''),    NULLIF(LTRIM(RTRIM(@ExpectedTelephone2)), N''));

    IF ISNULL(@WriteMode, 'off') <> 'live'
        THROW 51000, 'AGORA:WRITES_OFF:Changing customers is switched off (partners.write_mode). Nothing was written.', 1;
    IF ISNULL(@Authorized, 0) = 0
        THROW 51000, 'AGORA:DENIED:You may not change account customers.', 1;
    IF LEN(ISNULL(@Reason, N'')) < ISNULL(@ReasonMin, 10)
        THROW 51000, 'AGORA:REASON_REQUIRED:Say why the customer is being changed — it is kept with the change.', 1;
    IF (SELECT NewValue FROM @New WHERE Field = N'CustomerName') IS NULL
        THROW 51000, 'AGORA:NAME_REQUIRED:A customer needs a name.', 1;
    IF NULLIF(LTRIM(RTRIM(@AllowedBranchIds)), '') IS NOT NULL
       AND NOT EXISTS (SELECT 1 FROM STRING_SPLIT(@AllowedBranchIds, ',') WHERE TRY_CONVERT(INT, value) = @BranchId)
        THROW 51000, 'AGORA:NOT_FOUND:There is no such customer at a site you may see.', 1;

    DECLARE @WriteId UNIQUEIDENTIFIER = NEWID();
    DECLARE @Changed INT = 0;

    BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @Cur TABLE (Field NVARCHAR(40) PRIMARY KEY, Prior NVARCHAR(200));
    DECLARE @Found BIT = 0, @IsMop BIT;
    SELECT @Found = 1, @IsMop = c.IsMOP
    FROM [PumpIT].dbo.BRN_Customers c WITH (UPDLOCK, HOLDLOCK)
    WHERE c.SSBranchId = @BranchId AND c.CustomerCode = @CustomerCode;

    IF @Found = 0 OR NOT EXISTS (SELECT 1 FROM agora.Branch WHERE BranchId = @BranchId)
        THROW 51000, 'AGORA:NOT_FOUND:There is no such customer at a site you may see.', 1;
    IF @IsMop = 1
        THROW 51000, 'AGORA:PAYMENT_CONTROL:This is one of PumpIT''s payment-control accounts, not a customer. It is not changed from Agora.', 1;

    INSERT INTO @Cur
    SELECT v.Field, v.Prior
    FROM [PumpIT].dbo.BRN_Customers c
    CROSS APPLY (VALUES (N'CustomerName', CONVERT(NVARCHAR(200), c.CustomerName)),
                        (N'CustomerContact', CONVERT(NVARCHAR(200), c.CustomerContact)),
                        (N'Department', CONVERT(NVARCHAR(200), c.Department)),
                        (N'JobTitle', CONVERT(NVARCHAR(200), c.JobTitle)),
                        (N'TelNoA', CONVERT(NVARCHAR(200), c.TelNoA)),
                        (N'TelNoB', CONVERT(NVARCHAR(200), c.TelNoB))) v (Field, Prior)
    WHERE c.SSBranchId = @BranchId AND c.CustomerCode = @CustomerCode;

    IF EXISTS (SELECT 1 FROM @Cur c JOIN @New n ON n.Field = c.Field
               WHERE ISNULL(NULLIF(LTRIM(RTRIM(c.Prior)), N''), N'') <> ISNULL(n.Expected, N''))
        THROW 51000, 'AGORA:STALE:This customer was changed in PumpIT or by someone else since you opened the form. Open it again and redo your change.', 1;

    INSERT INTO agora.PartnersWrite (BranchId, WriteId, Verb, PumpItTable, PartnerCode, Field, PriorValue, NewValue,
                                     Reason, WriteMode, State, CreatedAt, CreatedBy)
    SELECT @BranchId, @WriteId, 'customer', 'BRN_Customers', @CustomerCode, n.Field, c.Prior, n.NewValue,
           @Reason, @WriteMode, 'active', SYSUTCDATETIME(), @UserId
    FROM @New n JOIN @Cur c ON c.Field = n.Field
    WHERE ISNULL(NULLIF(LTRIM(RTRIM(c.Prior)), N''), N'') <> ISNULL(n.NewValue, N'');
    SET @Changed = @@ROWCOUNT;

    IF @Changed > 0
        UPDATE c
           SET CustomerName    = CASE WHEN w.HasName = 1 THEN (SELECT NewValue FROM @New WHERE Field = N'CustomerName') ELSE c.CustomerName END,
               CustomerContact = CASE WHEN w.HasContact = 1 THEN (SELECT NewValue FROM @New WHERE Field = N'CustomerContact') ELSE c.CustomerContact END,
               Department      = CASE WHEN w.HasDept = 1 THEN (SELECT NewValue FROM @New WHERE Field = N'Department') ELSE c.Department END,
               JobTitle        = CASE WHEN w.HasJob = 1 THEN (SELECT NewValue FROM @New WHERE Field = N'JobTitle') ELSE c.JobTitle END,
               TelNoA          = CASE WHEN w.HasTelA = 1 THEN (SELECT NewValue FROM @New WHERE Field = N'TelNoA') ELSE c.TelNoA END,
               TelNoB          = CASE WHEN w.HasTelB = 1 THEN (SELECT NewValue FROM @New WHERE Field = N'TelNoB') ELSE c.TelNoB END
          FROM [PumpIT].dbo.BRN_Customers c
          CROSS JOIN (SELECT MAX(CASE WHEN Field = N'CustomerName' THEN 1 ELSE 0 END) AS HasName,
                             MAX(CASE WHEN Field = N'CustomerContact' THEN 1 ELSE 0 END) AS HasContact,
                             MAX(CASE WHEN Field = N'Department' THEN 1 ELSE 0 END) AS HasDept,
                             MAX(CASE WHEN Field = N'JobTitle' THEN 1 ELSE 0 END) AS HasJob,
                             MAX(CASE WHEN Field = N'TelNoA' THEN 1 ELSE 0 END) AS HasTelA,
                             MAX(CASE WHEN Field = N'TelNoB' THEN 1 ELSE 0 END) AS HasTelB
                      FROM agora.PartnersWrite WHERE WriteId = @WriteId) w
         WHERE c.SSBranchId = @BranchId AND c.CustomerCode = @CustomerCode;

    COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        DECLARE @Err NVARCHAR(2048) = ERROR_MESSAGE();
        THROW 51000, @Err, 1;
    END CATCH

    SELECT CONVERT(BIT, 1) AS Ok,
           CASE WHEN @Changed = 0 THEN 'NOTHING_CHANGED' ELSE 'SAVED' END AS Code,
           CASE WHEN @Changed = 0 THEN N'Nothing changed — the form matched PumpIT.'
                ELSE CONCAT(@Changed, N' change(s) written to PumpIT, each kept with its prior value.') END AS Message,
           CASE WHEN @Changed = 0 THEN NULL ELSE @WriteId END AS WriteId;
END
