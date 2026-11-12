/* The duplicate-code guard for a new supplier or account customer (T024).
   Read-only — it writes nothing, here or in PumpIT.

   Written BEFORE any save path exists, so that whichever one Ryan chooses (an
   Agora-owned row, or a named write back into BRN_Suppliers / BRN_Customers)
   calls this inside its own transaction with @Throw = 1 and refuses exactly
   what the screen warned about. The rules live here and only here; a
   FormRequest checks the shape, never these.

   @Kind      'Supplier' or 'Customer'
   @BranchId  the site the new row would be at
   @Code      the proposed code; compared trimmed and, under the estate's
              Latin1_General_CI_AS collation, case-blind — 'aps-0001' IS
              'APS-0001'
   @Name      optional; turns "code used elsewhere" into a refusal when the
              code means a different supplier at another site
   @AccountNo optional; the POS account number (SupplierID / DebtorID)
   @Throw     1 = THROW 51000 'AGORA:{Code}:{message}' on the first blocking
              finding; 0 = return the findings and let the screen show them

   Result: one row per finding (Code, Severity block|warn|info, Message), in
   the order a person should read them. No rows means the code is free and
   nothing about it looks wrong.

   Codes, suppliers:
     CODE_REQUIRED      block  no code given
     CODE_TOO_LONG      block  longer than BRN_Suppliers.SupplierCode (8)
     UNKNOWN_BRANCH     block  not a branch Agora knows
     CODE_TAKEN         block  the site already has that code
     CODE_MEANS_OTHER   block  another site has the code under a different name
     CODE_ELSEWHERE     info   other sites have the code — under the same name,
                               or no name was given to compare
     CODE_FORMAT        warn   not APS-#### / APE-####, the only shape the
                               live list issues (12 Nov 2026)
     ACCOUNT_TAKEN      block  another code at the site has that POS account
     NAME_TAKEN         warn   another code at the site has that name
   Customers: CODE_REQUIRED, CODE_TOO_LONG (10), UNKNOWN_BRANCH, CODE_TAKEN,
     ACCOUNT_TAKEN, NAME_TAKEN as above, and
     ACCOUNT_NOT_ON_TILL warn  the till has no such debtor account
   Any other @Kind: BAD_KIND, block.

   Read by Modules/Partners/Services/PartnerService. Deployed by v1__04p. */
CREATE OR ALTER PROCEDURE agora.usp_Partners_CheckCode
    @Kind NVARCHAR(10),
    @BranchId INT,
    @Code NVARCHAR(20),
    @Name NVARCHAR(100) = NULL,
    @AccountNo NVARCHAR(50) = NULL,
    @Throw BIT = 0
AS
BEGIN
    SET NOCOUNT ON;
    SET @Code = NULLIF(LTRIM(RTRIM(@Code)), N'');
    SET @Name = NULLIF(LTRIM(RTRIM(@Name)), N'');
    SET @AccountNo = NULLIF(LTRIM(RTRIM(@AccountNo)), N'');

    DECLARE @Findings TABLE (Seq INT IDENTITY(1, 1), Code NVARCHAR(40), Severity NVARCHAR(10), Message NVARCHAR(400));

    IF @Kind NOT IN (N'Supplier', N'Customer')
        INSERT INTO @Findings (Code, Severity, Message)
        VALUES (N'BAD_KIND', N'block', N'Say whether this is a supplier or a customer.');

    IF @Code IS NULL
        INSERT INTO @Findings (Code, Severity, Message) VALUES (N'CODE_REQUIRED', N'block', N'Give the new code.');

    IF @Code IS NOT NULL AND LEN(@Code) > CASE WHEN @Kind = N'Customer' THEN 10 ELSE 8 END
        INSERT INTO @Findings (Code, Severity, Message)
        VALUES (N'CODE_TOO_LONG', N'block', CONCAT(N'A ', LOWER(@Kind), N' code is at most ',
                CASE WHEN @Kind = N'Customer' THEN 10 ELSE 8 END, N' characters; PumpIT has nowhere to put more.'));

    IF NOT EXISTS (SELECT 1 FROM agora.Branch WHERE BranchId = @BranchId)
        INSERT INTO @Findings (Code, Severity, Message)
        VALUES (N'UNKNOWN_BRANCH', N'block', N'That is not a branch Agora knows.');

    IF @Kind = N'Supplier' AND @Code IS NOT NULL
    BEGIN
        DECLARE @Here NVARCHAR(70) = (SELECT TOP (1) CONCAT(SupplierCode, N': ', ISNULL(SupplierName, N'(no name)'))
                                      FROM agora.vw_PartnerSupplier
                                      WHERE BranchId = @BranchId AND SupplierCode = @Code);
        IF @Here IS NOT NULL
            INSERT INTO @Findings (Code, Severity, Message)
            VALUES (N'CODE_TAKEN', N'block', CONCAT(N'This site already has ', @Here, N'.'));

        DECLARE @Sites INT, @OtherName NVARCHAR(55), @OtherSites INT;
        SELECT @Sites = COUNT(DISTINCT s.BranchId)
        FROM agora.vw_PartnerSupplier s JOIN agora.Branch b ON b.BranchId = s.BranchId
        WHERE s.SupplierCode = @Code AND s.BranchId <> @BranchId;

        SELECT TOP (1) @OtherName = s.SupplierName, @OtherSites = COUNT(*) OVER ()
        FROM agora.vw_PartnerSupplier s JOIN agora.Branch b ON b.BranchId = s.BranchId
        WHERE s.SupplierCode = @Code AND s.BranchId <> @BranchId
          AND @Name IS NOT NULL AND ISNULL(s.SupplierName, N'') <> @Name
        ORDER BY s.BranchId;

        IF @OtherName IS NOT NULL
            INSERT INTO @Findings (Code, Severity, Message)
            VALUES (N'CODE_MEANS_OTHER', N'block', CONCAT(@Code, N' is ', @OtherName, N' at ', @OtherSites,
                    CASE WHEN @OtherSites = 1 THEN N' other site' ELSE N' other sites' END,
                    N'. A code is the same supplier everywhere; take the next free code instead.'));
        ELSE IF ISNULL(@Sites, 0) > 0 AND @Here IS NULL
            INSERT INTO @Findings (Code, Severity, Message)
            VALUES (N'CODE_ELSEWHERE', N'info', CONCAT(@Code, N' is already on the list at ', @Sites,
                    CASE WHEN @Sites = 1 THEN N' other site' ELSE N' other sites' END,
                    N'; this adds the same supplier here.'));

        IF @Code NOT LIKE N'AP[SE]-[0-9][0-9][0-9][0-9]'
            INSERT INTO @Findings (Code, Severity, Message)
            VALUES (N'CODE_FORMAT', N'warn', N'Supplier codes are APS-#### or APE-####; this one is not.');

        IF @AccountNo IS NOT NULL
        BEGIN
            DECLARE @AccountCode NVARCHAR(8) = (SELECT TOP (1) SupplierCode FROM agora.vw_PartnerSupplier
                                               WHERE BranchId = @BranchId AND AccountNo = @AccountNo
                                                 AND SupplierCode <> @Code ORDER BY SupplierCode);
            IF @AccountCode IS NOT NULL
                INSERT INTO @Findings (Code, Severity, Message)
                VALUES (N'ACCOUNT_TAKEN', N'block', CONCAT(N'POS account ', @AccountNo, N' is already ', @AccountCode,
                        N' at this site; a till account answers to one supplier.'));
        END

        IF @Name IS NOT NULL
        BEGIN
            DECLARE @NameCode NVARCHAR(8) = (SELECT TOP (1) SupplierCode FROM agora.vw_PartnerSupplier
                                            WHERE BranchId = @BranchId AND SupplierName = @Name
                                              AND SupplierCode <> @Code ORDER BY SupplierCode);
            IF @NameCode IS NOT NULL
                INSERT INTO @Findings (Code, Severity, Message)
                VALUES (N'NAME_TAKEN', N'warn', CONCAT(@Name, N' is already ', @NameCode, N' at this site.'));
        END
    END

    IF @Kind = N'Customer' AND @Code IS NOT NULL
    BEGIN
        DECLARE @HereCustomer NVARCHAR(120) = (SELECT TOP (1) CONCAT(CustomerCode, N': ', ISNULL(CustomerName, N'(no name)'))
                                               FROM agora.vw_PartnerCustomer
                                               WHERE BranchId = @BranchId AND CustomerCode = @Code);
        IF @HereCustomer IS NOT NULL
            INSERT INTO @Findings (Code, Severity, Message)
            VALUES (N'CODE_TAKEN', N'block', CONCAT(N'This site already has ', @HereCustomer, N'.'));

        IF @AccountNo IS NOT NULL
        BEGIN
            DECLARE @AccountCustomer NVARCHAR(10) = (SELECT TOP (1) CustomerCode FROM agora.vw_PartnerCustomer
                                                    WHERE BranchId = @BranchId AND AccountNo = @AccountNo
                                                      AND CustomerCode <> @Code ORDER BY CustomerCode);
            IF @AccountCustomer IS NOT NULL
                INSERT INTO @Findings (Code, Severity, Message)
                VALUES (N'ACCOUNT_TAKEN', N'block', CONCAT(N'Till account ', @AccountNo, N' is already ', @AccountCustomer,
                        N' at this site; a till account answers to one customer.'));

            IF NOT EXISTS (SELECT 1 FROM agora.vw_DebtorAccount WHERE BranchId = @BranchId AND AccountNo = @AccountNo)
                INSERT INTO @Findings (Code, Severity, Message)
                VALUES (N'ACCOUNT_NOT_ON_TILL', N'warn', CONCAT(N'The till at this site has no debtor account ', @AccountNo, N'.'));
        END

        IF @Name IS NOT NULL
        BEGIN
            DECLARE @NameCustomer NVARCHAR(10) = (SELECT TOP (1) CustomerCode FROM agora.vw_PartnerCustomer
                                                 WHERE BranchId = @BranchId AND CustomerName = @Name
                                                   AND CustomerCode <> @Code ORDER BY CustomerCode);
            IF @NameCustomer IS NOT NULL
                INSERT INTO @Findings (Code, Severity, Message)
                VALUES (N'NAME_TAKEN', N'warn', CONCAT(@Name, N' is already ', @NameCustomer, N' at this site.'));
        END
    END

    IF @Throw = 1
    BEGIN
        DECLARE @Refusal NVARCHAR(2048) = (SELECT TOP (1) CONCAT(N'AGORA:', Code, N':', Message) FROM @Findings
                                           WHERE Severity = N'block' ORDER BY Seq);
        IF @Refusal IS NOT NULL
            THROW 51000, @Refusal, 1;
    END

    SELECT Code, Severity, Message FROM @Findings ORDER BY Seq;
END
