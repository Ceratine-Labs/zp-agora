/* Rename one transaction type at one site in PumpIT (T022). A NAMED WRITE.

   CLAUDE.md rule 1; Ryan's standing rule of 12 Nov 2026 (PM decision
   01a1cecf-8e08). WRITES exactly [PumpIT].dbo.BRN_TransactionType.TypeDescription on
   ONE row, by (SSBranchId, TypeCode), in one transaction, and one
   agora.MasterWrite row, so agora.usp_Masters_ReverseWrite can put it back.

   THE NAME ONLY. TypeCode is what every purchase request names, and
   PumpIT's till-balance posting finds type '01' by its CODE
   (sp_Insert_TillBalanceTransactions). AlpNum, the prefix on the document
   number, and HighNum, the last number issued, are PumpIT's numbering and are
   never written from here.

   PER BRANCH: every site carries its own 01 and 02.
   Never an INSERT or a DELETE.

   Refuses (AGORA:{Code}):
     WRITES_OFF, DENIED (masters.transactiontypes.edit), REASON_REQUIRED, NOT_FOUND
     (also a site outside @AllowedBranchIds)
     DESCRIPTION  blank, or longer than PumpIT's 50 characters
     DUPLICATE    another transaction type at the site already has that name
     TRIGGER_ON   a PumpIT trigger on BRN_TransactionType is switched on
     STALE        the row changed since the form was drawn
   Nothing changed is not a refusal: Code NOTHING_CHANGED.

   Returns one row (Ok, Code, Message, WriteId).
   Called by Modules/Masters/Services/MasterWriteService. Deployed by v1__02pd. */
CREATE OR ALTER PROCEDURE agora.usp_Masters_SaveTransactionType
    @BranchId INT,
    @Key NVARCHAR(200),
    @TypeDescription NVARCHAR(200),
    @ExpectedTypeDescription NVARCHAR(400),
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
    SET @TypeDescription = LTRIM(RTRIM(@TypeDescription));
    DECLARE @Code NVARCHAR(2) = NULLIF(LTRIM(RTRIM(@Key)), N'');

    IF ISNULL(@WriteMode, 'off') <> 'live'
        THROW 51000, 'AGORA:WRITES_OFF:Changing reference masters is switched off (masters.write_mode). Nothing was written.', 1;
    IF ISNULL(@Authorized, 0) = 0
        THROW 51000, 'AGORA:DENIED:You may not change transaction types.', 1;
    IF LEN(ISNULL(@Reason, N'')) < ISNULL(@ReasonMin, 10)
        THROW 51000, 'AGORA:REASON_REQUIRED:Say why the transaction type is being changed — it is kept with the change.', 1;
    IF @Code IS NULL OR LEN(LTRIM(RTRIM(@Key))) > 2
       OR (NULLIF(LTRIM(RTRIM(@AllowedBranchIds)), '') IS NOT NULL
           AND NOT EXISTS (SELECT 1 FROM STRING_SPLIT(@AllowedBranchIds, ',') WHERE TRY_CONVERT(INT, value) = @BranchId))
        THROW 51000, 'AGORA:NOT_FOUND:There is no such transaction type at a site you may see.', 1;
    IF ISNULL(@TypeDescription, N'') = N'' OR LEN(@TypeDescription) > 50
        THROW 51000, 'AGORA:DESCRIPTION:A transaction type needs a name, at most 50 characters (PumpIT''s limit).', 1;
    IF EXISTS (SELECT 1 FROM [PumpIT].sys.triggers t
               WHERE t.parent_id = OBJECT_ID(N'[PumpIT].dbo.BRN_TransactionType') AND t.is_disabled = 0)
        THROW 51000, 'AGORA:TRIGGER_ON:PumpIT has a trigger switched on for BRN_TransactionType, which would write beyond it. Nothing was written.', 1;

    DECLARE @WriteId UNIQUEIDENTIFIER = NEWID();
    DECLARE @Changed INT = 0;

    BEGIN TRY
    BEGIN TRANSACTION;

    DECLARE @Found BIT, @Cur NVARCHAR(50);
    SELECT @Found = 1, @Cur = r.TypeDescription
    FROM [PumpIT].dbo.BRN_TransactionType r WITH (UPDLOCK, HOLDLOCK)
    WHERE r.SSBranchId = @BranchId AND r.TypeCode = @Code;

    IF @Found IS NULL
        THROW 51000, 'AGORA:NOT_FOUND:There is no such transaction type at a site you may see.', 1;
    IF ISNULL(LTRIM(RTRIM(@Cur)), N'') <> ISNULL(LTRIM(RTRIM(@ExpectedTypeDescription)), N'')
        THROW 51000, 'AGORA:STALE:This transaction type was changed in PumpIT or by someone else since you opened the form. Open it again and redo your change.', 1;

    IF ISNULL(LTRIM(RTRIM(@Cur)), N'') <> @TypeDescription
    BEGIN
        IF EXISTS (SELECT 1 FROM [PumpIT].dbo.BRN_TransactionType o
                   WHERE o.SSBranchId = @BranchId AND o.TypeCode <> @Code AND LTRIM(RTRIM(o.TypeDescription)) = @TypeDescription)
            THROW 51000, 'AGORA:DUPLICATE:Another transaction type at this site already has that name.', 1;

        INSERT INTO agora.MasterWrite (BranchId, WriteId, Entity, PumpItTable, RowKey, RowLabel, KeyJson, Field,
                                       PriorValue, NewValue, Reason, WriteMode, State, CreatedAt, CreatedBy)
        VALUES (@BranchId, @WriteId, N'TransactionType', N'BRN_TransactionType', @Code,
                CONCAT(N'Type ', @Code, N' · ', LTRIM(RTRIM(@Cur))),
                (SELECT @BranchId AS SSBranchId, @Code AS TypeCode FOR JSON PATH, WITHOUT_ARRAY_WRAPPER),
                N'TypeDescription', CONVERT(NVARCHAR(400), @Cur), @TypeDescription, @Reason, @WriteMode, 'active', SYSUTCDATETIME(), @UserId);
        SET @Changed = 1;

        UPDATE r SET TypeDescription = @TypeDescription
          FROM [PumpIT].dbo.BRN_TransactionType r
         WHERE r.SSBranchId = @BranchId AND r.TypeCode = @Code;
    END

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
                ELSE N'The new name was written to PumpIT''s BRN_TransactionType, the old one kept with it.' END AS Message,
           CASE WHEN @Changed = 0 THEN NULL ELSE @WriteId END AS WriteId;
END
