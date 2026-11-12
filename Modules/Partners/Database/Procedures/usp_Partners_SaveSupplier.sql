/* Change one supplier in PumpIT's BRN_Suppliers (T024). A NAMED WRITE.

   CLAUDE.md rule 1; Ryan's standing rule of 12 Nov 2026 ("agora is replacing
   pumpit, always write back to pumpit with confirmation", PM decision
   01a1cecf-8e08). PumpIT keeps trading on these rows — the cashup's Suppliers
   and Expenses legs and the till's paid-outs resolve a supplier through
   them — so an edit in Agora writes them back rather than keeping a copy.

   WRITES, in one transaction, and nothing else:
     SupplierName   on EVERY row carrying the code at the caller's sites,
                    because a code is the same supplier at every site; a row
                    whose name is already the new one is left alone
     ContactPerson, Department, isActive, IsCash, IsStock
                    on THIS site's row only — each site's flags are its own
   by primary key (SSBranchId, SupplierCode), and one agora.PartnersWrite row
   per (site, column) it changed, with the prior value exactly as stored and
   the new one, so agora.usp_Partners_ReverseWrite can put it back. Not the
   code, not the POS account number (SupplierID), not the addresses.

   A blank text value is written as NULL, as PumpIT's own rows carry it.

   Refuses (AGORA:{Code}):
     WRITES_OFF      @WriteMode is not 'live' (config partners.write_mode)
     DENIED          the caller lacks partners.suppliers.edit (@Authorized)
     REASON_REQUIRED fewer than @ReasonMin characters of reason
     NAME_REQUIRED   no supplier name
     NOT_FOUND       no such supplier, or a site outside @AllowedBranchIds
     STALE           this site's row changed since the form was drawn: the
                     @Expected* values are what the form showed
   Nothing changed is not a refusal: Code NOTHING_CHANGED, nothing written.

   Returns one row (Ok, Code, Message, WriteId, Sites).
   Called by Modules/Partners/Services/PartnerWriteService. Deployed by v1__04pa. */
CREATE OR ALTER PROCEDURE agora.usp_Partners_SaveSupplier
    @BranchId INT,
    @SupplierCode NVARCHAR(8),
    @SupplierName NVARCHAR(55),
    @ContactPerson NVARCHAR(50) = NULL,
    @Department NVARCHAR(30) = NULL,
    @IsActive BIT,
    @IsCash BIT,
    @IsStock BIT,
    @ExpectedSupplierName NVARCHAR(55) = NULL,
    @ExpectedContactPerson NVARCHAR(50) = NULL,
    @ExpectedDepartment NVARCHAR(30) = NULL,
    @ExpectedIsActive BIT,
    @ExpectedIsCash BIT,
    @ExpectedIsStock BIT,
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

    SET @SupplierCode = LTRIM(RTRIM(@SupplierCode));
    SET @SupplierName = NULLIF(LTRIM(RTRIM(@SupplierName)), N'');
    SET @ContactPerson = NULLIF(LTRIM(RTRIM(@ContactPerson)), N'');
    SET @Department = NULLIF(LTRIM(RTRIM(@Department)), N'');
    SET @ExpectedSupplierName = NULLIF(LTRIM(RTRIM(@ExpectedSupplierName)), N'');
    SET @ExpectedContactPerson = NULLIF(LTRIM(RTRIM(@ExpectedContactPerson)), N'');
    SET @ExpectedDepartment = NULLIF(LTRIM(RTRIM(@ExpectedDepartment)), N'');
    SET @Reason = NULLIF(LTRIM(RTRIM(@Reason)), N'');

    IF ISNULL(@WriteMode, 'off') <> 'live'
        THROW 51000, 'AGORA:WRITES_OFF:Changing suppliers is switched off (partners.write_mode). Nothing was written.', 1;
    IF ISNULL(@Authorized, 0) = 0
        THROW 51000, 'AGORA:DENIED:You may not change suppliers.', 1;
    IF LEN(ISNULL(@Reason, N'')) < ISNULL(@ReasonMin, 10)
        THROW 51000, 'AGORA:REASON_REQUIRED:Say why the supplier is being changed — it is kept with the change.', 1;
    IF @SupplierName IS NULL
        THROW 51000, 'AGORA:NAME_REQUIRED:A supplier needs a name.', 1;

    DECLARE @Allowed TABLE (BranchId INT PRIMARY KEY);
    INSERT INTO @Allowed
    SELECT DISTINCT TRY_CONVERT(INT, value) FROM STRING_SPLIT(@AllowedBranchIds, ',')
    WHERE TRY_CONVERT(INT, value) IS NOT NULL;
    DECLARE @AllBranches BIT = CASE WHEN NULLIF(LTRIM(RTRIM(@AllowedBranchIds)), '') IS NULL THEN 1 ELSE 0 END;

    IF @AllBranches = 0 AND NOT EXISTS (SELECT 1 FROM @Allowed WHERE BranchId = @BranchId)
        THROW 51000, 'AGORA:NOT_FOUND:There is no such supplier at a site you may see.', 1;

    DECLARE @WriteId UNIQUEIDENTIFIER = NEWID();
    DECLARE @Changed INT = 0, @Sites INT = 0;

    BEGIN TRY
    BEGIN TRANSACTION;

    /* Every row carrying the code that this press may touch, locked. */
    DECLARE @Rows TABLE (BranchId INT PRIMARY KEY, SupplierName NVARCHAR(55), ContactPerson NVARCHAR(50),
                         Department NVARCHAR(30), IsActive BIT, IsCash BIT, IsStock BIT);
    INSERT INTO @Rows
    SELECT s.SSBranchId, s.SupplierName, s.ContactPerson, s.Department, s.isActive, s.IsCash, s.IsStock
    FROM [PumpIT].dbo.BRN_Suppliers s WITH (UPDLOCK, HOLDLOCK)
    JOIN agora.Branch b ON b.BranchId = s.SSBranchId
    WHERE s.SupplierCode = @SupplierCode
      AND (@AllBranches = 1 OR s.SSBranchId IN (SELECT BranchId FROM @Allowed));

    DECLARE @CurName NVARCHAR(55), @CurContact NVARCHAR(50), @CurDept NVARCHAR(30),
            @CurActive BIT, @CurCash BIT, @CurStock BIT, @Found BIT = 0;
    SELECT @Found = 1, @CurName = SupplierName, @CurContact = ContactPerson, @CurDept = Department,
           @CurActive = IsActive, @CurCash = IsCash, @CurStock = IsStock
    FROM @Rows WHERE BranchId = @BranchId;

    IF @Found = 0
        THROW 51000, 'AGORA:NOT_FOUND:There is no such supplier at a site you may see.', 1;
    IF ISNULL(NULLIF(LTRIM(RTRIM(@CurName)), N''), N'') <> ISNULL(@ExpectedSupplierName, N'')
       OR ISNULL(NULLIF(LTRIM(RTRIM(@CurContact)), N''), N'') <> ISNULL(@ExpectedContactPerson, N'')
       OR ISNULL(NULLIF(LTRIM(RTRIM(@CurDept)), N''), N'') <> ISNULL(@ExpectedDepartment, N'')
       OR ISNULL(@CurActive, 1) <> @ExpectedIsActive OR @CurCash <> @ExpectedIsCash OR @CurStock <> @ExpectedIsStock
        THROW 51000, 'AGORA:STALE:This supplier was changed in PumpIT or by someone else since you opened the form. Open it again and redo your change.', 1;

    /* The name, at every row whose name is not already the new one. */
    INSERT INTO agora.PartnersWrite (BranchId, WriteId, Verb, PumpItTable, PartnerCode, Field, PriorValue, NewValue,
                                     Reason, WriteMode, State, CreatedAt, CreatedBy)
    SELECT r.BranchId, @WriteId, 'supplier', 'BRN_Suppliers', @SupplierCode, N'SupplierName', r.SupplierName, @SupplierName,
           @Reason, @WriteMode, 'active', SYSUTCDATETIME(), @UserId
    FROM @Rows r
    WHERE ISNULL(NULLIF(LTRIM(RTRIM(@CurName)), N''), N'') <> @SupplierName
      AND ISNULL(r.SupplierName, N'') <> @SupplierName;
    SET @Changed = @@ROWCOUNT;
    SET @Sites = @Changed;

    /* This site's own columns. */
    INSERT INTO agora.PartnersWrite (BranchId, WriteId, Verb, PumpItTable, PartnerCode, Field, PriorValue, NewValue,
                                     Reason, WriteMode, State, CreatedAt, CreatedBy)
    SELECT @BranchId, @WriteId, 'supplier', 'BRN_Suppliers', @SupplierCode, c.Field, c.PriorValue, c.NewValue,
           @Reason, @WriteMode, 'active', SYSUTCDATETIME(), @UserId
    FROM (VALUES
            (N'ContactPerson', CONVERT(NVARCHAR(200), @CurContact), CONVERT(NVARCHAR(200), @ContactPerson),
             CASE WHEN ISNULL(NULLIF(LTRIM(RTRIM(@CurContact)), N''), N'') <> ISNULL(@ContactPerson, N'') THEN 1 ELSE 0 END),
            (N'Department', CONVERT(NVARCHAR(200), @CurDept), CONVERT(NVARCHAR(200), @Department),
             CASE WHEN ISNULL(NULLIF(LTRIM(RTRIM(@CurDept)), N''), N'') <> ISNULL(@Department, N'') THEN 1 ELSE 0 END),
            (N'isActive', CONVERT(NVARCHAR(200), CONVERT(INT, @CurActive)), CONVERT(NVARCHAR(200), CONVERT(INT, @IsActive)),
             CASE WHEN ISNULL(@CurActive, 1) <> @IsActive THEN 1 ELSE 0 END),
            (N'IsCash', CONVERT(NVARCHAR(200), CONVERT(INT, @CurCash)), CONVERT(NVARCHAR(200), CONVERT(INT, @IsCash)),
             CASE WHEN @CurCash <> @IsCash THEN 1 ELSE 0 END),
            (N'IsStock', CONVERT(NVARCHAR(200), CONVERT(INT, @CurStock)), CONVERT(NVARCHAR(200), CONVERT(INT, @IsStock)),
             CASE WHEN @CurStock <> @IsStock THEN 1 ELSE 0 END)
         ) c (Field, PriorValue, NewValue, IsChanged)
    WHERE c.IsChanged = 1;
    SET @Changed = @Changed + @@ROWCOUNT;

    UPDATE s
       SET SupplierName = @SupplierName
      FROM [PumpIT].dbo.BRN_Suppliers s
      JOIN agora.PartnersWrite w ON w.WriteId = @WriteId AND w.Field = N'SupplierName'
                                AND w.BranchId = s.SSBranchId AND w.PartnerCode = s.SupplierCode
     WHERE s.SupplierCode = @SupplierCode;

    IF EXISTS (SELECT 1 FROM agora.PartnersWrite WHERE WriteId = @WriteId AND BranchId = @BranchId AND Field <> N'SupplierName')
        UPDATE s
           SET ContactPerson = CASE WHEN EXISTS (SELECT 1 FROM agora.PartnersWrite w WHERE w.WriteId = @WriteId AND w.Field = N'ContactPerson')
                                    THEN @ContactPerson ELSE s.ContactPerson END,
               Department    = CASE WHEN EXISTS (SELECT 1 FROM agora.PartnersWrite w WHERE w.WriteId = @WriteId AND w.Field = N'Department')
                                    THEN @Department ELSE s.Department END,
               isActive      = CASE WHEN EXISTS (SELECT 1 FROM agora.PartnersWrite w WHERE w.WriteId = @WriteId AND w.Field = N'isActive')
                                    THEN @IsActive ELSE s.isActive END,
               IsCash        = CASE WHEN EXISTS (SELECT 1 FROM agora.PartnersWrite w WHERE w.WriteId = @WriteId AND w.Field = N'IsCash')
                                    THEN @IsCash ELSE s.IsCash END,
               IsStock       = CASE WHEN EXISTS (SELECT 1 FROM agora.PartnersWrite w WHERE w.WriteId = @WriteId AND w.Field = N'IsStock')
                                    THEN @IsStock ELSE s.IsStock END
          FROM [PumpIT].dbo.BRN_Suppliers s
         WHERE s.SSBranchId = @BranchId AND s.SupplierCode = @SupplierCode;

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
                ELSE CONCAT(@Changed, N' change(s) written to PumpIT',
                            CASE WHEN @Sites > 1 THEN CONCAT(N' (the name at ', @Sites, N' sites)') ELSE N'' END,
                            N', each kept with its prior value.') END AS Message,
           CASE WHEN @Changed = 0 THEN NULL ELSE @WriteId END AS WriteId,
           @Sites AS Sites;
END
