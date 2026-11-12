/* Put back one press of a supplier or customer edit (T024). A NAMED WRITE.

   CLAUDE.md rule 1; Ryan's standing rule of 12 Nov 2026 (PM decision
   01a1cecf-8e08). The reversal of usp_Partners_SaveSupplier and
   usp_Partners_SaveCustomer, from their own ledger, agora.PartnersWrite: each
   column the press changed is set back to its PriorValue, on the row it was
   written to, by primary key — for a supplier's name that is every site the
   press wrote. All rows of the press (@WriteId) or none, in one transaction;
   each ledger row is marked reversed with who, when and why.

   Refuses (AGORA:{Code}):
     WRITES_OFF, REASON_REQUIRED
     NOT_FOUND         no such press, or one touching a site outside
                       @AllowedBranchIds
     DENIED            @AuthorizedSuppliers / @AuthorizedCustomers does not
                       cover it (partners.suppliers.edit, partners.customers.edit)
     NOT_REVERSIBLE    the press ADDED a supplier or customer: taking it away
                       would be a DELETE, which is not a named write. Switch
                       the supplier off instead.
     ALREADY_REVERSED  nothing of this press is still in force
     MOVED             PumpIT no longer holds what the press wrote — somebody
                       changed it since — so putting the prior back would undo
                       their change too. Nothing is written.

   Returns one row (Ok, Code, Message, WriteId).
   Called by Modules/Partners/Services/PartnerWriteService. Deployed by v1__04pa. */
CREATE OR ALTER PROCEDURE agora.usp_Partners_ReverseWrite
    @WriteId UNIQUEIDENTIFIER,
    @Reason NVARCHAR(400),
    @UserId INT,
    @AuthorizedSuppliers BIT = 0,
    @AuthorizedCustomers BIT = 0,
    @WriteMode VARCHAR(10) = 'off',
    @AllowedBranchIds NVARCHAR(MAX) = NULL,
    @ReasonMin INT = 10
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    SET @Reason = NULLIF(LTRIM(RTRIM(@Reason)), N'');

    IF ISNULL(@WriteMode, 'off') <> 'live'
        THROW 51000, 'AGORA:WRITES_OFF:Changing partners is switched off (partners.write_mode). Nothing was written.', 1;
    IF LEN(ISNULL(@Reason, N'')) < ISNULL(@ReasonMin, 10)
        THROW 51000, 'AGORA:REASON_REQUIRED:Say why the change is being put back — it is kept with the reversal.', 1;
    IF NOT EXISTS (SELECT 1 FROM agora.PartnersWrite WHERE WriteId = @WriteId)
       OR (NULLIF(LTRIM(RTRIM(@AllowedBranchIds)), '') IS NOT NULL
           AND EXISTS (SELECT 1 FROM agora.PartnersWrite w WHERE w.WriteId = @WriteId
                       AND NOT EXISTS (SELECT 1 FROM STRING_SPLIT(@AllowedBranchIds, ',') s WHERE TRY_CONVERT(INT, s.value) = w.BranchId)))
        THROW 51000, 'AGORA:NOT_FOUND:There is no such change at a site you may see.', 1;
    IF EXISTS (SELECT 1 FROM agora.PartnersWrite WHERE WriteId = @WriteId AND Verb LIKE '%-add')
        THROW 51000, 'AGORA:NOT_REVERSIBLE:A supplier or customer added to PumpIT is not taken away from Agora. Switch it off instead.', 1;
    IF (EXISTS (SELECT 1 FROM agora.PartnersWrite WHERE WriteId = @WriteId AND Verb = 'supplier') AND ISNULL(@AuthorizedSuppliers, 0) = 0)
       OR (EXISTS (SELECT 1 FROM agora.PartnersWrite WHERE WriteId = @WriteId AND Verb = 'customer') AND ISNULL(@AuthorizedCustomers, 0) = 0)
        THROW 51000, 'AGORA:DENIED:You may not put this change back.', 1;
    IF NOT EXISTS (SELECT 1 FROM agora.PartnersWrite WHERE WriteId = @WriteId AND State = 'active')
        THROW 51000, 'AGORA:ALREADY_REVERSED:This change has already been put back.', 1;

    DECLARE @Rows TABLE (Id BIGINT PRIMARY KEY, BranchId INT, Verb VARCHAR(14), Code NVARCHAR(10), Field NVARCHAR(40),
                         PriorValue NVARCHAR(200), NewValue NVARCHAR(200), CurValue NVARCHAR(200), Found BIT);
    DECLARE @Count INT;

    BEGIN TRY
    BEGIN TRANSACTION;

    INSERT INTO @Rows (Id, BranchId, Verb, Code, Field, PriorValue, NewValue, Found)
    SELECT w.Id, w.BranchId, w.Verb, w.PartnerCode, w.Field, w.PriorValue, w.NewValue, 0
    FROM agora.PartnersWrite w WITH (UPDLOCK, HOLDLOCK)
    WHERE w.WriteId = @WriteId AND w.State = 'active';
    SET @Count = @@ROWCOUNT;
    IF @Count = 0
        THROW 51000, 'AGORA:ALREADY_REVERSED:This change has already been put back.', 1;

    /* What PumpIT holds now, for every value the press wrote. */
    UPDATE r
       SET Found = 1,
           CurValue = CASE r.Field
               WHEN N'SupplierName'  THEN CONVERT(NVARCHAR(200), s.SupplierName)
               WHEN N'ContactPerson' THEN CONVERT(NVARCHAR(200), s.ContactPerson)
               WHEN N'Department'    THEN CONVERT(NVARCHAR(200), s.Department)
               WHEN N'isActive'      THEN CONVERT(NVARCHAR(200), CONVERT(INT, s.isActive))
               WHEN N'IsCash'        THEN CONVERT(NVARCHAR(200), CONVERT(INT, s.IsCash))
               WHEN N'IsStock'       THEN CONVERT(NVARCHAR(200), CONVERT(INT, s.IsStock)) END
      FROM @Rows r
      JOIN [PumpIT].dbo.BRN_Suppliers s WITH (UPDLOCK, HOLDLOCK)
        ON s.SSBranchId = r.BranchId AND s.SupplierCode = r.Code
     WHERE r.Verb = 'supplier';

    UPDATE r
       SET Found = 1,
           CurValue = CASE r.Field
               WHEN N'CustomerName'    THEN CONVERT(NVARCHAR(200), c.CustomerName)
               WHEN N'CustomerContact' THEN CONVERT(NVARCHAR(200), c.CustomerContact)
               WHEN N'Department'      THEN CONVERT(NVARCHAR(200), c.Department)
               WHEN N'JobTitle'        THEN CONVERT(NVARCHAR(200), c.JobTitle)
               WHEN N'TelNoA'          THEN CONVERT(NVARCHAR(200), c.TelNoA)
               WHEN N'TelNoB'          THEN CONVERT(NVARCHAR(200), c.TelNoB) END
      FROM @Rows r
      JOIN [PumpIT].dbo.BRN_Customers c WITH (UPDLOCK, HOLDLOCK)
        ON c.SSBranchId = r.BranchId AND c.CustomerCode = r.Code
     WHERE r.Verb = 'customer';

    IF EXISTS (SELECT 1 FROM @Rows WHERE Found = 0 OR ISNULL(CurValue, N'<null>') <> ISNULL(NewValue, N'<null>'))
        THROW 51000, 'AGORA:MOVED:PumpIT no longer holds what this change wrote — somebody has changed it since. Nothing was put back.', 1;

    UPDATE s
       SET SupplierName  = CASE WHEN EXISTS (SELECT 1 FROM @Rows r WHERE r.BranchId = s.SSBranchId AND r.Field = N'SupplierName')
                                THEN (SELECT CONVERT(NVARCHAR(55), r.PriorValue) FROM @Rows r WHERE r.BranchId = s.SSBranchId AND r.Field = N'SupplierName')
                                ELSE s.SupplierName END,
           ContactPerson = CASE WHEN EXISTS (SELECT 1 FROM @Rows r WHERE r.BranchId = s.SSBranchId AND r.Field = N'ContactPerson')
                                THEN (SELECT CONVERT(NVARCHAR(50), r.PriorValue) FROM @Rows r WHERE r.BranchId = s.SSBranchId AND r.Field = N'ContactPerson')
                                ELSE s.ContactPerson END,
           Department    = CASE WHEN EXISTS (SELECT 1 FROM @Rows r WHERE r.BranchId = s.SSBranchId AND r.Field = N'Department')
                                THEN (SELECT CONVERT(NVARCHAR(30), r.PriorValue) FROM @Rows r WHERE r.BranchId = s.SSBranchId AND r.Field = N'Department')
                                ELSE s.Department END,
           isActive      = CASE WHEN EXISTS (SELECT 1 FROM @Rows r WHERE r.BranchId = s.SSBranchId AND r.Field = N'isActive')
                                THEN (SELECT CONVERT(BIT, r.PriorValue) FROM @Rows r WHERE r.BranchId = s.SSBranchId AND r.Field = N'isActive')
                                ELSE s.isActive END,
           IsCash        = CASE WHEN EXISTS (SELECT 1 FROM @Rows r WHERE r.BranchId = s.SSBranchId AND r.Field = N'IsCash')
                                THEN (SELECT CONVERT(BIT, r.PriorValue) FROM @Rows r WHERE r.BranchId = s.SSBranchId AND r.Field = N'IsCash')
                                ELSE s.IsCash END,
           IsStock       = CASE WHEN EXISTS (SELECT 1 FROM @Rows r WHERE r.BranchId = s.SSBranchId AND r.Field = N'IsStock')
                                THEN (SELECT CONVERT(BIT, r.PriorValue) FROM @Rows r WHERE r.BranchId = s.SSBranchId AND r.Field = N'IsStock')
                                ELSE s.IsStock END
      FROM [PumpIT].dbo.BRN_Suppliers s
      JOIN (SELECT DISTINCT BranchId, Code FROM @Rows WHERE Verb = 'supplier') k
        ON k.BranchId = s.SSBranchId AND k.Code = s.SupplierCode;

    UPDATE c
       SET CustomerName    = CASE WHEN EXISTS (SELECT 1 FROM @Rows r WHERE r.Field = N'CustomerName')
                                  THEN (SELECT CONVERT(NVARCHAR(100), r.PriorValue) FROM @Rows r WHERE r.Field = N'CustomerName') ELSE c.CustomerName END,
           CustomerContact = CASE WHEN EXISTS (SELECT 1 FROM @Rows r WHERE r.Field = N'CustomerContact')
                                  THEN (SELECT CONVERT(NVARCHAR(35), r.PriorValue) FROM @Rows r WHERE r.Field = N'CustomerContact') ELSE c.CustomerContact END,
           Department      = CASE WHEN EXISTS (SELECT 1 FROM @Rows r WHERE r.Verb = 'customer' AND r.Field = N'Department')
                                  THEN (SELECT CONVERT(NVARCHAR(30), r.PriorValue) FROM @Rows r WHERE r.Verb = 'customer' AND r.Field = N'Department') ELSE c.Department END,
           JobTitle        = CASE WHEN EXISTS (SELECT 1 FROM @Rows r WHERE r.Field = N'JobTitle')
                                  THEN (SELECT CONVERT(NVARCHAR(50), r.PriorValue) FROM @Rows r WHERE r.Field = N'JobTitle') ELSE c.JobTitle END,
           TelNoA          = CASE WHEN EXISTS (SELECT 1 FROM @Rows r WHERE r.Field = N'TelNoA')
                                  THEN (SELECT CONVERT(NVARCHAR(25), r.PriorValue) FROM @Rows r WHERE r.Field = N'TelNoA') ELSE c.TelNoA END,
           TelNoB          = CASE WHEN EXISTS (SELECT 1 FROM @Rows r WHERE r.Field = N'TelNoB')
                                  THEN (SELECT CONVERT(NVARCHAR(25), r.PriorValue) FROM @Rows r WHERE r.Field = N'TelNoB') ELSE c.TelNoB END
      FROM [PumpIT].dbo.BRN_Customers c
      JOIN (SELECT DISTINCT BranchId, Code FROM @Rows WHERE Verb = 'customer') k
        ON k.BranchId = c.SSBranchId AND k.Code = c.CustomerCode;

    UPDATE w
       SET State = 'reversed', ReversedAt = SYSUTCDATETIME(), ReversedBy = @UserId, ReversalReason = @Reason,
           UpdatedAt = SYSUTCDATETIME(), UpdatedBy = @UserId
      FROM agora.PartnersWrite w
      JOIN @Rows r ON r.Id = w.Id;

    COMMIT TRANSACTION;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
        DECLARE @Err NVARCHAR(2048) = ERROR_MESSAGE();
        THROW 51000, @Err, 1;
    END CATCH

    SELECT CONVERT(BIT, 1) AS Ok, 'REVERSED' AS Code,
           CONCAT(@Count, N' change(s) put back in PumpIT.') AS Message, @WriteId AS WriteId;
END
