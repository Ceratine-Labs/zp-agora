/* Change one expense code at one site in PumpIT (T022). A NAMED WRITE.

   CLAUDE.md rule 1; Ryan's standing rule of 12 Nov 2026 (PM decision
   01a1cecf-8e08). WRITES exactly [PumpIT].dbo.BRN_Expenses
   .ExpenseDescription, .GLCode, .Vat and .IsActive on ONE row, by
   (SSBranchId, ExpenseNo), in one transaction, and one agora.MasterWrite row
   per column changed, so agora.usp_Masters_ReverseWrite can put it back.

   NEVER the ExpenseNo (every purchase request line and MIST expense row names
   it), the approver (ApproverUserId routes PumpIT's approvals) or the asset
   flag. Never an INSERT or a DELETE.

   'TILL BALANCE CONTROL' IS RESERVED. PumpIT's own posting of an approved till
   balance (dbo.sp_Insert_TillBalanceTransactions) finds its expense by that
   TEXT at the site, so it is never renamed, never switched off, and no other
   expense is renamed to it — a second one would make the lookup pick either.

   Refuses (AGORA:{Code}):
     WRITES_OFF, DENIED (masters.expenses.edit), REASON_REQUIRED, NOT_FOUND
     (also a site outside @AllowedBranchIds)
     DESCRIPTION  blank, or longer than PumpIT's 60 characters
     DUPLICATE    another expense at the site already has that description
     RESERVED     the Till Balance Control expense, as above
     GL_CODE      a GL code blank, longer than PumpIT's 8 characters or not
                  digits
     VAT          VAT other than 0 or 15 — PumpIT only knows VAT or none
                  (sp_Insert_TillBalanceTransactions reads Vat <> 0 as VAT)
     IN_USE       switching off an expense a purchase request at the site
                  still waiting for approval names
     TRIGGER_ON   a PumpIT trigger on BRN_Expenses is switched on
     STALE        the row changed since the form was drawn
   Nothing changed is not a refusal: Code NOTHING_CHANGED.

   Returns one row (Ok, Code, Message, WriteId).
   Called by Modules/Masters/Services/MasterWriteService. Deployed by v1__02pd. */
CREATE OR ALTER PROCEDURE agora.usp_Masters_SaveExpense
    @BranchId INT,
    @Key NVARCHAR(200),
    @ExpenseDescription NVARCHAR(200),
    @GLCode NVARCHAR(50),
    @Vat DECIMAL(9, 2),
    @IsActive BIT,
    @ExpectedExpenseDescription NVARCHAR(400),
    @ExpectedGLCode NVARCHAR(400),
    @ExpectedVat DECIMAL(9, 2),
    @ExpectedIsActive BIT,
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

    DECLARE @Reserved NVARCHAR(60) = N'Till Balance Control';
    SET @Reason = NULLIF(LTRIM(RTRIM(@Reason)), N'');
    SET @ExpenseDescription = LTRIM(RTRIM(@ExpenseDescription));
    SET @GLCode = LTRIM(RTRIM(@GLCode));
    DECLARE @No INT = TRY_CONVERT(INT, @Key);

    IF ISNULL(@WriteMode, 'off') <> 'live'
        THROW 51000, 'AGORA:WRITES_OFF:Changing reference masters is switched off (masters.write_mode). Nothing was written.', 1;
    IF ISNULL(@Authorized, 0) = 0
        THROW 51000, 'AGORA:DENIED:You may not change expense codes.', 1;
    IF LEN(ISNULL(@Reason, N'')) < ISNULL(@ReasonMin, 10)
        THROW 51000, 'AGORA:REASON_REQUIRED:Say why the expense code is being changed — it is kept with the change.', 1;
    IF @No IS NULL
       OR (NULLIF(LTRIM(RTRIM(@AllowedBranchIds)), '') IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM STRING_SPLIT(@AllowedBranchIds, ',') WHERE TRY_CONVERT(INT, value) = @BranchId))
        THROW 51000, 'AGORA:NOT_FOUND:There is no such expense code at a site you may see.', 1;
    IF ISNULL(@ExpenseDescription, N'') = N'' OR LEN(@ExpenseDescription) > 60
        THROW 51000, 'AGORA:DESCRIPTION:An expense needs a description, at most 60 characters (PumpIT''s limit).', 1;
    IF ISNULL(@GLCode, N'') = N'' OR LEN(@GLCode) > 8 OR @GLCode LIKE N'%[^0-9]%'
        THROW 51000, 'AGORA:GL_CODE:A GL code is the account number: digits only, at most 8.', 1;
    IF @Vat IS NULL OR @Vat NOT IN (0, 15)
        THROW 51000, 'AGORA:VAT:VAT is 0 or 15. PumpIT treats an expense as VAT or not.', 1;
    IF EXISTS (SELECT 1 FROM [PumpIT].sys.triggers t
               WHERE t.parent_id = OBJECT_ID(N'[PumpIT].dbo.BRN_Expenses') AND t.is_disabled = 0)
        THROW 51000, 'AGORA:TRIGGER_ON:PumpIT has a trigger switched on for BRN_Expenses, which would write beyond it. Nothing was written.', 1;

    DECLARE @WriteId UNIQUEIDENTIFIER = NEWID();
    DECLARE @Changed INT = 0;

    BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @Found BIT, @CurDesc NVARCHAR(60), @CurGl NVARCHAR(8), @CurVat INT, @Cur BIT;
    SELECT @Found = 1, @CurDesc = e.ExpenseDescription, @CurGl = e.GLCode, @CurVat = e.Vat, @Cur = e.IsActive
    FROM [PumpIT].dbo.BRN_Expenses e WITH (UPDLOCK, HOLDLOCK)
    WHERE e.SSBranchId = @BranchId AND e.ExpenseNo = @No;

    IF @Found IS NULL
        THROW 51000, 'AGORA:NOT_FOUND:There is no such expense code at a site you may see.', 1;
    IF ISNULL(LTRIM(RTRIM(@CurDesc)), N'') <> ISNULL(LTRIM(RTRIM(@ExpectedExpenseDescription)), N'')
       OR ISNULL(LTRIM(RTRIM(@CurGl)), N'') <> ISNULL(LTRIM(RTRIM(@ExpectedGLCode)), N'')
       OR ISNULL(@CurVat, -1) <> ISNULL(@ExpectedVat, -1)
       OR ISNULL(@Cur, 0) <> ISNULL(@ExpectedIsActive, 0)
        THROW 51000, 'AGORA:STALE:This expense code was changed in PumpIT or by someone else since you opened the form. Open it again and redo your change.', 1;

    DECLARE @Renamed BIT = IIF(ISNULL(LTRIM(RTRIM(@CurDesc)), N'') <> @ExpenseDescription, 1, 0);
    IF (LTRIM(RTRIM(@CurDesc)) = @Reserved AND (@Renamed = 1 OR @IsActive = 0))
       OR (@Renamed = 1 AND @ExpenseDescription = @Reserved)
        THROW 51000, 'AGORA:RESERVED:PumpIT posts every till balance to the expense called Till Balance Control, found by that name. It is not renamed or switched off, and no other expense takes the name.', 1;
    IF @Renamed = 1 AND EXISTS (
            SELECT 1 FROM [PumpIT].dbo.BRN_Expenses o
            WHERE o.SSBranchId = @BranchId AND o.ExpenseNo <> @No AND LTRIM(RTRIM(o.ExpenseDescription)) = @ExpenseDescription)
        THROW 51000, 'AGORA:DUPLICATE:Another expense at this site already has that description.', 1;
    IF @IsActive = 0 AND ISNULL(@Cur, 0) = 1 AND EXISTS (
            SELECT 1 FROM [PumpIT].dbo.BRN_TransactionLine l
            JOIN [PumpIT].dbo.BRN_Transaction t
              ON t.SSBranchId = l.SSBranchId AND t.TransactionNo = l.TransactionNo AND t.TypeCode = l.TypeCode
            WHERE l.SSBranchId = @BranchId AND l.ExpenseNo = @No
              AND ISNULL(t.Approved, 0) = 0 AND ISNULL(t.IsDeclined, 0) = 0 AND ISNULL(t.Posted, 0) = 0)
        THROW 51000, 'AGORA:IN_USE:A purchase request at this site waiting for approval names this expense. Approve or decline it first.', 1;

    INSERT INTO agora.MasterWrite (BranchId, WriteId, Entity, PumpItTable, RowKey, RowLabel, KeyJson, Field,
                                   PriorValue, NewValue, Reason, WriteMode, State, CreatedAt, CreatedBy)
    SELECT @BranchId, @WriteId, N'Expense', N'BRN_Expenses', CONVERT(NVARCHAR(200), @No),
           CONCAT(N'Expense ', @No, N' · ', LTRIM(RTRIM(@CurDesc))),
           (SELECT @BranchId AS SSBranchId, @No AS ExpenseNo FOR JSON PATH, WITHOUT_ARRAY_WRAPPER),
           c.Field, c.PriorValue, c.NewValue, @Reason, @WriteMode, 'active', SYSUTCDATETIME(), @UserId
    FROM (VALUES
            (N'ExpenseDescription', CONVERT(NVARCHAR(400), @CurDesc), CONVERT(NVARCHAR(400), @ExpenseDescription), @Renamed),
            (N'GLCode', CONVERT(NVARCHAR(400), @CurGl), CONVERT(NVARCHAR(400), @GLCode), IIF(ISNULL(LTRIM(RTRIM(@CurGl)), N'') <> @GLCode, 1, 0)),
            (N'Vat', CONVERT(NVARCHAR(400), @CurVat), CONVERT(NVARCHAR(400), CONVERT(INT, @Vat)), IIF(ISNULL(@CurVat, -1) <> @Vat, 1, 0)),
            (N'IsActive', CONVERT(NVARCHAR(10), CONVERT(INT, @Cur)), CONVERT(NVARCHAR(10), CONVERT(INT, @IsActive)), IIF(ISNULL(@Cur, 0) <> @IsActive OR @Cur IS NULL, 1, 0))
         ) c (Field, PriorValue, NewValue, IsChanged)
    WHERE c.IsChanged = 1;
    SET @Changed = @@ROWCOUNT;

    IF @Changed > 0
        UPDATE e
           SET ExpenseDescription = CASE WHEN @Renamed = 1 THEN @ExpenseDescription ELSE e.ExpenseDescription END,
               GLCode             = CASE WHEN ISNULL(LTRIM(RTRIM(e.GLCode)), N'') <> @GLCode THEN @GLCode ELSE e.GLCode END,
               Vat                = CONVERT(INT, @Vat),
               IsActive           = @IsActive
          FROM [PumpIT].dbo.BRN_Expenses e
         WHERE e.SSBranchId = @BranchId AND e.ExpenseNo = @No;

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
                ELSE CONCAT(@Changed, N' change(s) written to PumpIT''s BRN_Expenses, each kept with its prior value.') END AS Message,
           CASE WHEN @Changed = 0 THEN NULL ELSE @WriteId END AS WriteId;
END
