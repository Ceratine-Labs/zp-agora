/* Change one standard category's GL codes or active flag in PumpIT (T022).
   A NAMED WRITE.

   CLAUDE.md rule 1; Ryan's standing rule of 12 Nov 2026 (PM decision
   01a1cecf-8e08). WRITES exactly [PumpIT].dbo.SS_StandardCategory
   .SupplierGLCode, .SalesGLCode and .IsActive on ONE row, by
   StandardCategoryId, in one transaction, and one agora.MasterWrite row per
   column changed, so agora.usp_Masters_ReverseWrite can put it back.

   ONE ROW, NOTHING ELSE. The POS categories mapped to it keep their own GL
   codes in SDK_CategoryGLCode; they are not rewritten from here.

   THE NAME IS NOT CHANGED, ever, from Agora. PumpIT refers to a standard
   category by its TEXT — SDK_CategoryGLCode.StandardCategory and the copy
   every POS load stamps onto the MIST_Trans_* and _MIST_Transaction_* tables
   — and a rename could not follow it into the transactions in one
   transaction. It is refused rather than half-done.

   Refuses (AGORA:{Code}):
     WRITES_OFF, DENIED (masters.standardcategories.edit), REASON_REQUIRED,
     NOT_FOUND
     GL_CODE     a GL code blank, longer than 20 characters or not digits
     IN_USE      switching off a standard category ACTIVE POS categories
                 still map to
     TRIGGER_ON  a PumpIT trigger on SS_StandardCategory is switched on
                 (trg_SS_StandardCategory_Replicate; disabled 12 Nov 2026)
     STALE       the row changed since the form was drawn
   Nothing changed is not a refusal: Code NOTHING_CHANGED.

   @BranchId is the group entity (2): SS_StandardCategory is group-wide.
   Returns one row (Ok, Code, Message, WriteId).
   Called by Modules/Masters/Services/MasterWriteService. Deployed by v1__02pb. */
CREATE OR ALTER PROCEDURE agora.usp_Masters_SaveStandardCategory
    @BranchId INT,
    @Key NVARCHAR(200),
    @SupplierGLCode NVARCHAR(50),
    @SalesGLCode NVARCHAR(50),
    @IsActive BIT,
    @ExpectedSupplierGLCode NVARCHAR(50),
    @ExpectedSalesGLCode NVARCHAR(50),
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

    SET @Reason = NULLIF(LTRIM(RTRIM(@Reason)), N'');
    SET @SupplierGLCode = LTRIM(RTRIM(@SupplierGLCode));
    SET @SalesGLCode = LTRIM(RTRIM(@SalesGLCode));
    DECLARE @Id INT = TRY_CONVERT(INT, @Key);

    IF ISNULL(@WriteMode, 'off') <> 'live'
        THROW 51000, 'AGORA:WRITES_OFF:Changing reference masters is switched off (masters.write_mode). Nothing was written.', 1;
    IF ISNULL(@Authorized, 0) = 0
        THROW 51000, 'AGORA:DENIED:You may not change standard categories.', 1;
    IF LEN(ISNULL(@Reason, N'')) < ISNULL(@ReasonMin, 10)
        THROW 51000, 'AGORA:REASON_REQUIRED:Say why the standard category is being changed — it is kept with the change.', 1;
    IF ISNULL(@SupplierGLCode, N'') = N'' OR ISNULL(@SalesGLCode, N'') = N''
       OR LEN(@SupplierGLCode) > 20 OR LEN(@SalesGLCode) > 20
       OR @SupplierGLCode LIKE N'%[^0-9]%' OR @SalesGLCode LIKE N'%[^0-9]%'
        THROW 51000, 'AGORA:GL_CODE:A GL code is the account number: digits only, at most 20.', 1;
    IF EXISTS (SELECT 1 FROM [PumpIT].sys.triggers t
               WHERE t.parent_id = OBJECT_ID(N'[PumpIT].dbo.SS_StandardCategory') AND t.is_disabled = 0)
        THROW 51000, 'AGORA:TRIGGER_ON:PumpIT has a trigger switched on for SS_StandardCategory, which would write beyond it. Nothing was written.', 1;

    DECLARE @WriteId UNIQUEIDENTIFIER = NEWID();
    DECLARE @Changed INT = 0;

    BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @Name VARCHAR(40), @CurSup VARCHAR(50), @CurSales VARCHAR(50), @Cur BIT;
    SELECT @Name = s.StandardCategory, @CurSup = s.SupplierGLCode, @CurSales = s.SalesGLCode, @Cur = s.IsActive
    FROM [PumpIT].dbo.SS_StandardCategory s WITH (UPDLOCK, HOLDLOCK)
    WHERE s.StandardCategoryId = @Id;

    IF @Name IS NULL
        THROW 51000, 'AGORA:NOT_FOUND:There is no such standard category.', 1;
    IF LTRIM(RTRIM(@CurSup)) <> ISNULL(@ExpectedSupplierGLCode, N'') OR LTRIM(RTRIM(@CurSales)) <> ISNULL(@ExpectedSalesGLCode, N'')
       OR @Cur <> @ExpectedIsActive
        THROW 51000, 'AGORA:STALE:This standard category was changed in PumpIT or by someone else since you opened the form. Open it again and redo your change.', 1;
    IF @IsActive = 0 AND @Cur = 1 AND EXISTS (
            SELECT 1 FROM [PumpIT].dbo.SDK_CategoryGLCode g
            WHERE g.IsActive = 1 AND LTRIM(RTRIM(g.StandardCategory)) = LTRIM(RTRIM(@Name)))
        THROW 51000, 'AGORA:IN_USE:Active POS categories still map to this standard category. Move them or switch them off first.', 1;

    INSERT INTO agora.MasterWrite (BranchId, WriteId, Entity, PumpItTable, RowKey, RowLabel, KeyJson, Field,
                                   PriorValue, NewValue, Reason, WriteMode, State, CreatedAt, CreatedBy)
    SELECT @BranchId, @WriteId, N'StandardCategory', N'SS_StandardCategory', CONVERT(NVARCHAR(200), @Id), LTRIM(RTRIM(@Name)),
           (SELECT @Id AS StandardCategoryId FOR JSON PATH, WITHOUT_ARRAY_WRAPPER),
           c.Field, c.PriorValue, c.NewValue, @Reason, @WriteMode, 'active', SYSUTCDATETIME(), @UserId
    FROM (VALUES
            (N'SupplierGLCode', CONVERT(NVARCHAR(400), @CurSup), CONVERT(NVARCHAR(400), @SupplierGLCode), IIF(LTRIM(RTRIM(@CurSup)) <> @SupplierGLCode, 1, 0)),
            (N'SalesGLCode', CONVERT(NVARCHAR(400), @CurSales), CONVERT(NVARCHAR(400), @SalesGLCode), IIF(LTRIM(RTRIM(@CurSales)) <> @SalesGLCode, 1, 0)),
            (N'IsActive', CONVERT(NVARCHAR(10), CONVERT(INT, @Cur)), CONVERT(NVARCHAR(10), CONVERT(INT, @IsActive)), IIF(@Cur <> @IsActive, 1, 0))
         ) c (Field, PriorValue, NewValue, IsChanged)
    WHERE c.IsChanged = 1;
    SET @Changed = @@ROWCOUNT;

    IF @Changed > 0
        UPDATE s
           SET SupplierGLCode = CASE WHEN LTRIM(RTRIM(s.SupplierGLCode)) <> @SupplierGLCode THEN @SupplierGLCode ELSE s.SupplierGLCode END,
               SalesGLCode    = CASE WHEN LTRIM(RTRIM(s.SalesGLCode)) <> @SalesGLCode THEN @SalesGLCode ELSE s.SalesGLCode END,
               IsActive       = @IsActive
          FROM [PumpIT].dbo.SS_StandardCategory s
         WHERE s.StandardCategoryId = @Id;

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
                ELSE CONCAT(@Changed, N' change(s) written to PumpIT''s SS_StandardCategory, each kept with its prior value.') END AS Message,
           CASE WHEN @Changed = 0 THEN NULL ELSE @WriteId END AS WriteId;
END
